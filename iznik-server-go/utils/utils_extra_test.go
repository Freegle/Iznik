package utils

// ---------------------------------------------------------------------------
// Additional coverage for TidyName uncovered branches
// ---------------------------------------------------------------------------
//
// Two branches in TidyName were unreachable by the existing tests:
//
//  1. tnOnlyRegexp branch: fires when the second tnRegexp.ReplaceAllString
//     produces a string matching ^-g[0-9]+$  (e.g. "-g123").
//     Trigger: triple TN suffix with no preceding name, e.g. "-g1-g2-g3".
//       first  tnRegexp("-g1-g2-g3")  → "-g1-g2"  (non-empty, skips first guard)
//       second tnRegexp("-g1-g2")     → "-g1"      (matches tnOnlyRegexp)
//
//  2. Final len(name)==0 guard: fires when the second tnRegexp produces ""
//     and tnOnlyRegexp does NOT match (because "" doesn't match ^-g[0-9]+$).
//     Trigger: double TN suffix with no preceding name, e.g. "-g1-g2".
//       first  tnRegexp("-g1-g2") → "-g1"   (non-empty, skips first guard)
//       second tnRegexp("-g1")    → ""       (not matched by tnOnlyRegexp)
//       final  len==0 guard fires → "A freegler"
//
// Note on RandomUint64: the `v = 1` guard (zero-protection after 53-bit mask)
// has P(hit) ≈ 1/2^53 and cannot be exercised without replacing crypto/rand —
// which would require changing production code. That branch is left at its
// current 83.3% coverage intentionally.

import "testing"

func TestTidyName_TripleTNSuffixNoPrecedingNameHitsTNOnlyRegexp(t *testing.T) {
	// Three consecutive TN suffixes with no preceding name.
	// Path: first tnRegexp → "-g1-g2" (non-empty), second tnRegexp → "-g1"
	// which matches tnOnlyRegexp → "A freegler".
	got := TidyName("-g1-g2-g3")
	if got != "A freegler" {
		t.Errorf("TidyName(%q) = %q; want %q", "-g1-g2-g3", got, "A freegler")
	}
}

func TestTidyName_DoubleTNSuffixNoPrecedingNameHitsFinalGuard(t *testing.T) {
	// Two consecutive TN suffixes with no preceding name.
	// Path: first tnRegexp → "-g1" (non-empty), second tnRegexp → ""
	// ("" does not match tnOnlyRegexp), final len==0 guard fires → "A freegler".
	got := TidyName("-g1-g2")
	if got != "A freegler" {
		t.Errorf("TidyName(%q) = %q; want %q", "-g1-g2", got, "A freegler")
	}
}

func TestTidyName_MultiDigitDoubleTNSuffix(t *testing.T) {
	// Same path as above but with realistic multi-digit TN suffix numbers.
	got := TidyName("-g123456-g789012")
	if got != "A freegler" {
		t.Errorf("TidyName(%q) = %q; want %q", "-g123456-g789012", got, "A freegler")
	}
}

func TestTidyName_MultiDigitTripleTNSuffix(t *testing.T) {
	got := TidyName("-g123456-g789012-g345678")
	if got != "A freegler" {
		t.Errorf("TidyName(%q) = %q; want %q", "-g123456-g789012-g345678", got, "A freegler")
	}
}
