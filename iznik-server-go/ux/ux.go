// Package ux serves the read side of UX telemetry: the daily rollups the SysAdmin UX tab charts,
// plus the sampled session timelines from Loki. The contract is
// docs/developers/reference/ux-telemetry.md; the tables are written by `php artisan ux:rollup-daily`.
package ux

import (
	"fmt"
	"math"
	"regexp"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

const (
	dateFormat = "2006-01-02"
	// maxRangeDays keeps one request from scanning years of rollup rows.
	maxRangeDays = 366
	// maxFieldRows bounds the fields list, which is otherwise one row per (flow, step, field).
	maxFieldRows = 500
	// frictionTop is how many components the friction list returns.
	frictionTop = 20
)

// flowRe and stepRe are what flow and step names look like: letters, digits and a few separators.
// Checked on input because they go into queries (parameterised) and a Loki selector.
var flowRe = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,64}$`)

type period struct{ start, end time.Time }

func (p period) startStr() string { return p.start.Format(dateFormat) }
func (p period) endStr() string   { return p.end.Format(dateFormat) }

// request is what every handler works from after the common checks.
type request struct {
	cur, prev period
	flow      string
	filter    flowFilter // parsed in serve(); a bad value was already a 400
}

// scope is what a handler passes to rangeQuery for a table that has a flow column and can be
// filtered by device and sign-in.
func (r request) scope() scope { return scope{flow: r.flow, filter: r.filter} }

// filterOnly is the scope for friction, whose table has no flow column.
func (r request) filterOnly() scope { return scope{filter: r.filter} }

// requireSupport applies the Support/Admin gate the other sysadmin tabs use.
func requireSupport(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
	}
	return nil
}

// parsePeriods reads start and end (YYYY-MM-DD, both inclusive). They default to the last seven
// days ending yesterday, since today has no rollup yet. previous is the equal-length period
// immediately before.
func parsePeriods(c *fiber.Ctx) (period, period, error) {
	var cur period
	var err error

	yesterday := time.Now().UTC().AddDate(0, 0, -1)
	yesterday = time.Date(yesterday.Year(), yesterday.Month(), yesterday.Day(), 0, 0, 0, 0, time.UTC)

	cur.end = yesterday
	if s := c.Query("end"); s != "" {
		if cur.end, err = time.Parse(dateFormat, s); err != nil {
			return cur, cur, fiber.NewError(fiber.StatusBadRequest, "end must be YYYY-MM-DD")
		}
	}
	cur.start = cur.end.AddDate(0, 0, -6)
	if s := c.Query("start"); s != "" {
		if cur.start, err = time.Parse(dateFormat, s); err != nil {
			return cur, cur, fiber.NewError(fiber.StatusBadRequest, "start must be YYYY-MM-DD")
		}
	}
	if cur.start.After(cur.end) {
		return cur, cur, fiber.NewError(fiber.StatusBadRequest, "start is after end")
	}

	days := int(cur.end.Sub(cur.start).Hours()/24) + 1
	if days > maxRangeDays {
		return cur, cur, fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("range is limited to %d days", maxRangeDays))
	}

	var prev period
	prev.end = cur.start.AddDate(0, 0, -1)
	prev.start = prev.end.AddDate(0, 0, -(days - 1))
	return cur, prev, nil
}

func parseFlow(c *fiber.Ctx) (string, error) {
	flow := c.Query("flow")
	if flow != "" && !flowRe.MatchString(flow) {
		return "", fiber.NewError(fiber.StatusBadRequest, "invalid flow")
	}
	return flow, nil
}

// serve is the shared shell: permission, input, then the cached computation.
func serve(name string, build func(r request) (interface{}, error)) fiber.Handler {
	return func(c *fiber.Ctx) error {
		if err := requireSupport(c); err != nil {
			return err
		}
		cur, prev, err := parsePeriods(c)
		if err != nil {
			return err
		}
		flow, err := parseFlow(c)
		if err != nil {
			return err
		}
		filter, err := parseFilter(c)
		if err != nil {
			return err
		}
		r := request{cur: cur, prev: prev, flow: flow, filter: filter}

		// The key carries every filter the request can send, including one this endpoint ignores:
		// without them the first caller's answer would be served to everyone for ten minutes
		// whatever they asked for. The echo in the answer (Applied) differs by what was asked.
		key := fmt.Sprintf("%s|%s|%s|%s|%s|%s", name, cur.startStr(), cur.endStr(), flow, filter.device, filter.signedIn)
		out, err := cached(key, func() (interface{}, bool, error) {
			v, err := build(r)
			return v, err == nil, err
		})
		if err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Could not read UX rollups")
		}
		return c.JSON(out)
	}
}

// rangeQuery starts a query on a rollup table restricted to the period, then to s.flow when it is
// set, then to the filters this table honours (flowFilter.apply).
func rangeQuery(table string, p period, s scope) *gorm.DB {
	q := database.DBConn.Table(table).Where("`date` BETWEEN ? AND ?", p.startStr(), p.endStr())
	if s.flow != "" {
		q = q.Where("flow = ?", s.flow)
	}
	return s.filter.apply(q, table)
}

// weighted is a SQL fragment: the mean of col weighted by w over the rows where col is not NULL.
// The rollup stores a percentile per row, and the percentile of a union of rows is not recoverable
// from the percentiles of its parts, so this is an APPROXIMATION: each row's p50 or p90 counts in
// proportion to how many entered (or started) in it. It is exact when the rows agree and close
// when they are similar sizes, which is what comparing a week with the week before needs.
func weighted(col, w string) string {
	return fmt.Sprintf("CAST(ROUND(SUM(CASE WHEN %[1]s IS NOT NULL THEN %[1]s * %[2]s END) / NULLIF(SUM(CASE WHEN %[1]s IS NOT NULL THEN %[2]s END), 0)) AS SIGNED)", col, w)
}

// knownFlows is the seven funnels members can be in. It matches FlowName in
// iznik-nuxt3/composables/useFlowTelemetry.js, which is where a flow is named first.
var knownFlows = map[string]bool{
	"give":         true,
	"ask":          true,
	"reply":        true,
	"login":        true,
	"chat_promise": true,
	"chat_outcome": true,
	"settings":     true,
}

// timeFormat is how a stored time is written in a response: UTC, to the second, no zone.
const timeFormat = "2006-01-02 15:04:05"

// tstr formats a nullable time for a response. A missing time stays null.
func tstr(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.UTC().Format(timeFormat)
	return &s
}

// clip trims s and cuts it to at most n characters. It counts characters, not bytes, so a cut never
// splits one.
func clip(s string, n int) string {
	r := []rune(strings.TrimSpace(s))
	if len(r) > n {
		return string(r[:n])
	}
	return string(r)
}

// share is part over whole to three places, 0 for an empty whole.
func share(part, whole int64) float64 {
	if whole <= 0 {
		return 0
	}
	return math.Round(float64(part)/float64(whole)*1000) / 1000
}
