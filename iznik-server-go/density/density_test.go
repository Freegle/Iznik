package density

import (
	"errors"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/spatial"
)

func f(v float64) *float64 { return &v }

// The band policy must stay byte-identical with
// iznik-batch App\Services\Ripple\DensityService::band(), because the reach a
// post actually gets is decided there and the slider only describes it.
func TestBandMatchesPhpPolicy(t *testing.T) {
	k := 400

	tests := []struct {
		name     string
		found    int
		furthest *float64
		want     string
	}{
		{"nothing found is unknown, not sparse", 0, nil, BandUnknown},
		{"no radius is unknown", 400, nil, BandUnknown},
		{"fewer than k found is definitively sparse", 399, f(21.0), BandSparse},
		{"fewer than k found is sparse even with a tiny radius", 12, f(0.4), BandSparse},
		{"at the dense boundary is dense", 400, f(1.6), BandDense},
		{"just past the dense boundary is medium", 400, f(1.61), BandMedium},
		{"at the medium boundary is medium", 400, f(3.1), BandMedium},
		{"past the medium boundary is sparse", 400, f(3.11), BandSparse},
		{"a city centre is dense", 400, f(0.4), BandDense},
		{"deep countryside is sparse", 400, f(14.2), BandSparse},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := Band(tt.found, k, tt.furthest); got != tt.want {
				t.Errorf("Band(%d, %d, %v) = %q, want %q", tt.found, k, tt.furthest, got, tt.want)
			}
		})
	}
}

func TestMinutesForBand(t *testing.T) {
	cases := map[string]float64{
		BandDense:   20,
		BandMedium:  30,
		BandSparse:  45,
		BandUnknown: 30, // the flat cap: a cap must never fail to a value nobody chose
	}

	for band, want := range cases {
		if got := minutesForBand(band); got != want {
			t.Errorf("minutesForBand(%q) = %v, want %v", band, got, want)
		}
	}
}

func TestMinutesForBandHonoursEnv(t *testing.T) {
	t.Setenv("RIPPLE_DENSITY_MAX_MINUTES_SPARSE", "50")

	if got := minutesForBand(BandSparse); got != 50 {
		t.Errorf("minutesForBand(sparse) with env override = %v, want 50", got)
	}
}

func TestCapForUsesFurthestByGreatCircle(t *testing.T) {
	// Two members: one ~0.7 miles north, one ~2.4 miles east. The band must come
	// from the FURTHEST of them, and east-west separation must not be squashed
	// (the spatial server ranks in degrees, which understates it by ~a third at
	// UK latitudes).
	withKNN(t, func(dataset string, lng, lat float64, limit int, typeFilter string) ([]spatial.QueryResult, error) {
		results := make([]spatial.QueryResult, 0, limit)
		results = append(results, spatial.QueryResult{ID: 1, Extra: map[string]any{"lat": 53.4, "lng": -2.9}})
		results = append(results, spatial.QueryResult{ID: 2, Extra: map[string]any{"lat": 53.41, "lng": -2.9}})
		results = append(results, spatial.QueryResult{ID: 3, Extra: map[string]any{"lat": 53.4, "lng": -2.843}})
		// Pad out to the full k so the shortfall rule doesn't fire.
		for len(results) < limit {
			results = append(results, spatial.QueryResult{ID: int64(len(results) + 1), Extra: map[string]any{"lat": 53.4, "lng": -2.9}})
		}
		return results, nil
	})

	got := CapFor(53.4, -2.9)

	if got.Band != BandMedium {
		t.Errorf("band = %q, want %q (radius %v)", got.Band, BandMedium, got.RadiusMiles)
	}
	if got.MaxMinutes != 30 {
		t.Errorf("max minutes = %v, want 30", got.MaxMinutes)
	}
	if got.RadiusMiles == nil || *got.RadiusMiles < 2.0 || *got.RadiusMiles > 2.8 {
		t.Errorf("radius = %v, want the ~2.4 mile east member", got.RadiusMiles)
	}
}

func TestCapForFailsSoftToTheFlatCap(t *testing.T) {
	tests := []struct {
		name string
		knn  func(string, float64, float64, int, string) ([]spatial.QueryResult, error)
	}{
		{
			name: "spatial server unreachable",
			knn: func(string, float64, float64, int, string) ([]spatial.QueryResult, error) {
				return nil, errors.New("connection refused")
			},
		},
		{
			name: "empty index",
			knn: func(string, float64, float64, int, string) ([]spatial.QueryResult, error) {
				return nil, nil
			},
		},
		{
			name: "an older spatial build with no coordinates",
			knn: func(_ string, _, _ float64, limit int, _ string) ([]spatial.QueryResult, error) {
				return []spatial.QueryResult{{ID: 1, Extra: map[string]any{}}}, nil
			},
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			withKNN(t, tt.knn)

			got := CapFor(53.4, -2.9)

			if got.Band != BandUnknown {
				t.Errorf("band = %q, want %q", got.Band, BandUnknown)
			}
			if got.MaxMinutes != 30 {
				t.Errorf("max minutes = %v, want the flat 30", got.MaxMinutes)
			}
			if got.RadiusMiles != nil {
				t.Errorf("radius = %v, want nil", *got.RadiusMiles)
			}
		})
	}
}

// An empty index and a mid-rebuild index look identical from here, so a lone
// result must not stretch a London member's slider to the sparse cap.
func TestCapForSingleResultIsSparseOnlyWhenMeasured(t *testing.T) {
	withKNN(t, func(string, float64, float64, int, string) ([]spatial.QueryResult, error) {
		return []spatial.QueryResult{{ID: 1, Extra: map[string]any{"lat": 53.5, "lng": -2.9}}}, nil
	})

	got := CapFor(53.4, -2.9)

	if got.Band != BandSparse {
		t.Errorf("band = %q, want %q: a measured shortfall IS sparse", got.Band, BandSparse)
	}
	if got.MaxMinutes != 45 {
		t.Errorf("max minutes = %v, want 45", got.MaxMinutes)
	}
}

func TestCapForKillswitch(t *testing.T) {
	t.Setenv("RIPPLE_DENSITY_ENABLED", "false")
	withKNN(t, func(string, float64, float64, int, string) ([]spatial.QueryResult, error) {
		t.Fatal("the killswitch must short-circuit before the spatial call")
		return nil, nil
	})

	got := CapFor(53.4, -2.9)

	if got.Band != BandUnknown || got.MaxMinutes != 30 {
		t.Errorf("killswitch gave %+v, want the flat cap under band %q", got, BandUnknown)
	}
}

func TestEnabledDefaultsOn(t *testing.T) {
	os.Unsetenv("RIPPLE_DENSITY_ENABLED")

	if !enabled() {
		t.Error("density sizing must default ON, matching config/freegle.php")
	}
}

// withKNN swaps the spatial seam for the duration of one test.
func withKNN(t *testing.T, fn func(string, float64, float64, int, string) ([]spatial.QueryResult, error)) {
	t.Helper()
	prev := knn
	knn = fn
	t.Cleanup(func() { knn = prev })
}
