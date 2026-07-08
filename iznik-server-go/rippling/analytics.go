package rippling

import (
	"bytes"
	"encoding/json"
	"math"
	"net/http"
	"os"
	"sort"
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
// Used where rippling_reach is already joined as `rr`.
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

// samplePost is one post in the drive-time sample: origin + its repliers' points, each tagged
// rippled-out (server-derived, so section 3 can restrict the mean) and with its reply day (so a
// drive-time trend falls out of the same routing pass for free).
type samplePost struct {
	lat, lng float64
	points   [][2]float64
	rippled  []bool
	days     []string
}

// driveObs is one scored reply from the sample: its drive-time, whether it was rippled-out, and
// its reply day. One routing pass produces these; the overall/rippled means and the per-day trend
// are then computed in Go without re-calling the graph.
type driveObs struct {
	min     float64
	rippled bool
	day     string
}

// DriveStat is the result of a sampled drive-time computation: the mean travel minutes with a
// 95% CI half-width and the sample size, so the UI can show adequacy.
type DriveStat struct {
	MeanMin   float64 `json:"mean_min"`
	CIHalf    float64 `json:"ci_half_min"`
	NReplies  int     `json:"n_replies"`
	Available bool    `json:"available"`
}

// scoreSample runs one ripple-eval per sampled post (bounded concurrency) and returns the scored
// replies. Best-effort: routing/parse failures drop that post. This is the ONLY routing pass -
// every drive-time figure (overall, rippled-only, per-day trend) is derived from its output.
func scoreSample(posts []samplePost) []driveObs {
	var mu sync.Mutex
	obs := []driveObs{}

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
			local := []driveObs{}
			for j, res := range r.Results {
				if res.DriveMin == nil {
					continue
				}
				o := driveObs{min: *res.DriveMin}
				if j < len(p.rippled) {
					o.rippled = p.rippled[j]
				}
				if j < len(p.days) {
					o.day = p.days[j]
				}
				local = append(local, o)
			}
			mu.Lock()
			obs = append(obs, local...)
			mu.Unlock()
		}()
	}
	wg.Wait()
	return obs
}

// statFromObs is the mean drive-time (+95% CI) over the observations, optionally restricted to
// rippled-out replies.
func statFromObs(obs []driveObs, rippledOnly bool) DriveStat {
	vals := []float64{}
	for _, o := range obs {
		if rippledOnly && !o.rippled {
			continue
		}
		vals = append(vals, o.min)
	}
	stat := DriveStat{NReplies: len(vals)}
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

// DriveTrendPoint is one day's mean sampled drive-time (small per-day n; a rough guide).
type DriveTrendPoint struct {
	Day     string  `json:"day"`
	MeanMin float64 `json:"mean_min"`
	N       int     `json:"n"`
}

// driveTrendFromObs buckets the sampled drive-times by reply day (ascending). Per-day n is small
// (the window sample spread over days), so this is a rough trend, flagged as sample-based.
func driveTrendFromObs(obs []driveObs) []DriveTrendPoint {
	sums := map[string]float64{}
	counts := map[string]int{}
	days := []string{}
	for _, o := range obs {
		if o.day == "" {
			continue
		}
		if counts[o.day] == 0 {
			days = append(days, o.day)
		}
		sums[o.day] += o.min
		counts[o.day]++
	}
	sort.Strings(days)
	out := make([]DriveTrendPoint, 0, len(days))
	for _, d := range days {
		out = append(out, DriveTrendPoint{Day: d, MeanMin: sums[d] / float64(counts[d]), N: counts[d]})
	}
	return out
}

// fetchDriveSample pulls a random sample of posts (with their replier points) for the window +
// stratum, tagging each reply rippled-out server-side. rippled-out = the replier reached the
// post via rippling: not an established member of an ORIGIN group before arrival, on a post that
// had a rippled_in copy by reply time. (The same durable signal the attribution ladder uses.)
func fetchDriveSample(start, end, stratumSQL string, sampleN int) []samplePost {
	db := database.DBConn
	type row struct {
		Msgid   uint64
		Plat    float64
		Plng    float64
		Rlat    float64
		Rlng    float64
		Rippled int
		Day     string
	}
	var rows []row
	// Sample post ids first (ORDER BY RAND over the windowed rippled set), then expand to
	// replier points. Bounded to posts that actually have an Interested reply.
	db.Raw(`
		SELECT samp.msgid, ml.lat AS plat, ml.lng AS plng, ul.lat AS rlat, ul.lng AS rlng,
		       DATE_FORMAT(cm.date, '%Y-%m-%d') AS day,
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
		                     AND cm.date >= ? AND cm.date < ?
		JOIN users u       ON u.id = cm.userid
		JOIN locations ul  ON ul.id = u.lastlocation AND ul.lat IS NOT NULL
		ORDER BY samp.msgid`, start, end, sampleN, start, end).Scan(&rows)

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
		sp.days = append(sp.days, r.Day)
	}
	out := make([]samplePost, 0, len(order))
	for _, id := range order {
		out = append(out, *byPost[id])
	}
	return out
}

