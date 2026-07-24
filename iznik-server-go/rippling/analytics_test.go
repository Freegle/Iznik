package rippling

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// bullseyeEdges = [0, 10, 15, 20, 25, 30, 45] -> bands [0,10) [10,15) [15,20)
// [20,25) [25,30) [30,45]. Every band except the last is hi-exclusive; the
// last is inclusive at BOTH ends so no scored reply (capped at driveMaxMinutes
// == the top edge) is ever dropped. This table drives one value AT each of
// the six edges plus the two out-of-range values, and asserts which single
// band claims it.
func TestBullseye_EveryRingEdgeAssignedToExactlyOneBand(t *testing.T) {
	assert.Equal(t, []int{0, 10, 15, 20, 25, 30, driveMaxMinutes}, bullseyeEdges)

	cases := []struct {
		name     string
		min      float64
		wantBand int // index into the returned bands slice, or -1 for "no band"
	}{
		{"below the first ring is excluded from every band", -0.01, -1},
		{"edge 0 (first ring lo, inclusive) falls in band 0 [0,10)", 0, 0},
		{"just under edge 10 falls in band 0 [0,10)", 9.99, 0},
		{"edge 10 (shared boundary) falls in band 1 [10,15), NOT band 0 (band 0 is hi-exclusive)", 10, 1},
		{"edge 15 falls in band 2 [15,20), NOT band 1", 15, 2},
		{"edge 20 falls in band 3 [20,25), NOT band 2", 20, 3},
		{"edge 25 falls in band 4 [25,30), NOT band 3", 25, 4},
		{"edge 30 falls in band 5 [30,45], NOT band 4 - the last ring's lo is inclusive same as every other ring", 30, 5},
		{"edge 45 (top edge, last ring) is INCLUSIVE - special-cased so the cap value is not dropped", 45, 5},
		{"above the top ring is excluded from every band", 45.01, -1},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			bands := Bullseye([]float64{c.min}, []bool{false})
			assert.Len(t, bands, len(bullseyeEdges)-1)
			for i, b := range bands {
				if i == c.wantBand {
					assert.Equal(t, 1, b.NReplies, "band %d [%d,%d) should have claimed %v", i, b.MinLo, b.MinHi, c.min)
				} else {
					assert.Equal(t, 0, b.NReplies, "band %d [%d,%d) should NOT have claimed %v", i, b.MinLo, b.MinHi, c.min)
				}
			}
		})
	}
}

// A band with zero replies must not divide by zero when computing conversion
// / CI - both must stay at their Go zero value rather than NaN or panicking.
func TestBullseye_ZeroRepliesBandGuardsAgainstDivideByZero(t *testing.T) {
	// A single value in band 0 leaves every other band at NReplies == 0.
	bands := Bullseye([]float64{5}, []bool{true})
	for i, b := range bands {
		if i == 0 {
			continue
		}
		assert.Equal(t, 0, b.NReplies)
		assert.Equal(t, 0, b.NTakers)
		assert.Equal(t, 0.0, b.ConvPct, "zero-reply band must not divide by zero into NaN")
		assert.Equal(t, 0.0, b.CIHalf, "zero-reply band must not divide by zero into NaN")
	}
}

// All-takers and no-takers push the CI to its two extremes (p=1 and p=0);
// the normal-approximation CI half-width collapses to 0 at both extremes
// since p*(1-p) == 0.
func TestBullseye_ConversionExtremes(t *testing.T) {
	allTakers := Bullseye([]float64{1, 2, 3}, []bool{true, true, true})
	band0 := allTakers[0]
	assert.Equal(t, 3, band0.NReplies)
	assert.Equal(t, 3, band0.NTakers)
	assert.InDelta(t, 100.0, band0.ConvPct, 1e-9)
	assert.InDelta(t, 0.0, band0.CIHalf, 1e-9)

	noTakers := Bullseye([]float64{1, 2, 3}, []bool{false, false, false})
	band0b := noTakers[0]
	assert.Equal(t, 3, band0b.NReplies)
	assert.Equal(t, 0, band0b.NTakers)
	assert.Equal(t, 0.0, band0b.ConvPct)
	assert.InDelta(t, 0.0, band0b.CIHalf, 1e-9)
}

// Bullseye must tolerate takers being shorter (or longer) than mins without
// panicking - index-out-of-range positions are treated as non-takers.
func TestBullseye_MismatchedLengthSlicesTolerated(t *testing.T) {
	assert.NotPanics(t, func() {
		bands := Bullseye([]float64{1, 2, 3, 4, 5}, []bool{true})
		assert.Equal(t, 5, bands[0].NReplies)
		assert.Equal(t, 1, bands[0].NTakers) // only index 0 had a takers entry
	})

	assert.NotPanics(t, func() {
		// More takers than mins: the extra bool entries are simply unused.
		bands := Bullseye([]float64{1}, []bool{true, true, true})
		assert.Equal(t, 1, bands[0].NReplies)
		assert.Equal(t, 1, bands[0].NTakers)
	})
}

func TestBullseye_NilAndEmptyInputsReturnAllZeroBands(t *testing.T) {
	for _, bands := range [][]BullseyeBand{Bullseye(nil, nil), Bullseye([]float64{}, []bool{})} {
		assert.Len(t, bands, len(bullseyeEdges)-1)
		for _, b := range bands {
			assert.Equal(t, 0, b.NReplies)
			assert.Equal(t, 0, b.NTakers)
			assert.Equal(t, 0.0, b.ConvPct)
			assert.Equal(t, 0.0, b.CIHalf)
		}
	}
}
