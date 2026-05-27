package main

import (
	"encoding/json"
	"math"
	"sort"
	"strconv"
	"time"

	"github.com/gofiber/fiber/v2"
)

// handleDigestSimulator models the Problem-2 selection algorithm.
// Given a member at (lat, lng) and a set of weight knobs, it computes
// which posts from the reachable pool would actually land in the
// member's digest under the proposed selection criteria.
//
// Score per post is a weighted sum of:
//
//   - closeness:  1 - drive_min / max_drive_min       (closer = higher)
//   - freshness:  1 - age_h / window_h                (newer = higher)
//   - budget:     exp(-engagement / budget_decay)     (fewer eyeballs = higher)
//   - anchor:     1 if post.groupid in home_groups, else 0  (home-group bonus)
//
// Weights are all >= 0; weight 0 disables a signal.  After scoring, the
// top `cap` posts are selected.  If `group_by_poster` is true, multiple
// posts from the same poster collapse to one digest entry (the highest-
// scoring one), with the rest shown as a count.
//
// Engagement signal: messages_likes 'View' count + chat_messages
// 'Interested' replies.  Both represent "someone actually saw / acted on
// this post" — the eyeballs-budget signal the user proposed.
//
// GET /v1/digest-simulator?lat=...&lng=...&max_minutes=30
//   &w_closeness=1.0&w_freshness=0.5&w_budget=1.0&w_anchor=0
//   &cap=50&group_by_poster=false
func handleDigestSimulator(g *Graph, spatialURL string) fiber.Handler {
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

		maxMinutes := parseFloatQuery(c, "max_minutes", 30, 1, 120)
		wClose := parseFloatQuery(c, "w_closeness", 1.0, 0, 10)
		wFresh := parseFloatQuery(c, "w_freshness", 0.5, 0, 10)
		wBudget := parseFloatQuery(c, "w_budget", 1.0, 0, 10)
		wAnchor := parseFloatQuery(c, "w_anchor", 0, 0, 10)
		cap := int(parseFloatQuery(c, "cap", 50, 1, 1000))
		groupByPoster := c.Query("group_by_poster") == "true"
		windowH := parseFloatQuery(c, "window_hours", 24, 1, 168)
		budgetDecay := parseFloatQuery(c, "budget_decay", 25, 1, 1000)

		// Build the isochrone polygon for the member.
		maxSecs := float32(maxMinutes * 60)
		iso := Isochrone(g, lat, lng, maxSecs, Drive)
		if len(iso.ReachedNodes) == 0 {
			return c.JSON(fiber.Map{
				"pool_size": 0,
				"selected":  []any{},
				"deferred":  []any{},
			})
		}
		res := AutoResolution(maxSecs, Drive)
		poly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := poly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(fiber.Map{"pool_size": 0, "selected": []any{}})
		}
		wkt := ringToWKT(ring[0])

		db := ensureGroupsDB()
		if db == nil {
			return c.Status(fiber.StatusServiceUnavailable).
				JSON(fiber.Map{"error": "database not available"})
		}

		// Home-group identification: the four groups whose polygons cover
		// the member's location, or failing that the four closest by
		// centroid.  Four matches the doc's "4.5 average" figure.
		homeGroups := map[int64]struct{}{}
		homeGroupList := []fiber.Map{}
		grows, err := db.Query(`
			SELECT id, nameshort, ST_AsGeoJSON(polyindex)
			  FROM `+"`groups`"+`
			 WHERE ST_Contains(polyindex, ST_GeomFromText(?, 3857))
			 LIMIT 8
		`, "POINT("+strconv.FormatFloat(lng, 'f', 8, 64)+" "+strconv.FormatFloat(lat, 'f', 8, 64)+")")
		if err == nil {
			for grows.Next() {
				var id int64
				var name, polyJSON string
				if err := grows.Scan(&id, &name, &polyJSON); err == nil {
					homeGroups[id] = struct{}{}
					// Parse the polygon JSON so the client gets it as an
					// object rather than a string.
					var polyObj any
					_ = json.Unmarshal([]byte(polyJSON), &polyObj)
					homeGroupList = append(homeGroupList, fiber.Map{
						"id":      id,
						"name":    name,
						"polygon": polyObj,
					})
				}
			}
			grows.Close()
		}

		// Pull the reachable pool with engagement signals.  Limit to a
		// safety ceiling; in dense cities we won't realistically need more
		// than a few hundred to fill a digest cap of 100.
		const maxPool = 1000
		windowStart := time.Now().Add(-time.Duration(windowH * float64(time.Hour)))
		rows, err := db.Query(`
			SELECT ms.msgid,
			       ST_X(ms.point) AS lng,
			       ST_Y(ms.point) AS lat,
			       ms.msgtype,
			       ms.arrival,
			       ms.successful,
			       ms.promised,
			       COALESCE(ms.groupid, 0)            AS groupid,
			       COALESCE(g.nameshort, '')          AS groupname,
			       COALESCE(m.fromuser, 0)            AS fromuser,
			       COALESCE(m.subject, '')            AS subject,
			       (SELECT COALESCE(SUM(ml.count), 0)
			          FROM messages_likes ml
			         WHERE ml.msgid = ms.msgid
			           AND ml.type = 'View')          AS views,
			       (SELECT COUNT(*)
			          FROM chat_messages cm
			         WHERE cm.refmsgid = ms.msgid
			           AND cm.type = 'Interested'
			           AND cm.reviewrejected = 0
			           AND cm.reviewrequired = 0)     AS replies,
			       COALESCE(
			         (SELECT a.id FROM messages_attachments a
			           WHERE a.msgid = ms.msgid
			           ORDER BY a.`+"`primary`"+` DESC, a.id ASC LIMIT 1),
			         0)                               AS thumb_attachment_id,
			       COALESCE(
			         (SELECT a.externaluid FROM messages_attachments a
			           WHERE a.msgid = ms.msgid AND a.externaluid IS NOT NULL
			           ORDER BY a.`+"`primary`"+` DESC, a.id ASC LIMIT 1),
			         '')                              AS thumb_externaluid
			  FROM messages_spatial ms
			  LEFT JOIN messages m ON m.id = ms.msgid
			  LEFT JOIN `+"`groups`"+` g ON g.id = ms.groupid
			 WHERE ms.arrival >= ?
			   AND ms.msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 3857), ms.point)
			 ORDER BY ms.arrival DESC
			 LIMIT ?
		`, windowStart, wkt, maxPool)
		if err != nil {
			return c.Status(fiber.StatusInternalServerError).
				JSON(fiber.Map{"error": err.Error()})
		}
		defer rows.Close()

		type scored struct {
			MsgID            int64     `json:"msgid"`
			Lng              float64   `json:"lng"`
			Lat              float64   `json:"lat"`
			MsgType          string    `json:"msgtype"`
			Subject          string    `json:"subject"`
			Arrival          time.Time `json:"arrival"`
			Successful       bool      `json:"successful"`
			Promised         bool      `json:"promised"`
			GroupID          int64     `json:"groupid"`
			GroupName        string    `json:"groupname"`
			FromUser         int64     `json:"fromuser"`
			Views            int       `json:"views"`
			Replies          int       `json:"replies"`
			DriveMin         float64   `json:"drive_min"`
			AgeH             float64   `json:"age_h"`
			HomeGroup        bool      `json:"home_group"`
			ThumbAttachID    int64     `json:"thumb_attachment_id"`
			ThumbExternalUID string    `json:"thumb_externaluid"`
			ScoreClose       float64   `json:"score_close"`
			ScoreFresh       float64   `json:"score_fresh"`
			ScoreBudg        float64   `json:"score_budget"`
			ScoreAnch        float64   `json:"score_anchor"`
			Score            float64   `json:"score"`
		}
		pool := make([]scored, 0, 128)
		now := time.Now()
		for rows.Next() {
			var p scored
			if err := rows.Scan(&p.MsgID, &p.Lng, &p.Lat, &p.MsgType,
				&p.Arrival, &p.Successful, &p.Promised, &p.GroupID,
				&p.GroupName, &p.FromUser, &p.Subject, &p.Views, &p.Replies,
				&p.ThumbAttachID, &p.ThumbExternalUID); err != nil {
				continue
			}
			_, isHome := homeGroups[p.GroupID]
			// Promised/completed cross-group posts shouldn't be shown — the
			// member would never have known about them via their own group
			// and they're already gone, so no point either listing them or
			// nudging them to switch to immediate mode.  Keep promised /
			// completed only when they're from a home group.
			if (p.Successful || p.Promised) && !isHome {
				continue
			}
			// Drive-time to this post via nearest reached node.
			nid := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
			if nid != noNode {
				if secs, ok := iso.ReachedNodes[nid]; ok {
					p.DriveMin = float64(secs) / 60.0
				} else {
					p.DriveMin = maxMinutes
				}
			} else {
				p.DriveMin = maxMinutes
			}
			p.AgeH = now.Sub(p.Arrival).Hours()
			p.HomeGroup = isHome

			// Component scores normalised to ~[0,1].
			p.ScoreClose = 1.0 - p.DriveMin/maxMinutes
			if p.ScoreClose < 0 {
				p.ScoreClose = 0
			}
			p.ScoreFresh = 1.0 - p.AgeH/windowH
			if p.ScoreFresh < 0 {
				p.ScoreFresh = 0
			}
			// Time-aware budget signal: rate of engagement per hour, not raw
			// total.  Without this, an 18-hour-old post with 1 view ranks
			// below a 2-hour-old post with 1 view, even though the older
			// post has had 9× longer to accrue eyeballs — it's the rarer
			// of the two and we want it lifted, not penalised.  Clamp the
			// effective age at 1 h so brand-new posts don't all collapse
			// to the same enormous rate.
			ageHForRate := p.AgeH
			if ageHForRate < 1 {
				ageHForRate = 1
			}
			engagement := float64(p.Views+3*p.Replies) / ageHForRate
			p.ScoreBudg = math.Exp(-engagement / (budgetDecay / 12))
			if p.HomeGroup {
				p.ScoreAnch = 1.0
			}
			p.Score = wClose*p.ScoreClose + wFresh*p.ScoreFresh +
				wBudget*p.ScoreBudg + wAnchor*p.ScoreAnch
			pool = append(pool, p)
		}

		// Sort by score descending.
		sort.Slice(pool, func(i, j int) bool { return pool[i].Score > pool[j].Score })

		// Optional same-poster grouping: for each fromuser keep only
		// their highest-scoring post in the selected list; the rest go
		// to a per-poster cluster bucket.
		type cluster struct {
			FromUser    int64   `json:"fromuser"`
			Count       int     `json:"count"`
			TopMsgID    int64   `json:"top_msgid"`
			TopMsgType  string  `json:"top_msgtype"`
			TopLat      float64 `json:"top_lat"`
			TopLng      float64 `json:"top_lng"`
			ExtraMsgIDs []int64 `json:"extra_msgids"`
		}
		clusters := []cluster{}
		var selected []scored
		var deferred []scored

		if groupByPoster {
			byPoster := map[int64]*cluster{}
			seen := map[int64]bool{}
			for _, p := range pool {
				if p.FromUser == 0 {
					if len(selected) < cap {
						selected = append(selected, p)
					} else {
						deferred = append(deferred, p)
					}
					continue
				}
				if _, dup := seen[p.FromUser]; dup {
					cl := byPoster[p.FromUser]
					cl.Count++
					cl.ExtraMsgIDs = append(cl.ExtraMsgIDs, p.MsgID)
					deferred = append(deferred, p)
					continue
				}
				seen[p.FromUser] = true
				cl := &cluster{
					FromUser: p.FromUser, Count: 1, TopMsgID: p.MsgID,
					TopMsgType: p.MsgType, TopLat: p.Lat, TopLng: p.Lng,
				}
				byPoster[p.FromUser] = cl
				if len(selected) < cap {
					selected = append(selected, p)
				} else {
					deferred = append(deferred, p)
				}
			}
			for _, cl := range byPoster {
				if cl.Count > 1 {
					clusters = append(clusters, *cl)
				}
			}
			sort.Slice(clusters, func(i, j int) bool { return clusters[i].Count > clusters[j].Count })
		} else {
			for i, p := range pool {
				if i < cap {
					selected = append(selected, p)
				} else {
					deferred = append(deferred, p)
				}
			}
		}

		homeSelected := 0
		homePool := 0
		for _, p := range pool {
			if p.HomeGroup {
				homePool++
			}
		}
		for _, p := range selected {
			if p.HomeGroup {
				homeSelected++
			}
		}

		return c.JSON(fiber.Map{
			"max_drive_min":  maxMinutes,
			"window_hours":   windowH,
			"pool_size":      len(pool),
			"selected_count": len(selected),
			"deferred_count": len(deferred),
			"home_groups":    homeGroupList,
			"home_selected":  homeSelected,
			"home_pool":      homePool,
			"selected":       selected,
			"deferred":       deferred,
			"poster_groups":  clusters,
			"isochrone":      poly,
			"weights": fiber.Map{
				"closeness":     wClose,
				"freshness":     wFresh,
				"budget":        wBudget,
				"anchor":        wAnchor,
				"cap":           cap,
				"group_poster":  groupByPoster,
				"window_hours":  windowH,
				"budget_decay":  budgetDecay,
			},
		})
	}
}

func parseFloatQuery(c *fiber.Ctx, key string, def, min, max float64) float64 {
	s := c.Query(key)
	if s == "" {
		return def
	}
	v, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return def
	}
	if v < min {
		return min
	}
	if v > max {
		return max
	}
	return v
}
