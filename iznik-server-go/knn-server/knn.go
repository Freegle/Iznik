package main

import (
	"fmt"
	"math"
	"strings"

	"github.com/peterstace/simplefeatures/geom"
)

// bufferLevels are the radii in decimal degrees for the 12 progressive buffer
// levels, matching the V1 PostGIS expanding-buffer algorithm.
var bufferLevels = [12]float64{
	0.00015625, 0.0003125, 0.000625, 0.00125, 0.0025, 0.005,
	0.01, 0.02, 0.04, 0.08, 0.16, 0.32,
}

// areaMin / areaMax match the V1 ST_Area filter (decimal-degree units).
const (
	areaMin = 0.00001
	areaMax = 0.15
)

// FindNearestArea returns the locationid of the smallest area that intersects
// with a circle of increasing radius around (lng, lat). Returns nil if no
// candidate is found within the maximum buffer radius.
//
// Algorithm: progressive buffer expansion. At each of the 12 levels, the
// R-tree is queried for candidates whose bounding box overlaps the search
// square, then each candidate is tested for exact intersection with the
// corresponding circle. The first level that produces any match returns the
// match with the smallest area. This is more correct than centroid-distance
// KNN (used by PostGIS <-> operator) because it handles geometries whose
// centroid is far from the query point but whose boundary is close.
func FindNearestArea(idx *Index, lng, lat float64) (*int64, error) {
	for _, radius := range bufferLevels {
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

		var bestID *int64
		var bestArea float64

		for i := range candidates {
			c := &candidates[i]
			if c.Area < areaMin || c.Area > areaMax {
				continue
			}
			g, err := geom.UnmarshalWKB(c.WKB, geom.NoValidate{})
			if err != nil {
				continue
			}
			if geom.Intersects(g, circle) {
				if bestID == nil || c.Area < bestArea {
					id := c.LocationID
					bestID = &id
					bestArea = c.Area
				}
			}
		}

		if bestID != nil {
			return bestID, nil
		}
	}
	return nil, nil
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
