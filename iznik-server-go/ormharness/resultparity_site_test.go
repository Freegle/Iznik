package ormharness

// AssertResultParityForSite exists because Layer 2 could not name the site it
// proved: AssertResultParity takes the original SQL, not a site ID, so a site
// whose only evidence was a result-parity test was invisible to Gate 2 and read
// as "raw SQL deleted with nothing to account for it". getReviewQueue was
// exactly that, and its keep-raw rule was masking it - gate (f) skips keep-raw
// sites, so "the rule says it stays raw" hid "the raw SQL is already gone with
// nothing naming it".
//
// The wrapper therefore has one job beyond delegating: reject a site ID that is
// not in the manifest, so a typo fails loudly rather than quietly proving
// nothing. AssertGoldenSQL has that guard pinned by
// TestAssertGoldenSQL_UnknownSiteFails; this is the same guard on the Layer 2
// entry point, which had none until now.
//
// Only the rejection path is exercised here - it is the half that can be
// checked without a database, and the half that fails silently if broken. The
// success path needs a live connection and is covered by the Layer 2 tests in
// package test that call this function.

import (
	"strings"
	"testing"
)

func TestAssertResultParityForSite_UnknownSiteFails(t *testing.T) {
	// recordingT.Fatalf panics to reproduce the real Fatalf's "does not
	// return" behaviour, so the call has to be unwound the same way
	// runAssertGoldenSQL does - calling the assertion directly lets that
	// controlled panic escape and fails the test for the wrong reason.
	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r) // a genuine panic, not our controlled unwind
			}
		}()
		AssertResultParityForSite(rec, "not-a-real-site-id", nil, "SELECT 1", nil, nil)
	}()

	if !rec.failed {
		t.Fatalf("expected AssertResultParityForSite to reject a site id absent from the manifest")
	}
	if !strings.Contains(rec.msg, "no manifest entry for site") {
		t.Fatalf("expected the rejection to name the missing site, got: %s", rec.msg)
	}
}
