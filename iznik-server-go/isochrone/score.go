package isochrone

import (
	"math"
	"os"
	"regexp"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/utils"
)

// This file is the Go home of the "rippling relevance score" used to order
// the 'nearby' browse feed. It is a pure, DB-free mirror of the same formula
// implemented twice already:
//   - iznik-batch/app/Services/Ripple/DigestPostScorer.php (the unified
//     digest's ordering)
//   - iznik-routing-go/digest_simulator.go::scoreDigestPost (the /rippling
//     "digest preview" page)
//
// The formula is intentionally NOT reinvented here — see those two files for
// the canonical description of each term. Kept isolated in its own file (no
// DB/HTTP imports) so it stays trivially unit-testable.

// milesToMetres converts utils.Haversine's native miles output back to
// metres, using the same constant utils.Haversine divides by internally
// (1609.344), so the round trip is exact.
const milesToMetres = 1609.344

// ScoreWeights bundles the four browse-relevance weight knobs. Loaded from
// RIPPLE_BROWSE_* env vars (LoadScoreWeights) — deliberately separate from
// the digest's RIPPLE_DIGEST_* knobs, so tuning the browse feed's ordering
// never touches the digest's tuning, and vice versa.
type ScoreWeights struct {
	Close  float64
	Fresh  float64
	Budget float64
	Anchor float64
}

// ScoreEnv carries the bound-defining knobs: the freshness window, the
// budget-decay constant, and the fallback reach radius for posts whose reach
// extent can't be computed (parity with the digest's config keys, but read
// from RIPPLE_BROWSE_* env vars via LoadScoreEnv).
type ScoreEnv struct {
	WindowHours   float64
	BudgetDecay   float64
	DefaultReachM float64
	// MaxMinutes is the closeness horizon for the REFERENCE close term
	// (1 - driveMin/MaxMinutes, the formula the /rippling digest preview has
	// always used). The crow-flies term below was only ever its documented
	// performance approximation, from when a drive time per member was
	// infeasible; with the routing engine's precomputed leaf tables it costs
	// microseconds, so feeds score with the reference whenever the engine
	// answers. Default matches the simulator's max_minutes default and
	// RIPPLE_MAX_MINUTES.
	MaxMinutes float64
}

// ScoreComponents is the per-post score breakdown returned by Score: each
// signal in [0, 1] (Anchor is exactly 0 or 1) plus their weighted sum.
type ScoreComponents struct {
	Close, Fresh, Budget, Anchor, Total float64
}

// envFloat reads a float env var, falling back to def when unset or
// unparseable.
func envFloat(name string, def float64) float64 {
	if v := os.Getenv(name); v != "" {
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			return f
		}
	}
	return def
}

// LoadScoreWeights reads the RIPPLE_BROWSE_W_* env vars. Defaults mirror the
// digest's tuning (close+budget on, fresh+anchor off) but can be tuned for
// browse independently.
func LoadScoreWeights() ScoreWeights {
	return ScoreWeights{
		Close:  envFloat("RIPPLE_BROWSE_W_CLOSE", 1.0),
		Fresh:  envFloat("RIPPLE_BROWSE_W_FRESH", 0.0),
		Budget: envFloat("RIPPLE_BROWSE_W_BUDGET", 1.0),
		Anchor: envFloat("RIPPLE_BROWSE_W_ANCHOR", 0.0),
	}
}

// LoadScoreEnv reads the RIPPLE_BROWSE_* bound-defining env vars.
func LoadScoreEnv() ScoreEnv {
	return ScoreEnv{
		WindowHours:   envFloat("RIPPLE_BROWSE_WINDOW_HOURS", 24),
		BudgetDecay:   envFloat("RIPPLE_BROWSE_BUDGET_DECAY", 25),
		DefaultReachM: envFloat("RIPPLE_BROWSE_DEFAULT_REACH_M", 30000),
		MaxMinutes:    envFloat("RIPPLE_BROWSE_MAX_MINUTES", 30),
	}
}

// Score computes the rippling relevance score for a single post. Mirrors
// DigestPostScorer::score() / scoreDigestPost exactly (same formula, same
// clamping):
//
//	close:  1 - distanceMetres / reachRadiusMetres (clamped to [0,1]; reachRadiusMetres <= 0 => 0, no signal)
//	fresh:  1 - ageHours / windowHours              (clamped to [0,1])
//	budget: exp(-engagement / (budgetDecay / 12))   where engagement = (views + 3*replies) / max(1, ageHours)
//	anchor: 1 if homeGroup else 0
//	total:  weighted sum of the four signals above
func Score(distanceMetres, reachRadiusMetres, ageHours float64, views, replies int, homeGroup bool, w ScoreWeights, env ScoreEnv) ScoreComponents {
	return ScoreT(nil, distanceMetres, reachRadiusMetres, ageHours, views, replies, homeGroup, w, env)
}