// Section1KPI is the headline block for one stratum + window, over rippled-out offers.
// The headline reply rate is WITHIN 36H (the settling-window convention, comparable to the old
// dashboard); RepliedEverPct is the eventual total, shown smaller as context.
type Section1KPI struct {
	Stratum       string    `json:"stratum"`
	Posts         int       `json:"posts"`
	Replied36h    int       `json:"replied_36h"`
	Replied36hPct float64   `json:"replied_36h_pct"`
	RepliedEver   int       `json:"replied_ever"`
	RepliedEverPct float64  `json:"replied_ever_pct"`
	Taken         int       `json:"taken"`
	TakenPct      float64   `json:"taken_pct"`
	MeanReplies   float64   `json:"mean_replies"`
	MeanFreeglers float64   `json:"mean_freeglers_reached"`
	ReplyDrive    DriveStat `json:"reply_drive_min"`
	// Reply friction: share of replies that were HELD (an email/TN reply held because the post
	// had not yet rippled to the replier's location).
	HeldReplies    int     `json:"held_replies"`
	HeldRepliesPct float64 `json:"held_replies_pct"`
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
		Replied36h    int
		RepliedEver   int
		Taken         int
		TotalReplies  int
		MeanFreeglers float64
	}
	// replied_36h: got a reply within 36h of the settling clock (first ripple, else origin
	// arrival) - the headline convention. replied_ever: any reply eventually - the total.
	db.Raw(`
		SELECT COUNT(*) AS posts,
		       SUM(replied_36h) AS replied36h,
		       SUM(nreplies > 0) AS replied_ever,
		       SUM(taken) AS taken,
		       SUM(nreplies) AS total_replies,
		       AVG(freeglers) AS mean_freeglers
		FROM (
		    SELECT rr.total_freeglers AS freeglers,
		           (SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested') AS nreplies,
		           EXISTS(SELECT 1 FROM chat_messages c2 WHERE c2.refmsgid = rr.msgid AND c2.type = 'Interested'
		                  AND c2.date <= COALESCE(
		                       (SELECT MIN(arrival) FROM messages_groups WHERE msgid = rr.msgid AND rippled_in = 1 AND deleted = 0),
		                       (SELECT MIN(arrival) FROM messages_groups WHERE msgid = rr.msgid AND rippled_in = 0 AND deleted = 0)
		                  ) + INTERVAL 36 HOUR) AS replied_36h,
		           EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid) AS taken
		    FROM rippling_reach rr
		    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
		    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		) d`, start, end).Scan(&agg)

	kpi := Section1KPI{Stratum: stratum, Posts: agg.Posts, Replied36h: agg.Replied36h,
		RepliedEver: agg.RepliedEver, Taken: agg.Taken, MeanFreeglers: agg.MeanFreeglers}
	if agg.Posts > 0 {
		kpi.Replied36hPct = float64(agg.Replied36h) / float64(agg.Posts) * 100
		kpi.RepliedEverPct = float64(agg.RepliedEver) / float64(agg.Posts) * 100
		kpi.TakenPct = float64(agg.Taken) / float64(agg.Posts) * 100
		kpi.MeanReplies = float64(agg.TotalReplies) / float64(agg.Posts)
	}

	// Reply friction: held replies over the window on rippled-out offers, as a share of all
	// replies. rippling_held_replies has msgid + created_at so it scopes by post + stratum.
	var held int
	db.Raw(`
		SELECT COUNT(*) FROM rippling_held_replies hr
		JOIN rippling_reach rr ON rr.msgid = hr.msgid AND rr.total_freeglers > 0`+stratumSQL+`
		JOIN messages m ON m.id = hr.msgid AND m.type = 'Offer'
		WHERE hr.created_at >= ? AND hr.created_at < ?
		  AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = hr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)`,
		start, end).Scan(&held)
	kpi.HeldReplies = held
	if agg.TotalReplies > 0 {
		kpi.HeldRepliesPct = float64(held) / float64(agg.TotalReplies) * 100
	}

	// ONE sampled routing pass feeds every drive-time figure: the Section 1 overall mean, the
	// Section 3 rippled-out mean, and the drive-time trend - no re-calling the graph.
	obs := scoreSample(fetchDriveSample(start, end, stratumSQL, driveSampleSize()))
	kpi.ReplyDrive = statFromObs(obs, false)

	// Section 2 - trends: the SQL KPIs (reply rate, taken rate, mean replies, freeglers reached)
	// per arrival day, plus the sample-based drive-time trend from the pass above.
	trend := fiber.Map{
		"kpis":       trendSeries(start, end, stratumSQL),
		"drive_time": driveTrendFromObs(obs),
	}

	// Section 3 - is rippling helping? Server-derived rippled-out shares, the rescue floor and
	// contribution range, the home-vs-rippled comparison, and the rippled-out mean drive-time.
	s3 := rippledOutSection(start, end, stratumSQL)
	s3.RippleDrive = statFromObs(obs, true)

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

