package tryst

import (
	"encoding/base64"
	"encoding/json"
	"net/url"
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

// decodeCalendarLinkData parses the `data` query param out of a calendarLink()
// result the same way AddToCalendar.vue's download() does: extract, base64url
// decode, then JSON unmarshal.
func decodeCalendarLinkData(t *testing.T, link string) map[string]string {
	t.Helper()

	u, err := url.Parse(link)
	assert.NoError(t, err)

	encoded := u.Query().Get("data")
	assert.NotEmpty(t, encoded)

	decoded, err := base64.RawURLEncoding.DecodeString(encoded)
	assert.NoError(t, err)

	var eventData map[string]string
	assert.NoError(t, json.Unmarshal(decoded, &eventData))

	return eventData
}

func TestCalendarLink_MySQLDatetimeFormat(t *testing.T) {
	link := calendarLink(strPtr("2024-06-15 10:30:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "2024-06-15", eventData["startDate"])
	assert.Equal(t, "10:30", eventData["startTime"])
	assert.Equal(t, "11:30", eventData["endTime"]) // end = start + 1h
}

func TestCalendarLink_RFC3339Format(t *testing.T) {
	link := calendarLink(strPtr("2024-06-15T10:30:00Z"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "10:30", eventData["startTime"])
	assert.Equal(t, "11:30", eventData["endTime"])
}

func TestCalendarLink_RFC3339WithOffset(t *testing.T) {
	// +01:00 offset → stored as UTC 09:30, end UTC 10:30.
	link := calendarLink(strPtr("2024-06-15T10:30:00+01:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "09:30", eventData["startTime"])
	assert.Equal(t, "10:30", eventData["endTime"])
}

func TestCalendarLink_ContainsFreegleTitle(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Contains(t, eventData["name"], "Freegle")
}

func TestCalendarLink_ContainsDetails(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Contains(t, eventData["description"], "handover")
}

func TestCalendarLink_IncludesTimeZone(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 00:00:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.NotEmpty(t, eventData["timeZone"])
}

func TestCalendarLink_MidnightRollsOverToNextDay(t *testing.T) {
	// Start at 23:30, end should be 00:30 the following day.
	link := calendarLink(strPtr("2024-06-30 23:30:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "2024-06-30", eventData["startDate"])
	assert.Equal(t, "23:30", eventData["startTime"])
	assert.Equal(t, "00:30", eventData["endTime"])
}

func TestCalendarLink_URLPointsToCalendarPage(t *testing.T) {
	link := calendarLink(strPtr("2024-01-01 12:00:00"))
	u, err := url.Parse(link)
	assert.NoError(t, err)
	assert.Equal(t, "/calendar", u.Path)
	assert.True(t, strings.HasPrefix(link, "https://"))
}

func TestCalendarLink_NonEmptyForValidInput(t *testing.T) {
	link := calendarLink(strPtr("2024-05-03 14:00:00"))
	assert.NotEmpty(t, link)
}

func TestCalendarLink_NewYearMidnight(t *testing.T) {
	// Edge: 2023-12-31 23:00:00 UTC → end 2024-01-01 00:00:00 UTC.
	link := calendarLink(strPtr("2023-12-31 23:00:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "2023-12-31", eventData["startDate"])
	assert.Equal(t, "23:00", eventData["startTime"])
	assert.Equal(t, "00:00", eventData["endTime"])
}

func TestCalendarLink_LeapDay(t *testing.T) {
	// Leap day should parse fine.
	link := calendarLink(strPtr("2024-02-29 08:00:00"))
	eventData := decodeCalendarLinkData(t, link)
	assert.Equal(t, "2024-02-29", eventData["startDate"])
	assert.Equal(t, "08:00", eventData["startTime"])
	assert.Equal(t, "09:00", eventData["endTime"])
}

// TestCalendarLink_DataParamMatchesFrontendContract reproduces topic 9927 post 1:
// the in-app "Add to Calendar" button greys out and does nothing when tapped.
// AddToCalendar.vue's download() does the equivalent of decodeCalendarLinkData()
// above, and bails out silently (console.error only) if there is no `data` param
// or the fields it needs are missing. This test performs that same parse against
// the real calendarLink() output.
func TestCalendarLink_DataParamMatchesFrontendContract(t *testing.T) {
	link := calendarLink(strPtr("2024-06-15 10:30:00"))
	eventData := decodeCalendarLinkData(t, link)

	assert.Equal(t, "2024-06-15", eventData["startDate"])
	assert.Equal(t, "10:30", eventData["startTime"])
	assert.Equal(t, "11:30", eventData["endTime"])
	assert.NotEmpty(t, eventData["timeZone"])
	assert.NotEmpty(t, eventData["name"])
}
