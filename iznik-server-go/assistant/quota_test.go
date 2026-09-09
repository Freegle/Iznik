package assistant

import (
	"fmt"
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

func TestAsstDailyCapIsPerIdentity(t *testing.T) {
	q := NewQuota(100000, 100000, 100000, nil)
	for i := 0; i < anonIdentityDailyCap; i++ {
		if !q.Allow("a:one", "", false) {
			t.Fatalf("call %d refused before the identity's daily cap", i)
		}
	}
	if q.Allow("a:one", "", false) {
		t.Fatal("over the identity's day")
	}
	if !q.Allow("a:two", "", false) {
		t.Fatal("one identity's day is nobody else's")
	}
}

func TestAsstMintingIsRationedPerAddress(t *testing.T) {
	q := NewQuota(10, 10, 10, nil)
	for i := 0; i < mintPerAddressHour; i++ {
		if !q.AllowMint("1.2.3.4") {
			t.Fatalf("mint %d refused early", i)
		}
	}
	if q.AllowMint("1.2.3.4") {
		t.Fatal("an address cannot mint identities without end")
	}
	if !q.AllowMint("5.6.7.8") {
		t.Fatal("another address is unaffected")
	}
}

func TestAsstLockedDoesNotRememberTheInnocent(t *testing.T) {
	s := NewStrikes(3, time.Hour, nil)
	for i := 0; i < 500; i++ {
		s.Locked(fmt.Sprintf("a:%d", i))
	}
	if len(s.m) != 0 {
		t.Fatalf("checking must not grow the strikes map, got %d entries", len(s.m))
	}
	s.Record("a:bad")
	if len(s.m) != 1 {
		t.Fatalf("a strike is remembered, got %d", len(s.m))
	}
}
