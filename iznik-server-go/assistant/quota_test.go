package assistant

import (
	"testing"
	"time"
)

func TestAsstQuotaBucketsAndCaps(t *testing.T) {
	now := time.Unix(0, 0)
	clock := func() time.Time { return now }
	q := NewQuota(2, 3, 100, clock)
	if !q.Allow("a", "1", false) || !q.Allow("a", "1", false) {
		t.Fatal("first two allowed")
	}
	if q.Allow("a", "1", false) {
		t.Fatal("third denied by identity bucket")
	}
	now = now.Add(30 * time.Minute)
	if !q.Allow("a", "2", false) {
		t.Fatal("refill after half an hour")
	}
	if q.Allow("b", "3", false) {
		t.Fatal("anon daily cap of 3 reached")
	}
	if !q.Allow("c", "4", true) {
		t.Fatal("signed in has its own cap")
	}
}

func TestAsstStrikes(t *testing.T) {
	now := time.Unix(0, 0)
	s := NewStrikes(3, time.Hour, func() time.Time { return now })
	s.Record("x")
	s.Record("x")
	if s.Locked("x") {
		t.Fatal("two strikes should not lock")
	}
	s.Record("x")
	if !s.Locked("x") {
		t.Fatal("three strikes lock")
	}
	now = now.Add(time.Hour + time.Second)
	if s.Locked("x") {
		t.Fatal("strikes expire")
	}
}

func TestAsstEscalationLevels(t *testing.T) {
	if EscalationLevel(0) != 0 || EscalationLevel(1) != 1 || EscalationLevel(3) != 2 || EscalationLevel(5) != 3 {
		t.Fatal("levels")
	}
}
