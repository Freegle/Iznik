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
type Deferral struct {
	Domain string     `json:"domain"`
	Since  *time.Time `json:"since"`
}

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

	byDomain = m
	loadedAt = time.Now()
	return byDomain
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
