// Package rippling surfaces the rippling-out live event counters (rippling_event_metrics)
// and §16 rollout-health metrics to sysadmin. Read-only; Support/Admin gated by the route group.
package rippling

import (
	"context"
	"errors"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// MetricsDeadline caps the DB work behind /rippling/metrics. Every section reads a small
// rippling-owned table, so a healthy request is orders of magnitude inside this; it exists to
// make sure a pathological one fails fast and visibly (see the `degraded` response field)
// instead of holding queries open behind a response the gateway has already given up on.
// A var rather than a const so tests can shrink it to prove the degraded path.
var MetricsDeadline = 20 * time.Second

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

// HeldBySource breaks held replies down by origin channel (email / tn / web). The web reply-hold
// (in-app replies outside reach, previously rejected with 403) records source='web', so this
// shows how many replies the web hold is now capturing vs the email/TN path.
type HeldBySource struct {
	Source string `json:"source" gorm:"column:source"`
	Status string `json:"status" gorm:"column:status"`
	Count  int64  `json:"count"  gorm:"column:count"`
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

// ReplySourceRow is the daily split of Interested replies by attribution channel (the graded
// ladder captured at reply time in rippling_reply_attribution - see rippling/attribution.go):
// home (established origin-group member), ripple_notified (we mailed them the post via the
// ripple), ripple_group (saw it in their own group because it rippled in), ripple_join (in the
// origin group only because an earlier ripple auto-joined them there), ripple_reach (Browse
// exposure that existed only because the reach extended to them), organic_local (non-member
// who'd have seen it in Browse anyway), unknown (search / deep link / no location).
// Rows the backfill hasn't derived yet are bucketed live off the legacy was_home_member bit,
// qualified by the surviving membership's provenance. Ripple/RipplePct is the headline: channels
// that are DEFINITELY rippling - unlike the old replies-minus-home number, unknown is not
// credited to rippling.
type ReplySourceRow struct {
	Day     string `json:"day"     gorm:"column:day"`
	Replies int    `json:"replies" gorm:"column:replies"`
	Home    int    `json:"home"    gorm:"column:home"`

	RippleNotified int `json:"ripple_notified" gorm:"column:ripple_notified"`
	RippleGroup    int `json:"ripple_group"    gorm:"column:ripple_group"`
	RippleJoin     int `json:"ripple_join"     gorm:"column:ripple_join"`
	RippleReach    int `json:"ripple_reach"    gorm:"column:ripple_reach"`
	OrganicLocal   int `json:"organic_local"   gorm:"column:organic_local"`
	Unknown        int `json:"unknown"         gorm:"column:unknown"`

	Ripple    int     `json:"ripple"`     // computed in Go (notified + group + join + reach)
	RipplePct float64 `json:"ripple_pct"` // computed in Go
}

// ClientSourceCount is one row of the client-reported reply-surface summary (browse, search,
// message_page, notification, ...). Advisory: client-supplied, so it cross-checks the
// server-derived attribution rather than feeding it.
type ClientSourceCount struct {
	Source string `json:"source" gorm:"column:source"`
	Count  int    `json:"count"  gorm:"column:count"`
}

// GroupOption is one entry in the per-group filter: a group whose posts rippled inside the
// requested window, so its reply-source split is worth viewing on its own.
type GroupOption struct {
	ID   uint64 `json:"id"   gorm:"column:id"`
	Name string `json:"name" gorm:"column:name"`
}

// ReplySourceSplitSQL builds the per-day attribution-channel query over
// rippling_reply_attribution. Exported (and parameterised) so the legacy variant - which only
// runs against an unmigrated production DB - stays testable against a migrated test DB.
//
//   - wide: read the attribution channel the reply handler derived at capture time. Rows
//     without one (pre-migration rows the backfill hasn't visited yet) fall back PER ROW to
//     the same live derivation as the legacy variant - otherwise the window between the
//     migration landing and the backfill running would read as a misleading zero-ripple
//     chart (every attribution NULL folding to home/unknown).
//   - legacy (graded columns not yet migrated): derive the durable channels live from the
//     notified ledger, rippled-group memberships and origin-membership provenance. Correct for
//     home/notified/group/join; the location channels (ripple_reach/organic_local) are not
//     derivable retrospectively (locations drift, polygons grow) so they read 0 and those
//     replies sit in unknown.
//
// srcGroup is the optional origin-group scoping JOIN (aliases rra); it takes one bind arg
// before the two replied_at window args.
func ReplySourceSplitSQL(wide bool, srcGroup string) string {
	return `
		SELECT day,
		       COUNT(*) AS replies,
		       SUM(bucket = 'home') AS home,
		       SUM(bucket = 'ripple_notified') AS ripple_notified,
		       SUM(bucket = 'ripple_group') AS ripple_group,
		       SUM(bucket = 'ripple_join') AS ripple_join,
		       SUM(bucket = 'ripple_reach') AS ripple_reach,
		       SUM(bucket = 'organic_local') AS organic_local,
		       SUM(bucket = 'unknown') AS unknown
		FROM ` + ReplySourceInnerFrom(wide, srcGroup) + `
		GROUP BY day
		ORDER BY day DESC`
}

// ReplySourceInnerFrom builds the day+bucket derived-table subquery shared by
// ReplySourceSplitSQL's raw-SQL form (legacy/unmigrated-DB callers) and the
// GORM chain at Metrics' reply_source_split section (ORM migration site
// 568a5645fba7): the attribution-channel CASE expression lives in exactly
// this one place either way, so pulling the outer aggregation out into a
// real .Table()/.Select()/.Group()/.Order() chain does not duplicate it -
// see ormharness/bareexists_test.go's distinction between a legitimate
// .Table() subquery (this) and relocating a whole statement into .Select()
// (not this). Master's ripple_join derivation lives here for the same reason.
func ReplySourceInnerFrom(wide bool, srcGroup string) string {
	// "The only origin-group membership backing this row is one rippling created" - the frozen
	// was_home_member bit on a legacy row cannot tell home from ripple_join, because the capture
	// that wrote it did not look at membership provenance. Re-derived here from the surviving
	// memberships: a ripple-created one present AND no ordinary one. When BOTH are absent (the
	// member has left since) this is false and the frozen bit's answer of home stands - decay
	// must not silently demote a genuine home reply.
	rippleJoinOnly := `(EXISTS(SELECT 1 FROM messages_groups mgj
	                   INNER JOIN memberships memj ON memj.groupid = mgj.groupid
	                     AND memj.userid = rra.userid AND memj.collection = 'Approved'
	                     AND memj.added < mgj.arrival AND memj.rippled = 1
	                   WHERE mgj.msgid = rra.msgid AND mgj.rippled_in = 0 AND mgj.deleted = 0)
	              AND NOT EXISTS(SELECT 1 FROM messages_groups mgo
	                   INNER JOIN memberships memo ON memo.groupid = mgo.groupid
	                     AND memo.userid = rra.userid AND memo.collection = 'Approved'
	                     AND memo.added < mgo.arrival AND memo.rippled = 0
	                   WHERE mgo.msgid = rra.msgid AND mgo.rippled_in = 0 AND mgo.deleted = 0))`
	derive := `CASE
	       WHEN rra.was_home_member = 1 AND NOT ` + rippleJoinOnly + ` THEN 'home'
	       WHEN EXISTS(SELECT 1 FROM rippling_reach_notified rrn
	                   WHERE rrn.msgid = rra.msgid AND rrn.userid = rra.userid
	                     AND rrn.notified_at <= rra.replied_at) THEN 'ripple_notified'
	       WHEN EXISTS(SELECT 1 FROM messages_groups mgr
	                   INNER JOIN memberships mem ON mem.groupid = mgr.groupid
	                     AND mem.userid = rra.userid AND mem.collection = 'Approved'
	                     AND mem.added < mgr.arrival
	                   WHERE mgr.msgid = rra.msgid AND mgr.rippled_in = 1
	                     AND mgr.deleted = 0 AND mgr.arrival <= rra.replied_at) THEN 'ripple_group'
	       WHEN ` + rippleJoinOnly + ` THEN 'ripple_join'
	       ELSE 'unknown'
	       END`
	bucket := derive
	if wide {
		bucket = "COALESCE(rra.attribution, " + derive + ")"
	}
	return `(
		    SELECT DATE_FORMAT(rra.replied_at, '%Y-%m-%d') AS day,
		           ` + bucket + ` AS bucket
		    FROM rippling_reply_attribution rra` + srcGroup + `
		    WHERE rra.replied_at >= ? AND rra.replied_at < ?
		) b`
}

// captureFromCached holds the live-capture boundary once we have found it. Guarded by
// captureFromMu; empty means "not found yet", never "known to be none".
var captureFromMu sync.RWMutex
var captureFromCached string

// attributionCaptureFrom returns the first day (YYYY-MM-DD) carrying evidence that only the
// reply-time capture writes - location containment or a client-reported surface, which the
// backfill never sets. The dashboard draws it as a boundary on the attribution chart: before it
// the location channels are structurally zero (those replies sit in unknown), after it the full
// split applies. Deliberately unscoped by ?groupid= - it marks a deploy moment, not a per-group
// property.
//
// Cached for the life of the process, because the query cannot use an index: it ORs three
// nullable columns, so it full-scans rippling_reply_attribution - a table that grows with every
// reply, and the next thing here that would have crept past the gateway timeout. Caching is safe
// because the answer cannot change once found: it is a MIN over an append-only table whose new
// rows are always later, and an existing row never gains capture evidence afterwards.
//
// An empty answer is NOT cached - it means capture has not written anything yet, so the boundary
// is still to come and must be looked for again.
func attributionCaptureFrom(db *gorm.DB) (string, error) {
	if cached := cachedCaptureFrom(); cached != "" {
		return cached, nil
	}

	var day string
	// ORM migration site 8240fc74654f (wave 5).
	err := db.Table("rippling_reply_attribution").
		Select("COALESCE(DATE_FORMAT(MIN(replied_at), '%Y-%m-%d'), '')").
		Where("in_origin_catchment IS NOT NULL OR in_reach IS NOT NULL OR client_source IS NOT NULL").
		Scan(&day).Error
	if err != nil {
		return "", err
	}
	rememberCaptureFrom(day)
	return day, nil
}

func cachedCaptureFrom() string {
	captureFromMu.RLock()
	defer captureFromMu.RUnlock()
	return captureFromCached
}

// rememberCaptureFrom keeps a found boundary for the rest of the process. An empty day is
// deliberately not remembered: it means capture has written nothing yet, so the boundary is still
// to come and caching it would leave the chart unmarked until the next restart.
func rememberCaptureFrom(day string) {
	if day == "" {
		return
	}
	captureFromMu.Lock()
	defer captureFromMu.Unlock()
	captureFromCached = day
}

// Metrics returns the rippling-out event counters plus the §15/§16 rollout-health metrics.
// Events: reply_blocked (#2), held/released/taken_gone (#3), secondary_reject (#6),
// immediate_mailed (#0), rippled_in (#6). Support/Admin only.
//
// §16 fields (all defensive — empty/zero when source tables are not yet populated):
//   - live_metrics: volume_posts p50/p90 + secondary_reject_rate from the weekly batch rollup.
//   - held_reply_summary: counts by status + median hold duration (§15 friction).
//   - capture_summary: latest offline-simulator week for timing / capture rate (§16.4).
//   - reply_source_split: per-day reply attribution channels (§16.3 cross-group reach).
//
// EVERY query here reads a small rippling-owned table (or a window-bounded slice of
// rippling_reply_attribution), so the whole endpoint returns in well under the production
// gateway's timeout. That is a constraint, not an accident: the per-day reply-rate,
// replies-per-post, reply-distance, taken-rate and 30-day cross-group KPIs that used to be
// computed here each scanned messages_groups/chat_messages over the window, and once rippling
// scaled up (late June 2026 — 75% of a 30-day messages_groups slice is now rippled-in rows) they
// took 40-190s EACH on production. The gateway killed the request, and its 504 carries no
// Access-Control-Allow-Origin header, so the dashboard reported a misleading CORS error while
// the abandoned queries kept running and the client retried on top of them.
//
// They are gone rather than split behind their own endpoints because nothing read them: the old
// ModSysAdminRippling dashboard was retired in 6982b1ee3 (8 Jul 2026) and the equivalent
// reply-rate / taken-rate / mean-replies KPIs now come from /rippling/analytics, which anchors on
// rippling_reach instead of scanning messages_groups. Splitting would not have helped anyway —
// each individual query was already over the gateway timeout on its own.
//
// If a heavyweight KPI is ever wanted back here, it needs the /rippling/analytics treatment (a
// selective anchor table) or client-driven chunking like AnalyticsDriveTimes — not a bigger
// timeout.
//
// @Router /rippling/metrics [get]
// @Summary Rippling-out live event counters and §16 rollout-health metrics (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Support or Admin role required"
func Metrics(c *fiber.Ctx) error {
	// Hard deadline on the DB work, comfortably inside the production gateway's timeout, so this
	// endpoint always answers rather than leaving queries grinding behind a request that has
	// already been abandoned. It cannot be left to the request context alone: fasthttp closes
	// RequestCtx.Done() only when the SERVER shuts down, never on a client disconnect (see the
	// note on RequestCtx.Err in fasthttp/server.go), so a client that gives up cancels nothing.
	// That is how one slow load became a pile-up - the dashboard retried, and each retry stacked
	// another full set of queries on top of the ones still running.
	ctx, cancel := context.WithTimeout(c.Context(), MetricsDeadline)
	defer cancel()
	db := database.DBConn.WithContext(ctx)

	// Optional ?groupid= scopes the reply-source split to replies on posts ORIGINATING in that
	// group (its rippled_in=0 messages_groups row), so results read per place - dense Croydon
	// won't look like rural Ribble Valley. 0 = all groups. Each scoped query takes one gid arg.
	gid := c.QueryInt("groupid", 0)
	// Optional ?start= & ?end= bound the windowed sections; default to the last 30 days.
	// This is what lets you read a group's before vs after rippling went on.
	start := c.Query("start")
	end := c.Query("end")
	if start == "" {
		start = time.Now().AddDate(0, 0, -30).Format("2006-01-02 15:04:05")
	}
	if end == "" {
		end = time.Now().Format("2006-01-02 15:04:05")
	}
	// Whether the graded-attribution columns exist yet (production may lag the migration):
	// picks the reply-source query variant and is surfaced to the dashboard so it can note
	// that the location-based channels are pending. Deliberately NOT on the deadline-bound
	// handle: it is a one-off information_schema lookup whose answer is cached for the life of
	// the process, so letting a deadline make it fail would stick this API on the legacy variant
	// until the next restart.
	attributionWide := AttributionSchemaReady(database.DBConn)
	srcGroup := ""
	if gid > 0 {
		srcGroup = " JOIN messages_groups mg ON mg.msgid = rra.msgid AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
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

	// ---- Declare all result variables up front so goroutines can capture them --------
	totals := []EventMetric{}
	recent := []EventMetric{}
	hotspots := []Hotspot{}
	proposed := []ProposedParam{}
	liveMetrics := []LiveMetricRow{}
	heldReplySummary := []HeldReplySummary{}
	heldBySource := []HeldBySource{}
	var capture CaptureSummary
	replySources := []ReplySourceRow{}
	clientSources := []ClientSourceCount{}
	captureFrom := ""
	groupOpts := []GroupOption{}

	// ---- Run all independent DB queries concurrently --------------------------------
	// A section's query error is ignored on purpose: a table or column may not exist yet on a DB
	// that lags the migrations, and the panel simply omits that piece. The one error worth
	// reporting is the deadline - "we gave up" must not be served as an empty "nothing to show",
	// so those sections are named in `degraded` and the dashboard says so.
	var wg sync.WaitGroup
	var mu sync.Mutex
	degraded := []string{}
	section := func(name string, run func() error) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			err := run()
			if errors.Is(err, context.DeadlineExceeded) || errors.Is(err, context.Canceled) {
				mu.Lock()
				degraded = append(degraded, name)
				mu.Unlock()
			}
		}()
	}

	// §15 raw event counters.
	section("totals", func() error {
		// ORM migration site 7b13019b71cf (wave 1).
		return db.Table("rippling_event_metrics").
			Select("'' AS day, event, COALESCE(SUM(count), 0) AS count").
			Group("event").
			Order("event").
			Scan(&totals).Error
	})

	section("recent", func() error {
		// ORM migration site bd7c7b0064fb (wave 5).
		return db.Table("rippling_event_metrics").
			Select("DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count").
			Where("day >= CURDATE() - INTERVAL 30 DAY").
			Order("day DESC, event").
			Scan(&recent).Error
	})

	// §16 tuner hotspots – defensive; empty until PR G ships the table.
	section("hotspots", func() error {
		// ORM migration site 8aaa3043bf0c (wave 5).
		return db.Table("rippling_hotspots").
			Select("DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, area_type, area_id, COALESCE(area_name, '') AS area_name, metric, value, baseline, deviation, direction, severity").
			Where("detected_at >= NOW() - INTERVAL 30 DAY").
			Order("(severity = 'alert') DESC, ABS(deviation) DESC").
			Limit(100).
			Scan(&hotspots).Error
	})

	section("proposed_params", func() error {
		// ORM migration site d0a9e5f17cff (wave 5).
		return db.Table("rippling_params").
			Select("ons_category, max_minutes, COALESCE(rationale, '') AS rationale, DATE_FORMAT(proposed_at, '%Y-%m-%d %H:%i') AS proposed_at").
			Where("status = 'proposed'").
			Order("ons_category").
			Scan(&proposed).Error
	})

	// §16.1 / §16.2 volume + reach: overall live-metrics from weekly batch rollup.
	// Returns the two most recent weekly periods' overall rows so the dashboard can show a
	// trend. Defensive: returns empty if rippling_live_metrics doesn't exist yet.
	section("live_metrics", func() error {
		// ORM migration site 72175873186c (wave 5).
		return db.Table("rippling_live_metrics").
			Select("DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, metric, value, sample_size").
			Where("stratum_type = 'overall' AND period_type = 'weekly' AND period_start >= CURDATE() - INTERVAL 14 DAY").
			Order("period_start DESC, metric").
			Scan(&liveMetrics).Error
	})

	// §15 / §16.5 held-reply friction summary.
	// Live aggregate of rippling_held_replies by status, with median hold duration for
	// released rows. Defensive: returns empty if rippling_held_replies doesn't exist yet.
	section("held_reply_summary", func() error {
		// ORM migration site 7059261a513c (wave 5).
		return db.Table("rippling_held_replies").
			Select("status, COUNT(*) AS count, COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(releasedat, NOW())) / 3600.0), 0) AS median_hold_hours").
			Group("status").
			Order("status").
			Scan(&heldReplySummary).Error
	})

	// Held replies broken down by origin channel (email / tn / web). Defensive: the `source`
	// column is added by migration 2026_07_08_000001 — before it runs the query errors and the
	// slice stays empty (the panel just omits the breakdown), which is fine.
	section("held_reply_by_source", func() error {
		// ORM migration site 7a72ebd3ef4b (wave 1).
		return db.Table("rippling_held_replies").
			Select("source, status, COUNT(*) AS count").
			Group("source, status").
			Order("source, status").
			Scan(&heldBySource).Error
	})

	// §16.4 timing / capture: latest offline-simulator week.
	// Reads the most recent 'all'-group row from rippling_algorithm_metrics (renamed from
	// ripple_algorithm_metrics by migration 2026_06_18_000002). Returns zero struct if the
	// table is empty or doesn't exist yet.
	section("capture_summary", func() error {
		// ORM migration site 939fde07a522 (wave 5).
		return db.Table("rippling_algorithm_metrics").
			Select("DATE_FORMAT(week_start, '%Y-%m-%d') AS week_start, curve, pairs_total, pairs_in_time, pairs_late, COALESCE(reply_p50_hours, 0) AS reply_p50_hours, COALESCE(reply_p75_hours, 0) AS reply_p75_hours").
			Where("`group` = 'all'").
			Order("week_start DESC").
			Limit(1).
			Scan(&capture).Error
	})

	// (1) Reply attribution channels, per day, from rippling_reply_attribution (captured at
	//     reply time - the only sound attribution, since replying joins the member to the group).
	//     Two variants sharing one output shape:
	//     - wide: read the attribution channel the Go reply handler derived at capture time.
	//       Rows the backfill hasn't visited (attribution NULL) are bucketed live off the legacy
	//       was_home_member bit, qualified by the surviving membership's provenance so a
	//       ripple-created auto-join reads as ripple_join rather than home.
	//     - legacy (graded columns not yet migrated, e.g. production before the deploy): derive
	//       the durable channels live from the notified ledger and rippled-group memberships.
	//       Correct for notified/group/home; the location channels (ripple_reach/organic_local)
	//       are not derivable retrospectively (locations drift, polygons grow) so they read 0
	//       and those replies sit in unknown - attribution_channels_available tells the
	//       dashboard to say so.
	// ORM migration site 568a5645fba7 (research review). The attribution-
	// channel CASE expression is built once, in ReplySourceInnerFrom, and
	// shared by ReplySourceSplitSQL's raw-SQL form (rippling/metrics_test.go
	// exercises that function directly) and this GORM chain - so converting
	// the call site does not duplicate the tested logic, only moves the
	// OUTER aggregation (which has no logic of its own beyond fixed column
	// names) into real Select/Group/Order clauses. .Table() holds only the
	// derived-table subquery, a documented legitimate use of Table() for a
	// FROM-clause subquery - not the whole statement relocated into
	// .Select() (see ormharness/bareexists_test.go).
	section("reply_source_split", func() error {
		return db.Table(ReplySourceInnerFrom(attributionWide, srcGroup), gargs()...).
			Select("day, COUNT(*) AS replies, SUM(bucket = 'home') AS home, SUM(bucket = 'ripple_notified') AS ripple_notified, SUM(bucket = 'ripple_group') AS ripple_group, SUM(bucket = 'ripple_join') AS ripple_join, SUM(bucket = 'ripple_reach') AS ripple_reach, SUM(bucket = 'organic_local') AS organic_local, SUM(bucket = 'unknown') AS unknown").
			Group("day").
			Order("day DESC").
			Scan(&replySources).Error
	})

	// (1b) Client-reported reply surfaces over the same window (wide schema only - the column
	//      arrives with the graded-attribution migration). Advisory cross-check of (1).
	//
	// ORM migration site 10ee37c98574 (Tier 3 keep-raw review). srcGroup is
	// the only toggle reachable here (this section only runs when
	// attributionWide is true) - 2 possible rendered forms, both declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_10ee37c98574
	// (iznik-server-go/test).
	section("client_source_summary", func() error {
		if !attributionWide {
			return nil
		}
		// srcGroup's own "mg.groupid = ?" placeholder (present only when
		// gid>0) binds to the Table() expression it lives in, not to the
		// WHERE clause below - unlike gargs()'s flat ordering for db.Raw,
		// each GORM clause fragment binds only its own args.
		var tableArgs []interface{}
		if gid > 0 {
			tableArgs = []interface{}{gid}
		}
		return db.Table("rippling_reply_attribution rra"+srcGroup, tableArgs...).
			Select("COALESCE(rra.client_source, '(not reported)') AS source, COUNT(*) AS count").
			Where("rra.replied_at >= ? AND rra.replied_at < ?", start, end).
			Group("source").Order("count DESC").Scan(&clientSources).Error
	})

	// (1c) When did LIVE capture start? See attributionCaptureFrom - answered from cache after
	//      the first time, because the query behind it cannot use an index.
	section("attribution_capture_from", func() error {
		if !attributionWide {
			return nil
		}
		var err error
		captureFrom, err = attributionCaptureFrom(db)
		return err
	})

	// (2) Groups whose posts rippled inside the window - the ?groupid= filter options.
	// Bounded by the window (rippling_reach.created_at, its clustered-by-time column) rather than
	// scanning every reach row ever written: the list is a filter for the windowed sections above,
	// so groups that did not ripple in the window have nothing to filter to. Defensive: empty
	// while rippling is dark.
	section("groups", func() error {
		// ORM migration site a046c8fa9413 (wave 4).
		return db.Table("rippling_reach rr").
			Select("DISTINCT g.id AS id, g.nameshort AS name").
			Joins("JOIN messages_groups mg ON mg.msgid = rr.msgid AND mg.rippled_in = 0 AND mg.deleted = 0").
			Joins("JOIN `groups` g ON g.id = mg.groupid").
			Where("rr.created_at >= ? AND rr.created_at < ?", start, end).
			Order("g.nameshort").
			Scan(&groupOpts).Error
	})

	wg.Wait()

	// ---- Post-processing (serial; all goroutines done) --------------------------------

	// Compute derived fields for reply-source rows. The headline ripple share counts only the
	// channels that are DEFINITELY rippling - unknown is not credited (the old replies-minus-home
	// number silently attributed every un-evidenced reply to rippling).
	for i := range replySources {
		replySources[i].Ripple = replySources[i].RippleNotified + replySources[i].RippleGroup +
			replySources[i].RippleJoin + replySources[i].RippleReach
		if replySources[i].Replies > 0 {
			replySources[i].RipplePct = float64(replySources[i].Ripple) / float64(replySources[i].Replies) * 100
		}
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
		"held_reply_by_source":  heldBySource,
		"capture_summary":       capture,
		"reply_source_split":    replySources,
		"client_source_summary": clientSources,
		// False until the graded-attribution migration has run on this DB: the location
		// channels (ripple_reach/organic_local) read 0 and client sources are absent.
		"attribution_channels_available": attributionWide,
		// First day with reply-time-captured evidence ('' until the capture deploy has seen
		// a reply): the boundary the dashboard marks on the attribution chart.
		"attribution_capture_from": captureFrom,
		"groups":                   groupOpts,
		"groupid":                  gid,
		"start":                    start,
		"end":                      end,
		// Sections whose query hit the deadline and so came back empty because we gave up, not
		// because there is nothing to show. Normally empty; the dashboard says so when it isn't.
		"degraded": degraded,
	})
}
