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
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
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
// reads). It uses the FURTHEST reachable town's straight-line distance - a real, location-aware reach
// extent with NO hardcoded miles<->minutes mapping - and falls back to the isochrone's road frontier
// when nothing named is reachable. Floored so it never collapses to ~0. The nearby feed already gates
// every post through the real drive-time isochrone (ST_Contains on the reach polygon), so this cap
// only needs to be a rough, generous tightening; an approximate crow-flies circle is fine.
func reachRadiusMiles(crowMilesReachable []float64, fallbackMiles float64) float64 {
	radius := 0.0
	for _, d := range crowMilesReachable {
		if d > radius {
			radius = d
		}
	}
	if radius <= 0 {
		radius = fallbackMiles
	}
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
}
type rippleEvalResp struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
	FrontierMedianMiles *float64 `json:"frontier_median_miles"`
	FrontierMaxMiles    *float64 `json:"frontier_max_miles"`
}

// Near returns up to 5 town names reachable within the slider's TRAVEL TIME (minutes) from (lat,lng),
// by travel, furthest-selected and population-ordered - for the "Near: ..." hint under the distance
// slider. It also returns reach_radius_miles, the crow-flies radius that travel time reaches, which
// the client stores as settings.browseMaxDistance (so the fast Haversine feed filter tightens to
// roughly the chosen travel time, location-aware, with no hardcoded conversion). Names only, no
// units. Best-effort: any routing/DB failure returns an empty list (the hint hides).
//
// @Router /town/near [get]
// @Summary Up to 5 towns the browse/feed distance slider reaches (by drive-time), names only
// @Tags location
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
	// ORM migration site ed5b9c0716a2 (wave 1).
	db.Table("towns").
		Select("id, name, lat, lng").
		Where("lat IS NOT NULL AND lng IS NOT NULL AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?",
			lat-latDeg, lat+latDeg, lng-lngDeg, lng+lngDeg).
		Order("id").
		Scan(&rows)
	if len(rows) == 0 {
		return c.JSON(empty)
	}

	points := make([][2]float64, len(rows))
	for i, r := range rows {
		points[i] = [2]float64{r.Lng, r.Lat} // [lng, lat] GeoJSON order
	}
	body, _ := json.Marshal(rippleEvalReq{
		Lat: lat, Lng: lng, Mode: "drive", MaxMinutes: maxMin, Points: points,
		PointsOnly: true, Frontier: true,
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
	var crowReachable []float64 // straight-line miles to each town reachable within the time budget
	for i, rw := range rows {
		dm := r.Results[i].DriveMin
		cands[i] = TownCand{ID: rw.ID, Name: rw.Name, DriveMin: dm}
		if dm != nil && *dm <= maxMin {
			crowReachable = append(crowReachable, utils.Haversine(lat, lng, rw.Lat, rw.Lng))
		}
	}

	// The road-distance reach range ("reaches median..max miles by road"), shown alongside the town
	// list. Comes free from the isochrone, so it's present even when no named town falls inside the
	// reach.
	out := fiber.Map{"frontier_median_miles": r.FrontierMedianMiles, "frontier_max_miles": r.FrontierMaxMiles}

	// reach_radius_miles: the crow-flies radius the chosen travel time reaches, for the client to
	// store as settings.browseMaxDistance. Falls back to the isochrone's road frontier when no named
	// town is reachable (rural short trips).
	fallback := 0.0
	if r.FrontierMedianMiles != nil {
		fallback = *r.FrontierMedianMiles
	}
	out["reach_radius_miles"] = reachRadiusMiles(crowReachable, fallback)

	towns := SelectNear(cands, maxMin, 5)
	if len(towns) > 0 {
		out["towns"] = towns
		return c.JSON(out)
	}

	// Nothing within reach: return the single nearest town so the UI can say "Closer than: X"
	// instead of showing nothing - useful for rural users whose nearest town is beyond the reach.
	var closer string
	db.Raw(`SELECT name FROM towns WHERE lat IS NOT NULL AND lng IS NOT NULL
		ORDER BY ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?)) LIMIT 1`, lng, lat).Scan(&closer)
	out["towns"] = []string{}
	out["closer_than"] = closer
	return c.JSON(out)
}
