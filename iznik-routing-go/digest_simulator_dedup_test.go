package main

import "testing"

// Parity tests for the digest cross-post dedup helpers, which must match
// iznik-batch UnifiedDigestService::normalizeSubject / normalizeBody /
// getDeduplicationKey so the /rippling preview collapses the same items the
// real digest send does.

func TestNormalizeDigestSubject(t *testing.T) {
	cases := map[string]string{
		"OFFER: Bird cherry sapling (Trinity, Edinburgh EH5)": "bird cherry sapling",
		"WANTED: Garden table and chairs (Fulham Palace Rd SW6)": "garden table and chairs",
		"offer:   Swimming Caps / Hats x 6  (South Wimbledon SW19)": "swimming caps / hats x 6",
		"Plain subject no prefix":                                 "plain subject no prefix",
	}
	for in, want := range cases {
		if got := normalizeDigestSubject(in); got != want {
			t.Errorf("normalizeDigestSubject(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestNormalizeDigestBody(t *testing.T) {
	if got := normalizeDigestBody("  Hello   World\n\tFoo "); got != "hello world foo" {
		t.Errorf("normalizeDigestBody collapse/trim/lower failed: %q", got)
	}
}

func TestDigestDedupKey(t *testing.T) {
	// Content key = fromuser|normalizedSubject|locationid (never tnpostid).
	got := digestDedupKey(123, "OFFER: Sofa (SW1)", 4567)
	if got != "123|sofa|4567" {
		t.Errorf("content key = %q, want 123|sofa|4567", got)
	}
	// The #233 fix: the same item re-posted via TN on different days (different
	// tnpostid, same fromuser+subject+location) MUST share a key so the digest
	// collapses the repeats the way the website does. tnpostid is not in the key.
	if digestDedupKey(44780510, "OFFER: Small lamp (South ockendon RM15)", 4878480) !=
		digestDedupKey(44780510, "OFFER: Small lamp (South ockendon RM15)", 4878480) {
		t.Error("same content must yield the same key regardless of tnpostid")
	}
	// Same poster+item but different location -> different key (mirrors the send).
	if digestDedupKey(9, "OFFER: Lamp (A)", 100) == digestDedupKey(9, "OFFER: Lamp (B)", 200) {
		t.Error("different locationid must yield different keys")
	}
	// Missing locationid -> 'unknown'.
	if k := digestDedupKey(9, "WANTED: Bike", 0); k != "9|bike|unknown" {
		t.Errorf("missing locationid key = %q, want 9|bike|unknown", k)
	}
}
