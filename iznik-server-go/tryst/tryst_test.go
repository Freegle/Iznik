package tryst

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// helper: return a pointer to a string literal.
func strPtr(s string) *string { return &s }

// ── canSee ─────────────────────────────────────────────────────────────────

func TestCanSee_User1CanSee(t *testing.T) {
	tryst := &Tryst{ID: 1, User1: 10, User2: 20}
	assert.True(t, canSee(10, tryst))
}

func TestCanSee_User2CanSee(t *testing.T) {
	tryst := &Tryst{ID: 1, User1: 10, User2: 20}
	assert.True(t, canSee(20, tryst))
}

func TestCanSee_ThirdUserCannotSee(t *testing.T) {
	tryst := &Tryst{ID: 1, User1: 10, User2: 20}
	assert.False(t, canSee(30, tryst))
}

func TestCanSee_ZeroIDReturnsFalse(t *testing.T) {
	// A tryst with ID=0 is a zero-value DB miss — nobody should see it.
	tryst := &Tryst{ID: 0, User1: 10, User2: 20}
	assert.False(t, canSee(10, tryst))
}

func TestCanSee_ZeroCallerDoesNotMatchRealParticipants(t *testing.T) {
	// myid=0 (unauthenticated) must not match a tryst with real user IDs.
	tryst := &Tryst{ID: 1, User1: 10, User2: 20}
	assert.False(t, canSee(0, tryst))
}

func TestCanSee_ZeroEverything(t *testing.T) {
	// All-zero tryst (DB miss) and unauthenticated caller → false.
	tryst := &Tryst{ID: 0, User1: 0, User2: 0}
	assert.False(t, canSee(0, tryst))
}

func TestCanSee_LargeUserIDs(t *testing.T) {
	var maxID uint64 = ^uint64(0)
	tryst := &Tryst{ID: 999, User1: maxID, User2: maxID - 1}
	assert.True(t, canSee(maxID, tryst))
	assert.True(t, canSee(maxID-1, tryst))
	assert.False(t, canSee(maxID-2, tryst))
}

func TestCanSee_SameUserBothSlots(t *testing.T) {
	// Unusual but not impossible: same user in both slots still sees it.
	tryst := &Tryst{ID: 5, User1: 7, User2: 7}
	assert.True(t, canSee(7, tryst))
}

// ── calendarLink early-return guards ───────────────────────────────────────
// calendarLink requires a live DB for full behaviour; those cases are covered
// by the integration tests in test/tryst_test.go. Only the pre-DB early exits
// (nil/empty/unparseable arrangedfor) are exercised here, since they return
// before touching the db parameter.

func TestCalendarLink_NilReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(nil, 0, 0, 0, nil))
}

func TestCalendarLink_EmptyStringReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(nil, 0, 0, 0, strPtr("")))
}

func TestCalendarLink_InvalidFormatReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(nil, 0, 0, 0, strPtr("not-a-date")))
}

func TestCalendarLink_PartialDateReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(nil, 0, 0, 0, strPtr("2024-06-15")))
}

// Restored parse-variant coverage (the old calendarLink URL-format tests were
// replaced when the link moved to the V1 /calendar?data= form; the parse
// fallbacks still exist and stay covered here).
func TestParseArrangedFor_MySQLDatetime(t *testing.T) {
	tm, ok := parseArrangedFor("2026-07-06 14:30:00")
	if !ok || tm.Hour() != 14 || tm.Minute() != 30 {
		t.Fatalf("MySQL datetime parse failed: %v %v", tm, ok)
	}
}

func TestParseArrangedFor_RFC3339(t *testing.T) {
	tm, ok := parseArrangedFor("2026-07-06T14:30:00Z")
	if !ok || tm.Hour() != 14 {
		t.Fatalf("RFC3339 parse failed: %v %v", tm, ok)
	}
}

func TestParseArrangedFor_RFC3339WithOffset(t *testing.T) {
	tm, ok := parseArrangedFor("2026-07-06T14:30:00+01:00")
	if !ok || tm.UTC().Hour() != 13 {
		t.Fatalf("RFC3339 offset parse failed: %v %v", tm, ok)
	}
}

func TestParseArrangedFor_Garbage(t *testing.T) {
	if _, ok := parseArrangedFor("not a date"); ok {
		t.Fatal("garbage input should not parse")
	}
}
