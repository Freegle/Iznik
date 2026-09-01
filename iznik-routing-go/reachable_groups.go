package main

import (
	"database/sql"
	"fmt"
	"math"
	"sort"
	"strconv"

	"github.com/gofiber/fiber/v2"
)

// memberLoc is one active member's group id and approximate location.
type memberLoc struct {
	groupID int64
	lat     float64
	lng     float64
}

// memberSnap is an active member resolved to their drive-time from the origin: their group
// and the Dijkstra seconds of their nearest road node. Only members whose street node is in
// the reached set appear as snaps - a node is reached only if a drivable path exists back to
// the origin, so it cannot have crossed severed water or an excluded toll. This is the "a
// road goes from the member to the offer" test, not a polygon overlap.
type memberSnap struct {
	groupID int64
	seconds float32
}

// snapMembers resolves each member's location to their nearest road node and keeps those
// whose node is in the reached set, with the drive-time to that node. Members with no nearby
// road node (e.g. an offshore point) or whose street is not reached (far bank of a severed
// crossing) are dropped. Duplicate locations are looked up once.
func snapMembers(g *Graph, reached map[NodeID]float32, members []memberLoc) []memberSnap {
	type key struct{ lat, lng float64 }
	nodeCache := make(map[key]NodeID)
	snaps := make([]memberSnap, 0, len(members))
	for _, m := range members {
		k := key{m.lat, m.lng}
		nn, ok := nodeCache[k]
		if !ok {
			nn = nearestDriveNode(g, m.lat, m.lng)
			nodeCache[k] = nn
		}
		if nn == noNode {
			continue
		}
		if secs, ok := reached[nn]; ok {
			snaps = append(snaps, memberSnap{groupID: m.groupID, seconds: secs})
		}
	}
	return snaps
}

