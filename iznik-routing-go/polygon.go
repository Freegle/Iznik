package main

import (
	"math"
	"sort"
)

// GeoJSONPolygon is a minimal GeoJSON Polygon feature.
type GeoJSONPolygon struct {
	Type     string      `json:"type"`
	Geometry geoGeometry `json:"geometry"`
}

type geoGeometry struct {
	Type        string        `json:"type"`
	Coordinates [][][2]float64 `json:"coordinates"`
}

// IsochronePolygon converts a set of reachable nodes into a GeoJSON polygon
// using a grid-based approach: rasterise nodes onto a grid, find the boundary
// cells, and return their corners as a ring.
//
// Resolution controls the grid cell size in degrees (≈111km per degree).
// Typical values: 0.001 (≈111m) for walk, 0.005 (≈555m) for drive.
func IsochronePolygon(g *Graph, reached map[NodeID]float32, resolution float64) GeoJSONPolygon {
	if len(reached) == 0 {
		return emptyPolygon()
	}

	// Find bounding box.
	minLat, minLng := math.MaxFloat64, math.MaxFloat64
	maxLat, maxLng := -math.MaxFloat64, -math.MaxFloat64
	for id := range reached {
		n := g.Nodes[id]
		if n.Lat < minLat {
			minLat = n.Lat
		}
		if n.Lat > maxLat {
			maxLat = n.Lat
		}
		if n.Lng < minLng {
			minLng = n.Lng
		}
		if n.Lng > maxLng {
			maxLng = n.Lng
		}
	}

	// Add one cell of padding.
	minLat -= resolution
	minLng -= resolution
	maxLat += resolution
	maxLng += resolution

	cols := int((maxLng-minLng)/resolution) + 2
	rows := int((maxLat-minLat)/resolution) + 2

	// Mark grid cells that contain at least one reachable node.
	grid := make([][]bool, rows)
	for i := range grid {
		grid[i] = make([]bool, cols)
	}
	for id := range reached {
		n := g.Nodes[id]
		row := int((n.Lat - minLat) / resolution)
		col := int((n.Lng - minLng) / resolution)
		if row >= 0 && row < rows && col >= 0 && col < cols {
			grid[row][col] = true
		}
	}

	// Collect boundary cell corners (cells adjacent to empty cells).
	type pt struct{ lat, lng float64 }
	pointSet := make(map[pt]bool)

	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			if !grid[r][c] {
				continue
			}
			// Check if any neighbour is outside/empty — if so, this is a boundary cell.
			boundary := false
			for _, dr := range []int{-1, 0, 1} {
				for _, dc := range []int{-1, 0, 1} {
					nr, nc := r+dr, c+dc
					if nr < 0 || nr >= rows || nc < 0 || nc >= cols || !grid[nr][nc] {
						boundary = true
					}
				}
			}
			if !boundary {
				continue
			}
			lat0 := minLat + float64(r)*resolution
			lng0 := minLng + float64(c)*resolution
			pointSet[pt{lat0, lng0}] = true
			pointSet[pt{lat0 + resolution, lng0}] = true
			pointSet[pt{lat0, lng0 + resolution}] = true
			pointSet[pt{lat0 + resolution, lng0 + resolution}] = true
		}
	}

	// Convert to convex hull for a clean polygon.
	points := make([][2]float64, 0, len(pointSet))
	for p := range pointSet {
		points = append(points, [2]float64{p.lng, p.lat}) // GeoJSON is [lng, lat]
	}

	hull := convexHull(points)
	if len(hull) < 3 {
		return emptyPolygon()
	}
	// Close the ring.
	hull = append(hull, hull[0])

	return GeoJSONPolygon{
		Type: "Feature",
		Geometry: geoGeometry{
			Type:        "Polygon",
			Coordinates: [][][2]float64{hull},
		},
	}
}

func emptyPolygon() GeoJSONPolygon {
	return GeoJSONPolygon{
		Type: "Feature",
		Geometry: geoGeometry{
			Type:        "Polygon",
			Coordinates: [][][2]float64{{}},
		},
	}
}

// convexHull computes the convex hull of points using the Graham scan.
// Points are [lng, lat] pairs (GeoJSON convention).
func convexHull(pts [][2]float64) [][2]float64 {
	n := len(pts)
	if n < 3 {
		return pts
	}

	// Find lowest-leftmost point.
	pivot := 0
	for i := 1; i < n; i++ {
		if pts[i][1] < pts[pivot][1] || (pts[i][1] == pts[pivot][1] && pts[i][0] < pts[pivot][0]) {
			pivot = i
		}
	}
	pts[0], pts[pivot] = pts[pivot], pts[0]
	p0 := pts[0]

	sort.Slice(pts[1:], func(i, j int) bool {
		a, b := pts[1+i], pts[1+j]
		cross := crossProduct(p0, a, b)
		if cross != 0 {
			return cross > 0
		}
		da := dist2(p0, a)
		db := dist2(p0, b)
		return da < db
	})

	stack := [][2]float64{pts[0], pts[1], pts[2]}
	for i := 3; i < n; i++ {
		for len(stack) > 1 && crossProduct(stack[len(stack)-2], stack[len(stack)-1], pts[i]) <= 0 {
			stack = stack[:len(stack)-1]
		}
		stack = append(stack, pts[i])
	}
	return stack
}

func crossProduct(o, a, b [2]float64) float64 {
	return (a[0]-o[0])*(b[1]-o[1]) - (a[1]-o[1])*(b[0]-o[0])
}

func dist2(a, b [2]float64) float64 {
	dx := a[0] - b[0]
	dy := a[1] - b[1]
	return dx*dx + dy*dy
}

// AutoResolution picks a grid resolution appropriate for the isochrone size.
func AutoResolution(limitSeconds float32, mode Mode) float64 {
	// Approximate reach in km.
	var speedKmH float64
	switch mode {
	case Walk:
		speedKmH = 5
	case Cycle:
		speedKmH = 15
	default:
		speedKmH = 50
	}
	reachKm := speedKmH * float64(limitSeconds) / 3600.0
	// Use ~1/20th of reach as resolution, in degrees (1 deg ≈ 111 km).
	res := reachKm / 20.0 / 111.0
	if res < 0.0005 {
		res = 0.0005
	}
	if res > 0.01 {
		res = 0.01
	}
	return res
}
