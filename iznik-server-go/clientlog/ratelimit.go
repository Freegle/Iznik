package clientlog

import (
	"os"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
)

// defaultRatePerMin is how many log ENTRIES one IP may send a minute. A batch counts for as many
// entries as it carries, because a page that flushes 50 events in one request is 50 lines in Loki.
// A busy shared address (a school, a library) can legitimately send a few hundred in a minute.
const defaultRatePerMin = 600

// flowLimitMultiple is how many times the general limit an IP may send in flow events, which are
// counted separately. A member in a wizard sends a few a minute, so this is room for a large shared
// address, not a licence for unlimited lines.
const flowLimitMultiple = 10

// maxTrackedIPs bounds the limiter's memory. Each entry is one IP's current minute, so the table
// holds at most this many small structs however many addresses are thrown at it.
const maxTrackedIPs = 20000

// ratePerMin reads CLIENTLOG_RATE_PER_MIN, falling back to the default for anything unusable.
func ratePerMin() int {
	if n, err := strconv.Atoi(os.Getenv("CLIENTLOG_RATE_PER_MIN")); err == nil && n > 0 {
		return n
	}
	return defaultRatePerMin
}

type ipWindow struct {
	minute int64
	count  int
	warned bool
}

// limiter is a fixed-window, per-IP counter held in memory. Fixed windows are coarse (up to twice
// the rate across a minute boundary) but that is fine for a guard against a runaway client.
type limiter struct {
	mu      sync.Mutex
	windows map[string]*ipWindow
	maxIPs  int
	// lastSweep is the minute of the last full scan for expired windows; scans counts them.
	lastSweep int64
	scans     int
}

// clientKey is the address the limiter counts against. X-Forwarded-For cannot be used: main.go
// trusts it unvalidated, a client can write any leading entries, and behind haproxy and traefik
// its rightmost entry is a proxy, so counting on that would put every member in one bucket. It is
// misc.ClientAddress: X-Real-IP, which haproxy sets to the peer address, or the socket peer.
func clientKey(c *fiber.Ctx) string {
	return misc.ClientAddress(c)
}

func newLimiter() *limiter {
	return &limiter{windows: map[string]*ipWindow{}, maxIPs: maxTrackedIPs}
}

var defaultLimiter = newLimiter()

// flowLimiter counts flow events alone, against flowLimitMultiple times the limit.
var flowLimiter = newLimiter()

func (l *limiter) size() int {
	l.mu.Lock()
	defer l.mu.Unlock()
	return len(l.windows)
}

// take asks for n entries for ip against limit a minute. It returns how many may be kept, and
// whether this call is the first in the current minute to hit the limit, which is the caller's
// cue to write the one warning line.
func (l *limiter) take(ip string, n int, limit int, now time.Time) (accepted int, tripped bool) {
	if n <= 0 {
		return 0, false
	}
	minute := now.Unix() / 60

	l.mu.Lock()
	defer l.mu.Unlock()

	w, ok := l.windows[ip]
	if !ok {
		if len(l.windows) >= l.maxIPs && l.lastSweep != minute {
			// Evict windows from earlier minutes; they no longer limit anyone. At most one scan a
			// minute, so a full table cannot make every new address pay for a walk under the lock.
			l.lastSweep = minute
			l.scans++
			for k, old := range l.windows {
				if old.minute != minute {
					delete(l.windows, k)
				}
			}
		}
		if len(l.windows) >= l.maxIPs {
			// Full of live windows. Better to let this one IP through untracked than to grow
			// without bound or to refuse a stranger because other addresses are busy.
			return n, false
		}
		w = &ipWindow{minute: minute}
		l.windows[ip] = w
	} else if w.minute != minute {
		w.minute, w.count, w.warned = minute, 0, false
	}

	room := limit - w.count
	if room < 0 {
		room = 0
	}
	accepted = n
	if accepted > room {
		accepted = room
	}
	w.count += accepted

	if accepted < n && !w.warned {
		w.warned = true
		tripped = true
	}
	return accepted, tripped
}
