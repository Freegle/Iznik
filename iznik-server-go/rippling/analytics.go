package rippling

import (
	"bytes"
	"encoding/json"
	"math"
	"net/http"
	"os"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// Package-level analytics endpoint for the sysadmin rippling tab. Everything here is computed
// ON THE FLY (no overnight batch): pure-SQL KPIs over the full set, and drive-time metrics from
// a random SAMPLE of posts scored against the live routing graph. Read-only.

// routingEvalURL is the routing server's /v1/ripple-eval base. Prod: the routing internal
// no-auth port (http://spatial:8194). Local apiv2-live points ROUTING_EVAL_URL at the live
// routing tunnel (http://<host>:1235). Kept distinct from the KNN spatial server URL.
func routingEvalURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
		return u
	}
	if u := os.Getenv("SPATIAL_KNN_URL"); u != "" {
		return u
	}
	return "http://spatial:8194"
}

// Isochrones can take a couple of seconds each on the UK graph, so this client is far more
// patient than the 5s KNN client.
var routingClient = &http.Client{Timeout: 25 * time.Second}

// driveMaxMinutes caps the isochrone. 45 is far cheaper to compute than 60 and covers virtually
// all real reply travel; anything beyond reads as "unreachable" and is excluded from the mean.
const driveMaxMinutes = 45

// envInt reads a positive int env var, falling back to def. Lets the drive-time sample size and
// concurrency be tuned per-environment WITHOUT a rebuild - prod's routing is on the local network
// (fast), while apiv2-live reaches it over the SSH tunnel (slow), so they want different values.
func envInt(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return n
		}
	}
	return def
}

// driveSampleSize: per-view post sample for the drive-time metrics. ~250 posts (~500 replies)
// pins the mean to ±~1 min. RIPPLE_ANALYTICS_SAMPLE overrides.
func driveSampleSize() int { return envInt("RIPPLE_ANALYTICS_SAMPLE", 250) }

// driveConcurrency: simultaneous ripple-eval calls. The pass is RTT-bound, so higher concurrency
// shrinks wall-time roughly linearly. RIPPLE_ANALYTICS_CONCURRENCY overrides.
func driveConcurrency() int { return envInt("RIPPLE_ANALYTICS_CONCURRENCY", 16) }

// StratumFilter renders the rippling_reach.total_freeglers SQL predicate for a density stratum.
// Terciles from live data (active freeglers reached, ~6-month window): rural <1700, suburban
// 1700-3800, dense >3800. "all" (or unknown) imposes no extra bound beyond total_freeglers>0.
func StratumFilter(stratum string) string {
	switch stratum {
	case "rural":
		return " AND rr.total_freeglers < 1700"
	case "suburban":
		return " AND rr.total_freeglers >= 1700 AND rr.total_freeglers < 3800"
	case "dense":
		return " AND rr.total_freeglers >= 3800"
	default:
		return ""
	}
}

type rippleEvalReq struct {
	Lat        float64      `json:"lat"`
	Lng        float64      `json:"lng"`
	Mode       string       `json:"mode"`
	MaxMinutes float64      `json:"max_minutes"`
	Points     [][2]float64 `json:"points"`
}
type rippleEvalResp struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
}

// samplePost is one post in the drive-time sample: origin + its repliers' points, plus which
// repliers are "rippled-out" (server-derived) so section 3 can restrict the mean.
type samplePost struct {
	lat, lng float64
	points   [][2]float64
	rippled  []bool
}

// DriveStat is the result of a sampled drive-time computation: the mean travel minutes with a
// 95% CI half-width and the sample size, so the UI can show adequacy.
type DriveStat struct {
	MeanMin   float64 `json:"mean_min"`
	CIHalf    float64 `json:"ci_half_min"`
	NReplies  int     `json:"n_replies"`
	NPosts    int     `json:"n_posts"`
	Available bool    `json:"available"`
}