// Section3RippledOut is the "is rippling helping?" block. rippled-out is server-derived: the
// replier reached the post via rippling (not an established member of an origin group before
// arrival).
//
// The headline is a CONTRIBUTION RANGE, because per-reply conversion (rippled worse - people
// further away follow through less) is the wrong lens: rippled takes are ADDITIVE.
//   - Floor = RescuedTakes: posts taken by a rippled replier that had NO home-group reply at all.
//     Without rippling those posts go silent, so those takes definitely would not have happened.
//   - Ceiling = RippledTakers: every take by a rippled replier (assumes no home member would have
//     taken it instead).
// Rippling's true contribution to reuse sits between the two.
//
// HomeConvPct / RippledConvPct give the reply->take comparison you'd expect rippled to lose (it's
// further away), shown so the "worse per reply, still additive" point is explicit.
type Section3RippledOut struct {
	Replies           int     `json:"replies"`
	RippledReplies    int     `json:"rippled_replies"`
	RippledRepliesPct float64 `json:"rippled_replies_pct"`
	Takers            int     `json:"takers"`
	RippledTakers     int     `json:"rippled_takers"`
	RippledTakersPct  float64 `json:"rippled_takers_pct"`

	// "Is it helping?" contribution range (share of all takes on rippled-eligible posts).
	RescuedTakes        int     `json:"rescued_takes"`
	RescuedTakesPct     float64 `json:"rescued_takes_pct"`
	ContributionLowPct  float64 `json:"contribution_low_pct"`  // = rescued / takers
	ContributionHighPct float64 `json:"contribution_high_pct"` // = rippled / takers

	// Per-reply reply->take conversion, home vs rippled cohort.
	HomeConvPct    float64 `json:"home_conv_pct"`
	RippledConvPct float64 `json:"rippled_conv_pct"`

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

	// Rescue floor: DISTINCT posts that were taken AND had at least one reply AND had NO reply
	// from an established home-group member - so the take could only have come via rippling.
	var rescued int
	database.DBConn.Raw(`
		SELECT COUNT(*) FROM (
		    SELECT rr.msgid
		    FROM rippling_reach rr
		    JOIN messages m ON m.id = rr.msgid AND m.type = 'Offer'
		    WHERE rr.created_at >= ? AND rr.created_at < ? AND rr.total_freeglers > 0`+stratumSQL+`
		      AND EXISTS(SELECT 1 FROM messages_groups mgr WHERE mgr.msgid = rr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)
		      AND EXISTS(SELECT 1 FROM messages_by mb WHERE mb.msgid = rr.msgid)
		      AND EXISTS(SELECT 1 FROM chat_messages c WHERE c.refmsgid = rr.msgid AND c.type = 'Interested')
		      AND NOT EXISTS(
		          SELECT 1 FROM chat_messages ch
		          INNER JOIN messages_groups og ON og.msgid = ch.refmsgid AND og.rippled_in = 0 AND og.deleted = 0
		          INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = ch.userid
		            AND mem.collection = 'Approved' AND mem.added < og.arrival
		          WHERE ch.refmsgid = rr.msgid AND ch.type = 'Interested')
		) x`, start, end).Scan(&rescued)

	s := Section3RippledOut{Replies: raw.Replies, RippledReplies: raw.RippledReplies,
		Takers: raw.Takers, RippledTakers: raw.RippledTakers, RescuedTakes: rescued}
	if raw.Replies > 0 {
		s.RippledRepliesPct = float64(raw.RippledReplies) / float64(raw.Replies) * 100
		s.ClientInstrumentedPct = float64(raw.ClientRippled) / float64(raw.Replies) * 100
	}
	if raw.Takers > 0 {
		s.RippledTakersPct = float64(raw.RippledTakers) / float64(raw.Takers) * 100
		s.RescuedTakesPct = float64(rescued) / float64(raw.Takers) * 100
		s.ContributionLowPct = s.RescuedTakesPct
		s.ContributionHighPct = s.RippledTakersPct
	}
	// Per-reply conversion, home vs rippled cohort (derived from the counts).
	if raw.RippledReplies > 0 {
		s.RippledConvPct = float64(raw.RippledTakers) / float64(raw.RippledReplies) * 100
	}
	if homeReplies := raw.Replies - raw.RippledReplies; homeReplies > 0 {
		s.HomeConvPct = float64(raw.Takers-raw.RippledTakers) / float64(homeReplies) * 100
	}
	return s
}