// groupIDsWithinSeconds returns the sorted ids of groups with at least one snapped member
// whose drive-time is within the budget - "which groups are reached at this tick". One
// reachable member suffices. Always non-nil so it serialises as [] not null.
func groupIDsWithinSeconds(snaps []memberSnap, budgetSeconds float32) []int64 {
	seen := make(map[int64]bool)
	for _, s := range snaps {
		if s.seconds <= budgetSeconds {
			seen[s.groupID] = true
		}
	}
	ids := make([]int64, 0, len(seen))
	for id := range seen {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
	return ids
}

// freeglerReachableGroupIDs returns the ids of groups that have at least one member whose
// location is genuinely road-reachable from the origin, at any drive-time in the reached
// set. The members passed in are already the active members who live inside their own
// group's polygon (see queryActiveMembersInBox), so a group is counted only when a real
// person who lives there can actually drive to the post.
func freeglerReachableGroupIDs(g *Graph, reached map[NodeID]float32, members []memberLoc) []int64 {
	return groupIDsWithinSeconds(snapMembers(g, reached, members), float32(math.Inf(1)))
}

// reachedBBox is the lat/lng bounding box of the reached node set, used to pre-filter the
// active-member query cheaply before the exact nearest-node test.
func reachedBBox(g *Graph, reached map[NodeID]float32) (minLat, maxLat, minLng, maxLng float64) {
	minLat, minLng = 90, 180
	maxLat, maxLng = -90, -180
	for id := range reached {
		n := g.Nodes[id]
		lat, lng := float64(n.Lat), float64(n.Lng)
		if lat < minLat {
			minLat = lat
		}
		if lat > maxLat {
			maxLat = lat
		}
		if lng < minLng {
			minLng = lng
		}
		if lng > maxLng {
			maxLng = lng
		}
	}
	return
}

// queryActiveMembersInBox returns every (group, location) for Approved members active in the
// last 90 days (the same "active" definition as group-actives) whose location falls in the
// bounding box AND inside their own group's polygon. The in-polygon filter is what makes a
// group's membership mean "people who live in the group's area", not "anyone who happens to
// be a member" - so a member of Gravesend_Freegle who actually lives in Essex (a common
// rippled-in case) does not make Gravesend reachable.
//
// The location used is the member's postcode centre (users.lastlocation -> locations),
// falling back to their privacy-blurred approx point only when no usable postcode exists
// (~0.06% of active members on live). The precise point matters because the blur is the one
// thing that can put a member's point in the wrong place relative to a barrier: a blurred
// point can land in the river channel, where the crow-flies nearest road node is on the far
// bank, wrongly making the far group "reachable". A postcode centre sits on its own street,
// on the correct bank (postcodes do not span barriers). This is server-side only - member
// locations are never returned to the caller, only group ids. users_approxlocs is a modest
// (~168k) point cloud, so this stays cheap.
func queryActiveMembersInBox(db *sql.DB, minLat, maxLat, minLng, maxLng float64) ([]memberLoc, error) {
	// STRAIGHT_JOIN driving from the users_approxlocs SPATIAL index: without it the
	// optimizer leads with groups x memberships (~1.8M rows nationally, ~20s); with it
	// the ~thousands of in-bbox freeglers come first (~1s). position is SRID 3857 with
	// lng/lat axis order, matching the POLYGON below.
	bboxWKT := fmt.Sprintf("POLYGON((%[1]f %[3]f, %[2]f %[3]f, %[2]f %[4]f, %[1]f %[4]f, %[1]f %[3]f))",
		minLng, maxLng, minLat, maxLat)
	rows, err := db.Query(
		`SELECT STRAIGHT_JOIN DISTINCT m.groupid,
		        COALESCE(pl.lat, ual.lat), COALESCE(pl.lng, ual.lng)
		 FROM users_approxlocs ual
		 INNER JOIN memberships m ON m.userid = ual.userid AND m.collection = 'Approved'
		 INNER JOIN users u ON u.id = ual.userid AND u.lastaccess >= NOW() - INTERVAL 90 DAY
		 INNER JOIN `+"`groups`"+` g ON g.id = m.groupid
		     AND g.publish = 1 AND g.listable = 1
		     AND ST_GeometryType(g.polyindex) IN ('POLYGON', 'MULTIPOLYGON')
		 LEFT JOIN locations pl ON pl.id = u.lastlocation
		     AND pl.type = 'Postcode' AND NOT (pl.lat = 0 AND pl.lng = 0)
		 WHERE MBRContains(ST_GeomFromText(?, 3857), ual.position)
		   AND ST_Contains(g.polyindex,
		         ST_SRID(POINT(COALESCE(pl.lng, ual.lng), COALESCE(pl.lat, ual.lat)), 3857))`,
		bboxWKT)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]memberLoc, 0, 1024)
	for rows.Next() {
		var ml memberLoc
		if err := rows.Scan(&ml.groupID, &ml.lat, &ml.lng); err != nil {
			continue
		}
		out = append(out, ml)
	}
	return out, rows.Err()
}

// handleReachableGroups serves GET /v1/reachable-groups?lat=&lng=&minutes=&mode=. A group is
// returned iff at least one active member's own location is road-reachable from the origin
// (see freeglerReachableGroupIDs). This is the ripple-targeting signal: it counts a group only
// when there is a real person there who can actually drive to the post, which excludes both
// far-bank groups across severed water and groups whose reached corner has no active freeglers.
// reachable_group_ids is always present and non-null; it is empty when computable-but-zero, so
// callers can tell "gate computed nothing" from "no data".
func handleReachableGroups(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		latS, lngS := c.Query("lat"), c.Query("lng")
		if latS == "" || lngS == "" {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		lat, err1 := strconv.ParseFloat(latS, 64)
		lng, err2 := strconv.ParseFloat(lngS, 64)
		if err1 != nil || err2 != nil {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng must be numeric")
		}
		minutes, _ := strconv.ParseFloat(c.Query("minutes", "30"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 30
		}
		ids := make([]int64, 0)
		iso := Isochrone(g, lat, lng, float32(minutes*60))
		if len(iso.ReachedNodes) > 0 {
			if db := ensureGroupsDB(); db != nil {
				minLat, maxLat, minLng, maxLng := reachedBBox(g, iso.ReachedNodes)
				if members, err := queryActiveMembersInBox(db, minLat, maxLat, minLng, maxLng); err == nil {
					ids = freeglerReachableGroupIDs(g, iso.ReachedNodes, members)
				}
			}
		}
		return c.JSON(fiber.Map{"reachable_group_ids": ids})
	}
}
