// Package rippling surfaces the rippling-out live event counters (rippling_event_metrics)
// to sysadmin (design §15/§16). Read-only; Support/Admin gated by the route group.
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

// Metrics returns the rippling-out event counters: per-event totals and the last 30 days
// broken down by day. Events: reply_blocked (#2), held/released/taken_gone (#3),
// secondary_reject (#6), immediate_mailed (#0), rippled_in (#6). Support/Admin only.
//
// @Router /rippling/metrics [get]
// @Summary Rippling-out live event counters (sysadmin)
// @Tags rippling
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Support or Admin role required"
func Metrics(c *fiber.Ctx) error {
	db := database.DBConn

	// Errors are ignored on purpose: until the rippling event table exists the result is
	// simply empty, so the endpoint never 500s during rollout.
	totals := []EventMetric{}
	db.Raw("SELECT '' AS day, event, COALESCE(SUM(count), 0) AS count " +
		"FROM rippling_event_metrics GROUP BY event ORDER BY event").Scan(&totals)

	recent := []EventMetric{}
	db.Raw("SELECT DATE_FORMAT(day, '%Y-%m-%d') AS day, event, count " +
		"FROM rippling_event_metrics WHERE day >= CURDATE() - INTERVAL 30 DAY " +
		"ORDER BY day DESC, event").Scan(&recent)

	// Geographically-unusual hotspots and advisory param proposals from the §16 tuner. These
	// tables ship with PR G, so the scans simply return empty until then.
	hotspots := []Hotspot{}
	db.Raw("SELECT DATE_FORMAT(period_start, '%Y-%m-%d') AS period_start, area_type, area_id, " +
		"COALESCE(area_name, '') AS area_name, metric, value, baseline, deviation, direction, severity " +
		"FROM rippling_hotspots WHERE detected_at >= NOW() - INTERVAL 30 DAY " +
		"ORDER BY (severity = 'alert') DESC, ABS(deviation) DESC LIMIT 100").Scan(&hotspots)

	proposed := []ProposedParam{}
	db.Raw("SELECT ons_category, max_minutes, COALESCE(rationale, '') AS rationale, " +
		"DATE_FORMAT(proposed_at, '%Y-%m-%d %H:%i') AS proposed_at " +
		"FROM rippling_params WHERE status = 'proposed' ORDER BY ons_category").Scan(&proposed)

	return c.JSON(fiber.Map{
		"totals":          totals,
		"recent":          recent,
		"hotspots":        hotspots,
		"proposed_params": proposed,
	})
}
