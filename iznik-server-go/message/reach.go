package message

import (
	"database/sql"
	"encoding/base64"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// ReachBlockedSet returns the subset of msgids that a viewer at (lat,lng) may
// NOT reply to because the post has rippled out (it has a rippling_reach row)
// but not yet to the viewer's location. This is the canonical reply-eligibility
// containment check, shared by the browse read path and the similar-posts
// endpoint so the SQL lives in exactly one place.
//
// Fail-open semantics (matching the existing guards): a msgid is blocked only
// when a reach row exists AND its reach does not contain the point. Any error
// (e.g. rippling_reach not yet deployed) yields an empty set, and a viewer with
// no location (0,0) is never blocked.
//
// myid is the VIEWER, when there is one: a viewer an overflow ring admits (see
// rippling.ViewerOverflowPaths) is not blocked, matching the feed, the badge
// and search - the mail deliberately invites ring members, so the message page
// must not tell them "not reached yet" nor the reply gates hold what they send.
// Callers checking reach from a post's own location rather than a viewer's (the
// match mailers) pass 0: no viewer, no rings.
//
// A FROZEN reach (status 'held', set by FreezeReachIfOriginPending when the origin copy is
// pulled back for moderation) is treated here exactly like a live one. Freezing stops a post
// being pushed OUTWARD - it is not mailed or pushed while its origin is under review - but it
// does not make a member who is outside the polygon reached. They are not, so the message page
// tells them so, and their reply is held to protect the poster's ordering just as it would be
// on a post still expanding.
//
// Containment consults the sandwich bounds when migrated (see
// rippling/reachbounds.go): outside a real outer_bound is an authoritative
// reject, inside inner_bound an authoritative accept, and only the band between
// touches the ~178KB exact polygon; POINT (completed) bounds fall back to the
// exact test. The rings sit OUTSIDE that ladder: they can rescue even an
// authoritative polygon reject, because a ring extends beyond the outer bound
// by construction.
//
// The query itself is ReachBlockedOrigins; this is the membership-only view of
// it for the callers that do not need the origins.
func ReachBlockedSet(myid uint64, msgids []uint64, lat, lng float64) map[uint64]bool {
	origins := ReachBlockedOrigins(myid, msgids, lat, lng)
	blocked := make(map[uint64]bool, len(origins))
	for msgid := range origins {
		blocked[msgid] = true
	}

	return blocked
}

// ReachOrigin is what a blocked post's reach row says about itself: the already-blurred
// point the reach was grown from, and the timetable it will grow on. Ok is false when
// the row has no origin, which is possible on rows written before the columns were
// populated - callers must not treat (0,0) as a location.
type ReachOrigin struct {
	Lat float64
	Lng float64
	Ok  bool
	// Schedule and Arrival are what turn a member's drive time into a date: the tick
	// whose drive-time budget first reaches them, and when that tick goes live.
	// Schedule is nil when the column is empty or unusable.
	Schedule []rippling.ScheduleTick
	Arrival  *time.Time
}

// ReachBlockedOrigins is ReachBlockedSet with the reach origin of each blocked
// post, for sizing the reply-delay estimate shown to a member who is about to
// reply to something that has not rippled to them yet.
//
// It is the same single query - the blocked rows are already being read, so
// carrying two more columns off them costs nothing, and the delay is then pure
// arithmetic. That matters because this sits on the feed's hot path: an estimate
// that needed its own query (or worse, a routing call) per post would not be
// worth showing.
func ReachBlockedOrigins(myid uint64, msgids []uint64, lat, lng float64) map[uint64]ReachOrigin {
	blocked := make(map[uint64]ReachOrigin)
	if len(msgids) == 0 || (lat == 0 && lng == 0) {
		return blocked
	}

	db := database.DBConn

	// The viewer's rings, as a rescue: NOT blocked when a ring admits them,
	// however the polygon test came out.
	//
	// Asked of rippling.AdmittedMsgids - the SAME call the feed, the badge, the
	// reply gate and the mail make - and applied here in Go rather than as a
	// predicate in the query. Consistency across surfaces is the constraint this
	// lane lives under: a member the mail invites must not be told "not reached
	// yet" here, and the only way to guarantee that is for every surface to read
	// one answer rather than to re-derive it from the same JSON and hope the
	// derivations agree. It also keeps the ring test out of a query that has an
	// index to lose (see the 2026-08-21 incidents).
	admitted := make(map[uint64]struct{})
	for _, id := range rippling.AdmittedMsgids(db, lng, lat, utils.SRID,
		rippling.ViewerOverflowPaths(db, myid, float32(lat), float32(lng))) {
		admitted[id] = struct{}{}
	}
	// Containment from each row's stored cell grid by primary key
	// (rippling.ReachMembership), with the legacy sandwich/exact SQL as the
	// helper's own per-row fallback while those columns exist. A failed
	// fetch keeps the old behaviour for a failed query: nothing is reported
	// blocked.
	membership, err := rippling.ReachMembership(db, msgids, lng, lat, utils.SRID)
	if err == nil {
		for id, info := range membership {
			if info.InReach {
				continue
			}
			// A ring admits them: the post is not blocked, whatever the
			// committed reach said. Applied after the lookup so the ring
			// answer comes from one place for every surface.
			if _, ok := admitted[id]; ok {
				continue
			}
			origin := ReachOrigin{Arrival: info.Arrival}
			if info.Lat != nil && info.Lng != nil {
				origin.Lat, origin.Lng, origin.Ok = *info.Lat, *info.Lng, true
			}
			if info.Schedule != nil {
				origin.Schedule = rippling.ParseSchedule(*info.Schedule)
			}
			blocked[id] = origin
		}
	}
	return blocked
}

// ReachResponse is a post's ACTUAL rippling-out progress, for the moderation reach map to
// compare against the EXPECTED progress (the map itself draws the expected/projected reach;
// this tells the modal how far the post has really rippled, so it can flag when the engine
// is behind). `Rippling` is false (with a Reason) when the post has no reach row.
type ReachResponse struct {
	Rippling bool `json:"rippling"`
	// Reason explains why a post has no actual reach (only set when Rippling is false):
	//   "disabled"     - rippling is switched off globally (the dark default).
	//   "notbrowsable" - the post isn't browsable (pending/taken/withdrawn).
	//   "pending"      - eligible but no reach row yet (the ~1-minute post-approval window,
	//                    or it arrived before the go-live cutoff).
	Reason          string  `json:"reason,omitempty"`
	Msgid           uint64  `json:"msgid"`
	Tick            int     `json:"tick"`
	TotalTicks      int     `json:"totalticks"`
	Status          string  `json:"status"`
	Arrival         *string `json:"arrival"`
	NextExpansionAt *string `json:"nextexpansionat"`
	// Polygon is the ACTUAL stored reach (rippling_reach.polygon) as GeoJSON, and is what the
	// reach modal draws - a reach is held, clipped where members left a group, or capped by the
	// poster's distance preference, none of which a client-side projection of the schedule
	// knows about, so the projection was dropped in favour of this. This is the ONE place the
	// stored polygon crosses the API: mod-only, one post per request, so the payload
	// (~300KB typical / ~850KB worst on prod at 5 decimal places, well-compressed on the wire
	// - the grid-fill polygons are highly repetitive) is a deliberate exception to the "never
	// ship reach polygons" rule that governs the member-facing feed.
	Polygon string `json:"polygon,omitempty"`
	// Overflow is the post's rings - the lanes that admit members the committed reach
	// does not cover - keyed by lane ("rural.sparse", "cluster.w1"), each as GeoJSON.
	//
	// Without them the map under-reports where a post went, and it does so exactly for
	// the rural posts whose moderators are most likely to be asking: a Hawes post's
	// reach outline stops at the dale, while two wedges carry it to Penrith and
	// Lancaster and the mail invites those members. "Did this get to X?" answered from
	// the outline alone is wrong whenever X is in a ring.
	//
	// SIMPLIFIED hard (~150m). The stored rings average 37,000 vertices; shipping them
	// whole would roughly triple the heaviest mod call there is. An outline a moderator
	// can see the shape of is the whole requirement here - nothing decides admission
	// from this.
	Overflow map[string]string `json:"overflow,omitempty"`
}

// overflowLanePaths are the ring lanes a post can carry, as JSON paths. Fixed set, so
// the query below can name them: one column each, NULL for the lanes this post has not
// got. Matches rippling.ViewerOverflowPaths' vocabulary.
var overflowLanePaths = []struct{ Key, Path string }{
	{"rural.dense", "$.rural.dense"},
	{"rural.medium", "$.rural.medium"},
	{"rural.sparse", "$.rural.sparse"},
	{"fairness.1", `$.fairness."1"`},
	{"fairness.2", `$.fairness."2"`},
	{"fairness.3", `$.fairness."3"`},
	{"fairness.4", `$.fairness."4"`},
	{"cluster.w1", "$.cluster.w1"},
	{"cluster.w2", "$.cluster.w2"},
	{"cluster.w3", "$.cluster.w3"},
}

// ringSimplifyDegrees is the simplify tolerance for those outlines, in coordinate
// degrees (the geometry's SRID label notwithstanding) - about 150m, which takes a
// 37k-vertex ring to something in the low thousands.
const ringSimplifyDegrees = 0.0015

// overflowRings reads a post's rings as simplified GeoJSON, keyed by lane.
//
// Mod-only and one post per request, so the parse cost this pays - a handful of rings,
// once - is affordable here in a way it never was on a read surface (see
// rippling.AdmittedMsgids for what asking this per candidate row cost).
// Each lane's outline comes from its stored cell grid (overflow_cells, the
// same JSON paths as the ring WKT by design), traced back to a vector by the
// spatial server (spatial.VectorizeCells) at the same display tolerance the
// old ST_Simplify used. A lane without cells falls back to its legacy ring
// WKT while that column exists.
func overflowRings(db *gorm.DB, msgid uint64) map[string]string {
	legacy := rippling.LegacyOverflowReady(db)

	cols := make([]string, 0, 2*len(overflowLanePaths))
	args := make([]interface{}, 0, 2*len(overflowLanePaths))
	for _, lane := range overflowLanePaths {
		cols = append(cols, "JSON_UNQUOTE(JSON_EXTRACT(overflow_cells, ?))")
		args = append(args, lane.Path)
	}
	if legacy {
		for i, lane := range overflowLanePaths {
			cols = append(cols, fmt.Sprintf(
				"ST_AsGeoJSON(ST_Simplify(ST_GeomFromText(JSON_UNQUOTE(JSON_EXTRACT(overflow_bounds, ?)), %d), %v), 5) AS g%d",
				utils.SRID, ringSimplifyDegrees, i))
			args = append(args, lane.Path)
		}
	}

	dest := make([]sql.NullString, len(cols))
	scan := make([]interface{}, len(cols))
	for i := range dest {
		scan[i] = &dest[i]
	}

	// keep-raw: the SELECT list is built dynamically (one JSON_EXTRACT per
	// lane, doubled for the legacy fallback era) with per-column spatial
	// functions - GORM cannot render a variable-width select into positional
	// NullString scan targets.
	row := db.Raw("SELECT "+strings.Join(cols, ", ")+
		" FROM rippling_reach WHERE msgid = ?",
		append(args, msgid)...).Row()
	if row == nil || row.Scan(scan...) != nil {
		return nil
	}

	rings := map[string]string{}
	for i, lane := range overflowLanePaths {
		if dest[i].Valid && dest[i].String != "" {
			if cells, err := base64.StdEncoding.DecodeString(dest[i].String); err == nil {
				if _, geojson, verr := spatial.VectorizeCells(cells, ringSimplifyDegrees); verr == nil {
					rings[lane.Key] = geojson
					continue
				}
			}
		}
		if legacy {
			j := len(overflowLanePaths) + i
			if dest[j].Valid && dest[j].String != "" {
				rings[lane.Key] = dest[j].String
			}
		}
	}
	if len(rings) == 0 {
		return nil
	}
	return rings
}

type reachRow struct {
	Tick            int
	TotalTicks      int
	Status          string
	Arrival         *string
	NextExpansionAt *string
	Polygon         *string
	// Cells is the stored cell grid, vectorized for display when present.
	Cells []byte `gorm:"column:cells"`
}

// reachDisplayToleranceDegrees is the simplify tolerance for the reach
// boundary drawn on the moderation map: ~50m, which takes an exact lattice
// trace (tens of thousands of right-angle corners) to a vertex count
// comparable to the ~11k-vertex traced isochrone it replaces. Display only -
// nothing that feeds a decision uses a simplified trace.
const reachDisplayToleranceDegrees = 0.0005

// Reach returns a post's current ACTUAL rippling-out progress (the hazard-schedule tick it has
// really reached), which is what the moderation reach map draws.
// Any moderator (or Admin/Support); returns rippling:false (not 404) with a reason when the
// post has no reach row.
//
// @Router /message/{id}/reach [get]
// @Summary Actual rippling-out progress of a post (moderation)
// @Tags message
// @Produce json
// @Param id path int true "Message ID"
// @Security BearerAuth
// @Success 200 {object} message.ReachResponse
// @Failure 403 {object} fiber.Error "Moderator required"
func Reach(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	// Confirm the post exists (and is on a group at all).
	var groupids []uint64
	db.Table("messages_groups").Select("groupid").Where("msgid = ? AND deleted = 0", id).Scan(&groupids)
	if len(groupids) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Any moderator may look at reach, not only mods of the groups this post is on.
	// Rippling deliberately carries posts to OTHER groups, so the mods who most need
	// to see how far one has travelled are usually not mods of its origin group -
	// gating on mod-of-this-post's-group hid reach from exactly them. Reach exposes
	// no member data, so there is nothing here to scope per group.
	if !user.IsModOfAnyGroup(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Moderator required")
	}

	// The map overlay's boundary comes from the stored cell grid, traced back
	// to a vector by the spatial server (spatial.VectorizeCells - the one
	// place that judgement lives) at a display tolerance comparable to the
	// old geometry's density. Read the row and its cells first; the legacy
	// geometry SQL below stands in per row while its columns exist (through
	// the dedup COALESCE when that era's tables do - on a drained row the
	// local blob is only a sentinel POINT).
	var row reachRow
	found := db.Table("rippling_reach rr").
		Select("rr.tick, rr.total_ticks, rr.status, rr.arrival, rr.next_expansion_at, rr.polygon_cells AS cells").
		Where("rr.msgid = ?", id).
		Scan(&row)
	if found.RowsAffected > 0 {
		if len(row.Cells) > 0 {
			if _, geojson, err := spatial.VectorizeCells(row.Cells, reachDisplayToleranceDegrees); err == nil {
				row.Polygon = &geojson
			}
		}
		if row.Polygon == nil && rippling.LegacyPolygonReady(db) {
			// 5 decimal places ≈ 1m - plenty for a map overlay. The stored
			// coordinates are lng/lat degrees (the SRID label
			// notwithstanding), which is exactly GeoJSON's [lng, lat] order.
			share := rippling.GeomShareReady(db)
			var legacy struct {
				Polygon *string `gorm:"column:polygon"`
			}
			db.Table("rippling_reach rr"+rippling.GeomJoin(share, "rr", "polygon", "g")).
				Select("ST_AsGeoJSON("+rippling.GeomExpr(share, "rr", "polygon", "g")+", 5) AS polygon").
				Where("rr.msgid = ?", id).
				Scan(&legacy)
			row.Polygon = legacy.Polygon
		}
	}

	if found.RowsAffected == 0 {
		// No actual reach row. Work out WHY so the UI doesn't imply the engine is behind when
		// it simply isn't running (dark) or the post isn't eligible yet.
		reason := "pending"
		if !rippleEnabled() {
			reason = "disabled"
		} else {
			var inSpatial int
			// inSpatial is int, not
			// int64, so this keeps Row().Scan rather than GORM's Count, which
			// requires *int64.
			db.Table("messages_spatial").Select("COUNT(*)").Where("msgid = ?", id).Row().Scan(&inSpatial)
			if inSpatial == 0 {
				reason = "notbrowsable"
			}
		}
		return c.JSON(ReachResponse{Rippling: false, Reason: reason, Msgid: id})
	}

	polygon := ""
	if row.Polygon != nil {
		polygon = *row.Polygon
	}
	return c.JSON(ReachResponse{
		Rippling:        true,
		Msgid:           id,
		Tick:            row.Tick,
		TotalTicks:      row.TotalTicks,
		Status:          row.Status,
		Arrival:         row.Arrival,
		NextExpansionAt: row.NextExpansionAt,
		Polygon:         polygon,
		Overflow:        overflowRings(db, id),
	})
}
