package donations

import (
	"os"
	"testing"
	"time"
)

func TestGetDonationTarget(t *testing.T) {
	orig := os.Getenv("DONATION_TARGET")
	t.Cleanup(func() { os.Setenv("DONATION_TARGET", orig) })

	os.Unsetenv("DONATION_TARGET")
	if got := getDonationTarget(); got != DEFAULT_DONATION_TARGET {
		t.Errorf("unset env: getDonationTarget() = %d, want %d", got, DEFAULT_DONATION_TARGET)
	}

	os.Setenv("DONATION_TARGET", "")
	if got := getDonationTarget(); got != DEFAULT_DONATION_TARGET {
		t.Errorf("empty env: getDonationTarget() = %d, want %d", got, DEFAULT_DONATION_TARGET)
	}

	os.Setenv("DONATION_TARGET", "7000")
	if got := getDonationTarget(); got != 7000 {
		t.Errorf("valid env: getDonationTarget() = %d, want 7000", got)
	}

	os.Setenv("DONATION_TARGET", "not-a-number")
	if got := getDonationTarget(); got != DEFAULT_DONATION_TARGET {
		t.Errorf("invalid env: getDonationTarget() = %d, want %d (default)", got, DEFAULT_DONATION_TARGET)
	}
}

func TestGetExcludedPayers(t *testing.T) {
	orig := os.Getenv("DONATIONS_EXCLUDE")
	t.Cleanup(func() { os.Setenv("DONATIONS_EXCLUDE", orig) })

	os.Unsetenv("DONATIONS_EXCLUDE")
	got := getExcludedPayers()
	if len(got) != 2 {
		t.Errorf("unset env: expected 2 default payers, got %d: %v", len(got), got)
	}

	os.Setenv("DONATIONS_EXCLUDE", "a@x.com, b@x.com ,c@x.com")
	got = getExcludedPayers()
	want := []string{"a@x.com", "b@x.com", "c@x.com"}
	if len(got) != len(want) {
		t.Fatalf("trimmed split: got %v want %v", got, want)
	}
	for i, w := range want {
		if got[i] != w {
			t.Errorf("[%d] got %q want %q", i, got[i], w)
		}
	}

	os.Setenv("DONATIONS_EXCLUDE", ",a@x.com,, ,b@x.com,")
	got = getExcludedPayers()
	if len(got) != 2 || got[0] != "a@x.com" || got[1] != "b@x.com" {
		t.Errorf("empty segments dropped: got %v want [a@x.com b@x.com]", got)
	}

	os.Setenv("DONATIONS_EXCLUDE", "only@x.com")
	got = getExcludedPayers()
	if len(got) != 1 || got[0] != "only@x.com" {
		t.Errorf("single entry: got %v", got)
	}
}

func TestGetStripeKey(t *testing.T) {
	origLive := os.Getenv("STRIPE_SECRET_KEY")
	origTest := os.Getenv("STRIPE_SECRET_KEY_TEST")
	t.Cleanup(func() {
		os.Setenv("STRIPE_SECRET_KEY", origLive)
		os.Setenv("STRIPE_SECRET_KEY_TEST", origTest)
	})

	os.Setenv("STRIPE_SECRET_KEY", "sk_live_xyz")
	os.Setenv("STRIPE_SECRET_KEY_TEST", "sk_test_abc")

	if got := getStripeKey(false); got != "sk_live_xyz" {
		t.Errorf("live: got %q want sk_live_xyz", got)
	}
	if got := getStripeKey(true); got != "sk_test_abc" {
		t.Errorf("test: got %q want sk_test_abc", got)
	}

	os.Unsetenv("STRIPE_SECRET_KEY")
	os.Unsetenv("STRIPE_SECRET_KEY_TEST")
	if got := getStripeKey(false); got != "" {
		t.Errorf("unset live: want empty, got %q", got)
	}
	if got := getStripeKey(true); got != "" {
		t.Errorf("unset test: want empty, got %q", got)
	}
}

func TestDonationConstants(t *testing.T) {
	if DEFAULT_DONATION_TARGET <= 0 {
		t.Errorf("DEFAULT_DONATION_TARGET must be positive, got %d", DEFAULT_DONATION_TARGET)
	}
	if DEFAULT_DONATIONS_EXCLUDE == "" {
		t.Errorf("DEFAULT_DONATIONS_EXCLUDE must not be empty")
	}
	if MANUAL_THANKS <= 0 {
		t.Errorf("MANUAL_THANKS must be positive, got %f", MANUAL_THANKS)
	}
	for _, name := range []string{TYPE_PAYPAL, TYPE_STRIPE, TYPE_EXTERNAL, TYPE_OTHER, SOURCE_BANK_TRANSFER, PERIOD_THIS} {
		if name == "" {
			t.Errorf("donation type/source/period constant must not be empty")
		}
	}
}