// meanDriveMinFromSample runs one ripple-eval per sampled post (bounded concurrency) and returns
// the mean drive-time over the collected replies. When rippledOnly, only replies flagged
// rippled-out contribute. Best-effort: routing/parse failures drop that post from the sample.
func meanDriveMinFromSample(posts []samplePost, rippledOnly bool) DriveStat {
	var mu sync.Mutex
	vals := []float64{}
	nPosts := 0

	sem := make(chan struct{}, driveConcurrency())
	var wg sync.WaitGroup
	for i := range posts {
		p := posts[i]
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			body, _ := json.Marshal(rippleEvalReq{
				Lat: p.lat, Lng: p.lng, Mode: "drive", MaxMinutes: driveMaxMinutes, Points: p.points,
			})
			resp, err := routingClient.Post(routingEvalURL()+"/v1/ripple-eval",
				"application/json", bytes.NewReader(body))
			if err != nil {
				return
			}
			defer resp.Body.Close()
			if resp.StatusCode != http.StatusOK {
				return
			}
			var r rippleEvalResp
			if json.NewDecoder(resp.Body).Decode(&r) != nil || len(r.Results) != len(p.points) {
				return
			}
			local := []float64{}
			for j, res := range r.Results {
				if rippledOnly && (j >= len(p.rippled) || !p.rippled[j]) {
					continue
				}
				if res.DriveMin != nil {
					local = append(local, *res.DriveMin)
				}
			}
			mu.Lock()
			vals = append(vals, local...)
			if len(local) > 0 {
				nPosts++
			}
			mu.Unlock()
		}()
	}
	wg.Wait()

	stat := DriveStat{NReplies: len(vals), NPosts: nPosts}
	if len(vals) == 0 {
		return stat
	}
	stat.Available = true
	sum := 0.0
	for _, v := range vals {
		sum += v
	}
	stat.MeanMin = sum / float64(len(vals))
	if len(vals) > 1 {
		varsum := 0.0
		for _, v := range vals {
			varsum += (v - stat.MeanMin) * (v - stat.MeanMin)
		}
		sd := math.Sqrt(varsum / float64(len(vals)-1))
		stat.CIHalf = 1.96 * sd / math.Sqrt(float64(len(vals)))
	}
	return stat
}

// fetchDriveSample pulls a random sample of posts (with their replier points) for the window +
// stratum, tagging each reply rippled-out server-side. rippled-out = the replier reached the
// post via rippling: not an established member of an ORIGIN group before arrival, on a post that
// had a rippled_in copy by reply time. (The same durable signal the attribution ladder uses.)
func fetchDriveSample(start, end, stratumSQL string, sampleN int) []samplePost {
	db := database.DBConn
	type row struct {
		Msgid    uint64
		Plat     float64
		Plng     float64
		Rlat     float64
		Rlng     float64
		Rippled  int
	}
	var rows []row
	// Sample post ids first (ORDER BY RAND over the windowed rippled set), then expand to
	// replier points. Bounded to posts that actually have an Interested reply.
	db.Raw(`
		SELECT samp.msgid, ml.lat AS plat, ml.lng AS plng, ul.lat AS rlat, ul.lng AS rlng,
		       (NOT EXISTS(SELECT 1 FROM messages_groups og
		                   INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = cm.userid
		                     AND mem.collection = 'Approved' AND mem.added < og.arrival
		                   WHERE og.msgid = samp.msgid AND og.rippled_in = 0 AND og.deleted = 0)) AS rippled
		FROM (
		    SELECT rr.msgid
		    FROM rippling_reach rr
		    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
		    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		      AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')
		    ORDER BY RAND() LIMIT ?
		) samp
		JOIN messages m    ON m.id = samp.msgid
		JOIN locations ml  ON ml.id = m.locationid AND ml.lat IS NOT NULL
		JOIN chat_messages cm ON cm.refmsgid = samp.msgid AND cm.type = 'Interested'
		JOIN users u       ON u.id = cm.userid
		JOIN locations ul  ON ul.id = u.lastlocation AND ul.lat IS NOT NULL
		ORDER BY samp.msgid`, start, end, sampleN).Scan(&rows)

	byPost := map[uint64]*samplePost{}
	order := []uint64{}
	for _, r := range rows {
		sp := byPost[r.Msgid]
		if sp == nil {
			sp = &samplePost{lat: r.Plat, lng: r.Plng}
			byPost[r.Msgid] = sp
			order = append(order, r.Msgid)
		}
		sp.points = append(sp.points, [2]float64{r.Rlng, r.Rlat})
		sp.rippled = append(sp.rippled, r.Rippled == 1)
	}
	out := make([]samplePost, 0, len(order))
	for _, id := range order {
		out = append(out, *byPost[id])
	}
	return out
}

