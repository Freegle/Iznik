// Package addedorigin holds the values of memberships.added_origin and
// memberships_history.added_origin: how a membership row was created. The value is set once, at
// insert, and every insert goes through ForInsert so that a new row with no known origin is
// reported rather than silently stored as unknown.
package addedorigin

import (
	"fmt"
	"log"
	"strings"
	"sync"

	"github.com/getsentry/sentry-go"
)

// The values, in the ENUM's order. Laravel's Membership::ADDED_ORIGINS holds the same list.
const (
	Manual        = "manual"
	Posted        = "posted"
	Replied       = "replied"
	Subscribed    = "subscribed"
	Rippled       = "rippled"
	Moderator     = "moderator"
	Partner       = "partner"
	TNUnaddressed = "tn_unaddressed"
	Signup        = "signup"
	Unknown       = "unknown"
)

var all = []string{Manual, Posted, Replied, Subscribed, Rippled, Moderator, Partner, TNUnaddressed, Signup, Unknown}

// All returns every value, in the ENUM's order.
func All() []string {
	return append([]string(nil), all...)
}

// fromClient are the values a member's own request may name. The rest are decided by the server:
// rippled, tn_unaddressed and partner by the paths that create them, moderator by who is acting,
// subscribed by the mail path, and unknown is never a reason anyone gives.
var fromClient = []string{Manual, Posted, Replied, Signup}

// FromClient reports whether a request may name origin.
func FromClient(origin string) bool {
	return contains(fromClient, origin)
}

// ClientValuesMessage is the refusal for an origin a request may not name.
func ClientValuesMessage() string {
	return "origin must be one of: " + strings.Join(fromClient, ", ")
}

// ForInsert returns the value to write on a new membership or history row. Anything that is not
// a known origin other than unknown is written as unknown and reported: after Phase 3 every way
// of creating a membership knows why, so an unknown on a new row is a caller that forgot. The
// join still goes ahead. site, userid and groupid say which path it was.
func ForInsert(site string, userid, groupid uint64, origin string) string {
	if origin != Unknown && contains(all, origin) {
		return origin
	}

	reportMu.RLock()
	r := reporter
	reportMu.RUnlock()
	r(fmt.Sprintf("Membership created with no known origin (%q) at %s: user %d, group %d",
		origin, site, userid, groupid))

	return Unknown
}

var (
	reportMu sync.RWMutex
	reporter = reportToSentry
)

func reportToSentry(msg string) {
	log.Println(msg)
	sentry.WithScope(func(scope *sentry.Scope) {
		scope.SetLevel(sentry.LevelError)
		sentry.CaptureMessage(msg)
	})
}

// SetReporter replaces where unknown origins are reported, for tests, and returns a function that
// puts the previous one back.
func SetReporter(r func(msg string)) (restore func()) {
	reportMu.Lock()
	prev := reporter
	reporter = r
	reportMu.Unlock()

	return func() {
		reportMu.Lock()
		reporter = prev
		reportMu.Unlock()
	}
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}
