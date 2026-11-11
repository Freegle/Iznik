package firstreply

import (
	"math"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// envTrue
// ---------------------------------------------------------------------------

func TestEnvTrue(t *testing.T) {
	tests := []struct {
		name  string
		value string
		unset bool
		want  bool
	}{
		{name: "true", value: "true", want: true},
		{name: "one", value: "1", want: true},
		{name: "false", value: "false", want: false},
		{name: "zero", value: "0", want: false},
		{name: "empty string", value: "", want: false},
		{name: "unset", unset: true, want: false},
		{name: "uppercase TRUE not recognised", value: "TRUE", want: false},
		{name: "yes not recognised", value: "yes", want: false},
		{name: "garbage", value: "banana", want: false},
	}

	const envName = "FIRSTREPLY_TEST_ENV_TRUE"

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if tt.unset {
				t.Setenv(envName, "")
				// t.Setenv can't unset; simulate unset by ensuring empty value,
				// which envTrue treats the same as unset (both fall through to false).
			} else {
				t.Setenv(envName, tt.value)
			}

			got := envTrue(envName)
			assert.Equal(t, tt.want, got)
		})
	}
}

// ---------------------------------------------------------------------------
// Enabled
// ---------------------------------------------------------------------------

func TestEnabled(t *testing.T) {
	tests := []struct {
		name     string
		enabled  string
		passthru string
		want     bool
	}{
		{name: "both true", enabled: "true", passthru: "true", want: true},
		{name: "both 1", enabled: "1", passthru: "1", want: true},
		{name: "enabled only", enabled: "true", passthru: "false", want: false},
		{name: "passthrough only", enabled: "false", passthru: "true", want: false},
		{name: "neither", enabled: "false", passthru: "false", want: false},
		{name: "both unset", enabled: "", passthru: "", want: false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Setenv("FIRSTREPLY_ENABLED", tt.enabled)
			t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", tt.passthru)

			assert.Equal(t, tt.want, Enabled())
		})
	}
}

// ---------------------------------------------------------------------------
// rolloutPercent
// ---------------------------------------------------------------------------

func TestRolloutPercent(t *testing.T) {
	tests := []struct {
		name  string
		value string
		unset bool
		want  int
	}{
		{name: "unset defaults to zero", unset: true, want: 0},
		{name: "empty string defaults to zero", value: "", want: 0},
		{name: "mid value", value: "50", want: 50},
		{name: "zero explicit", value: "0", want: 0},
		{name: "hundred exact", value: "100", want: 100},
		{name: "negative clamps to zero", value: "-5", want: 0},
		{name: "over hundred clamps to hundred", value: "150", want: 100},
		{name: "unparseable falls back to zero", value: "banana", want: 0},
		{name: "float string is unparseable", value: "12.5", want: 0},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if !tt.unset {
				t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", tt.value)
			}

			assert.Equal(t, tt.want, rolloutPercent())
		})
	}
}

// ---------------------------------------------------------------------------
// RolloutBucket
// ---------------------------------------------------------------------------

func TestRolloutBucket_KnownValues(t *testing.T) {
	// Pinned values: crc32.ChecksumIEEE("<msgid>|firstreply") % 100. These mirror
	// the PHP/MySQL CRC32 expressions this bucketing has to agree with, so a
	// change here is a signal the shared hash has drifted.
	tests := []struct {
		msgid uint64
		want  int
	}{
		{msgid: 1, want: 69},
		{msgid: 2, want: 93},
		{msgid: 3, want: 66},
		{msgid: 12345, want: 36},
		{msgid: 999999, want: 99},
		{msgid: 42, want: 89},
		{msgid: 100, want: 19},
		{msgid: 0, want: 82},
	}

	for _, tt := range tests {
		assert.Equal(t, tt.want, RolloutBucket(tt.msgid), "msgid=%d", tt.msgid)
	}
}

func TestRolloutBucket_Deterministic(t *testing.T) {
	// Same input must always produce the same bucket, since a post's trial
	// membership has to be stable for its whole life.
	first := RolloutBucket(555)
	second := RolloutBucket(555)
	assert.Equal(t, first, second)
}

func TestRolloutBucket_InRange(t *testing.T) {
	for _, msgid := range []uint64{0, 1, 7, 999, math.MaxUint32, math.MaxUint64} {
		bucket := RolloutBucket(msgid)
		assert.GreaterOrEqual(t, bucket, 0)
		assert.Less(t, bucket, 100)
	}
}

// ---------------------------------------------------------------------------
// inRollout
// ---------------------------------------------------------------------------

func TestInRollout(t *testing.T) {
	tests := []struct {
		name    string
		percent string
		msgid   uint64
		want    bool
	}{
		// msgid=100 buckets at 19.
		{name: "zero percent always excludes", percent: "0", msgid: 100, want: false},
		{name: "hundred percent always includes regardless of bucket", percent: "100", msgid: 999999, want: true},
		{name: "bucket strictly below percent is included", percent: "50", msgid: 100, want: true},
		{name: "bucket at or above percent is excluded", percent: "50", msgid: 1, want: false},
		{name: "bucket one above threshold is included", percent: "20", msgid: 100, want: true},
		{name: "bucket exactly at threshold is excluded", percent: "19", msgid: 100, want: false},
		{name: "negative percent clamps to zero and excludes", percent: "-10", msgid: 100, want: false},
		{name: "over-hundred percent clamps to hundred and includes", percent: "500", msgid: 1, want: true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", tt.percent)
			assert.Equal(t, tt.want, inRollout(tt.msgid))
		})
	}
}

// ---------------------------------------------------------------------------
// maxExistingRepliers
// ---------------------------------------------------------------------------

func TestMaxExistingRepliers(t *testing.T) {
	tests := []struct {
		name  string
		value string
		unset bool
		want  int
	}{
		{name: "unset defaults to one", unset: true, want: 1},
		{name: "empty string defaults to one", value: "", want: 1},
		{name: "explicit value", value: "5", want: 5},
		{name: "zero falls back to default", value: "0", want: 1},
		{name: "negative falls back to default", value: "-3", want: 1},
		{name: "unparseable falls back to default", value: "banana", want: 1},
		{name: "one explicit", value: "1", want: 1},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if !tt.unset {
				t.Setenv("FIRSTREPLY_PASSTHROUGH_MAX_REPLIERS", tt.value)
			}

			assert.Equal(t, tt.want, maxExistingRepliers())
		})
	}
}

// ---------------------------------------------------------------------------
// ShouldPassThrough — only the early-return branches that are reachable
// without a live database connection. The db-dependent branches (replier
// count, max_polygon containment) need a real *gorm.DB and are exercised by
// the integration suite instead.
// ---------------------------------------------------------------------------

func TestShouldPassThrough_DisabledReturnsFalseWithoutTouchingDB(t *testing.T) {
	t.Setenv("FIRSTREPLY_ENABLED", "false")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "false")

	// Passing a nil *gorm.DB proves the function returns before it ever
	// dereferences db: if it didn't, this call would panic instead of
	// returning false.
	got := ShouldPassThrough(nil, 100, -0.1, 51.5)
	assert.False(t, got)
}

func TestShouldPassThrough_EnabledButOutOfRolloutReturnsFalseWithoutTouchingDB(t *testing.T) {
	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "0")

	got := ShouldPassThrough(nil, 100, -0.1, 51.5)
	assert.False(t, got)
}
