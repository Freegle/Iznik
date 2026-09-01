package main

import (
	"testing"
)

// gridNodes builds a node slice with the 1-based sentinel the graph uses.
func gridNodes(coords ...[2]float32) []Node {
	nodes := make([]Node, len(coords)+1)
	for i, c := range coords {
		nodes[i+1] = Node{Lat: c[0], Lng: c[1]}
	}
	return nodes
}

// keyOf is the cell of a stored node. It must go through float32 exactly as
// buildGrid does: float32(51.45) widens to 51.45000076..., which truncates to
// cell 5145, while the float64 literal 51.45 truncates to 5144. Deriving the
// expected key from the literal instead of the stored value tests the wrong
// thing.
func keyOf(nodes []Node, id NodeID) (int16, int16) {
	k := cellKey(float64(nodes[id].Lat), float64(nodes[id].Lng))
	return k[0], k[1]
}

func TestBuildGridCellMembership(t *testing.T) {
	// Three nodes in one cell, one in a neighbouring cell, one far away.
	nodes := gridNodes(
		[2]float32{51.4501, -2.5801}, // cell (5145, -258)
		[2]float32{51.4502, -2.5802},
		[2]float32{51.4509, -2.5809},
		[2]float32{51.4601, -2.5801}, // cell (5146, -258)
		[2]float32{55.9500, -3.1900}, // cell (5595, -319)
	)
	gr := buildGrid(nodes)

	if got := gr.cellCount(); got != 3 {
		t.Fatalf("cellCount = %d, want 3", got)
	}

	row, col := keyOf(nodes, 1)
	first := gr.at(row, col)
	if len(first) != 3 {
		t.Fatalf("busy cell has %d nodes, want 3 (%v)", len(first), first)
	}
	seen := map[NodeID]bool{}
	for _, id := range first {
		seen[id] = true
	}
	for _, want := range []NodeID{1, 2, 3} {
		if !seen[want] {
			t.Errorf("busy cell missing node %d, got %v", want, first)
		}
	}

	row4, col4 := keyOf(nodes, 4)
	second := gr.at(row4, col4)
	if len(second) != 1 || second[0] != 4 {
		t.Errorf("neighbour cell = %v, want [4]", second)
	}
}

func TestBuildGridEmptyCellIsNil(t *testing.T) {
	gr := buildGrid(gridNodes([2]float32{51.45, -2.58}))
	if got := gr.at(9000, 9000); got != nil {
		t.Errorf("empty cell = %v, want nil", got)
	}
}

func TestBuildGridSkipsZeroCoordinateSentinel(t *testing.T) {
	// The node at index 0 is the sentinel and must never be indexed, and a real
	// node that somehow has (0,0) is skipped by the same rule the map version
	// used, so the two builders agree.
	nodes := gridNodes(
		[2]float32{0, 0},
		[2]float32{51.45, -2.58},
	)
	gr := buildGrid(nodes)
	if got := gr.cellCount(); got != 1 {
		t.Fatalf("cellCount = %d, want 1 (zero-coordinate nodes are skipped)", got)
	}
	if got := len(gr.flat); got != 1 {
		t.Fatalf("flat length = %d, want 1", got)
	}
	row, col := keyOf(nodes, 2)
	if got := gr.at(row, col); len(got) != 1 || got[0] != 2 {
		t.Errorf("cell = %v, want [2]", got)
	}
}

func TestBuildGridOffsetsArePackedExactly(t *testing.T) {
	// CSR invariant: offs is non-decreasing, starts at 0, ends at len(flat),
	// and the cells partition flat with no slack. This is the property that
	// replaces the old append-growth allocation, so it is worth pinning.
	var coords [][2]float32
	for i := 0; i < 200; i++ {
		coords = append(coords, [2]float32{
			51.0 + float32(i%7)*0.01,
			-2.0 + float32(i%5)*0.01,
		})
	}
	gr := buildGrid(gridNodes(coords...))

	if gr.offs[0] != 0 {
		t.Errorf("offs[0] = %d, want 0", gr.offs[0])
	}
	if int(gr.offs[len(gr.offs)-1]) != len(gr.flat) {
		t.Errorf("offs ends at %d, want len(flat) = %d", gr.offs[len(gr.offs)-1], len(gr.flat))
	}
	for i := 1; i < len(gr.offs); i++ {
		if gr.offs[i] < gr.offs[i-1] {
			t.Fatalf("offs not monotonic at %d: %d < %d", i, gr.offs[i], gr.offs[i-1])
		}
	}
	if len(gr.flat) != 200 {
		t.Errorf("flat holds %d ids, want 200 (every node placed exactly once)", len(gr.flat))
	}

	// Every node id appears exactly once across all cells.
	count := map[NodeID]int{}
	for _, id := range gr.flat {
		count[id]++
	}
	if len(count) != 200 {
		t.Errorf("%d distinct ids in flat, want 200", len(count))
	}
	for id, n := range count {
		if n != 1 {
			t.Errorf("node %d appears %d times", id, n)
		}
	}
}

func TestGridNilReceiverIsSafe(t *testing.T) {
	var gr *Grid
	if got := gr.at(0, 0); got != nil {
		t.Errorf("nil grid at() = %v, want nil", got)
	}
	if got := gr.cellCount(); got != 0 {
		t.Errorf("nil grid cellCount() = %d, want 0", got)
	}
}
