package main

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
)

// loadAllGroupPolygons loads every publishable, listable group that has a polygon
// polyindex, as groupPoly values ready for reached-node classification. A
// MULTIPOLYGON group becomes several groupPoly sharing its ID (reachableGroupIDs
// dedupes them).
func loadAllGroupPolygons(db *sql.DB) ([]groupPoly, error) {
	rows, err := db.Query("SELECT id, ST_AsText(polyindex) FROM `groups` " +
		"WHERE publish = 1 AND listable = 1 AND polyindex IS NOT NULL " +
		"AND ST_GeometryType(polyindex) <> 'POINT'")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := make([]groupPoly, 0)
	for rows.Next() {
		var id int64
		var wkt sql.NullString
		if err := rows.Scan(&id, &wkt); err != nil {
			continue
		}
		if wkt.Valid {
			out = append(out, parseGroupPolys(id, wkt.String)...)
		}
	}
	return out, rows.Err()
}

// handleReachableGroups serves GET /v1/reachable-groups?lat=&lng=&minutes=&mode=,
// returning the IDs of groups containing at least one road node reachable within
// the budget. Unlike the smoothed reach polygon (which over-approximates and can
// bridge water), a reached node cannot cross a river or an excluded toll, so this
// is the correct ripple-targeting signal (plan 2026-07-06). The polygon is kept
// for display only. reachable_group_ids is always present and non-null; it is
// empty when computable-but-zero, so callers can tell "gate computed nothing"
// from "no data".
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

		mode := Drive
		switch c.Query("mode", "drive") {
		case "walk":
			mode = Walk
		case "cycle":
			mode = Cycle
		}

		ids := make([]int64, 0)
		iso := Isochrone(g, lat, lng, float32(minutes*60), mode)
		if len(iso.ReachedNodes) > 0 {
			if db := ensureGroupsDB(); db != nil {
				if polys, err := loadAllGroupPolygons(db); err == nil {
					ids = reachableGroupIDs(g, iso.ReachedNodes, polys)
				}
			}
		}
		return c.JSON(fiber.Map{"reachable_group_ids": ids})
	}
}
