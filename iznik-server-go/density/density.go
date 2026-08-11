// Package density answers "how far is it worth travelling from here", from how
// thinly freeglers are spread around a point.
//
// The reach a POST gets is decided in iznik-batch by
// App\Services\Ripple\DensityService: dense areas are capped at 20 minutes
// because conversion collapses past ~20-25 there, sparse ones get 45 because it
// does not fall at all out to 45, and everything else keeps the flat 30. That
// decision is what a member actually experiences, so the browse slider has to
// describe the same thing rather than offering a fixed 5-30 that is too short in
// the country and too long in the city.
//
// This is therefore a deliberate second implementation of one policy. It is kept
// honest by reading the SAME env var names as
// iznik-batch/config/freegle.php ripple.density, with the same defaults, and by
// TestBandMatchesPhpPolicy mirroring DensityService::band()'s cases. Change one,
// change the other.
package density

import (
	"os"
	"strconv"

	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/utils"
)

const (
	BandDense  = "dense"
	BandMedium = "medium"
	BandSparse = "sparse"

	// BandUnknown means no usable measurement: the flat cap applies and the
	// caller says so, rather than guessing a band.
	BandUnknown = "unknown"
)

// Result is a cap plus the measurement behind it, so a client (or a support
// investigation) can read the number back against the reason for it.
type Result struct {
	Band        string
	RadiusMiles *float64
	MaxMinutes  float64
}

// knn is the spatial seam, swapped in tests.
var knn = spatial.KNN

func envFloat(name string, def float64) float64 {
	if v := os.Getenv(name); v != "" {
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			return f
		}
	}
	return def
}

func envInt(name string, def int) int {
	if v := os.Getenv(name); v != "" {
		if i, err := strconv.Atoi(v); err == nil {
			return i
		}
	}
	return def
}

// enabled mirrors config('freegle.ripple.density.enabled'), which defaults ON.
func enabled() bool {
	v := os.Getenv("RIPPLE_DENSITY_ENABLED")
	if v == "" {
		return true
	}
	b, err := strconv.ParseBool(v)
	return err != nil || b
}

// FlatMinutes is the cap that applies when density sizing is off or cannot
// measure. Mirrors config('freegle.ripple.max_minutes').
func FlatMinutes() float64 {
	return envFloat("RIPPLE_MAX_MINUTES", 30)
}

// K is how many nearest freeglers define the density radius.
func K() int {
	k := envInt("RIPPLE_DENSITY_K", 400)
	if k < 1 {
		k = 1
	}
	return k
}

func minutesForBand(band string) float64 {
	switch band {
	case BandDense:
		return envFloat("RIPPLE_DENSITY_MAX_MINUTES_DENSE", 20)
	case BandMedium:
		return envFloat("RIPPLE_DENSITY_MAX_MINUTES_MEDIUM", 30)
	case BandSparse:
		return envFloat("RIPPLE_DENSITY_MAX_MINUTES_SPARSE", 45)
	default:
		return FlatMinutes()
	}
}

// Band decides which band a nearest-k measurement falls in. Pure, so the policy
// is testable without a spatial server.
//
// Nothing found (or no answer at all) is unknown, NOT sparse: an empty result
// and an empty index look identical from here, and quietly stretching a London
// member's slider because the index was mid-rebuild is a worse failure than
// leaving them on the flat cap.
//
// Some found but fewer than k IS sparse, and confidently so: the KNN buffer
// ladder sweeps out to its ceiling before giving up, so failing to find k inside
// that means the true k-radius is past the sparse threshold whatever the exact
// figure. furthestMiles is then a lower bound rather than the radius, which is
// why the band is decided by the shortfall and not by the number.
func Band(found, k int, furthestMiles *float64) string {
	if found <= 0 || furthestMiles == nil {
		return BandUnknown
	}
	if found < k {
		return BandSparse
	}

	if *furthestMiles <= envFloat("RIPPLE_DENSITY_DENSE_MAX_MILES", 1.6) {
		return BandDense
	}
	if *furthestMiles <= envFloat("RIPPLE_DENSITY_MEDIUM_MAX_MILES", 3.1) {
		return BandMedium
	}
	return BandSparse
}

// CapFor is the travel-time budget worth offering at this point, plus the
// measurement behind it. Fails soft in every direction: an unreachable spatial
// server, an empty result or the killswitch all give BandUnknown and the flat
// cap.
func CapFor(lat, lng float64) Result {
	flat := Result{Band: BandUnknown, MaxMinutes: FlatMinutes()}

	if !enabled() {
		return flat
	}

	k := K()
	results, err := knn("userapproxlocs", lng, lat, k, "")
	if err != nil || len(results) == 0 {
		return flat
	}

	// The spatial server ranks by Euclidean degrees, which at UK latitudes
	// squashes east-west distance by ~cos(lat). The set of k it returns is
	// therefore a near-miss for the true nearest k, but the RADIUS must still be
	// honest, so take the furthest of them by great-circle distance.
	furthest := 0.0
	for _, r := range results {
		rLat, okLat := extraFloat(r.Extra, "lat")
		rLng, okLng := extraFloat(r.Extra, "lng")
		if !okLat || !okLng {
			return flat // an older spatial build without coordinates: do not guess
		}
		if d := utils.Haversine(lat, lng, rLat, rLng); d > furthest {
			furthest = d
		}
	}

	band := Band(len(results), k, &furthest)
	if band == BandUnknown {
		return flat
	}

	return Result{Band: band, RadiusMiles: &furthest, MaxMinutes: minutesForBand(band)}
}

// extraFloat reads a coordinate out of the spatial server's untyped extras,
// which arrive as JSON numbers (float64) but may be absent entirely.
func extraFloat(extra map[string]any, key string) (float64, bool) {
	v, ok := extra[key]
	if !ok {
		return 0, false
	}
	switch n := v.(type) {
	case float64:
		return n, true
	case float32:
		return float64(n), true
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	}
	return 0, false
}
