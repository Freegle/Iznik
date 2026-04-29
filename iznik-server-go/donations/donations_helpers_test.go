package donations

import (
	"os"
	"testing"
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
