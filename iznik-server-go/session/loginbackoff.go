package session

import (
	"fmt"
	"net/netip"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
)

// After repeated failed logins the next attempt has to wait, and the wait doubles with each further
// failure. That makes guessing a password slow without locking anyone out for good: the longest wait
// is loginBackoffMaxWait, and a member who is stuck can still use "Forgot password", a login link or
// a social login, none of which are held back by a wrong password.
//
// Failures are counted twice, against the member being tried and against the address trying. The
// member count stops many addresses guessing at one member; the address count stops one address
// trying one password on many members. An address gets more room than a member because a school
// or library is many members behind one address.
//
// The refusal is a 403, like a wrong password, not a 429: the website and the apps already out
// there retry a 429 by themselves for minutes and then show nothing, and they show a 403's status
// text to the member as it is.
//
// The counts are held in memory, like the client log limiter. They are lost on a restart and are
// not shared between API processes, so with N processes a guesser gets N times the allowance. That
// is still a few guesses an hour instead of thousands.
const (
	loginBackoffAccountFree = 5
	loginBackoffAddressFree = 20
	loginBackoffFirstWait   = 30 * time.Second
	loginBackoffMaxWait     = 15 * time.Minute
	// A count is forgotten once this long has passed with no further failure.
	loginBackoffForget = time.Hour
	// Bounds the table however many accounts or addresses are thrown at it.
	loginBackoffMaxKeys = 50000

	// RetLoginBackoff is the ret value of a login refused because of earlier failures.
	RetLoginBackoff = 13
)

type loginFailures struct {
	count     int
	last      time.Time
	notBefore time.Time
}

type loginBackoff struct {
	mu        sync.Mutex
	keys      map[string]*loginFailures
	maxKeys   int
	lastSweep time.Time
	now       func() time.Time
}

func newLoginBackoff() *loginBackoff {
	return &loginBackoff{keys: map[string]*loginFailures{}, maxKeys: loginBackoffMaxKeys, now: time.Now}
}

var defaultLoginBackoff = newLoginBackoff()

// ResetLoginBackoff forgets every count. For tests.
func ResetLoginBackoff() {
	defaultLoginBackoff.mu.Lock()
	defer defaultLoginBackoff.mu.Unlock()
	defaultLoginBackoff.keys = map[string]*loginFailures{}
}

// loginBackoffWait is how long the next attempt waits after the count-th failure in a row, given
// free failures that cost nothing.
func loginBackoffWait(count int, free int) time.Duration {
	over := count - free
	if over <= 0 {
		return 0
	}
	wait := loginBackoffFirstWait
	for i := 1; i < over; i++ {
		wait *= 2
		if wait >= loginBackoffMaxWait {
			return loginBackoffMaxWait
		}
	}
	return wait
}

// wait returns how long key still has to wait before another attempt, or zero.
func (b *loginBackoff) wait(key string) time.Duration {
	if key == "" {
		return 0
	}
	now := b.now()

	b.mu.Lock()
	defer b.mu.Unlock()

	f, ok := b.keys[key]
	if !ok {
		return 0
	}
	if now.Sub(f.last) >= loginBackoffForget {
		delete(b.keys, key)
		return 0
	}
	if f.notBefore.After(now) {
		return f.notBefore.Sub(now)
	}
	return 0
}

