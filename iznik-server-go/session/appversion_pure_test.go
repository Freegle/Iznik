package session

import "testing"

// Pure tests for the kill-switch comparison. No DB needed.
func TestWebversionOlderThan(t *testing.T) {
	cases := []struct {
		name string
		web  string
		min  string
		want bool
	}{
		{"older than threshold", "2026-06-01T00:00:00Z", "2026-06-10T00:00:00Z", true},
		{"newer than threshold", "2026-06-15T00:00:00Z", "2026-06-10T00:00:00Z", false},
		{"exactly equal is not older", "2026-06-10T00:00:00Z", "2026-06-10T00:00:00Z", false},
		{"older with tz offset", "2026-06-10T00:00:00+01:00", "2026-06-10T00:00:00Z", true},
		{"fractional seconds (toISOString form) parses", "2026-06-01T00:00:00.123Z", "2026-06-10T00:00:00Z", true},
		{"empty webversion fails open", "", "2026-06-10T00:00:00Z", false},
		{"unset threshold fails open", "2026-06-01T00:00:00Z", "", false},
		{"both empty", "", "", false},
		{"legacy non-ISO webversion fails open", "3.2.5", "2026-06-10T00:00:00Z", false},
		{"non-ISO threshold fails open", "2026-06-01T00:00:00Z", "not-a-date", false},
		{"date-only (non-RFC3339) webversion fails open", "2026-06-01", "2026-06-10T00:00:00Z", false},
	}
	for _, c := range cases {
		if got := webversionOlderThan(c.web, c.min); got != c.want {
			t.Errorf("%s: webversionOlderThan(%q, %q) = %v, want %v", c.name, c.web, c.min, got, c.want)
		}
	}
}

// ModTools and the Freegle app/web must be gated on separate config keys so they
// can be forced to update independently.
func TestMinWebversionConfigKey(t *testing.T) {
	if got := minWebversionConfigKey(true); got != "app_min_webversion_mt" {
		t.Errorf("modtools client: got %q, want app_min_webversion_mt", got)
	}
	if got := minWebversionConfigKey(false); got != "app_min_webversion" {
		t.Errorf("non-modtools client: got %q, want app_min_webversion", got)
	}
}
