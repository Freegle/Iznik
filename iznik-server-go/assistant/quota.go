package assistant

import (
	"sync"
	"time"
)

// Model calls are metered per identity and per IP, with separate daily caps for
// anonymous and signed-in identities. Over quota is not an error: the caller falls
// back to the template lines.

type bucketState struct {
	tokens float64
	at     time.Time
}

// Bucket is a token bucket keyed by string.
type Bucket struct {
	mu       sync.Mutex
	capacity float64
	perSec   float64
	now      func() time.Time
	m        map[string]*bucketState
}

func NewBucket(capacity int, per time.Duration, now func() time.Time) *Bucket {
	if now == nil {
		now = time.Now
	}
	return &Bucket{capacity: float64(capacity), perSec: float64(capacity) / per.Seconds(), now: now, m: map[string]*bucketState{}}
}

// Take consumes one token if available.
func (b *Bucket) Take(key string) bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	t := b.now()
	st, ok := b.m[key]
	if !ok {
		st = &bucketState{tokens: b.capacity, at: t}
		b.m[key] = st
	}
	st.tokens += t.Sub(st.at).Seconds() * b.perSec
	if st.tokens > b.capacity {
		st.tokens = b.capacity
	}
	st.at = t
	if st.tokens < 1 {
		return false
	}
	st.tokens--
	if len(b.m) > 50000 {
		// Bound memory: drop everything; genuine users refill at once.
		b.m = map[string]*bucketState{key: st}
	}
	return true
}

// DailyCounter caps calls per UTC day.
type DailyCounter struct {
	mu        sync.Mutex
	cap       int
	now       func() time.Time
	day       string
	count     int
	Exhausted bool
}

func NewDailyCounter(cap int, now func() time.Time) *DailyCounter {
	if now == nil {
		now = time.Now
	}
	return &DailyCounter{cap: cap, now: now}
}

func (d *DailyCounter) roll() {
	day := d.now().UTC().Format("2006-01-02")
	if day != d.day {
		d.day = day
		d.count = 0
		d.Exhausted = false
	}
}

// Take consumes one call if under the cap; reports newlyExhausted once per day.
func (d *DailyCounter) Take() (ok bool, newlyExhausted bool) {
	d.mu.Lock()
	defer d.mu.Unlock()
	d.roll()
	if d.count >= d.cap {
		if !d.Exhausted {
			d.Exhausted = true
			return false, true
		}
		return false, false
	}
	d.count++
	return true, false
}

// dailyKeyed caps calls per key per UTC day.
type dailyKeyed struct {
	mu    sync.Mutex
	cap   int
	now   func() time.Time
	day   string
	count map[string]int
}

func newDailyKeyed(cap int, now func() time.Time) *dailyKeyed {
	if now == nil {
		now = time.Now
	}
	return &dailyKeyed{cap: cap, now: now, count: map[string]int{}}
}

func (d *dailyKeyed) Take(key string) bool {
	d.mu.Lock()
	defer d.mu.Unlock()
	day := d.now().UTC().Format("2006-01-02")
	if day != d.day {
		d.day = day
		d.count = map[string]int{}
	}
	if d.count[key] >= d.cap {
		return false
	}
	d.count[key]++
	if len(d.count) > 50000 {
		d.count = map[string]int{key: d.count[key]}
	}
	return true
}

// Per-identity daily caps: plenty for anyone giving or asking several times in a day, far
// below what a script could spend. The sitewide caps behind them are the spend breaker.
// Identities themselves are rationed too, since every other limit hangs off them.
const (
	anonIdentityDailyCap = 60
	authIdentityDailyCap = 300
	mintPerAddressHour   = 600
	mintSitewideHour     = 3000
)

// Quota combines the buckets and caps.
type Quota struct {
	identity  *Bucket
	ip        *Bucket
	anonEach  *dailyKeyed
	authEach  *dailyKeyed
	anonDaily *DailyCounter
	authDaily *DailyCounter
	mintIP    *Bucket
	mintAll   *Bucket
	Log       func(kind string, key string)
}

func NewQuota(perHour, anonDailyCap, authDailyCap int, now func() time.Time) *Quota {
	return &Quota{
		identity: NewBucket(perHour, time.Hour, now),
		// The address is a coarse brake behind the identity bucket; several people can
		// share one, so it is ten times looser.
		ip:        NewBucket(perHour*10, time.Hour, now),
		anonEach:  newDailyKeyed(anonIdentityDailyCap, now),
		authEach:  newDailyKeyed(authIdentityDailyCap, now),
		anonDaily: NewDailyCounter(anonDailyCap, now),
		authDaily: NewDailyCounter(authDailyCap, now),
		mintIP:    NewBucket(mintPerAddressHour, time.Hour, now),
		mintAll:   NewBucket(mintSitewideHour, time.Hour, now),
		Log:       func(string, string) {},
	}
}

// Allow reports whether a model call may be made for this identity and address:
// identity per hour, address per hour, identity per day, then the sitewide day.
func (q *Quota) Allow(identity, ip string, signedIn bool) bool {
	if !q.identity.Take(identity) {
		q.Log("quota_identity", identity)
		return false
	}
	if ip != "" && !q.ip.Take(ip) {
		q.Log("quota_ip", ip)
		return false
	}
	each, daily, kind := q.anonEach, q.anonDaily, "anon"
	if signedIn {
		each, daily, kind = q.authEach, q.authDaily, "auth"
	}
	if !each.Take(identity) {
		q.Log("quota_identity_daily", identity)
		return false
	}
	ok, newly := daily.Take()
	if newly {
		q.Log("quota_exhausted", kind)
	}
	return ok
}

// AllowMint says whether a new anonymous identity may be issued to this address.
func (q *Quota) AllowMint(ip string) bool {
	if !q.mintAll.Take("all") {
		q.Log("mint_sitewide", ip)
		return false
	}
	if ip != "" && !q.mintIP.Take(ip) {
		q.Log("mint_address", ip)
		return false
	}
	return true
}
