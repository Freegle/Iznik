package town

// The "Near: ..." hint under the browse/feed distance slider. Given the user's location and the
// slider's TRAVEL TIME (minutes), list up to 5 towns the setting reaches - by REAL travel (drive-time
// via the routing server's ripple-eval), not crow-flies. We show place NAMES only, never any distance
// or time units: the user just sees which places their setting covers, and the list changes as they
// drag. The slider is time-based end to end - the minutes go straight to the isochrone's max_minutes,
// with no hardcoded miles<->minutes conversion anywhere.

import (
	"bytes"
	"encoding/json"
	"math"
	"net/http"
	"os"
	"sort"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/density"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// routingEvalURL / routingClient reach the routing server's /v1/ripple-eval (real drive times).
// Mirrors rippling/analytics.go's client, kept local so the packages stay independent.
func routingEvalURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
		return u
	}
	if u := os.Getenv("SPATIAL_KNN_URL"); u != "" {
		return u
	}
	return "http://spatial:8194"
}

var routingClient = &http.Client{Timeout: 15 * time.Second}

// reachRadiusFloorMiles keeps the derived crow-flies cap from collapsing to ~0 (which would hide
// everything) when the chosen reach is tiny or no named town falls inside it.
const reachRadiusFloorMiles = 1.0

// reachRadiusMiles converts the towns reachable within the chosen travel time into a crow-flies mile
// radius to store as settings.browseMaxDistance (the value the feed's fast Haversine distance filter
// reads). PURE travel time: the radius is the isochrone's road frontier, floored so a degenerate
// isochrone can never collapse the browse cap to ~0. Named towns deliberately play NO part - they
// exist for the NearbyTowns display and community news, and deriving the radius from them collapsed
// a 25-minute reach to 1 mile for a member whose only nearby named town was her own (ChitChat
// 616307). Road distance never understates crow distance, and the nearby feed already gates every
// post through the real drive-time isochrone (ST_Contains on the reach polygon), so this cap only
// needs to be a rough, generous tightening; the frontier is the right travel-time-only source.
func reachRadiusMiles(frontierMedianMiles float64) float64 {
	radius := frontierMedianMiles
	if radius < reachRadiusFloorMiles {
		radius = reachRadiusFloorMiles
	}
	return radius
}

// TownCand is a candidate town with its drive-time from the user (nil = unreachable in the budget).
type TownCand struct {
	ID       uint64
	Name     string
	DriveMin *float64
}

// SelectNear picks the FURTHEST towns reachable within maxMinutes (so the list changes as the range
// widens, rather than always showing the same nearest places), then returns their names ordered by
// population. The towns table is curated in descending-population order by ascending id, so a
// smaller id is a bigger place. Returns up to `limit` names.
func SelectNear(cands []TownCand, maxMinutes float64, limit int) []string {
	reachable := make([]TownCand, 0, len(cands))
	for _, c := range cands {
		if c.DriveMin != nil && *c.DriveMin <= maxMinutes {
			reachable = append(reachable, c)
		}
	}
	// Furthest first (largest drive-time); tie-break by bigger population (smaller id) for stability.
	sort.Slice(reachable, func(i, j int) bool {
		if *reachable[i].DriveMin != *reachable[j].DriveMin {
			return *reachable[i].DriveMin > *reachable[j].DriveMin
		}
		return reachable[i].ID < reachable[j].ID
	})
	if len(reachable) > limit {
		reachable = reachable[:limit]
	}
	// Display order: population descending (ascending id).
	sort.Slice(reachable, func(i, j int) bool { return reachable[i].ID < reachable[j].ID })
	out := make([]string, 0, len(reachable))
	for _, c := range reachable {
		out = append(out, c.Name)
	}
	return out
}

type rippleEvalReq struct {
	Lat        float64      `json:"lat"`
	Lng        float64      `json:"lng"`
	Mode       string       `json:"mode"`
	MaxMinutes float64      `json:"max_minutes"`
	Points     [][2]float64 `json:"points"`
	// We only read drive_min per town, never rank, so skip the freegler enumeration (the routing
	// bottleneck). And ask for the road-distance frontier range so the UI can show how far the
	// setting reaches by road.
	PointsOnly bool `json:"points_only"`
	Frontier   bool `json:"frontier"`
	// PolygonSimplifyM asks the routing server for the reach's SHAPE too, simplified to that
	// many metres. Requested only when the caller passes ?polygon=1, so the Feed settings
	// slider - which wants the radius and the towns, not a map - keeps paying nothing for it.
	PolygonSimplifyM float64 `json:"polygon_simplify_m,omitempty"`
}
type rippleEvalResp struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
	FrontierMedianMiles *float64        `json:"frontier_median_miles"`
	FrontierMaxMiles    *float64        `json:"frontier_max_miles"`
	Polygon             json.RawMessage `json:"polygon"`
}

