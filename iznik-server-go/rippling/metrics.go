// Package rippling surfaces the rippling-out live event counters (rippling_event_metrics)
// and §16 rollout-health metrics to sysadmin. Read-only; Support/Admin gated by the route group.
package rippling

import (
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// EventMetric is one (day, event, count) counter row. For the totals roll-up `Day` is empty.
type EventMetric struct {
	Day   string `json:"day"`
	Event string `json:"event"`
	Count uint64 `json:"count"`
}

// Hotspot is one geographically-unusual area flagged by the §16 tuner (ripple:tune): an area
// whose metric is a robust outlier vs the population, so a local problem the overall average
// hides is surfaced for attention.
type Hotspot struct {
	PeriodStart string  `json:"period_start"`
	AreaType    string  `json:"area_type"`
	AreaID      *uint64 `json:"area_id"`
	AreaName    string  `json:"area_name"`
	Metric      string  `json:"metric"`
	Value       float64 `json:"value"`
	Baseline    float64 `json:"baseline"`
	Deviation   float64 `json:"deviation"`
	Direction   string  `json:"direction"`
	Severity    string  `json:"severity"`
}

// ProposedParam is one advisory per-ONS-category parameter change the tuner suggests (a human
// promotes it to active; nothing changes the engine until then).
type ProposedParam struct {
	OnsCategory string `json:"ons_category"`
	MaxMinutes  *int   `json:"max_minutes"`
	Rationale   string `json:"rationale"`
	ProposedAt  string `json:"proposed_at"`
}

// LiveMetricRow is one (period_start, metric, value, sample_size) summary row from the weekly
// batch rollup (rippling_live_metrics). Only the 'overall' stratum is returned here; per-group
// detail lives in the hotspots surface.
type LiveMetricRow struct {
	PeriodStart string  `json:"period_start" gorm:"column:period_start"`
	Metric      string  `json:"metric"       gorm:"column:metric"`
	Value       float64 `json:"value"        gorm:"column:value"`
	SampleSize  int     `json:"sample_size"  gorm:"column:sample_size"`
}

// HeldReplySummary is the aggregate picture of rippling_held_replies: how many are in each
// state and the median hold duration for released replies. Lives in the §15 friction panel.
type HeldReplySummary struct {
	Status  string  `json:"status"           gorm:"column:status"`
	Count   int64   `json:"count"            gorm:"column:count"`
	MedianH float64 `json:"median_hold_hours" gorm:"column:median_hold_hours"`
}

// CrossGroupSummary reports the share of post appearances that were rippled in by the engine
// (messages_groups.rippled_in = 1) and what fraction of those were approved vs rejected. This
// directly measures §16.3 — "cross-group reach: fraction of posts from groups the viewer was
// not a member of before."
type CrossGroupSummary struct {
	PeriodDays    int     `json:"period_days"`
	RippledIn     int64   `json:"rippled_in"`
	Total         int64   `json:"total"`
	CrossGroupPct float64 `json:"cross_group_pct"`
	ApprovalRate  float64 `json:"approval_rate"`
}

// CaptureSummary is the most-recent week's timing/capture picture from the offline simulator
// (rippling_algorithm_metrics, renamed from ripple_algorithm_metrics). Reports the 'all'-group
// row for the latest week so the dashboard can answer "are repliers being reached in time?"
// (§16.4: pairs_in_time/pairs_late, capture rate).
type CaptureSummary struct {
	WeekStart   string  `json:"week_start"      gorm:"column:week_start"`
	Curve       string  `json:"curve"           gorm:"column:curve"`
	PairsTotal  int     `json:"pairs_total"     gorm:"column:pairs_total"`
	PairsInTime int     `json:"pairs_in_time"   gorm:"column:pairs_in_time"`
	PairsLate   int     `json:"pairs_late"      gorm:"column:pairs_late"`
	CaptureRate float64 `json:"capture_rate"` // computed in Go, not from DB
	ReplyP50H   float64 `json:"reply_p50_hours" gorm:"column:reply_p50_hours"`
	ReplyP75H   float64 `json:"reply_p75_hours" gorm:"column:reply_p75_hours"`
}

// ReplyRateRow is the headline KPI per arrival day: of Offers that arrived that day (and whose
// full 36h window has elapsed, so the figure is complete), what fraction received at least one
// Interested reply within 36h. This is the "are we turning 0-reply posts into 1-reply posts"
// number. Computed live from messages + chat_messages, so it works over all history with no
// instrumentation and no capture (reply timestamps don't change).
// HomePosts/RipplePosts partition Posts by whether the post has any rippled-in messages_groups row.
type ReplyRateRow struct {
	Day      string  `json:"day"     gorm:"column:day"`
	Posts    int     `json:"posts"   gorm:"column:posts"`
	Replied  int     `json:"replied" gorm:"column:replied"`
	ReplyPct float64 `json:"reply_pct"` // computed in Go

	HomePosts   int     `json:"home_posts"   gorm:"column:home_posts"`
	HomeReplied int     `json:"home_replied" gorm:"column:home_replied"`
	HomePct     float64 `json:"home_pct"` // computed in Go

	RipplePosts   int     `json:"ripple_posts"   gorm:"column:ripple_posts"`
	RippleReplied int     `json:"ripple_replied" gorm:"column:ripple_replied"`
	RipplePct     float64 `json:"ripple_pct"` // computed in Go

	Provisional bool `json:"provisional"` // computed in Go (day within the 36h settling window)
}

// ReplySourceRow is the daily split of Interested replies by whether the replier reached the post
// via rippling or via their own (home) group. A reply counts as "home" only if the replier was an
// approved member of an ORIGIN (rippled_in=0) group of the post BEFORE the post arrived
// (memberships.added < arrival). We key on the join TIMESTAMP, not current membership, because
// replying can join the member to the group - a naive membership check would mis-label every
// rippling reply as home-group. Everything else is rippling-attributable.
type ReplySourceRow struct {
	Day       string  `json:"day"     gorm:"column:day"`
	Replies   int     `json:"replies" gorm:"column:replies"`
	Home      int     `json:"home"    gorm:"column:home"`
	Ripple    int     `json:"ripple"`     // computed in Go (replies - home)
	RipplePct float64 `json:"ripple_pct"` // computed in Go
}

// ReplyDistanceRow is the daily median crow-flies distance (km) from a post's location to the
// replier's home location, over Interested replies - how far rippling is pulling replies from.
// HomeMedianKm/RippleMedianKm split the median by whether the post was rippled-out.
type ReplyDistanceRow struct {
	Day      string  `json:"day"       gorm:"column:day"`
	Replies  int     `json:"replies"   gorm:"column:replies"`
	MedianKm float64 `json:"median_km" gorm:"column:median_km"`

	HomeReplies    int     `json:"home_replies"     gorm:"column:home_replies"`
	HomeMedianKm   float64 `json:"home_median_km"   gorm:"column:home_median_km"`
	RippleReplies  int     `json:"ripple_replies"   gorm:"column:ripple_replies"`
	RippleMedianKm float64 `json:"ripple_median_km" gorm:"column:ripple_median_km"`
}

// TakenRateRow is the daily reuse outcome: of posts that arrived that day (and have had a settling
// window to find a taker), what fraction reached a Taken (offer) or Received (wanted) outcome.
// HomePosts/RipplePosts partition Posts by whether the post has any rippled-in messages_groups row.
type TakenRateRow struct {
	Day      string  `json:"day"   gorm:"column:day"`
	Posts    int     `json:"posts" gorm:"column:posts"`
	Taken    int     `json:"taken" gorm:"column:taken"`
	TakenPct float64 `json:"taken_pct"` // computed in Go

	HomePosts int     `json:"home_posts" gorm:"column:home_posts"`
	HomeTaken int     `json:"home_taken" gorm:"column:home_taken"`
	HomePct   float64 `json:"home_pct"` // computed in Go

	RipplePosts int     `json:"ripple_posts" gorm:"column:ripple_posts"`
	RippleTaken int     `json:"ripple_taken" gorm:"column:ripple_taken"`
	RipplePct   float64 `json:"ripple_pct"` // computed in Go

	Provisional bool `json:"provisional"` // computed in Go (day within the 7-day settling window)
}

// GroupOption is one entry in the per-group KPI filter: a group whose posts have rippled, so its
// KPIs are worth viewing on their own (the experiment groups surface here once they ripple).
type GroupOption struct {
	ID   uint64 `json:"id"   gorm:"column:id"`
	Name string `json:"name" gorm:"column:name"`
}

// Metrics returns the rippling-out event counters plus the §15/§16 rollout-health metrics.
// Events: reply_blocked (#2), held/released/taken_gone (#3), secondary_reject (#6),
// immediate_mailed (#0), rippled_in (#6). Support/Admin only.
//
// New §16 fields (all defensive — empty/zero when source tables are not yet populated):
//   - live_metrics: volume_posts p50/p90 + secondary_reject_rate from the weekly batch rollup.
//   - held_reply_summary: counts by status + median hold duration (§15 friction).
//   - cross_group_summary: rippled-in share + approval rate over the last 30 days (§16.3).
//   - capture_summary: latest offline-simulator week for timing / capture rate (§16.4).
//
// @Router /rippling/metrics [get]
// @Summary Rippling-out live event counters and §16 rollout-health metrics (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Support or Admin role required"
func Metrics(c *fiber.Ctx) error {
	db := database.DBConn

	// ---- Reply + reuse KPIs: parse params first so arg-builder closures can capture them ----
	// Optional ?groupid= scopes every KPI below to posts ORIGINATING in that group (its
	// rippled_in=0 messages_groups row), so results read per place - dense Croydon won't look
	// like rural Ribble Valley. 0 = all groups. Each scoped query takes one gid arg.
	gid := c.QueryInt("groupid", 0)
	// Optional ?start= & ?end= date range bounds every KPI below; default to the last 30 days.
	// This is what lets you read a treatment group's before vs after rippling went on.
	start := c.Query("start")
	end := c.Query("end")
	if start == "" {
		start = time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05")
	}
	if end == "" {
		end = time.Now().Format("2006-01-02 15:04:05")
	}
	rateGroup, srcGroup, distGroup := "", "", ""
	if gid > 0 {
		rateGroup = " AND mg.groupid = ? AND mg.rippled_in = 0"
		srcGroup = " JOIN messages_groups mg ON mg.msgid = rra.msgid AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
		distGroup = " JOIN messages_groups mg ON mg.msgid = m.id AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
	}
	// Per-query args: the group filter (when set) sits in a JOIN before the date-bounded WHERE,
	// so gid comes first, then start, end.
	gargs := func() []interface{} {
		a := []interface{}{}
		if gid > 0 {
			a = append(a, gid)
		}
		return append(a, start, end)
	}

	// replyRateArgs orders args to match the SQL: optional group filter, then the fr-subquery
	// window (start, end), then the outer arrival window (start, end).
	// Parallel in structure to takenRateArgs — keep the two in sync.
	replyRateArgs := func(groupID int, windowStart, windowEnd string) []interface{} {
		a := []interface{}{}
		if groupID > 0 {
			a = append(a, groupID)
		}
		return append(a, windowStart, windowEnd, windowStart, windowEnd)
	}

	// takenRateArgs orders args to match the SQL: optional group filter, then the outcomes
	// subquery lower bound (start), then the outer arrival window (start, end).
	// Parallel in structure to replyRateArgs — keep the two in sync.
	takenRateArgs := func(groupID int, windowStart, windowEnd string) []interface{} {
		a := []interface{}{}
		if groupID > 0 {
			a = append(a, groupID)
		}
		return append(a, windowStart, windowStart, windowEnd)
	}

	// ---- Declare all result variables up front so goroutines can capture them --------
	totals := []EventMetric{}
	recent := []EventMetric{}
	hotspots := []Hotspot{}
	proposed := []ProposedParam{}
	liveMetrics := []LiveMetricRow{}
	heldReplySummary := []HeldReplySummary{}
	type crossGroupRaw struct {
		Total           int64 `gorm:"column:total"`
		RippledIn       int64 `gorm:"column:rippled_in"`
		ApprovedRippled int64 `gorm:"column:approved_rippled"`
	}
	var cg crossGroupRaw
	var capture CaptureSummary
	replyRates := []ReplyRateRow{}
	replySources := []ReplySourceRow{}
	replyDistances := []ReplyDistanceRow{}
	takenRates := []TakenRateRow{}
	groupOpts := []GroupOption{}

	// ---- Run all independent DB queries concurrently --------------------------------
	var wg sync.WaitGroup

	// §15 raw event counters – errors ignored on purpose (table may not exist yet).
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT '' AS day, event, COALESCE(SUM(count), 0) AS count " +
			"FROM rippling_event_metrics GROUP BY event ORDER BY event").Scan(&totals)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count " +
			"FROM rippling_event_metrics WHERE day >= CURDATE() - INTERVAL 30 DAY " +
			"ORDER BY day DESC, event").Scan(&recent)
	}()

	// §16 tuner hotspots – defensive; empty until PR G ships the table.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, area_type, area_id, " +
			"COALESCE(area_name, '') AS area_name, metric, value, baseline, deviation, direction, severity " +
			"FROM rippling_hotspots WHERE detected_at >= NOW() - INTERVAL 30 DAY " +
			"ORDER BY (severity = 'alert') DESC, ABS(deviation) DESC LIMIT 100").Scan(&hotspots)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT ons_category, max_minutes, COALESCE(rationale, '') AS rationale, " +
			"DATE_FORMAT(proposed_at, '%Y-%m-%d %H:%i') AS proposed_at " +
			"FROM rippling_params WHERE status = 'proposed' ORDER BY ons_category").Scan(&proposed)
	}()

	// §16.1 / §16.2 volume + reach: overall live-metrics from weekly batch rollup.
	// Returns the two most recent weekly periods' overall rows so the dashboard can show a
	// trend. Defensive: returns empty if rippling_live_metrics doesn't exist yet.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, metric, value, sample_size " +
			"FROM rippling_live_metrics " +
			"WHERE stratum_type = 'overall' AND period_type = 'weekly' " +
			"AND period_start >= CURDATE() - INTERVAL 14 DAY " +
			"ORDER BY period_start DESC, metric").Scan(&liveMetrics)
	}()

	// §15 / §16.5 held-reply friction summary.
	// Live aggregate of rippling_held_replies by status, with median hold duration for
	// released rows. Defensive: returns empty if rippling_held_replies doesn't exist yet.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT status, COUNT(*) AS count, " +
			"COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(releasedat, NOW())) / 3600.0), 0) AS median_hold_hours " +
			"FROM rippling_held_replies " +
			"GROUP BY status ORDER BY status").Scan(&heldReplySummary)
	}()

	// §16.3 cross-group reach summary (last 30 days).
	// Uses messages_groups.rippled_in (added by migration 2026_06_18_000004). Measures
	// what fraction of post appearances were rippled in by the engine, and of those, what
	// fraction were approved (not rejected). Defensive: returns zero struct if column absent.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT " +
			"COUNT(*) AS total, " +
			"COALESCE(SUM(rippled_in), 0) AS rippled_in, " +
			"COALESCE(SUM(CASE WHEN rippled_in = 1 AND collection = 'Approved' THEN 1 ELSE 0 END), 0) AS approved_rippled " +
			"FROM messages_groups " +
			"WHERE arrival >= CURDATE() - INTERVAL 30 DAY " +
			"AND deleted = 0").Scan(&cg)
	}()

	// §16.4 timing / capture: latest offline-simulator week.
	// Reads the most recent 'all'-group row from rippling_algorithm_metrics (renamed from
	// ripple_algorithm_metrics by migration 2026_06_18_000002). Returns zero struct if the
	// table is empty or doesn't exist yet.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT DATE_FORMAT(week_start, '%Y-%m-%d') AS week_start, curve, " +
			"pairs_total, pairs_in_time, pairs_late, " +
			"COALESCE(reply_p50_hours, 0) AS reply_p50_hours, " +
			"COALESCE(reply_p75_hours, 0) AS reply_p75_hours " +
			"FROM rippling_algorithm_metrics " +
			"WHERE `group` = 'all' " +
			"ORDER BY week_start DESC LIMIT 1").Scan(&capture)
	}()

	// (1) % of Offers with a reply within 36h, per arrival day.
	// The fr subquery is bounded to the requested window (+ 36h slack) so it doesn't
	// scan the full chat_messages history. The young-cut (arrival < NOW()-36h) is removed
	// because the window end already controls which days are complete.
	// Parallel in structure to query (4) (taken rate) — keep the two in sync.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw(`
			SELECT day,
			       COUNT(*) AS posts,
			       SUM(replied) AS replied,
			       SUM(1 - is_rippled) AS home_posts, -- is_rippled is 0/1 from EXISTS (never NULL)
			       SUM(CASE WHEN is_rippled = 0 THEN replied ELSE 0 END) AS home_replied,
			       SUM(is_rippled) AS ripple_posts,
			       SUM(CASE WHEN is_rippled = 1 THEN replied ELSE 0 END) AS ripple_replied
			FROM (
			    SELECT m.id,
			           DATE_FORMAT(m.arrival, '%Y-%m-%d') AS day,
			           MAX(CASE WHEN fr.first_reply <= m.arrival + INTERVAL 36 HOUR THEN 1 ELSE 0 END) AS replied,
			           EXISTS (SELECT 1 FROM messages_groups mgr
			                   WHERE mgr.msgid = m.id AND mgr.rippled_in = 1 AND mgr.deleted = 0) AS is_rippled
			    FROM messages m
			    JOIN messages_groups mg ON mg.msgid = m.id AND mg.collection = 'Approved' AND mg.deleted = 0`+rateGroup+`
			    LEFT JOIN (
			        SELECT refmsgid, MIN(date) AS first_reply FROM chat_messages
			        WHERE type = 'Interested' AND date >= ? AND date < (? + INTERVAL 36 HOUR) GROUP BY refmsgid
			    ) fr ON fr.refmsgid = m.id
			    WHERE m.type = 'Offer'
			      AND m.arrival >= ? AND m.arrival < ?
			    GROUP BY m.id, m.arrival
			) per_msg
			GROUP BY day
			ORDER BY day DESC`, replyRateArgs(gid, start, end)...).Scan(&replyRates)
	}()

	// (2) Rippling vs home-group replies, per day, from rippling_reply_attribution (captured at
	//     reply time - the only sound attribution, since replying joins the member to the group).
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw(`
			SELECT DATE_FORMAT(rra.replied_at, '%Y-%m-%d') AS day,
			       COUNT(*) AS replies,
			       COALESCE(SUM(rra.was_home_member), 0) AS home
			FROM rippling_reply_attribution rra`+srcGroup+`
			WHERE rra.replied_at >= ? AND rra.replied_at < ?
			GROUP BY DATE_FORMAT(rra.replied_at, '%Y-%m-%d')
			ORDER BY day DESC`, gargs()...).Scan(&replySources)
	}()

	// (3) Median crow-flies distance (km) from post to replier, per reply day. The origin-group
	//     join is added only when filtering (avoids multi-group row inflation in the all view).
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw(`
			SELECT day,
			       MAX(cnt_all) AS replies,
			       AVG(CASE WHEN rn_all IN (FLOOR((cnt_all + 1) / 2), FLOOR((cnt_all + 2) / 2)) THEN dist_km END) AS median_km,
			       MAX(CASE WHEN is_rippled = 0 THEN cnt_coh ELSE 0 END) AS home_replies,
			       AVG(CASE WHEN is_rippled = 0 AND rn_coh IN (FLOOR((cnt_coh + 1) / 2), FLOOR((cnt_coh + 2) / 2)) THEN dist_km END) AS home_median_km,
			       MAX(CASE WHEN is_rippled = 1 THEN cnt_coh ELSE 0 END) AS ripple_replies,
			       AVG(CASE WHEN is_rippled = 1 AND rn_coh IN (FLOOR((cnt_coh + 1) / 2), FLOOR((cnt_coh + 2) / 2)) THEN dist_km END) AS ripple_median_km
			FROM (
			  SELECT day, dist_km, is_rippled,
			         ROW_NUMBER() OVER (PARTITION BY day ORDER BY dist_km)              AS rn_all,
			         COUNT(*)     OVER (PARTITION BY day)                                AS cnt_all,
			         ROW_NUMBER() OVER (PARTITION BY day, is_rippled ORDER BY dist_km)   AS rn_coh,
			         COUNT(*)     OVER (PARTITION BY day, is_rippled)                     AS cnt_coh
			  FROM (
			    SELECT DATE_FORMAT(cm.date, '%Y-%m-%d') AS day,
			           ST_Distance_Sphere(POINT(ml.lng, ml.lat), POINT(ul.lng, ul.lat)) / 1000 AS dist_km,
			           EXISTS (SELECT 1 FROM messages_groups mgr
			                   WHERE mgr.msgid = m.id AND mgr.rippled_in = 1 AND mgr.deleted = 0) AS is_rippled
			    FROM chat_messages cm
			    JOIN messages m   ON m.id = cm.refmsgid AND m.type = 'Offer'`+distGroup+`
			    JOIN locations ml ON ml.id = m.locationid
			    JOIN users u      ON u.id = cm.userid
			    JOIN locations ul ON ul.id = u.lastlocation
			    WHERE cm.type = 'Interested' AND cm.date >= ? AND cm.date < ?
			      AND ml.lat IS NOT NULL AND ul.lat IS NOT NULL
			  ) d
			) r
			GROUP BY day
			ORDER BY day DESC`, gargs()...).Scan(&replyDistances)
	}()

	// (4) Reuse outcome: % of posts (Offers taken / Wanteds received) per arrival day.
	// The outcomes subquery is bounded to the requested window so it doesn't scan all
	// history. The young-cut (arrival < NOW()-7d) is removed; the window end controls
	// which days are complete.
	// Parallel in structure to query (1) (reply rate) — keep the two in sync.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw(`
			SELECT day,
			       COUNT(*) AS posts,
			       SUM(taken) AS taken,
			       SUM(1 - is_rippled) AS home_posts, -- is_rippled is 0/1 from EXISTS (never NULL)
			       SUM(CASE WHEN is_rippled = 0 THEN taken ELSE 0 END) AS home_taken,
			       SUM(is_rippled) AS ripple_posts,
			       SUM(CASE WHEN is_rippled = 1 THEN taken ELSE 0 END) AS ripple_taken
			FROM (
			    SELECT m.id,
			           DATE_FORMAT(m.arrival, '%Y-%m-%d') AS day,
			           MAX(CASE WHEN o.msgid IS NOT NULL THEN 1 ELSE 0 END) AS taken,
			           EXISTS (SELECT 1 FROM messages_groups mgr
			                   WHERE mgr.msgid = m.id AND mgr.rippled_in = 1 AND mgr.deleted = 0) AS is_rippled
			    FROM messages m
			    JOIN messages_groups mg ON mg.msgid = m.id AND mg.collection = 'Approved' AND mg.deleted = 0`+rateGroup+`
			    LEFT JOIN (SELECT DISTINCT msgid FROM messages_outcomes
			               WHERE outcome IN ('Taken','Received') AND timestamp >= ?) o
			           ON o.msgid = m.id
			    WHERE m.type IN ('Offer','Wanted')
			      AND m.arrival >= ? AND m.arrival < ?
			    GROUP BY m.id, m.arrival
			) per_msg
			GROUP BY day
			ORDER BY day DESC`, takenRateArgs(gid, start, end)...).Scan(&takenRates)
	}()

	// Groups whose posts have rippled - the per-group filter options (the experiment groups appear
	// here once they ripple). Defensive: empty while rippling is dark.
	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Raw("SELECT DISTINCT g.id AS id, g.nameshort AS name " +
			"FROM rippling_reach rr " +
			"JOIN messages_groups mg ON mg.msgid = rr.msgid AND mg.rippled_in = 0 AND mg.deleted = 0 " +
			"JOIN `groups` g ON g.id = mg.groupid " +
			"ORDER BY g.nameshort").Scan(&groupOpts)
	}()

	wg.Wait()

	// ---- Post-processing (serial; all goroutines done) --------------------------------

	// Compute derived percentage fields for reply-rate rows (overall + home/ripple cohorts).
	// Days whose arrival is within 36h of now are still maturing (their 36h reply window hasn't
	// fully elapsed) — flag them so the client dashes the tail. Day-granularity is enough for the
	// visual; compare the ISO day string against the cutoff date. This is intentionally cautious:
	// a day may be flagged provisional for a short window around midnight even once its settling
	// window has fully elapsed (errs toward dashing one extra day, which is safe).
	reply36hCutoff := time.Now().Add(-36 * time.Hour).Format("2006-01-02")
	for i := range replyRates {
		if replyRates[i].Posts > 0 {
			replyRates[i].ReplyPct = float64(replyRates[i].Replied) / float64(replyRates[i].Posts) * 100
		}
		if replyRates[i].HomePosts > 0 {
			replyRates[i].HomePct = float64(replyRates[i].HomeReplied) / float64(replyRates[i].HomePosts) * 100
		}
		if replyRates[i].RipplePosts > 0 {
			replyRates[i].RipplePct = float64(replyRates[i].RippleReplied) / float64(replyRates[i].RipplePosts) * 100
		}
		replyRates[i].Provisional = replyRates[i].Day >= reply36hCutoff
	}

	// Compute derived fields for reply-source rows.
	for i := range replySources {
		replySources[i].Ripple = replySources[i].Replies - replySources[i].Home
		if replySources[i].Replies > 0 {
			replySources[i].RipplePct = float64(replySources[i].Ripple) / float64(replySources[i].Replies) * 100
		}
	}

	// Compute derived percentage fields for taken-rate rows (overall + home/ripple cohorts).
	// The provisional flag is intentionally cautious: a day may be flagged provisional for a
	// short window around midnight even once its settling window has fully elapsed (errs toward
	// dashing one extra day, which is safe).
	taken7dCutoff := time.Now().AddDate(0, 0, -7).Format("2006-01-02")
	for i := range takenRates {
		if takenRates[i].Posts > 0 {
			takenRates[i].TakenPct = float64(takenRates[i].Taken) / float64(takenRates[i].Posts) * 100
		}
		if takenRates[i].HomePosts > 0 {
			takenRates[i].HomePct = float64(takenRates[i].HomeTaken) / float64(takenRates[i].HomePosts) * 100
		}
		if takenRates[i].RipplePosts > 0 {
			takenRates[i].RipplePct = float64(takenRates[i].RippleTaken) / float64(takenRates[i].RipplePosts) * 100
		}
		takenRates[i].Provisional = takenRates[i].Day >= taken7dCutoff
	}

	// Assemble the cross-group summary struct from the raw scan.
	crossGroup := CrossGroupSummary{PeriodDays: 30, Total: cg.Total, RippledIn: cg.RippledIn}
	if cg.Total > 0 {
		crossGroup.CrossGroupPct = float64(cg.RippledIn) / float64(cg.Total) * 100
	}
	if cg.RippledIn > 0 {
		crossGroup.ApprovalRate = float64(cg.ApprovedRippled) / float64(cg.RippledIn) * 100
	}

	// Compute capture rate from the offline-simulator summary.
	if capture.PairsTotal > 0 {
		capture.CaptureRate = float64(capture.PairsInTime) / float64(capture.PairsTotal) * 100
	}

	return c.JSON(fiber.Map{
		"totals":                totals,
		"recent":                recent,
		"hotspots":              hotspots,
		"proposed_params":       proposed,
		"live_metrics":          liveMetrics,
		"held_reply_summary":    heldReplySummary,
		"cross_group_summary":   crossGroup,
		"capture_summary":       capture,
		"reply_rate_36h":        replyRates,
		"reply_source_split":    replySources,
		"reply_distance_median": replyDistances,
		"taken_rate":            takenRates,
		"groups":                groupOpts,
		"groupid":               gid,
		"start":                 start,
		"end":                   end,
	})
}
