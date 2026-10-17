package utils

import (
	"os"
	"testing"

	"github.com/stretchr/testify/assert"
)

// The cases for EnvSwitch, EnvPositiveInt and EnvGroupIDs are those of the batch's
// tests/Unit/Support/EnvSettingTest.php, which pins the same rule on its side. A case added to one
// belongs in the other.

func TestEnvSwitch(t *testing.T) {
	cases := []struct {
		name  string
		value string
		on    bool
	}{
		{"true", "true", true},
		{"TRUE", "TRUE", true},
		{"True", "True", true},
		{"padded true", "  true ", true},
		{"one", "1", true},
		{"padded one", " 1 ", true},
		{"yes is not on", "yes", false},
		{"on is not on", "on", false},
		{"off is off", "off", false},
		{"no is off", "no", false},
		{"disabled is off", "disabled", false},
		{"false", "false", false},
		{"zero", "0", false},
		{"two", "2", false},
		{"empty", "", false},
		{"blank", "   ", false},
		{"typo", "truee", false},
	}

	for _, c := range cases {
		t.Setenv("ENV_SETTING_FOR_TEST", c.value)
		assert.Equal(t, c.on, EnvSwitch("ENV_SETTING_FOR_TEST"), "%s: %q", c.name, c.value)
	}

	os.Unsetenv("ENV_SETTING_NEVER_SET_FOR_TEST")
	assert.False(t, EnvSwitch("ENV_SETTING_NEVER_SET_FOR_TEST"), "unset is off")
}

func TestEnvPositiveInt(t *testing.T) {
	// The default is 30 here so that a fall back to it cannot be mistaken for a value.
	cases := []struct {
		name  string
		value string
		want  int
	}{
		{"plain", "20", 20},
		{"padded", " 15 ", 15},
		{"plus sign", "+7", 7},
		{"leading zeros", "007", 7},
		{"zero", "0", 30},
		{"negative", "-5", 30},
		{"letters", "abc", 30},
		{"units suffix", "20min", 30},
		{"decimal", "12.5", 30},
		{"exponent", "1e3", 30},
		{"empty", "", 30},
		{"non ascii digits", "٢٠", 30},
		{"nine digits", "123456789", 123456789},
		{"ten digits", "1234567890", 30},
	}

	for _, c := range cases {
		t.Setenv("ENV_SETTING_FOR_TEST", c.value)
		assert.Equal(t, c.want, EnvPositiveInt("ENV_SETTING_FOR_TEST", 30), "%s: %q", c.name, c.value)
	}

	os.Unsetenv("ENV_SETTING_NEVER_SET_FOR_TEST")
	assert.Equal(t, 30, EnvPositiveInt("ENV_SETTING_NEVER_SET_FOR_TEST", 30), "unset is the default")
}

func TestEnvGroupIDs(t *testing.T) {
	cases := []struct {
		name  string
		value string
		want  []uint64
	}{
		{"empty", "", nil},
		{"blank", "  ", nil},
		{"one", "12", []uint64{12}},
		{"spaces and trailing commas", " 12, 13 ,,", []uint64{12, 13}},
		{"zero is no group", "0", nil},
		{"letters", "abc", nil},
		{"digits then letters", "12abc,13", []uint64{13}},
		{"exponent", "1e3", nil},
		{"decimal", "12.9", nil},
		{"plus sign", "+12", nil},
		{"minus sign", "-3", nil},
		{"leading zeros", "012", []uint64{12}},
		{"duplicates", "12,12", []uint64{12}},
		{"non ascii digits", "٣", nil},
		{"too many digits", "1234567890123456789", nil},
	}

	for _, c := range cases {
		t.Setenv("ENV_SETTING_FOR_TEST", c.value)
		got := EnvGroupIDs("ENV_SETTING_FOR_TEST")
		if len(c.want) == 0 {
			assert.Empty(t, got, "%s: %q", c.name, c.value)
		} else {
			assert.Equal(t, c.want, got, "%s: %q", c.name, c.value)
		}
	}

	os.Unsetenv("ENV_SETTING_NEVER_SET_FOR_TEST")
	assert.Empty(t, EnvGroupIDs("ENV_SETTING_NEVER_SET_FOR_TEST"), "unset lists nobody")
}

func TestAutoapproveTrialGroup_GlobalSwitchIsReadLikeTheBatchReadsIt(t *testing.T) {
	// The batch publishes posts for every community when it reads the switch as on, so this side
	// must read it the same way for every value, not just the usual spellings: a value the batch
	// calls on and this side calls off publishes posts with no countdown, no Check queue and no
	// badge to say so, and the reverse shows a countdown for a post nothing will publish. The
	// batch's rule is "true" or "1", in any case; "off" and "no" are off (PHP's own cast reads
	// them as on, which is what this replaced).
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	for _, v := range []string{"true", "True", "TRUE", "1", " true "} {
		t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", v)
		assert.True(t, AutoapproveTrialGroup(5), "%q turns it on", v)
	}

	for _, v := range []string{"", "false", "False", "0", "yes", "on", "off", "no", "2", "truee"} {
		t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", v)
		assert.False(t, AutoapproveTrialGroup(5), "%q leaves it off", v)
	}
}

func TestAutoapproveTrialGroup_ListIsReadLikeTheBatchReadsIt(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")

	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", " 12, 13 ,,")
	assert.True(t, AutoapproveTrialGroup(12))
	assert.True(t, AutoapproveTrialGroup(13))
	assert.False(t, AutoapproveTrialGroup(14))

	// A token that is not plain digits is dropped, not guessed at: "12abc" is not community 12,
	// "1e3" is not 1000, "+12" is not 12 and zero is not a community.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "12abc,1e3,+12,0,13")
	assert.False(t, AutoapproveTrialGroup(12))
	assert.False(t, AutoapproveTrialGroup(1000))
	assert.False(t, AutoapproveTrialGroup(0))
	assert.True(t, AutoapproveTrialGroup(13))

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "12abc,13")
	assert.True(t, AutomodShadowGroup(13))
	assert.False(t, AutomodShadowGroup(12))
}

func TestAutoapproveDelayMinutes_IsReadLikeTheBatchReadsIt(t *testing.T) {
	// The countdown a moderator sees is the batch's wait. A value the batch takes as the default
	// (20) and this side takes literally, or the other way round, shows a countdown that is
	// wrong from the first post.
	cases := []struct {
		value string
		want  int
	}{
		{"", 20},
		{" 45 ", 45},
		{"+7", 7},
		{"007", 7},
		{"0", 20},
		{"-5", 20},
		{"abc", 20},
		{"12.5", 20},
		{"1e3", 20},
		{"20min", 20},
		{"123456789", 123456789},
		{"1234567890", 20},
	}

	for _, c := range cases {
		t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", c.value)
		assert.Equal(t, c.want, AutoapproveDelayMinutes(), "%q", c.value)
	}
}
