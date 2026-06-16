package main

import (
	"strconv"
	"time"

	"github.com/gofiber/fiber/v2"
)

// handlePostsForMember returns the posts a member at (lat, lng) would have
// been *eligible* to see in their digest on a given day — every post that
// arrived in the previous 24 h whose location falls inside the member's
// drive-time isochrone.
//
// Inverse of the rippling-out view: instead of "where would my post be
// shown", this answers "which posts would I see".  Selection (the
// publicity-budget cut) happens on top; this endpoint returns the pool.
//
// GET /v1/posts-for-member?lat=...&lng=...&date=YYYY-MM-DD&max_minutes=30
//
// Date defaults to today; max_minutes defaults to 30.
func handlePostsForMember(g *Graph, spatialURL string) fiber.Handler {
	return func(c *fiber.Ctx) error {
		latStr := c.Query("lat")
		lngStr := c.Query("lng")
		if latStr == "" || lngStr == "" {
			return c.Status(fiber.StatusBadRequest).
				JSON(fiber.Map{"error": "lat and lng are required"})
		}
		lat, err := strconv.ParseFloat(latStr, 64)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).
				JSON(fiber.Map{"error": "invalid lat"})
		}
		lng, err := strconv.ParseFloat(lngStr, 64)
		if err != nil {
			return c.Status(fiber.StatusBadRequest).
				JSON(fiber.Map{"error": "invalid lng"})
		}
		maxMinutes := 30.0
		if s := c.Query("max_minutes"); s != "" {
			if v, err := strconv.ParseFloat(s, 64); err == nil && v > 0 && v <= 120 {
				maxMinutes = v
			}
		}

		// Day window: posts that arrived in the 24 h *before* the chosen
		// day's end.  Slider value is a calendar date string; we treat
		// "today's digest" as "anything new since the day before."
		dayEnd := time.Now()
		if s := c.Query("date"); s != "" {
			t, err := time.Parse("2006-01-02", s)
			if err != nil {
				return c.Status(fiber.StatusBadRequest).
					JSON(fiber.Map{"error": "date must be YYYY-MM-DD"})
			}
			// End of the chosen day, 23:59:59 local.
			dayEnd = time.Date(t.Year(), t.Month(), t.Day(), 23, 59, 59, 0, time.Local)
		}
		dayStart := dayEnd.Add(-24 * time.Hour)

		// Build the isochrone polygon for the member.
		maxSecs := float32(maxMinutes * 60)
		iso := Isochrone(g, lat, lng, maxSecs, Drive)
		if len(iso.ReachedNodes) == 0 {
			return c.JSON(fiber.Map{
				"max_drive_min": maxMinutes,
				"day_start":     dayStart.Format(time.RFC3339),
				"day_end":       dayEnd.Format(time.RFC3339),
				"posts":         []any{},
			})
		}
		res := AutoResolution(maxSecs, Drive)
		poly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := poly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(fiber.Map{
				"max_drive_min": maxMinutes,
				"posts":         []any{},
			})
		}
		wkt := ringToWKT(ring[0])

		db := ensureGroupsDB()
		if db == nil {
			return c.Status(fiber.StatusServiceUnavailable).
				JSON(fiber.Map{"error": "database not available"})
		}

		// The messages_spatial.point column declares SRID 3857 even though
		// the values are stored as lng/lat degrees (legacy Iznik
		// mislabel); we have to tag our polygon as 3857 too or MySQL
		// refuses the ST_Contains.  The LIMIT is a safety belt; in dense
		// cities a 30-min reach can contain thousands of posts and we
		// don't need to ship them all to the browser for a visualisation.
		const maxPosts = 500
		rows, err := db.Query(`
			SELECT msgid,
			       ST_X(point) AS lng,
			       ST_Y(point) AS lat,
			       msgtype,
			       arrival,
			       successful,
			       promised,
			       COALESCE(groupid, 0)
			  FROM messages_spatial
			 WHERE arrival BETWEEN ? AND ?
			   AND msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 3857), point)
			 ORDER BY arrival DESC
			 LIMIT ?
		`, dayStart, dayEnd, wkt, maxPosts)
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).
				JSON(fiber.Map{"error": err.Error()})
		}
		defer rows.Close()

		type post struct {
			MsgID      int64     `json:"msgid"`
			Lng        float64   `json:"lng"`
			Lat        float64   `json:"lat"`
			MsgType    string    `json:"msgtype"`
			Arrival    time.Time `json:"arrival"`
			Successful bool      `json:"successful"`
			Promised   bool      `json:"promised"`
			GroupID    int64     `json:"groupid"`
		}
		out := make([]post, 0, 64)
		for rows.Next() {
			var p post
			if err := rows.Scan(&p.MsgID, &p.Lng, &p.Lat, &p.MsgType,
				&p.Arrival, &p.Successful, &p.Promised, &p.GroupID); err != nil {
				continue
			}
			out = append(out, p)
		}

		return c.JSON(fiber.Map{
			"max_drive_min": maxMinutes,
			"day_start":     dayStart.Format(time.RFC3339),
			"day_end":       dayEnd.Format(time.RFC3339),
			"isochrone":     poly,
			"total":         len(out),
			"truncated":     len(out) == maxPosts,
			"posts":         out,
		})
	}
}
