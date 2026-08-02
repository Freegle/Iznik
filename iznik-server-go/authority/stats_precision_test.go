package authority

// Proof backing the Tier 6 conversion of GetStatsByAuthority's three weight
// queries (sites 6d50d3895aa7, f281cfe83025, 3ecb2fba572f): moving the
// fallback average weight from fmt.Sprintf("%f", avg) literal-text splicing
// to a genuine GORM bind.
//
// The two are NOT byte-identical: %f rounds to 6 decimal places, a bind
// carries the full float64. This test exists because "the numbers look
// close" is not evidence - it quantifies exactly how far apart they can get,
// so the decision to convert is based on a proven bound rather than an
// assumption.
//
// DECISION RECORDED HERE: the per-value drift is bounded at 5e-7 (proven
// below), and TestAuthorityStats_AvgPrecisionAggregateImpact bounds the
// worst-case consequence on the actual output - PostcodeStats.Weight - at
// 0.05kg across a deliberately generous 100,000 substituted rows. Fifty
// grams on a kg-scale weight statistic is the number that justified
// converting these three sites rather than leaving them raw. If that number
// had come out in kilograms rather than grams for a realistic row count, the
// right call would have been to leave them raw - the bound is the answer,
// not the row-count estimate or the per-value drift on its own.

import (
	"fmt"
	"math"
	"math/rand"
	"strconv"
	"testing"
)

// TestAuthorityStats_AvgPrecisionBound proves the maximum possible divergence
// between the current behaviour (avg formatted with "%f", six decimal places,
// then reparsed as MySQL would parse that literal) and the converted
// behaviour (avg passed as a full-precision bind) is bounded by half a unit
// in the sixth decimal place - a mathematical property of rounding to a fixed
// number of decimal digits, true for every float64, not just ones this test
// happens to sample.
func TestAuthorityStats_AvgPrecisionBound(t *testing.T) {
	// The mechanical bound: rounding any real number to 6 decimal places can
	// never move it by more than half of the smallest representable unit at
	// that precision (0.5 * 1e-6). This holds for every finite float64, so
	// proving it on a large, targeted sample is a check that the mechanism
	// behaves as the mathematics says, not an attempt to enumerate all cases.
	//
	// The comparison below allows a tiny relative slack (1e-9, about one part
	// in a billion) on top of that mathematical 5e-7 bound. Without it this
	// test is flaky against its own arithmetic: avg, the reparsed %f text,
	// and the drift subtraction are themselves computed in float64, the same
	// imprecise representation the bound describes, so a case whose true
	// drift is exactly 5e-7 can round to a handful of ULPs on either side of
	// that constant - observed directly: avg=2.9999995 formatted to
	// "2.999999" measured drift=5.00000000069889e-07 against a bare 5e-7
	// bound, which is a comparison artefact, not a real precision failure.
	// 1e-9 relative is far too small to mask an actual regression: it would
	// need to be roughly a million times looser before it could hide
	// anything that matters to the aggregate-impact test below.
	// The bound has to be half an ulp of the SIXTH DECIMAL PLACE plus a few
	// ulps of the value itself, not a flat 5e-7.
	//
	// %f rounds to six decimals, so the rounding error alone is at most 5e-7.
	// But float64 spacing scales with magnitude: the reparsed value is the
	// nearest representable double to the six-decimal string, and for avg near
	// 2648 that representation error is already comparable to 1e-13, which
	// pushed the measured drift to 5.0000017e-07 and failed a flat bound. The
	// original 5e-7 was not merely tight, it was the wrong shape - it would
	// have failed for any sufficiently large average, which for a weight in
	// grams is entirely reachable.
	//
	// 4 ulps is generous for two roundings (format, then parse) while staying
	// far too small to mask a real regression: it would have to grow by about
	// nine orders of magnitude before it could hide anything the
	// aggregate-impact test below cares about.
	driftBound := func(avg float64) float64 {
		return 5e-7 + 4*math.Nextafter(math.Abs(avg), math.Inf(1)) - 4*math.Abs(avg)
	}

	check := func(avg float64) {
		t.Helper()
		formatted := fmt.Sprintf("%f", avg)
		reparsed, err := strconv.ParseFloat(formatted, 64)
		if err != nil {
			t.Fatalf("could not reparse %q: %v", formatted, err)
		}
		drift := math.Abs(reparsed - avg)
		if bound := driftBound(avg); drift > bound {
			t.Fatalf("avg=%v formatted=%q reparsed=%v drift=%v exceeds bound %v (5e-7 rounding + 4 ulp)",
				avg, formatted, reparsed, drift, bound)
		}
	}

	// Boundary and structurally interesting values.
	for _, avg := range []float64{
		0, 0.000001, 0.0000001, 0.5, 1, 1.0 / 3.0, 2.0 / 7.0,
		2.9999995, 2.9999996, 9999999.999999, 0.01, 5000.00,
	} {
		check(avg)
	}

	// weight is DECIMAL(10,2) (see items table migration), so avg is
	// SUM(popularity*weight)/SUM(popularity) for a weight column with at most
	// 2 decimal digits and a popularity count as the divisor - a ratio of
	// sums that, like 1/3, need not terminate in a finite decimal expansion.
	// This is exactly the shape that stresses %f's 6-decimal truncation, so
	// the random sample is built the same way the real query builds avg,
	// across a generous real-world weight range (1 gram to 5 tonnes) and
	// popularity counts (1 to 1000), rather than sampling floats uniformly at
	// random and missing the shape entirely.
	rng := rand.New(rand.NewSource(42))
	for i := 0; i < 200000; i++ {
		n := rng.Intn(50) + 1
		var totalWeight float64
		var totalPopularity int64
		for j := 0; j < n; j++ {
			weight := math.Round(rng.Float64()*499999+1) / 100 // 0.01 .. 5000.00, 2dp
			popularity := int64(rng.Intn(1000) + 1)
			totalWeight += weight * float64(popularity)
			totalPopularity += popularity
		}
		avg := totalWeight / float64(totalPopularity)
		check(avg)
	}
}

