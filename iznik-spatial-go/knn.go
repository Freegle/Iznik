package main

import (
	"fmt"
	"math"
	"sort"
	"strings"

	"github.com/peterstace/simplefeatures/geom"
)

// bufferLevels are the radii in decimal degrees for the 12 progressive buffer
// levels, matching the V1 PostGIS expanding-buffer algorithm.
var bufferLevels = [12]float64{
	0.00015625, 0.0003125, 0.000625, 0.00125, 0.0025, 0.005,
	0.01, 0.02, 0.04, 0.08, 0.16, 0.32,
}

// FindNearestPolygon returns up to limit polygon items that intersect an
// expanding buffer around (lng, lat), sorted by (buffer level, area ascending).
//
// This implements the progressive buffer expansion algorithm, matching the V1
// PostGIS expanding-buffer logic. The function stops expanding as soon as at
// least `limit` candidates have been found at the current level.
//
// match optionally restricts the candidate pool: items whose Extra fields fail
// the predicate are skipped and do NOT count towards limit, so the buffer keeps
// expanding until enough matching items are found. nil matches everything.
//
// dedupKey optionally collapses duplicates: items sharing the same non-empty key
// are returned only once — and because the buffer rings expand outward from the
// query point, the instance kept is the nearest. Skipped duplicates do NOT count
// towards limit, so the buffer keeps expanding past them until `limit` distinct
// items are found (the jobs dataset uses this so one ad spammed to many towns
// doesn't fill the result with copies). An empty key disables dedup for that
// item (treated as always-distinct); nil disables dedup entirely.
func FindNearestPolygon(idx *Index, lng, lat float64, limit int, match func(extra map[string]any) bool, dedupKey func(extra map[string]any) string) ([]QueryResult, error) {
	type cand struct {
		level int
		area  float64
		id    int64
		extra map[string]any
	}
	var collected []cand
	seen := make(map[int64]struct{})
	var dupKeys map[string]struct{}
	if dedupKey != nil {
		dupKeys = make(map[string]struct{})
	}

	for level, radius := range bufferLevels {
		candidates, err := QueryBBox(idx, lng-radius, lng+radius, lat-radius, lat+radius)
		if err != nil {
			return nil, err
		}
		if len(candidates) == 0 {
			continue
		}

		circle, err := geom.UnmarshalWKT(circleWKT(lng, lat, radius), geom.NoValidate{})
		if err != nil {
			return nil, fmt.Errorf("build circle: %w", err)
		}

		for _, c := range candidates {
			if _, already := seen[c.ExtID]; already {
				continue
			}
			if c.WKB == nil {
				continue
			}
			if match != nil && !match(c.Extra) {
				seen[c.ExtID] = struct{}{}
				continue
			}
			// Skip the (relatively expensive) polygon intersection for items whose
			// dedup key was already collected at this or a nearer ring: they are
			// duplicates of an already-returned item regardless of whether they
			// intersect, so dropping them now keeps the jobs query fast where one ad
			// is spammed to thousands of nearby rows. The key is only *claimed* on a
			// genuine intersection below, so a not-yet-claimed key still gets the full
			// test (claiming it earlier — for a bbox-near item that fails the circle
			// test — would suppress the real nearest instance at a later, wider ring).
			var key string
			if dedupKey != nil {
				if key = dedupKey(c.Extra); key != "" {
					if _, dup := dupKeys[key]; dup {
						seen[c.ExtID] = struct{}{}
						continue
					}
				}
			}
			g, err := geom.UnmarshalWKB(c.WKB, geom.NoValidate{})
			if err != nil {
				continue
			}
			if geom.Intersects(g, circle) {
				seen[c.ExtID] = struct{}{}
				if key != "" {
					dupKeys[key] = struct{}{}
				}
				collected = append(collected, cand{level, c.Area, c.ExtID, c.Extra})
			}
		}

		if len(collected) >= limit {
			break
		}
	}

	sort.Slice(collected, func(i, j int) bool {
		if collected[i].level != collected[j].level {
			return collected[i].level < collected[j].level
		}
		return collected[i].area < collected[j].area
	})

	if len(collected) > limit {
		collected = collected[:limit]
	}

	results := make([]QueryResult, len(collected))
	for i, m := range collected {
		results[i] = QueryResult{
			ID:       m.id,
			Distance: bufferLevels[m.level],
			Extra:    m.extra,
		}
	}
	return results, nil
}

// FindNearestPoints returns up to limit point items nearest to (lng, lat).
// It uses an expanding square search, ranks by Euclidean distance, and optionally
// filters results to items whose point lies within polygon.
func FindNearestPoints(idx *Index, lng, lat float64, limit int, polygon *geom.Geometry) ([]QueryResult, error) {
	seen := make(map[int64]bool)
	var candidates []Item

	for _, radius := range bufferLevels {
		newCandidates, err := QueryBBox(idx, lng-radius, lng+radius, lat-radius, lat+radius)
		if err != nil {
			return nil, err
		}
		for _, c := range newCandidates {
			if !seen[c.ExtID] {
				seen[c.ExtID] = true
				candidates = append(candidates, c)
			}
		}
		if len(candidates) >= limit {
			break
		}
	}

	if polygon != nil {
		filtered := candidates[:0]
		for _, c := range candidates {
			pt, err := geom.UnmarshalWKT(
				fmt.Sprintf("POINT(%.10f %.10f)", c.MinLng, c.MinLat),
				geom.NoValidate{},
			)
			if err != nil {
				continue
			}
			if geom.Intersects(pt, *polygon) {
				filtered = append(filtered, c)
			}
		}
		candidates = filtered
	}

	sort.Slice(candidates, func(i, j int) bool {
		di := euclidDist(lng, lat, candidates[i].MinLng, candidates[i].MinLat)
		dj := euclidDist(lng, lat, candidates[j].MinLng, candidates[j].MinLat)
		return di < dj
	})

	if len(candidates) > limit {
		candidates = candidates[:limit]
	}

	results := make([]QueryResult, len(candidates))
	for i, c := range candidates {
		results[i] = QueryResult{
			ID:       c.ExtID,
			Distance: euclidDist(lng, lat, c.MinLng, c.MinLat),
			Extra:    c.Extra,
		}
	}
	return results, nil
}

// euclidDist returns the Euclidean distance between two points in decimal degrees.
func euclidDist(lng1, lat1, lng2, lat2 float64) float64 {
	dlng := lng1 - lng2
	dlat := lat1 - lat2
	return math.Sqrt(dlng*dlng + dlat*dlat)
}

// circleWKT returns a WKT polygon with 32 segments approximating a circle
// centred at (cx, cy) with radius r (decimal degrees).
func circleWKT(cx, cy, r float64) string {
	const segments = 32
	pts := make([]string, segments+1)
	for i := 0; i <= segments; i++ {
		a := float64(i) * 2.0 * math.Pi / float64(segments)
		pts[i] = fmt.Sprintf("%.8f %.8f", cx+r*math.Cos(a), cy+r*math.Sin(a))
	}
	return "POLYGON((" + strings.Join(pts, ",") + "))"
}
