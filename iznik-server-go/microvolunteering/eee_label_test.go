package microvolunteering

import "testing"

func TestIsValidEEECondition(t *testing.T) {
	cases := map[string]bool{
		"reusable": true,
		"damaged":  true,
		"unsure":   true,
		"":         false,
		"good":     false, // 5-level scheme was removed — only the 3-value set is valid
		"broken":   false,
		"REUSABLE": false, // case-sensitive on the wire so client and server stay aligned
	}
	for v, want := range cases {
		if got := isValidEEECondition(v); got != want {
			t.Errorf("isValidEEECondition(%q) = %v, want %v", v, got, want)
		}
	}
}

func TestIsValidEEEWeight(t *testing.T) {
	cases := map[string]bool{
		"under_1kg":  true,
		"1_5kg":      true,
		"5_20kg":     true,
		"20_100kg":   true,
		"over_100kg": true,
		"unsure":     true,
		"":           false,
		"heavy":      false,
		"5kg":        false,
	}
	for v, want := range cases {
		if got := isValidEEEWeight(v); got != want {
			t.Errorf("isValidEEEWeight(%q) = %v, want %v", v, got, want)
		}
	}
}

func TestIsValidEEESize(t *testing.T) {
	cases := map[string]bool{
		"tiny":   true,
		"small":  true,
		"medium": true,
		"large":  true,
		"unsure": true,
		"":       false,
		"huge":   false,
	}
	for v, want := range cases {
		if got := isValidEEESize(v); got != want {
			t.Errorf("isValidEEESize(%q) = %v, want %v", v, got, want)
		}
	}
}

func TestCleanSubject(t *testing.T) {
	cases := map[string]string{
		"OFFER: Washing machine (Hanwell W7)":  "Washing machine",
		"Offered: Old TV (Cambridge CB1)":      "Old TV",
		"WANTED: Bicycle":                      "Bicycle",
		"  Toaster  (Hammersmith W6)  ":        "Toaster",
		"Plain subject with no prefix":         "Plain subject with no prefix",
		"Offer: Something (no postcode)":       "Something", // bracketed tail is still stripped
		"":                                     "",
	}
	for in, want := range cases {
		if got := cleanSubject(in); got != want {
			t.Errorf("cleanSubject(%q) = %q, want %q", in, got, want)
		}
	}
}
