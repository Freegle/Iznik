package changes

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// formatISO
// ---------------------------------------------------------------------------

func TestFormatISO_ValidMySQLDatetime(t *testing.T) {
	// Standard MySQL datetime format must convert to RFC3339 (UTC "Z" suffix).
	got := formatISO("2026-03-15 14:30:00")
	assert.Equal(t, "2026-03-15T14:30:00Z", got)
}

func TestFormatISO_MidnightDatetime(t *testing.T) {
	// Midnight boundary — ensures zero-padded time is handled correctly.
	got := formatISO("2026-01-01 00:00:00")
	assert.Equal(t, "2026-01-01T00:00:00Z", got)
}

func TestFormatISO_EndOfDay(t *testing.T) {
	// Last second of day.
	got := formatISO("2026-12-31 23:59:59")
	assert.Equal(t, "2026-12-31T23:59:59Z", got)
}

func TestFormatISO_InvalidStringReturnedAsIs(t *testing.T) {
	// Garbage input: time.Parse fails, so the original string is returned unchanged.
	got := formatISO("not-a-date")
	assert.Equal(t, "not-a-date", got)
}

func TestFormatISO_EmptyStringReturnedAsIs(t *testing.T) {
	// Empty string: Parse fails, returns empty.
	got := formatISO("")
	assert.Equal(t, "", got)
}

func TestFormatISO_AlreadyISO8601ReturnedAsIs(t *testing.T) {
	// A string already in RFC3339 format can't be parsed as MySQL datetime
	// ("2006-01-02T15:04:05Z" doesn't match "2006-01-02 15:04:05"), so it is
	// passed through unchanged.
	iso := "2026-03-15T14:30:00Z"
	got := formatISO(iso)
	assert.Equal(t, iso, got)
}

// ---------------------------------------------------------------------------
// resolveSince
// ---------------------------------------------------------------------------

// clampRef is a fixed reference point so the clamp arithmetic is exact.
var clampRef = time.Date(2026, 8, 18, 12, 0, 0, 0, time.UTC)

func TestResolveSince_EmptyDefaultsToAnHourAgo(t *testing.T) {
	got, err := resolveSince("", clampRef)
	assert.NoError(t, err)
	assert.Equal(t, clampRef.Add(-1*time.Hour), got)
}

func TestResolveSince_RecentValueIsUsedUnchanged(t *testing.T) {
	// Inside the window, the partner gets exactly what they asked for.
	asked := clampRef.Add(-48 * time.Hour)
	got, err := resolveSince(asked.Format(time.RFC3339), clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(asked))
}

func TestResolveSince_MySQLDatetimeAccepted(t *testing.T) {
	got, err := resolveSince("2026-08-16 09:30:00", clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(time.Date(2026, 8, 16, 9, 30, 0, 0, time.UTC)))
}

func TestResolveSince_AncientValueIsClamped(t *testing.T) {
	// The 2026-08-17 outage: a since of 1947 scanned 17.9M rows and OOM-killed
	// the node. It must come back as exactly MaxSinceLookback ago.
	got, err := resolveSince("1947-10-11T22:41:56Z", clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(clampRef.Add(-MaxSinceLookback)),
		"an ancient since must be clamped to the maximum look-back")
}

func TestResolveSince_JustOutsideWindowIsClamped(t *testing.T) {
	// One second beyond the boundary is still beyond it.
	got, err := resolveSince(clampRef.Add(-MaxSinceLookback-time.Second).Format(time.RFC3339), clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(clampRef.Add(-MaxSinceLookback)))
}

func TestResolveSince_AtBoundaryIsNotClamped(t *testing.T) {
	// Exactly on the boundary is inside the window, so it survives untouched.
	asked := clampRef.Add(-MaxSinceLookback)
	got, err := resolveSince(asked.Format(time.RFC3339), clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(asked))
}

func TestResolveSince_FutureValueIsLeftAlone(t *testing.T) {
	// A future since yields no rows, which is harmless - don't rewrite it.
	asked := clampRef.Add(24 * time.Hour)
	got, err := resolveSince(asked.Format(time.RFC3339), clampRef)
	assert.NoError(t, err)
	assert.True(t, got.Equal(asked))
}

