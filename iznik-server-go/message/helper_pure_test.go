package message

import "testing"

// Pure unit tests for the Freegle Helper mapping/utility functions that need no
// database or fiber context.

func TestProposalKindToSentKind(t *testing.T) {
	for _, k := range []string{"allocation", "rejection", "reminder", "withdrawal_notice"} {
		if got := proposalKindToSentKind(k); got != k {
			t.Errorf("proposalKindToSentKind(%q) = %q, want %q (known kinds pass through)", k, got, k)
		}
	}
	for _, k := range []string{"other", "", "Allocation", "unknown-kind"} {
		if got := proposalKindToSentKind(k); got != "other" {
			t.Errorf("proposalKindToSentKind(%q) = %q, want \"other\"", k, got)
		}
	}
}

func TestDerefU64(t *testing.T) {
	if got := derefU64(nil); got != 0 {
		t.Errorf("derefU64(nil) = %d, want 0", got)
	}
	v := uint64(42)
	if got := derefU64(&v); got != 42 {
		t.Errorf("derefU64(&42) = %d, want 42", got)
	}
}