// ScoreT is Score with the REFERENCE close term when a drive time is known:
// close = 1 - driveMin/env.MaxMinutes, exactly the formula the /rippling
// digest preview (digest_simulator.go scoreDigestPost) has always used. The
// crow term remains as the documented approximation for posts the routing
// engine has no answer for (nil driveMin) - unknown means "fall back to the
// proxy", never "score zero", because nil says nothing about how far the
// post is.
func ScoreT(driveMin *float64, distanceMetres, reachRadiusMetres, ageHours float64, views, replies int, homeGroup bool, w ScoreWeights, env ScoreEnv) ScoreComponents {
	var s ScoreComponents

	if driveMin != nil && env.MaxMinutes > 0 {
		s.Close = 1.0 - *driveMin/env.MaxMinutes
		if s.Close < 0 {
			s.Close = 0
		}
	} else if reachRadiusMetres > 0 {
		s.Close = 1.0 - distanceMetres/reachRadiusMetres
		if s.Close < 0 {
			s.Close = 0
		}
	}

	s.Fresh = 1.0 - ageHours/env.WindowHours
	if s.Fresh < 0 {
		s.Fresh = 0
	}

	rateAgeH := ageHours
	if rateAgeH < 1 {
		rateAgeH = 1
	}
	engagement := float64(views+3*replies) / rateAgeH
	s.Budget = math.Exp(-engagement / (env.BudgetDecay / 12))

	if homeGroup {
		s.Anchor = 1.0
	}

	s.Total = w.Close*s.Close + w.Fresh*s.Fresh + w.Budget*s.Budget + w.Anchor*s.Anchor
	return s
}

// HaversineMetres is utils.Haversine (which returns miles, via a WGS84
// geodesic calculation) converted to metres. The scoring formula needs true
// metres to match reachRadiusMetres (itself measured in metres); reusing the
// existing geodesic implementation keeps this consistent with every other
// distance calculation in the codebase rather than introducing a second
// great-circle formula.
func HaversineMetres(lat1, lng1, lat2, lng2 float64) float64 {
	return utils.Haversine(lat1, lng1, lat2, lng2) * milesToMetres
}

// polygonRingRE extracts the first (exterior) ring's coordinate list out of a
// WKT POLYGON(...) string, e.g. "POLYGON((-0.2 51.4,0.0 51.4,...))" yields
// "-0.2 51.4,0.0 51.4,...". Mirrors UnifiedDigestService::reachRadiusMetres's
// PHP regex exactly, so browse and the digest measure reach extent the same
// way.
var polygonRingRE = regexp.MustCompile(`(?s)POLYGON\s*\(\s*\(([^)]+)\)`)

// ReachRadiusMetres is a post's reach extent in metres: the greatest
// great-circle distance from the reach origin (originLat, originLng) to any
// vertex of the given reach-polygon WKT. The feed passes the polygon's
// bounding-box envelope (ST_AsText(ST_Envelope(rr.outer_bound))) rather than
// any exact geometry — the envelope's corners give the reach extent for the
// 'close' term (a small, uniform over-estimate that only nudges relevance
// ordering). Falls back to defaultM when the WKT can't be parsed or has no
// extent — defensive; every reach-arm post has a rippling_reach row with a
// real outer bound.
func ReachRadiusMetres(originLat, originLng float64, polygonWKT string, defaultM float64) float64 {
	m := polygonRingRE.FindStringSubmatch(polygonWKT)
	if m == nil {
		return defaultM
	}

	maxDist := 0.0
	for _, pair := range strings.Split(m[1], ",") {
		parts := strings.Fields(strings.TrimSpace(pair))
		if len(parts) < 2 {
			continue
		}
		// WKT form is "lng lat" (x is lng, y is lat).
		vLng, errLng := strconv.ParseFloat(parts[0], 64)
		vLat, errLat := strconv.ParseFloat(parts[1], 64)
		if errLng != nil || errLat != nil {
			continue
		}
		if d := HaversineMetres(originLat, originLng, vLat, vLng); d > maxDist {
			maxDist = d
		}
	}

	if maxDist > 0 {
		return maxDist
	}
	return defaultM
}