func TestResolveSince_GarbageIsAnError(t *testing.T) {
	// The handler turns this into a 400 rather than silently defaulting.
	_, err := resolveSince("not-a-date", clampRef)
	assert.Error(t, err)
}

func TestMaxSinceLookbackIsNinetyDays(t *testing.T) {
	// Widening this widens the worst-case memory of a single request, which is
	// what took the node down - change it deliberately, with the numbers.
	assert.Equal(t, 90*24*time.Hour, MaxSinceLookback)
}

// ---------------------------------------------------------------------------
// UserChange JSON marshaling
// ---------------------------------------------------------------------------

func TestUserChange_NilLastUpdatedMarshal(t *testing.T) {
	// A UserChange with nil LastUpdated must serialise to {"id":1,"lastupdated":null}.
	uc := UserChange{ID: 1, LastUpdated: nil}
	b, err := json.Marshal(uc)
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	assert.Equal(t, float64(1), m["id"])
	assert.Nil(t, m["lastupdated"], "nil pointer should serialise to JSON null")
}

func TestUserChange_NonNilLastUpdatedMarshal(t *testing.T) {
	// A UserChange with a set LastUpdated must include the string value.
	val := "2026-03-15T14:30:00Z"
	uc := UserChange{ID: 42, LastUpdated: &val}
	b, err := json.Marshal(uc)
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	assert.Equal(t, val, m["lastupdated"])
}

func TestUserChange_TypeIsAlwaysPresent(t *testing.T) {
	// Partners branch on type, so the key must be in the JSON even if we ever
	// forget to set it.
	b, err := json.Marshal(UserChange{ID: 7})
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	_, present := m["type"]
	assert.True(t, present, "type key must always be present in the JSON object")
}

func TestUserChange_DeletedTypeMarshal(t *testing.T) {
	ts := "2026-03-15T14:30:00Z"
	b, err := json.Marshal(UserChange{ID: 8, LastUpdated: &ts, Type: UserChangeDeleted})
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	assert.Equal(t, float64(8), m["id"], "the id is all a partner needs to remove their copy")
	assert.Equal(t, "Deleted", m["type"])
}

func TestUserChangeTypeValues(t *testing.T) {
	// These strings are part of the partner API contract — changing them breaks
	// TrashNothing's handling silently.
	assert.Equal(t, "Modified", UserChangeModified)
	assert.Equal(t, "Deleted", UserChangeDeleted)
}

// ---------------------------------------------------------------------------
// Rating JSON marshaling
// ---------------------------------------------------------------------------

func TestRating_NilTnRatingIDMarshal(t *testing.T) {
	// TnRatingID is a *uint64 — nil must serialise as JSON null, not omitted.
	r := Rating{ID: 10, Rater: 1, Ratee: 2, Rating: "Up", Timestamp: "2026-03-15T14:30:00Z", Visible: 1, TnRatingID: nil}
	b, err := json.Marshal(r)
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	_, present := m["tn_rating_id"]
	assert.True(t, present, "tn_rating_id key must always be present in the JSON object")
	assert.Nil(t, m["tn_rating_id"], "nil *uint64 must serialise to JSON null")
}

func TestRating_NonNilTnRatingIDMarshal(t *testing.T) {
	// When TnRatingID is set it must appear as the correct numeric value.
	var tnID uint64 = 12345
	r := Rating{ID: 20, Rater: 3, Ratee: 4, Rating: "Down", TnRatingID: &tnID}
	b, err := json.Marshal(r)
	assert.NoError(t, err)

	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	assert.Equal(t, float64(12345), m["tn_rating_id"])
}

// ---------------------------------------------------------------------------
// MessageChange JSON marshaling
// ---------------------------------------------------------------------------

func TestMessageChange_FieldRoundTrip(t *testing.T) {
	// All three fields (id, timestamp, type) must survive a JSON round-trip.
	mc := MessageChange{ID: 99, Timestamp: "2026-06-01T10:00:00Z", Type: "Taken"}
	b, err := json.Marshal(mc)
	assert.NoError(t, err)

	var mc2 MessageChange
	assert.NoError(t, json.Unmarshal(b, &mc2))
	assert.Equal(t, mc.ID, mc2.ID)
	assert.Equal(t, mc.Timestamp, mc2.Timestamp)
	assert.Equal(t, mc.Type, mc2.Type)
}
