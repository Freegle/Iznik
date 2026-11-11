package utils

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// The same cases as the batch's ItemMatchKeyTest: both sides must give the same key.
func TestItemKey(t *testing.T) {
	for in, want := range map[string]string{
		"Knitting needles":  "knitting needle",
		"Cat Litter!":       "cat litter",
		"daisies":           "daisy",
		"shelves":           "shelf",
		"Child's bike":      "child bike",
		"Child’s bike":      "child bike",
		"crème brûlée dish": "creme brulee dish",
		"e-bike":            "ebike",
		"L plates":          "l plate",
		"glasses":           "glasses",
		"chest of drawers":  "chest of drawers",
		"Fish & chips":      "fish and chip",
		"boxes":             "box",
		"bus":               "bus",
		"  -- ":             "",
		"a-b-c":             "ab c",
	} {
		assert.Equal(t, want, ItemKey(in), in)
	}
}
