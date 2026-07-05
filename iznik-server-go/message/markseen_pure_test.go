package message

import (
	"reflect"
	"testing"
)

// dedupeSortedIDs backs the batched "mark seen": it must sort ascending (so concurrent same-user
// mark-seens lock rows in a consistent order and can't deadlock) and drop duplicates (so a repeated
// id doesn't appear twice in one multi-row INSERT).
func TestDedupeSortedIDs(t *testing.T) {
	cases := []struct {
		name string
		in   []uint64
		want []uint64
	}{
		{"empty", []uint64{}, []uint64{}},
		{"single", []uint64{5}, []uint64{5}},
		{"already sorted, distinct", []uint64{1, 2, 3}, []uint64{1, 2, 3}},
		{"unsorted", []uint64{3, 1, 2}, []uint64{1, 2, 3}},
		{"duplicates", []uint64{2, 1, 2, 3, 1}, []uint64{1, 2, 3}},
		{"all same", []uint64{7, 7, 7}, []uint64{7}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := dedupeSortedIDs(tc.in)
			// Compare as sorted-distinct slices; empty vs single are returned as-is by the fast path.
			if len(tc.in) < 2 {
				if !reflect.DeepEqual(got, tc.in) {
					t.Fatalf("small input passthrough: got %v, want %v", got, tc.in)
				}
				return
			}
			if !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("dedupeSortedIDs(%v) = %v, want %v", tc.in, got, tc.want)
			}
		})
	}
}

// The input slice must not be mutated (it is the request body's IDs, which the caller may still use).
func TestDedupeSortedIDsDoesNotMutateInput(t *testing.T) {
	in := []uint64{3, 1, 2, 1}
	_ = dedupeSortedIDs(in)
	if !reflect.DeepEqual(in, []uint64{3, 1, 2, 1}) {
		t.Fatalf("input was mutated: %v", in)
	}
}
