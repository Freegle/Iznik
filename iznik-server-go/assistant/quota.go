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

// Quota combines the buckets and caps.
type Quota struct {
	identity  *Bucket
	ip        *Bucket
	anonDaily *DailyCounter
	authDaily *DailyCounter
	Log       func(kind string, key string)
}

func NewQuota(perHour, anonDailyCap, authDailyCap int, now func() time.Time) *Quota {
	return &Quota{
		identity:  NewBucket(perHour, time.Hour, now),
		ip:        NewBucket(perHour, time.Hour, now),
		anonDaily: NewDailyCounter(anonDailyCap, now),
		authDaily: NewDailyCounter(authDailyCap, now),
		Log:       func(string, string) {},
	}
}

// Allow reports whether a model call may be made for this identity and IP.
func (q *Quota) Allow(identity, ip string, signedIn bool) bool {
	if !q.identity.Take(identity) {
		q.Log("quota_identity", identity)
		return false
	}
	if ip != "" && !q.ip.Take(ip) {
		q.Log("quota_ip", ip)
		return false
	}
	daily := q.anonDaily
	kind := "anon"
	if signedIn {
		daily = q.authDaily
		kind = "auth"
	}
	ok, newly := daily.Take()
	if newly {
		q.Log("quota_exhausted", kind)
	}
	return ok
}