// fail records one failed attempt for key.
func (b *loginBackoff) fail(key string, free int) {
	if key == "" {
		return
	}
	now := b.now()

	b.mu.Lock()
	defer b.mu.Unlock()

	f, ok := b.keys[key]
	if ok && now.Sub(f.last) >= loginBackoffForget {
		f.count = 0
	}
	if !ok {
		if len(b.keys) >= b.maxKeys && now.Sub(b.lastSweep) >= time.Minute {
			// Drop counts nobody has added to for an hour. At most one scan a minute, so a full
			// table cannot make every new key pay for a walk under the lock.
			b.lastSweep = now
			for k, old := range b.keys {
				if now.Sub(old.last) >= loginBackoffForget {
					delete(b.keys, k)
				}
			}
		}
		if len(b.keys) >= b.maxKeys && b.lastSweep.Equal(now) {
			// Still full after the sweep: drop the single failures, so a flood of them cannot crowd
			// out the keys that matter. A count that is part way to a wait is kept.
			for k, old := range b.keys {
				if old.count <= 1 && !old.notBefore.After(now) {
					delete(b.keys, k)
				}
			}
		}
		if len(b.keys) >= b.maxKeys {
			// Full of counts that are waiting or part way there. Leave this one uncounted rather
			// than grow without bound.
			return
		}
		f = &loginFailures{}
		b.keys[key] = f
	}
	f.count++
	f.last = now
	f.notBefore = now.Add(loginBackoffWait(f.count, free))
}

// clear forgets key, after a successful login.
func (b *loginBackoff) clear(key string) {
	b.mu.Lock()
	defer b.mu.Unlock()
	delete(b.keys, key)
}

// loginUserKey is the count for the member a login is trying.
func loginUserKey(uid uint64) string {
	return fmt.Sprintf("user:%d", uid)
}

// loginAddressKey is the count for the address making the attempt, or "" when it is not counted.
// Only a public address is counted. Anything else is a proxy or an internal caller, never one
// client: if haproxy ever stopped passing X-Real-IP, every member would arrive from the proxy's
// private address, and counting that would hold back everyone because of one guesser.
func loginAddressKey(c *fiber.Ctx) string {
	addr, err := netip.ParseAddr(misc.ClientAddress(c))
	if err != nil {
		return ""
	}
	addr = addr.Unmap()
	if !addr.IsGlobalUnicast() || addr.IsPrivate() {
		return ""
	}
	if addr.Is6() {
		// One IPv6 customer has a whole /64 to pick addresses from, so that is what is counted.
		if prefix, err := addr.Prefix(64); err == nil {
			return "addr:" + prefix.String()
		}
	}
	return "addr:" + addr.String()
}

// loginHeldBack answers the request with a refusal when the member (accountKey, or "" when not yet
// known) or the address has to wait, and reports whether it did. A refused attempt is not itself
// counted as a failure, so waiting out the time always works.
func loginHeldBack(c *fiber.Ctx, accountKey string) (bool, error) {
	wait := defaultLoginBackoff.wait(accountKey)
	if w := defaultLoginBackoff.wait(loginAddressKey(c)); w > wait {
		wait = w
	}
	if wait <= 0 {
		return false, nil
	}

	seconds := int((wait + time.Second - 1) / time.Second)
	c.Set(fiber.HeaderRetryAfter, fmt.Sprint(seconds))

	return true, c.Status(fiber.StatusForbidden).JSON(fiber.Map{
		"ret":        RetLoginBackoff,
		"status":     "Too many failed attempts. Please wait " + loginWaitWords(seconds) + " and try again, or use Forgot password.",
		"retryafter": seconds,
	})
}

// loginWaitWords says a wait in the words a member reads: seconds under a minute, else whole minutes
// rounded up.
func loginWaitWords(seconds int) string {
	if seconds < 60 {
		if seconds == 1 {
			return "1 second"
		}
		return fmt.Sprintf("%d seconds", seconds)
	}
	minutes := (seconds + 59) / 60
	if minutes == 1 {
		return "1 minute"
	}
	return fmt.Sprintf("%d minutes", minutes)
}

// loginFailed counts a failed attempt against the member, when there is one, and the address.
func loginFailed(c *fiber.Ctx, accountKey string) {
	defaultLoginBackoff.fail(accountKey, loginBackoffAccountFree)
	defaultLoginBackoff.fail(loginAddressKey(c), loginBackoffAddressFree)
}

// loginSucceeded forgets the member's failures. The address keeps its count: one member getting in
// does not vouch for everything else that address has tried.
func loginSucceeded(accountKey string) {
	defaultLoginBackoff.clear(accountKey)
}
