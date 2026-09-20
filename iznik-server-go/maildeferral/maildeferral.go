// Package maildeferral reports which recipient domains our relay currently
// cannot deliver to, so the site can tell affected members to check back here
// rather than wait for email that is not coming.
//
// The data is written by iznik-batch's deferral scanner into mail_suppressions
// (scope='domain', released_at IS NULL while active). A provider deferring us
// is invisible to the sending code - our relay returns 250 and only discovers
// afterwards that the receiving side will not take the message - so this table
// is the only place the condition is known.
package maildeferral

import (
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
)

// PerMailboxReason matches a delay reason that describes ONE recipient's
// mailbox rather than the provider's treatment of us - almost always a full
// inbox. Those must never be presented as a provider problem: counting them as
// one suppressed gmail.com for two and a half hours on 2026-08-19 while Gmail
// was delivering normally (see iznik-batch RelayQueueSnapshot::isPerMailbox,
// which this mirrors - keep the two in step).
//
// 4.3.1 "insufficient system storage" is deliberately absent: that is the
// receiving SERVER running out, which IS about the provider.
const PerMailboxReason = `4[.]2[.]2|over[- ]?quota|quota exceeded|mailbox (is )?full|out of storage|not enough storage space`

// Deferral is one receiving domain we currently cannot deliver to.
// Deferral is a domain whose mail is running late, for the member-facing
// banner.
//
// Deliberately carries no field for WHICH of the two causes it is - a provider
// refusing us outright, or mail queued behind our own rate limiting. The member
// sees one thing either way: email that has not arrived. And the distinction
// does not survive contact with the truth anyway, because we only pace a
// provider that will not let us send faster; our rate limit is their limit,
// enforced at our end to keep the mail flowing at all. So the wording blames
// the receiving end in both cases, and there is no field here to tempt anyone
// into branching on it. The operational split lives in ModTools, on
// mail_relay_queue, where it is acted on.
type Deferral struct {
	Domain string     `json:"domain"`
	Since  *time.Time `json:"since"`
}

// How long mail must have been sitting in our own queue before we say anything.
// A sending address on a rate delay always has SOMETHING queued; that is what
// pacing is, and warning about it would be permanent and meaningless. Two hours
// is the point at which a member would notice an email had not arrived.
const pacedMinAge = 2 * time.Hour

// The session call is hot and this set is tiny - a handful of domains that
// change at most every few minutes - so it is cached in process rather than
// queried per session. One query a minute for the whole instance.
const cacheFor = 60 * time.Second

var (
	mu       sync.RWMutex
	byDomain map[string]Deferral
	loadedAt time.Time
)

func fresh() bool {
	return byDomain != nil && time.Since(loadedAt) < cacheFor
}

func snapshot() map[string]Deferral {
	mu.RLock()
	if fresh() {
		m := byDomain
		mu.RUnlock()
		return m
	}
	mu.RUnlock()

	mu.Lock()
	defer mu.Unlock()

	// Another goroutine may have refreshed while we waited for the write lock.
	if fresh() {
		return byDomain
	}

	var rows []struct {
		Value         string     `gorm:"column:value"`
		DeferredSince *time.Time `gorm:"column:deferred_since"`
	}

	// Scan errors are deliberately not propagated. This drives a warning
	// banner: failing to read it must leave members seeing nothing unusual,
	// never break their session call.
	database.DBConn.Table("mail_suppressions").
		Select("value, deferred_since").
		Where("scope = ? AND released_at IS NULL", "domain").
		Scan(&rows)

	m := make(map[string]Deferral, len(rows))
	for _, r := range rows {
		d := strings.ToLower(strings.TrimSpace(r.Value))
		if d != "" {
			m[d] = Deferral{Domain: d, Since: r.DeferredSince}
		}
	}

	// The other half: mail nothing has refused, queued behind our own pacing
	// of a provider that will not take it faster. A member cannot tell the
	// difference - the email simply has not arrived - so both reach the
	// banner. Only domains whose oldest waiting message is genuinely old
	// qualify; see pacedMinAge.
	var paced []pacedRow

	database.DBConn.Table("mail_relay_queue").
		Select("domain, oldest").
		Where("waiting > 0 AND oldest IS NOT NULL AND oldest < ?", time.Now().Add(-pacedMinAge)).
		Scan(&paced)

	mergePaced(m, paced)

	byDomain = m
	loadedAt = time.Now()
	return byDomain
}

// pacedRow is one mail_relay_queue row old enough to be worth warning about.
type pacedRow struct {
	Domain string     `gorm:"column:domain"`
	Oldest *time.Time `gorm:"column:oldest"`
}

// mergePaced folds paced domains into a map that already holds outright
// refusals.
//
// Where both apply, the refusal wins. It carries the provider's own words and
// a real start date - the moment they began turning us away - whereas a paced
// row can only offer the arrival time of whatever happens to be at the front
// of the queue, which moves as the queue drains.
func mergePaced(m map[string]Deferral, paced []pacedRow) {
	for _, r := range paced {
		d := strings.ToLower(strings.TrimSpace(r.Domain))
		if d == "" {
			continue
		}

		if _, already := m[d]; already {
			continue
		}

		m[d] = Deferral{Domain: d, Since: r.Oldest}
	}
}

// ForEmail returns the active deferral covering this address's domain, or nil.
func ForEmail(email string) *Deferral {
	d := DomainOf(email)
	if d == "" {
		return nil
	}

	if found, ok := snapshot()[d]; ok {
		return &found
	}

	return nil
}

// DomainOf extracts the lowercased domain from an address. Split on the LAST
// @, since the local part may legitimately contain one in a quoted string.
func DomainOf(email string) string {
	at := strings.LastIndex(email, "@")
	if at < 0 || at == len(email)-1 {
		return ""
	}

	return strings.ToLower(strings.TrimSpace(email[at+1:]))
}