// Section1KPI is the "where we are as a platform" headline block for one stratum + window.
type Section1KPI struct {
	Stratum       string    `json:"stratum"`
	Posts         int       `json:"posts"`
	Replied       int       `json:"replied"`
	RepliedPct    float64   `json:"replied_pct"`
	Taken         int       `json:"taken"`
	TakenPct      float64   `json:"taken_pct"`
	MeanReplies   float64   `json:"mean_replies"`
	MeanFreeglers float64   `json:"mean_freeglers_reached"`
	ReplyDrive    DriveStat `json:"reply_drive_min"`
}

// Analytics is the on-the-fly sysadmin rippling analytics endpoint. Support/Admin only.
//
// @Router /rippling/analytics [get]
// @Summary On-the-fly rippling analytics KPIs (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
func Analytics(c *fiber.Ctx) error {
	db := database.DBConn
	stratum := c.Query("stratum", "all")
	start := c.Query("start")
	end := c.Query("end")
	if start == "" {
		start = time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05")
	}
	if end == "" {
		end = time.Now().Format("2006-01-02 15:04:05")
	}
	stratumSQL := StratumFilter(stratum)

	// Section 1 counts - pure SQL, full set. Per-post nreplies/taken/freeglers, then aggregate.
	var agg struct {
		Posts         int
		Replied       int
		Taken         int
		TotalReplies  int
		MeanFreeglers float64
	}
	db.Raw(`
		SELECT COUNT(*) AS posts,
		       SUM(nreplies > 0) AS replied,
		       SUM(taken) AS taken,
		       SUM(nreplies) AS total_replies,
		       AVG(freeglers) AS mean_freeglers
		FROM (
		    SELECT rr.total_freeglers AS freeglers,
		           (SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested') AS nreplies,
		           EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid) AS taken
		    FROM rippling_reach rr
		    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
		    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		) d`, start, end).Scan(&agg)

	kpi := Section1KPI{Stratum: stratum, Posts: agg.Posts, Replied: agg.Replied, Taken: agg.Taken,
		MeanFreeglers: agg.MeanFreeglers}
	if agg.Posts > 0 {
		kpi.RepliedPct = float64(agg.Replied) / float64(agg.Posts) * 100
		kpi.TakenPct = float64(agg.Taken) / float64(agg.Posts) * 100
		kpi.MeanReplies = float64(agg.TotalReplies) / float64(agg.Posts)
	}

	// Section 1 + 3 drive-time - one sampled routing pass covers both (all replies and the
	// rippled-out subset) since fetchDriveSample tags each reply rippled-out server-side.
	sample := fetchDriveSample(start, end, stratumSQL, driveSampleSize())
	kpi.ReplyDrive = meanDriveMinFromSample(sample, false)

	// Section 2 - trends: the same SQL KPIs bucketed per arrival day. Drive-time is NOT trended
	// (per-day sampling would multiply the routing cost); the section-1 mean stands for the window.
	trend := trendSeries(start, end, stratumSQL)

	// Section 3 - rippling-out specific (server-derived): of replies to rippled posts, what share
	// came via rippling, and of the takers, what share replied via rippling. Plus the rippled-out
	// mean drive-time from the same sample.
	s3 := rippledOutSection(start, end, stratumSQL)
	s3.RippleDrive = meanDriveMinFromSample(sample, true)

	return c.JSON(fiber.Map{
		"stratum":       stratum,
		"start":         start,
		"end":           end,
		"sample_target": driveSampleSize(),
		"section1":      kpi,
		"section2":      trend,
		"section3":      s3,
	})
}

// TrendRow is one arrival-day point for the Section 2 time series.
type TrendRow struct {
	Day           string  `json:"day"            gorm:"column:day"`
	Posts         int     `json:"posts"          gorm:"column:posts"`
	RepliedPct    float64 `json:"replied_pct"    gorm:"column:replied_pct"`
	TakenPct      float64 `json:"taken_pct"      gorm:"column:taken_pct"`
	MeanReplies   float64 `json:"mean_replies"   gorm:"column:mean_replies"`
	MeanFreeglers float64 `json:"mean_freeglers" gorm:"column:mean_freeglers"`
}

