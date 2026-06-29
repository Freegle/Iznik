package message

import (
	"testing"
)

// Pure unit tests for the bulk-offer helpers that need no database: the
// interest-active predicate and the plain-text catalogue summary.

func TestInterestIsActive(t *testing.T) {
	cases := map[string]bool{
		"Offered":    true,
		"Promised":   true,
		"Interested": true,
		"":           true,
		"Withdrawn":  false,
		"Rejected":   false,
	}
	for state, want := range cases {
		if got := interestIsActive(state); got != want {
			t.Errorf("interestIsActive(%q) = %v, want %v", state, got, want)
		}
	}
}

func TestBuildBulkSummary_FullCatalogueAndSlots(t *testing.T) {
	items := []BulkItemInput{
		{Name: "   ", Quantity: 2},                         // blank name -> skipped, no number
		{Name: "Sofa", Quantity: 0, Condition: "Good"},     // qty < 1 -> 1; condition shown
		{Name: "Chair", Quantity: 3, Condition: "Unknown"}, // "Unknown" condition hidden
		{Name: "Lamp", Quantity: 1, Condition: ""},         // empty condition hidden
	}
	slots := []string{"  Mon 9-5  ", "", "Tue 10-2"} // blank slot dropped, others trimmed

	got := buildBulkSummary(items, slots)
	want := "Items available in this offer:\n" +
		"1) 1× Sofa (Good)\n" +
		"2) 3× Chair\n" +
		"3) 1× Lamp" +
		"\n\nCollection times (let us know which suits you):\n" +
		"- Mon 9-5\n" +
		"- Tue 10-2"
	if got != want {
		t.Fatalf("buildBulkSummary mismatch:\n got=%q\nwant=%q", got, want)
	}
}

func TestBuildBulkSummary_NoSlotsOmitsWindowSection(t *testing.T) {
	got := buildBulkSummary([]BulkItemInput{{Name: "Table", Quantity: 1}}, nil)
	want := "Items available in this offer:\n1) 1× Table"
	if got != want {
		t.Fatalf("got=%q want=%q", got, want)
	}
}

func TestBuildBulkSummary_NoNamedItemsReturnsEmpty(t *testing.T) {
	// All items blank-named -> nothing to summarise -> empty string (even with slots).
	got := buildBulkSummary([]BulkItemInput{{Name: " "}, {Name: ""}}, []string{"Mon"})
	if got != "" {
		t.Fatalf("expected empty summary, got %q", got)
	}
}
