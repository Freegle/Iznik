package spatial

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// baseURL prefers SPATIAL_KNN_URL (canonical), falls back to SPATIAL_SERVER_URL
// (backward compat), then to the local-Docker default.
func TestBaseURL_SpatialKnnURLWins(t *testing.T) {
	t.Setenv("SPATIAL_KNN_URL", "http://spatial-knn:8194")
	t.Setenv("SPATIAL_SERVER_URL", "http://spatial-server:9999")
	assert.Equal(t, "http://spatial-knn:8194", baseURL())
}

func TestBaseURL_FallsBackToSpatialServerURL(t *testing.T) {
	t.Setenv("SPATIAL_KNN_URL", "")
	t.Setenv("SPATIAL_SERVER_URL", "http://spatial-server:9999")
	assert.Equal(t, "http://spatial-server:9999", baseURL())
}

func TestBaseURL_DefaultsToLocalhostWhenNeitherSet(t *testing.T) {
	t.Setenv("SPATIAL_KNN_URL", "")
	t.Setenv("SPATIAL_SERVER_URL", "")
	assert.Equal(t, "http://localhost:8194", baseURL())
}

// ExtraString returns a string value from Extra, or "" if the key is absent
// or holds a non-string value.
func TestExtraString(t *testing.T) {
	r := QueryResult{Extra: map[string]any{
		"name":  "Watermead",
		"count": 5,
	}}

	assert.Equal(t, "Watermead", ExtraString(r, "name"))
	assert.Equal(t, "", ExtraString(r, "count"), "wrong JSON type must not be type-asserted, must fall back to empty string")
	assert.Equal(t, "", ExtraString(r, "missing"), "absent key must fall back to empty string")

	empty := QueryResult{}
	assert.Equal(t, "", ExtraString(empty, "name"), "nil Extra map must not panic")
}

// ExtraInt64 returns an int64 value from Extra. JSON numbers decode as
// float64 (the typical path off the wire), but an already-native int64 is
// also accepted; anything else (including absent) defaults to 0.
func TestExtraInt64(t *testing.T) {
	r := QueryResult{Extra: map[string]any{
		"id_from_json": float64(42),
		"id_native":    int64(7),
		"name":         "not a number",
	}}

	assert.Equal(t, int64(42), ExtraInt64(r, "id_from_json"))
	assert.Equal(t, int64(7), ExtraInt64(r, "id_native"))
	assert.Equal(t, int64(0), ExtraInt64(r, "name"), "wrong type must default to 0")
	assert.Equal(t, int64(0), ExtraInt64(r, "missing"), "absent key must default to 0")

	empty := QueryResult{}
	assert.Equal(t, int64(0), ExtraInt64(empty, "id_from_json"), "nil Extra map must not panic")
}