// TestParsePayPalDate covers all six format branches and the error path.
func TestParsePayPalDate(t *testing.T) {
	cases := []struct {
		name    string
		input   string
		wantErr bool
		// expected fields (checked only when !wantErr)
		wantYear  int
		wantMonth time.Month
		wantDay   int
		wantHour  int
		wantMin   int
		wantSec   int
	}{
		{
			name:      "PayPal format with timezone",
			input:     "12:34:56 Jan 02, 2026 UTC",
			wantYear:  2026, wantMonth: time.January, wantDay: 2,
			wantHour: 12, wantMin: 34, wantSec: 56,
		},
		{
			name:      "PayPal format without timezone",
			input:     "08:00:00 Mar 15, 2025",
			wantYear:  2025, wantMonth: time.March, wantDay: 15,
			wantHour: 8, wantMin: 0, wantSec: 0,
		},
		{
			name:      "MySQL datetime format",
			input:     "2026-05-24 09:30:00",
			wantYear:  2026, wantMonth: time.May, wantDay: 24,
			wantHour: 9, wantMin: 30, wantSec: 0,
		},
		{
			name:      "ISO 8601 format",
			input:     "2026-01-01T00:00:00Z",
			wantYear:  2026, wantMonth: time.January, wantDay: 1,
			wantHour: 0, wantMin: 0, wantSec: 0,
		},
		{
			name:      "RFC1123 format",
			input:     "Mon, 02 Jan 2006 15:04:05 UTC",
			wantYear:  2006, wantMonth: time.January, wantDay: 2,
			wantHour: 15, wantMin: 4, wantSec: 5,
		},
		{
			name:      "RFC1123Z format",
			input:     "Mon, 02 Jan 2006 15:04:05 +0000",
			wantYear:  2006, wantMonth: time.January, wantDay: 2,
			wantHour: 15, wantMin: 4, wantSec: 5,
		},
		{
			name:    "invalid gibberish returns error",
			input:   "not-a-date",
			wantErr: true,
		},
		{
			name:    "empty string returns error",
			input:   "",
			wantErr: true,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := parsePayPalDate(tc.input)
			if tc.wantErr {
				if err == nil {
					t.Errorf("parsePayPalDate(%q) expected error, got nil (time=%v)", tc.input, got)
				}
				return
			}
			if err != nil {
				t.Fatalf("parsePayPalDate(%q) unexpected error: %v", tc.input, err)
			}
			if got.Year() != tc.wantYear || got.Month() != tc.wantMonth || got.Day() != tc.wantDay {
				t.Errorf("parsePayPalDate(%q) date = %d-%d-%d, want %d-%d-%d",
					tc.input, got.Year(), got.Month(), got.Day(),
					tc.wantYear, tc.wantMonth, tc.wantDay)
			}
			if got.Hour() != tc.wantHour || got.Minute() != tc.wantMin || got.Second() != tc.wantSec {
				t.Errorf("parsePayPalDate(%q) time = %02d:%02d:%02d, want %02d:%02d:%02d",
					tc.input, got.Hour(), got.Minute(), got.Second(),
					tc.wantHour, tc.wantMin, tc.wantSec)
			}
		})
	}
}

// TestIsExcludedPayer covers default exclusions and custom env overrides.
func TestIsExcludedPayer(t *testing.T) {
	orig := os.Getenv("DONATIONS_EXCLUDE")
	t.Cleanup(func() { os.Setenv("DONATIONS_EXCLUDE", orig) })

	// Use defaults (unset env).
	os.Unsetenv("DONATIONS_EXCLUDE")

	defaultExcluded := []string{
		"ppgfukpay@paypalgivingfund.org",
		"paypal.msb@tipalti.com",
	}
	for _, email := range defaultExcluded {
		if !IsExcludedPayer(email) {
			t.Errorf("IsExcludedPayer(%q) = false with defaults, want true", email)
		}
	}

	// Case-insensitive match.
	if !IsExcludedPayer("PPGFUKPAY@PAYPALGIVINGFUND.ORG") {
		t.Error("IsExcludedPayer should be case-insensitive for default exclusion")
	}

	// Legitimate payer must not be excluded.
	if IsExcludedPayer("donor@example.com") {
		t.Error("IsExcludedPayer(\"donor@example.com\") = true, want false")
	}

	// Empty string is never an excluded payer.
	if IsExcludedPayer("") {
		t.Error("IsExcludedPayer(\"\") = true, want false")
	}

	// Custom env override replaces defaults.
	os.Setenv("DONATIONS_EXCLUDE", "custom@blocked.com")
	if !IsExcludedPayer("custom@blocked.com") {
		t.Error("IsExcludedPayer should respect custom DONATIONS_EXCLUDE")
	}
	if IsExcludedPayer("ppgfukpay@paypalgivingfund.org") {
		t.Error("default exclusion should not apply when DONATIONS_EXCLUDE is set")
	}

	// Case-insensitive for custom exclusion.
	if !IsExcludedPayer("CUSTOM@BLOCKED.COM") {
		t.Error("IsExcludedPayer should be case-insensitive for custom exclusions")
	}
}
