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

	return c.JSON(fiber.Map{
		"totals": totals,
		"recent": recent,
	})
}