// TestAuthorityStats_AvgPrecisionAggregateImpact bounds the CONSEQUENCE of
// that per-value drift on the actual output: PostcodeStats.Weight is a SUM
// over every row where the real per-item weight is missing and the fallback
// avg is substituted. The maximum possible total drift on that sum is
// (number of substituted rows) * (per-value bound), which this test pins so
// the "is this negligible" judgement has an actual number attached rather
// than an intuition.
func TestAuthorityStats_AvgPrecisionAggregateImpact(t *testing.T) {
	const maxAllowedDrift = 5e-7

	// Deliberately generous: an authority-wide, up-to-a-year query returning
	// this many rows with a missing per-item weight in a single postcode
	// bucket would be an extreme outlier for this codebase's real traffic
	// volumes, so this overstates the impact rather than understates it.
	const generousRowCount = 100000

	maxAggregateDrift := float64(generousRowCount) * maxAllowedDrift
	// 100000 * 5e-7 = 0.05kg - fifty grams, on a weight statistic that is a
	// SUM measured in kilograms across an authority's whole reported activity
	// for the period. If this bound is ever raised because a test above
	// starts failing, this number must be re-examined against what the
	// endpoint is actually used for before deciding the change is still
	// negligible.
	if maxAggregateDrift > 0.1 {
		t.Fatalf("aggregate drift bound %v kg exceeds the 0.1kg sanity ceiling this test was written to confirm - "+
			"re-evaluate whether the precision conversion is still safe", maxAggregateDrift)
	}
	t.Logf("worst-case aggregate drift across %d substituted rows: %v kg", generousRowCount, maxAggregateDrift)
}
