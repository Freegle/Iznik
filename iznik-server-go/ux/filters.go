package ux

import (
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// The filters the SysAdmin UX tab can put on a read: Device and Signed in. They are checked once, in
// serve(), and added to a query in one place, flowFilter.apply, so a handler cannot forget one and
// cannot add one to a table that lacks the column. Every response says which filters it used
// (Applied), because a caller that asked for a filter must be able to tell that it was honoured.
// docs/developers/reference/ux-sysadmin-tab.md says which table honours which.

// deviceClasses maps each value of the device parameter to the device_class values it covers. "web"
// is every browser size and "app" is both native apps. The rollup column is_app is true exactly when
// device_class is an app value, so it is never read. A row whose device_class is empty is counted
// under no value here, and so appears only when no device is asked for.
var deviceClasses = map[string][]string{
	"web":         {"mobile", "tablet", "desktop"},
	"app":         {"app-ios", "app-android"},
	"mobile":      {"mobile"},
	"tablet":      {"tablet"},
	"desktop":     {"desktop"},
	"app-ios":     {"app-ios"},
	"app-android": {"app-android"},
}

// honours says which filters each rollup table can be cut by. A table that is not listed honours none.
var honours = map[string]struct{ device, signedIn bool }{
	// logged_in is the state when each event was sent, not when the visit began, so the outcome
	// counts (started, finished) cannot be cut by it: a signed-in rate of finished over started
	// could pass 100 percent. Only the step figures can.
	"ux_flow_daily":         {device: true, signedIn: true},
	"ux_flow_outcome_daily": {device: true, signedIn: false},
	"ux_field_daily":        {device: true, signedIn: false}, // the table has no logged_in column
	"ux_component_daily":    {device: true, signedIn: false}, // the table has no logged_in column
}

// flowFilter is what a request asked for, already checked. The zero value asks for nothing.
type flowFilter struct {
	device   string // "" means not asked; otherwise a key of deviceClasses
	signedIn string // "" means not asked; otherwise "1" or "0"
}

// scope is what a handler hands to rangeQuery: the funnel it asked for and the filters it was sent.
type scope struct {
	flow   string
	filter flowFilter
}

// FilterEcho is what a response says it applied. An empty string means the filter was not requested,
// or this endpoint does not honour it.
type FilterEcho struct {
	Device   string `json:"device"`
	SignedIn string `json:"signed_in"`
}

// Applied is embedded in every read response, so `filters` and `ignored` sit at the top level of
// the JSON. Ignored lists the requested filters this endpoint does not honour. It is always an
// array, never null.
type Applied struct {
	Filters FilterEcho `json:"filters"`
	Ignored []string   `json:"ignored"`
}

// parseFilter reads device and signed_in. Values are checked against a fixed list and bound as query
// arguments later; they are never put into SQL text.
func parseFilter(c *fiber.Ctx) (flowFilter, error) {
	var f flowFilter
	if d := c.Query("device"); d != "" {
		if _, ok := deviceClasses[d]; !ok {
			return f, fiber.NewError(fiber.StatusBadRequest, "device must be web, app, mobile, tablet, desktop, app-ios or app-android")
		}
		f.device = d
	}
	if s := c.Query("signed_in"); s != "" {
		if s != "1" && s != "0" {
			return f, fiber.NewError(fiber.StatusBadRequest, "signed_in must be 1 or 0")
		}
		f.signedIn = s
	}
	return f, nil
}

// apply adds the conditions this table can honour (the honours map) and no others.
func (f flowFilter) apply(q *gorm.DB, table string) *gorm.DB {
	h := honours[table]
	if f.device != "" && h.device {
		classes := deviceClasses[f.device]
		if len(classes) == 1 {
			q = q.Where("device_class = ?", classes[0])
		} else {
			q = q.Where("device_class IN ?", classes)
		}
	}
	if f.signedIn != "" && h.signedIn {
		q = q.Where("logged_in = ?", f.signedIn == "1")
	}
	return q
}

// applied says which requested filters this table honoured (echoed) and which it ignored.
func (f flowFilter) applied(table string) Applied {
	h := honours[table]
	a := Applied{Ignored: []string{}}
	if f.device != "" {
		if h.device {
			a.Filters.Device = f.device
		} else {
			a.Ignored = append(a.Ignored, "device")
		}
	}
	if f.signedIn != "" {
		if h.signedIn {
			a.Filters.SignedIn = f.signedIn
		} else {
			a.Ignored = append(a.Ignored, "signed_in")
		}
	}
	return a
}
