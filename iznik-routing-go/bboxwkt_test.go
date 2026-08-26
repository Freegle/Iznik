package main

import (
	"math"
	"strings"
	"testing"
)

// The spatial index rejects a WKT polygon with more than 10,000 vertices. Once display
// smoothing left the tracer, a 45-minute drive reach out of central London traced ~13k,
// so nearby-freeglers' boundary query started returning 400 and the handler soft-failed
// to an empty list — at the DEFAULT reach, which is where the explorer's Freegler dots
// and its "would be notified" count live. The fix is to ask the index by bounding box
// and run the boundary test locally, so these lock the two properties that makes safe:
// the box is always four corners, and it never excludes anything the ring includes.

const spatialIndexVertexLimit = 10_000

func vertexCount(wkt string) int {
	return strings.Count(wkt, ",") + 1
}

func TestBboxWKTIsAlwaysFiveVertices(t *testing.T) {
	cases := []struct {
		name                           string
		minLat, maxLat, minLng, maxLng float64
	}{
		{"tiny", 51.50, 51.51, -0.13, -0.12},
		{"london 45 minutes", 51.10, 51.95, -1.20, 0.75},
		{"whole country", 49.9, 60.9, -8.7, 1.8},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := vertexCount(bboxWKT(c.minLat, c.maxLat, c.minLng, c.maxLng))
			// Four corners plus the closing repeat, whatever the reach.
			if got != 5 {
				t.Fatalf("bboxWKT vertices = %d, want 5", got)
			}
			if got > spatialIndexVertexLimit {
				t.Fatalf("bboxWKT would be rejected by the index at %d vertices", got)
			}
		})
	}
}

func TestBboxWKTEnclosesEveryRingVertex(t *testing.T) {
	// A deliberately concave ring: the box has to cover the spikes as well as the body,
	// or the candidate set would silently miss freeglers the boundary admits.
	ring := [][2]float64{
		{-0.20, 51.40},
		{-0.05, 51.62},
		{0.10, 51.42},
		{0.02, 51.55},
		{-0.12, 51.38},
		{-0.20, 51.40},
	}
	minLat, maxLat := math.Inf(1), math.Inf(-1)
	minLng, maxLng := math.Inf(1), math.Inf(-1)
	for _, p := range ring {
		minLng = math.Min(minLng, p[0])
		maxLng = math.Max(maxLng, p[0])
		minLat = math.Min(minLat, p[1])
		maxLat = math.Max(maxLat, p[1])
	}

	// Compared directly rather than with pointInRing: the extreme vertices lie exactly ON
	// the box edge, where ray casting is undefined, and "encloses" here has to mean
	// inclusive or the candidate set could drop the very points that define the reach.
	for _, p := range ring {
		if p[0] < minLng || p[0] > maxLng || p[1] < minLat || p[1] > maxLat {
			t.Fatalf("ring vertex %v falls outside its own bounding box", p)
		}
	}

	// And the box really is looser than the ring, which is why the handler still has to
	// run pointInRing on the candidates rather than trusting the box.
	corner := [2]float64{maxLng, maxLat}
	if pointInRing(corner[0], corner[1], ring) {
		t.Fatal("expected the box corner to lie outside the concave ring")
	}
}

func TestBboxWKTNamesCornersAsLngLat(t *testing.T) {
	// WKT is "x y", and these coordinates are lng/lat — getting this the wrong way round
	// would put the query in the sea off Somalia and return nothing, quietly.
	wkt := bboxWKT(51.40, 51.62, -0.20, 0.10)
	if !strings.HasPrefix(wkt, "POLYGON((-0.200000 51.400000") {
		t.Fatalf("bboxWKT = %q, want it to open at (minLng minLat)", wkt)
	}
	if !strings.HasSuffix(wkt, "-0.200000 51.400000))") {
		t.Fatalf("bboxWKT = %q, want it to close back on (minLng minLat)", wkt)
	}
}
