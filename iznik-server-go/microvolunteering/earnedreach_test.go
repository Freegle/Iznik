package microvolunteering

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// The earned-reach switch is read by the batch through EnvSetting::flag (config/freegle.php), the
// one rule every post-moderation switch follows: on for "true" or "1", any case, trimmed, and off
// for anything else. It must be read the same way here or the reviewer pool and the reach gate
// disagree: "yes" is off on both sides.
func TestEarnedReachEnabledIsReadLikeTheBatchReadsIt(t *testing.T) {
	for _, v := range []string{"true", "True", "TRUE", "1", " true "} {
		t.Setenv("RIPPLE_EARNED_REACH_ENABLED", v)
		assert.True(t, earnedReachEnabled(), "%q turns it on", v)
	}

	for _, v := range []string{"", "false", "False", "0", "(true)", "yes", "on", "2"} {
		t.Setenv("RIPPLE_EARNED_REACH_ENABLED", v)
		assert.False(t, earnedReachEnabled(), "%q leaves it off", v)
	}
}
