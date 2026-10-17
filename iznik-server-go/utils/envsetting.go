package utils

import (
	"os"
	"strconv"
	"strings"
)

// The post-moderation settings are read by the batch (the cron that publishes posts) and by this
// API (the countdown, the Check queue and its badge). docker-compose hands both the same raw
// strings, so both must apply the same rule to them: a switch one side reads as on and the other
// as off means posts that publish themselves with nothing on screen to say so, or a countdown for
// a post nothing will publish.
//
// EnvSwitch, EnvPositiveInt and EnvGroupIDs are the batch's App\Support\EnvSetting::flag,
// positiveInt and groupIds, case for case. utils/envsetting_test.go holds the same cases as the
// batch's tests/Unit/Support/EnvSettingTest.php; a case added to one belongs in the other.
// Every switch the two sides share goes through these; a `(bool) env()` read on the batch side
// is a bug, not a second rule.

// phpTrimChars is what PHP's trim() strips by default. strings.TrimSpace would also strip
// non-breaking and other Unicode spaces, which PHP leaves in place.
const phpTrimChars = " \t\n\r\x00\x0b"

// EnvSwitch reports whether the named variable switches a feature on: its trimmed value is
// "true" or "1", in any case. Anything else is off, "yes", "on", "off", "no" and "2" included:
// a master switch that publishes posts must not be turned on by a word that merely looks like a
// value. Mirrors EnvSetting::flag.
func EnvSwitch(name string) bool {
	v := strings.Trim(os.Getenv(name), phpTrimChars)

	return strings.EqualFold(v, "true") || v == "1"
}

// EnvPositiveInt reads the named variable as a positive whole number, else def. Blank, zero,
// negative, fractional, exponent and non-numeric values all mean the default, as does a number of
// more than nine digits, which is a typo and not a setting: a typo must never give a wait of
// zero. A leading plus sign and leading zeros are allowed. Mirrors EnvSetting::positiveInt.
func EnvPositiveInt(name string, def int) int {
	v := strings.TrimPrefix(strings.Trim(os.Getenv(name), phpTrimChars), "+")
	if len(v) == 0 || len(v) > 9 || !asciiDigits(v) {
		return def
	}

	n, err := strconv.Atoi(v)
	if err != nil || n <= 0 {
		return def
	}

	return n
}

// EnvGroupIDs reads the named variable as a comma-separated list of community ids. A token counts
// only when it is plain ASCII digits after trimming, at most 18 of them, so "12abc", "1e3", "+12"
// and "-3" are dropped and never guessed at, and a long typo cannot overflow into a real id. Zero
// is not a community. Each id is listed once. Mirrors EnvSetting::groupIds.
func EnvGroupIDs(name string) []uint64 {
	var ids []uint64
	seen := map[uint64]bool{}

	for _, token := range strings.Split(os.Getenv(name), ",") {
		token = strings.Trim(token, phpTrimChars)
		if len(token) == 0 || len(token) > 18 || !asciiDigits(token) {
			continue
		}

		id, err := strconv.ParseUint(token, 10, 64)
		if err != nil || id == 0 || seen[id] {
			continue
		}

		seen[id] = true
		ids = append(ids, id)
	}

	return ids
}

// asciiDigits is true for a non-empty string of the digits 0 to 9 and nothing else: not the digits
// of other scripts, which unicode.IsDigit and strconv would not agree about.
func asciiDigits(s string) bool {
	if len(s) == 0 {
		return false
	}

	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}

	return true
}
