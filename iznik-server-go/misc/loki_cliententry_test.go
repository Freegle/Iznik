package misc

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
)

// clientlog.go puts the JWT user id into the entry as a uint64, and a JSON-decoded entry has a
// float64; both must attach user_id and user_bucket, or a logged-in member's lines lose their user.
func TestLogClientEntryAttachesUserForAnyIntegerType(t *testing.T) {
	cases := map[string]interface{}{
		"uint64":      uint64(70),
		"int":         int(70),
		"int64":       int64(70),
		"uint32":      uint32(70),
		"float64":     float64(70),
		"json.Number": json.Number("70"),
	}

	for name, val := range cases {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			l := newDirectEnabledClient(dir)
			l.LogClientEntry("info", "flow", map[string]interface{}{"user_id": val, "message": "x"})
			l.Close()

			entries := readAllLogEntries(t, dir)
			if assert.Len(t, entries, 1) {
				labels := labelsOf(t, entries[0])
				assert.Equal(t, "70", labels["user_id"])
				assert.Equal(t, UserBucket(70), labels["user_bucket"])
			}
		})
	}
}

func TestLogClientEntryIgnoresZeroAndNonNumericUser(t *testing.T) {
	for name, val := range map[string]interface{}{
		"zero": uint64(0), "string": "abc", "nil": nil, "negative": int64(-4), "bad number": json.Number("x"),
	} {
		t.Run(name, func(t *testing.T) {
			dir := t.TempDir()
			l := newDirectEnabledClient(dir)
			l.LogClientEntry("info", "flow", map[string]interface{}{"user_id": val})
			l.Close()

			entries := readAllLogEntries(t, dir)
			if assert.Len(t, entries, 1) {
				labels := labelsOf(t, entries[0])
				assert.NotContains(t, labels, "user_id")
				assert.NotContains(t, labels, "user_bucket")
			}
		})
	}
}

// /clientlog is logged by the clientlog handler itself, once per entry. The general request
// middleware must not write it a second time as a source="api" line.
func TestSkipRequestLog(t *testing.T) {
	assert.True(t, SkipRequestLog("/api/online"))
	assert.True(t, SkipRequestLog("/swagger"))
	assert.True(t, SkipRequestLog("/swagger/index.html"))
	assert.True(t, SkipRequestLog("/api/clientlog"))
	assert.True(t, SkipRequestLog("/apiv2/clientlog"))
	assert.False(t, SkipRequestLog("/api/message/1"))
	assert.False(t, SkipRequestLog("/api/clientlogs/other"))
	assert.False(t, SkipRequestLog("/api/modtools/ux/funnels"))
}
