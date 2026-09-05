package main

import (
	"path/filepath"
	"testing"
)

// makeBridgeGraph builds two dense residential grids joined by exactly two
// long bridge roads — the min cut between the halves is 2.
func makeBridgeGraph() *Graph {
	var nodes []RawNodeSpec
	var ways []RawWaySpec
	id := int64(1)
	grid := func(latO, lngO float64, rows, cols int) [][]int64 {
		ids := make([][]int64, rows)
		for r := 0; r < rows; r++ {
			ids[r] = make([]int64, cols)
			for c := 0; c < cols; c++ {
				nodes = append(nodes, RawNodeSpec{OSMID: id, Lat: latO + float64(r)*0.001, Lng: lngO + float64(c)*0.001})
				ids[r][c] = id
				id++
			}
		}
		for r := 0; r < rows; r++ {
			for c := 0; c+1 < cols; c++ {
				ways = append(ways, RawWaySpec{NodeIDs: []int64{ids[r][c], ids[r][c+1]}, Highway: "residential"})
			}
		}
		for r := 0; r+1 < rows; r++ {
			for c := 0; c < cols; c++ {
				ways = append(ways, RawWaySpec{NodeIDs: []int64{ids[r][c], ids[r+1][c]}, Highway: "residential"})
			}
		}
		return ids
	}
	west := grid(51.40, -2.70, 12, 12)
	east := grid(51.40, -2.55, 12, 12) // ~10km east: a genuine gap
	// Two bridges: north and south.
	ways = append(ways,
		RawWaySpec{NodeIDs: []int64{west[2][11], east[2][0]}, Highway: "primary"},
		RawWaySpec{NodeIDs: []int64{west[9][11], east[9][0]}, Highway: "primary"},
	)
	return BuildGraphFromRaw(nodes, ways, nil)
}

func TestPartitionFindsBridgeCut(t *testing.T) {
	g := makeBridgeGraph()
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 200, 0.25)

	if len(part.Stats) == 0 {
		t.Fatal("no bisections recorded")
	}
	top := part.Stats[0]
	if top.Cut > 2 {
		t.Fatalf("top bisection cut = %d, want <= 2 (the two bridges)", top.Cut)
	}
	if top.Balance < 0.2 {
		t.Fatalf("top bisection balance = %.2f, want >= 0.2", top.Balance)
	}
}

func TestPartitionGridCutIsOneRow(t *testing.T) {
	g := makeTestGrid(nil)
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 700, 0.25)

	top := part.Stats[0]
	// A 50×50 grid's balanced min cut is one grid line = 50 edges (49-51 with
	// the absorbed corners); inertial flow must find essentially that.
	if top.Cut > 55 {
		t.Fatalf("grid top cut = %d, want <= 55", top.Cut)
	}
	for _, l := range part.LeafNodes {
		if len(l) > 700 {
			t.Fatalf("leaf size %d exceeds leafMax", len(l))
		}
	}
}

func TestPartitionInvariantsBristol(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 3000, 0.25)

	// Every drive-usable overlay node has a leaf; LeafOf agrees with LeafNodes.
	seen := 0
	for leaf, lst := range part.LeafNodes {
		if len(lst) == 0 {
			t.Fatalf("leaf %d is empty", leaf)
		}
		if len(lst) > 3000 {
			t.Fatalf("leaf %d size %d exceeds leafMax", leaf, len(lst))
		}
		for _, oi := range lst {
			if part.LeafAt(oi) != int32(leaf) {
				t.Fatalf("LeafOf[%d]=%d, want %d", oi, part.LeafAt(oi), leaf)
			}
			seen++
		}
	}
	// A node belongs to the drive partition if it has a drive chain edge in
	// EITHER direction (a oneway sink has only incoming ones).
	incident := make([]bool, ov.NodeCount()+1)
	for oi := uint32(1); oi <= uint32(ov.NodeCount()); oi++ {
		for _, e := range ov.EdgesFrom(oi) {
			if e.To != oi {
				incident[oi] = true
				incident[e.To] = true
			}
		}
	}
	driveJunctions := 0
	for _, in := range incident {
		if in {
			driveJunctions++
		}
	}
	if seen != driveJunctions {
		t.Fatalf("partition covers %d nodes, drive overlay has %d", seen, driveJunctions)
	}
	t.Logf("bristol partition: %d leaves over %d drive junctions, %d bisections",
		len(part.LeafNodes), seen, len(part.Stats))

	// Round-trip the artifact.
	path := filepath.Join(t.TempDir(), "partition.snap")
	ovFP := overlayFingerprint(ov)
	if err := savePartition(path, part, ovFP); err != nil {
		t.Fatalf("save: %v", err)
	}
	part2, err := loadPartition(path, ovFP)
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	// A partition built on a different overlay must be refused, not read
	// through against a numbering it was never built for.
	if _, err := loadPartition(path, ovFP^1); err == nil {
		t.Fatal("a partition from a different overlay was accepted")
	}
	if len(part2.LeafNodes) != len(part.LeafNodes) || len(part2.Stats) != len(part.Stats) {
		t.Fatal("partition artifact round trip lost data")
	}
	for i := range part.LeafOf {
		if part.LeafOf[i] != part2.LeafOf[i] {
			t.Fatalf("LeafOf[%d] differs after round trip", i)
		}
	}
}
