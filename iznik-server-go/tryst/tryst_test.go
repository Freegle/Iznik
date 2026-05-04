package tryst

import (
	"strings"
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

// ── calendarLink ────────────────────────────────────────────────────────────

func TestCalendarLink_NilReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(nil))
}

func TestCalendarLink_EmptyStringReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(strPtr("")))
}

func TestCalendarLink_InvalidFormatReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(strPtr("not-a-date")))
}

func TestCalendarLink_PartialDateReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", calendarLink(strPtr("2024-06-15")))
}

func TestCalendarLink_MySQLDatetimeFormat(t *testing.T) {
	link := calendarLink(strPtr("2024-06-15 10:30:00"))
	assert.Contains(t, link, "https://www.google.com/calendar/render")
	assert.Contains(t, link, "20240615T103000Z") // start
	assert.Contains(t, link, "20240615T113000Z") // end = start + 1h
}

func TestCalendarLink_RFC3339Format(t *testing.T) {
	link := calendarLink(strPtr("2024-06-15T10:30:00Z"))
	assert.Contains(t, link, "20240615T103000Z")
	assert.Contains(t, link, "20240615T113000Z")
}

func TestCalendarLink_RFC3339WithOffset(t *testing.T) {
	// +01:00 offset → stored as UTC 09:30, end UTC 10:30.
	link := calendarLink(strPtr("2024-06-15T10:30:00+01:00"))
	assert.Contains(t, link, "20240615T093000Z")
	assert.Contains(t, link, "20240615T103000Z")
}

func TestCalendarLink_ContainsFreegleTitle(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	assert.Contains(t, link, "Freegle+Handover")
}

func TestCalendarLink_ContainsDetails(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	assert.Contains(t, link, "Arrange+handover+of+Freegle+item")
}

func TestCalendarLink_ContainsActionTemplate(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	assert.Contains(t, link, "action=TEMPLATE")
}

func TestCalendarLink_ContainsSFAndOutput(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	assert.Contains(t, link, "sf=true")
	assert.Contains(t, link, "output=xml")
}

func TestCalendarLink_DatesSlashSeparated(t *testing.T) {
	// Google Calendar expects dates=START/END.
	link := calendarLink(strPtr("2024-03-10 09:00:00"))
	assert.Contains(t, link, "20240310T090000Z/20240310T100000Z")
}

func TestCalendarLink_MidnightRollsOverToNextDay(t *testing.T) {
	// Start at 23:30, end should be 00:30 the following day.
	link := calendarLink(strPtr("2024-06-30 23:30:00"))
	assert.Contains(t, link, "20240630T233000Z/20240701T003000Z")
}

func TestCalendarLink_URLStartsWithGoogleCalendar(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 12:00:00"))
	assert.True(t, strings.HasPrefix(link, "https://www.google.com/calendar/render"))
}

func TestCalendarLink_NonEmptyForValidInput(t *testing.T) {
	link := calendarLink(strPtr("2024-05-03 14:00:00"))
	assert.NotEmpty(t, link)
}

func TestCalendarLink_NewYearMidnight(t *testing.T) {
	// Edge: 2023-12-31 23:00:00 UTC → end 2024-01-01 00:00:00 UTC.
	link := calendarLink(strPtr("2023-12-31 23:00:00"))
	assert.Contains(t, link, "20231231T230000Z/20240101T000000Z")
}

func TestCalendarLink_LeapDay(t *testing.T) {
	// Leap day should parse fine.
	link := calendarLink(strPtr("2024-02-29 08:00:00"))
	assert.Contains(t, link, "20240229T080000Z/20240229T090000Z")
}