// reachPolygonSimplifyM is the display tolerance for the browse map's reach overlay, in
// metres. It turns a 45-minute reach from ~27,000 vertices and ~1.2MB of GeoJSON into
// ~2,000 vertices and ~40KB, which gzip takes to under 10KB.
//
// 100m rather than something coarser because the boundary error is what is being bought.
// Measured against the exact polygon for a 45-minute drive reach around Edinburgh, the
// simplified shape disagrees with it over 0.96% of its area at 50m, 1.66% at 100m, 2.84%
// at 200m and 4.85% at 400m - and past 200m it starts systematically inflating (area ratio
// 1.012 at 400m) rather than just wobbling. 100m keeps the shape honest at a size worth
// having, which matters because an approximated boundary can cut across a narrow barrier;
// see docs/developers/reference/rippling-algorithm.md 2a for why the reach polygon proper
// is never simplified. This shape illustrates how far the member can travel and is never
// a containment test.
const reachPolygonSimplifyM = 100

// Near returns up to 5 town names reachable within the slider's TRAVEL TIME (minutes) from (lat,lng),
// by travel, furthest-selected and population-ordered - for the "Near: ..." hint under the distance
// slider. It also returns reach_radius_miles, the crow-flies radius that travel time reaches, which
// the client stores as settings.browseMaxDistance (so the fast Haversine feed filter tightens to
// roughly the chosen travel time, location-aware, with no hardcoded conversion). Names only, no
// units. Best-effort: any routing/DB failure returns an empty list (the hint hides).
//
// cap_minutes is the top of the slider for THIS member: the reach budget their local freegler
// density earns (20 dense / 30 medium / 45 sparse), because that is the budget the reach engine
// gives posts around them. A fixed 5-30 slider is simultaneously too short in the country - where a
// member cannot ask for the 45 minutes they now actually receive - and too long in the city, where
// the top stops describe travel the reach engine no longer honours. Always present, so the client
// never has to guess; it falls back to the flat cap whenever density cannot be measured.
//
// @Router /town/near [get]
// @Summary Up to 5 towns the browse/feed distance slider reaches (by drive-time), names only
// @Tags location
// @Param lat query number true "Latitude to measure the travel time from"
// @Param lng query number true "Longitude to measure the travel time from"
// @Param minutes query number true "Travel-time budget in minutes (the slider position)"
// @Param polygon query string false "Pass 1 to also return reach_polygon, the outline of that travel time as GeoJSON, for a map overlay. An illustration only - never a containment test."
// @Produce json
// @Success 200 {object} map[string]interface{}
func Near(c *fiber.Ctx) error {
	lat, _ := strconv.ParseFloat(c.Query("lat"), 64)
	lng, _ := strconv.ParseFloat(c.Query("lng"), 64)
	minutes, _ := strconv.ParseFloat(c.Query("minutes"), 64)
	empty := fiber.Map{"towns": []string{}}
	if (lat == 0 && lng == 0) || minutes <= 0 {
		return c.JSON(empty)
	}

	// The cap describes the member's location, not the chosen travel time, so it
	// rides on every response - including the ones where the town hint gives up.
	// A client that only learned it on a lucky call would show the wrong slider.
	reachCap := density.CapFor(lat, lng)
	empty["cap_minutes"] = reachCap.MaxMinutes
	empty["density_band"] = reachCap.Band
	maxMin := minutes
	if maxMin > 120 {
		maxMin = 120 // cap so "no limit" never routes the whole country
	}

	// Candidate towns within a generous crow-flies box: fast roads can cover well over a mile a
	// minute, so size the box off the time budget (~1.5 mi/min plus slack) to be sure we don't miss
	// a reachable town. The towns table is tiny (~234 rows), so a generous box stays cheap.
	boxMiles := maxMin*1.5 + 5
	latDeg := boxMiles / 69.0
	lngDeg := boxMiles / (69.0 * math.Cos(lat*math.Pi/180))
	db := database.DBConn
	type row struct {
		ID   uint64
		Name string
		Lat  float64
		Lng  float64
	}
	var rows []row
	db.Table("towns").
		Select("id, name, lat, lng").
		Where("lat IS NOT NULL AND lng IS NOT NULL AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?",
			lat-latDeg, lat+latDeg, lng-lngDeg, lng+lngDeg).
		Order("id").
		Scan(&rows)

	// An empty box is not a reason to stop. The town names are display material for the
	// "Near: ..." hint, while reach_radius_miles comes from the isochrone frontier and
	// reach_polygon from its shape - neither depends on a town falling inside the box. A member
	// whose nearest curated town is 27 miles away (Hastings; the table holds only ~234 major
	// places) still needs a radius at the narrow end of the slider, and a missing one reads to
	// the client as a failed derivation, which stores "no limit" and switches every distance
	// filter off. One routing call produces all three answers, so it runs whether or not there
	// are candidates and SelectNear simply returns nothing.
	points := make([][2]float64, len(rows))
	for i, r := range rows {
		points[i] = [2]float64{r.Lng, r.Lat} // [lng, lat] GeoJSON order
	}
	// ?polygon=1 also asks for the reach outline, for the browse map's coverage overlay. It
	// rides on this call rather than having its own endpoint because the Dijkstra it needs is
	// the one this call already runs; a separate endpoint would route the same reach twice.
	var simplifyM float64
	if c.Query("polygon") == "1" {
		simplifyM = reachPolygonSimplifyM
	}

	body, _ := json.Marshal(rippleEvalReq{
		Lat: lat, Lng: lng, Mode: "drive", MaxMinutes: maxMin, Points: points,
		PointsOnly: true, Frontier: true, PolygonSimplifyM: simplifyM,
	})
	resp, err := routingClient.Post(routingEvalURL()+"/v1/ripple-eval", "application/json", bytes.NewReader(body))
	if err != nil {
		return c.JSON(empty)
	}
	defer resp.Body.Close()
	var r rippleEvalResp
	if resp.StatusCode != http.StatusOK || json.NewDecoder(resp.Body).Decode(&r) != nil || len(r.Results) != len(rows) {
		return c.JSON(empty)
	}
	cands := make([]TownCand, len(rows))
	for i, rw := range rows {
		cands[i] = TownCand{ID: rw.ID, Name: rw.Name, DriveMin: r.Results[i].DriveMin}
	}

	// The road-distance reach range ("reaches median..max miles by road"), shown alongside the town
	// list. Comes free from the isochrone, so it's present even when no named town falls inside the
	// reach.
	out := fiber.Map{
		"frontier_median_miles": r.FrontierMedianMiles,
		"frontier_max_miles":    r.FrontierMaxMiles,
		"cap_minutes":           reachCap.MaxMinutes,
		"density_band":          reachCap.Band,
	}

	// reach_radius_miles: the mile radius the chosen travel time reaches, for the client to store as
	// settings.browseMaxDistance. This is a PURE travel-time value from the isochrone frontier -
	// named towns play no part (they are display material for NearbyTowns / community news). Deriving
	// it from reachable towns collapsed the radius to ~1 mile for a member whose only nearby named
	// town was her own (ChitChat 616307). Omitted when the isochrone has no frontier, so the client
	// treats the derivation as failed rather than storing a made-up cap.
	if r.FrontierMedianMiles != nil {
		out["reach_radius_miles"] = reachRadiusMiles(*r.FrontierMedianMiles)
	}

	// reach_polygon: the outline of that same travel time, as a GeoJSON Feature, for the
	// browse map to shade. Passed straight through rather than re-encoded - we have nothing
	// to add to it, and decoding thousands of coordinates only to re-encode them would be
	// pure cost. Absent whenever the routing server had no drawable shape, so the client
	// falls back rather than drawing a degenerate one.
	//
	// This is an ILLUSTRATION of the member's own travel time, not the set of posts they can
	// see: each post ripples out from its OWN origin with its own budget, so a post can reach
	// a member who could not have reached it in the same time. The list stays filtered by
	// settings.browseMaxDistance (the crow-flies radius derived above); this only shades.
	if len(r.Polygon) > 0 && string(r.Polygon) != "null" {
		out["reach_polygon"] = r.Polygon
	}

	towns := SelectNear(cands, maxMin, 5)
	if len(towns) > 0 {
		out["towns"] = towns
		return c.JSON(out)
	}

	// Nothing within reach: return the single nearest town so the UI can say "Closer than: X"
	// instead of showing nothing - useful for rural users whose nearest town is beyond the reach.
	var closer string
	// Order() itself
	// takes no bind args, so the two ST_Distance_Sphere binds go through
	// clause.OrderBy{Expression: gorm.Expr(...)} instead - same technique as
	// message/message.go's ResolveOnBehalfPosting (site ecaf3f90bee2).
	db.Table("towns").
		Select("name").
		Where("lat IS NOT NULL AND lng IS NOT NULL").
		Order(clause.OrderBy{Expression: gorm.Expr("ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?))", lng, lat)}).
		Limit(1).
		Scan(&closer)
	out["towns"] = []string{}
	out["closer_than"] = closer
	return c.JSON(out)
}