// trendSeries returns per-day KPI points (ascending) over the window + stratum. Pure SQL.
func trendSeries(start, end, stratumSQL string) []TrendRow {
	rows := []TrendRow{}
	database.DBConn.Raw(`
		SELECT DATE_FORMAT(created, '%Y-%m-%d') AS day,
		       COUNT(*) AS posts,
		       100 * SUM(nreplies > 0) / COUNT(*) AS replied_pct,
		       100 * SUM(taken) / COUNT(*) AS taken_pct,
		       SUM(nreplies) / COUNT(*) AS mean_replies,
		       AVG(freeglers) AS mean_freeglers
		FROM (
		    SELECT rr.created_at AS created, rr.total_freeglers AS freeglers,
		           (SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested') AS nreplies,
		           EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid) AS taken
		    FROM rippling_reach rr
		    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
		    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		) d
		GROUP BY day ORDER BY day`, start, end).Scan(&rows)
	return rows
}

// Section3RippledOut is the "rippling-out specifically" block. rippled-out is server-derived:
// the replier reached the post via rippling (not an established member of an origin group before
// arrival). ClientInstrumentedPct cross-checks against the client-reported provenance once that
// data is live in prod (currently ~0).
type Section3RippledOut struct {
	Replies               int       `json:"replies"`
	RippledReplies        int       `json:"rippled_replies"`
	RippledRepliesPct     float64   `json:"rippled_replies_pct"`
	Takers                int       `json:"takers"`
	RippledTakers         int       `json:"rippled_takers"`
	RippledTakersPct      float64   `json:"rippled_takers_pct"`
	ClientInstrumentedPct float64   `json:"client_instrumented_pct"`
	RippleDrive           DriveStat `json:"ripple_drive_min"`
}

// rippledOutSection computes the server-derived rippled-out reply/taker shares (pure SQL) over
// replies to rippled posts in the window + stratum.
func rippledOutSection(start, end, stratumSQL string) Section3RippledOut {
	var raw struct {
		Replies        int
		RippledReplies int
		Takers         int
		RippledTakers  int
		ClientRippled  int
	}
	database.DBConn.Raw(`
		SELECT COUNT(*) AS replies,
		       SUM(rippled) AS rippled_replies,
		       SUM(is_taker) AS takers,
		       SUM(rippled AND is_taker) AS rippled_takers,
		       SUM(client_rippled) AS client_rippled
		FROM (
		    SELECT
		      (NOT EXISTS(SELECT 1 FROM messages_groups og
		                  INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = cm.userid
		                    AND mem.collection = 'Approved' AND mem.added < og.arrival
		                  WHERE og.msgid = cm.refmsgid AND og.rippled_in = 0 AND og.deleted = 0)) AS rippled,
		      EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = cm.refmsgid AND mb.userid = cm.userid) AS is_taker,
		      EXISTS(SELECT 1 FROM rippling_reply_attribution rra
		             WHERE rra.msgid = cm.refmsgid AND rra.userid = cm.userid
		               AND rra.attribution IN ('ripple_notified','ripple_group','ripple_reach')) AS client_rippled
		    FROM chat_messages cm
		    JOIN rippling_reach rr ON rr.msgid = cm.refmsgid AND rr.total_freeglers > 0`+stratumSQL+`
		    JOIN messages m ON m.id = cm.refmsgid AND m.type = 'Offer'
		    WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = cm.refmsgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		) d`, start, end).Scan(&raw)

	s := Section3RippledOut{Replies: raw.Replies, RippledReplies: raw.RippledReplies,
		Takers: raw.Takers, RippledTakers: raw.RippledTakers}
	if raw.Replies > 0 {
		s.RippledRepliesPct = float64(raw.RippledReplies) / float64(raw.Replies) * 100
		s.ClientInstrumentedPct = float64(raw.ClientRippled) / float64(raw.Replies) * 100
	}
	if raw.Takers > 0 {
		s.RippledTakersPct = float64(raw.RippledTakers) / float64(raw.Takers) * 100
	}
	return s
}
