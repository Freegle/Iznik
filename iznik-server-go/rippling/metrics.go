// Package rippling surfaces the rippling-out live event counters (rippling_event_metrics)
// and §16 rollout-health metrics to sysadmin. Read-only; Support/Admin gated by the route group.
package rippling

import (
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

	// ---- §15 raw event counters -------------------------------------------------------
	// Errors are ignored on purpose: until the rippling event table exists the result is
	// simply empty, so the endpoint never 500s during rollout.
	totals := []EventMetric{}
	db.Raw("SELECT '' AS day, event, COALESCE(SUM(count), 0) AS count " +
		"FROM rippling_event_metrics GROUP BY event ORDER BY event").Scan(&totals)

	recent := []EventMetric{}
	db.Raw("SELECT DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count " +
		"FROM rippling_event_metrics WHERE day >= CURDATE() - INTERVAL 30 DAY " +
		"ORDER BY day DESC, event").Scan(&recent)

	// ---- §16 tuner hotspots and advisory param proposals ------------------------------
	// These tables ship with PR G, so the scans simply return empty until then.
	hotspots := []Hotspot{}
	db.Raw("SELECT DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, area_type, area_id, " +
		"COALESCE(area_name, '') AS area_name, metric, value, baseline, deviation, direction, severity " +
		"FROM rippling_hotspots WHERE detected_at >= NOW() - INTERVAL 30 DAY " +
		"ORDER BY (severity = 'alert') DESC, ABS(deviation) DESC LIMIT 100").Scan(&hotspots)

	proposed := []ProposedParam{}
	db.Raw("SELECT ons_category, max_minutes, COALESCE(rationale, '') AS rationale, " +
		"DATE_FORMAT(proposed_at, '%Y-%m-%d %H:%i') AS proposed_at " +
		"FROM rippling_params WHERE status = 'proposed' ORDER BY ons_category").Scan(&proposed)

	// ---- §16.1 / §16.2 volume + reach: overall live-metrics from weekly batch rollup --
	// Returns the two most recent weekly periods' overall rows so the dashboard can show a
	// trend. Defensive: returns empty if rippling_live_metrics doesn't exist yet.
	liveMetrics := []LiveMetricRow{}
	db.Raw("SELECT DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, metric, value, sample_size " +
		"FROM rippling_live_metrics " +
		"WHERE stratum_type = 'overall' AND period_type = 'weekly' " +
		"AND period_start >= CURDATE() - INTERVAL 14 DAY " +
		"ORDER BY period_start DESC, metric").Scan(&liveMetrics)

	// ---- §15 / §16.5 held-reply friction summary ------------------------------------
	// Live aggregate of rippling_held_replies by status, with median hold duration for
	// released rows. Defensive: returns empty if rippling_held_replies doesn't exist yet.
	heldReplySummary := []HeldReplySummary{}
	db.Raw("SELECT status, COUNT(*) AS count, " +
		"COALESCE(AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(releasedat, NOW())) / 3600.0), 0) AS median_hold_hours " +
		"FROM rippling_held_replies " +
		"GROUP BY status ORDER BY status").Scan(&heldReplySummary)

	// ---- §16.3 cross-group reach summary (last 30 days) -----------------------------
	// Uses messages_groups.rippled_in (added by migration 2026_06_18_000004). Measures
	// what fraction of post appearances were rippled in by the engine, and of those, what
	// fraction were approved (not rejected). Defensive: returns zero struct if column absent.
	type crossGroupRaw struct {
		Total           int64 `gorm:"column:total"`
		RippledIn       int64 `gorm:"column:rippled_in"`
		ApprovedRippled int64 `gorm:"column:approved_rippled"`
	}
	var cg crossGroupRaw
	db.Raw("SELECT " +
		"COUNT(*) AS total, " +
		"COALESCE(SUM(rippled_in), 0) AS rippled_in, " +
		"COALESCE(SUM(CASE WHEN rippled_in = 1 AND collection = 'Approved' THEN 1 ELSE 0 END), 0) AS approved_rippled " +
		"FROM messages_groups " +
		"WHERE arrival >= CURDATE() - INTERVAL 30 DAY " +
		"AND deleted = 0").Scan(&cg)
	crossGroup := CrossGroupSummary{PeriodDays: 30, Total: cg.Total, RippledIn: cg.RippledIn}
	if cg.Total > 0 {
		crossGroup.CrossGroupPct = float64(cg.RippledIn) / float64(cg.Total) * 100
	}
	if cg.RippledIn > 0 {
		crossGroup.ApprovalRate = float64(cg.ApprovedRippled) / float64(cg.RippledIn) * 100
	}

	// ---- §16.4 timing / capture: latest offline-simulator week ----------------------
	// Reads the most recent 'all'-group row from rippling_algorithm_metrics (renamed from
	// ripple_algorithm_metrics by migration 2026_06_18_000002). Returns zero struct if the
	// table is empty or doesn't exist yet.
	var capture CaptureSummary
	db.Raw("SELECT DATE_FORMAT(week_start, '%Y-%m-%d') AS week_start, curve, " +
		"pairs_total, pairs_in_time, pairs_late, " +
		"COALESCE(reply_p50_hours, 0) AS reply_p50_hours, " +
		"COALESCE(reply_p75_hours, 0) AS reply_p75_hours " +
		"FROM rippling_algorithm_metrics " +
		"WHERE `group` = 'all' " +
		"ORDER BY week_start DESC LIMIT 1").Scan(&capture)
	if capture.PairsTotal > 0 {
		capture.CaptureRate = float64(capture.PairsInTime) / float64(capture.PairsTotal) * 100
	}

	return c.JSON(fiber.Map{
		"totals":              totals,
		"recent":              recent,
		"hotspots":            hotspots,
		"proposed_params":     proposed,
		"live_metrics":        liveMetrics,
		"held_reply_summary":  heldReplySummary,
		"cross_group_summary": crossGroup,
		"capture_summary":     capture,
	})
}
