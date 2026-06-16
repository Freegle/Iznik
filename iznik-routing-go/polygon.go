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
	Type        string         `json:"type"`
	Coordinates [][][2]float64 `json:"coordinates"`
}

// buildIsochroneRings rasterises the reached nodes onto a grid and traces all
// boundary rings, returning them sorted by area (largest first).
func buildIsochroneRings(g *Graph, reached map[NodeID]float32, resolution float64) [][][2]float64 {
	if len(reached) == 0 {
		return nil
	}

	// Find bounding box.
	minLat, minLng := math.MaxFloat64, math.MaxFloat64
	maxLat, maxLng := -math.MaxFloat64, -math.MaxFloat64
	for id := range reached {
		n := g.Nodes[id]
		lat, lng := float64(n.Lat), float64(n.Lng)
		if lat < minLat {
			minLat = lat
		}
		if lat > maxLat {
			maxLat = lat
		}
		if lng < minLng {
			minLng = lng
		}
		if lng > maxLng {
			maxLng = lng
		}
	}

	// One cell of padding.
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
		row := int((float64(n.Lat) - minLat) / resolution)
		col := int((float64(n.Lng) - minLng) / resolution)
		if row >= 0 && row < rows && col >= 0 && col < cols {
			grid[row][col] = true
		}
	}

	// Morphological closing: fill empty cells that have ≥2 orthogonal filled
	// neighbours. Read from a snapshot so fills don't cascade across the grid
	// and produce spurious straight-line edges at the grid boundary.
	snap := make([][]bool, rows)
	for i := range snap {
		snap[i] = make([]bool, cols)
		copy(snap[i], grid[i])
	}
	for r := 1; r < rows-1; r++ {
		for c := 1; c < cols-1; c++ {
			if snap[r][c] {
				continue
			}
			n := 0
			if snap[r+1][c] {
				n++
			}
			if snap[r-1][c] {
				n++
			}
			if snap[r][c+1] {
				n++
			}
			if snap[r][c-1] {
				n++
			}
			if n >= 2 {
				grid[r][c] = true
			}
		}
	}

	return traceBoundary(grid, rows, cols, minLat, minLng, resolution)
}

// IsochronePolygon converts a set of reachable nodes into a GeoJSON polygon
// representing the largest contiguous area.
func IsochronePolygon(g *Graph, reached map[NodeID]float32, resolution float64) GeoJSONPolygon {
	rings := buildIsochroneRings(g, reached, resolution)
	if len(rings) == 0 {
		return emptyPolygon()
	}
	ring := removeCollinear(rings[0])
	if len(ring) < 4 {
		return emptyPolygon()
	}
	return GeoJSONPolygon{
		Type: "Feature",
		Geometry: geoGeometry{
			Type:        "Polygon",
			Coordinates: [][][2]float64{ring},
		},
	}
}

// IsochroneIslands returns all non-dominant polygon rings (disconnected islands)
// as separate GeoJSON polygons, sorted by area descending.
func IsochroneIslands(g *Graph, reached map[NodeID]float32, resolution float64) []GeoJSONPolygon {
	rings := buildIsochroneRings(g, reached, resolution)
	if len(rings) <= 1 {
		return nil
	}
	var result []GeoJSONPolygon
	for _, ring := range rings[1:] {
		ring = removeCollinear(ring)
		if len(ring) < 4 {
			continue
		}
		result = append(result, GeoJSONPolygon{
			Type: "Feature",
			Geometry: geoGeometry{
				Type:        "Polygon",
				Coordinates: [][][2]float64{ring},
			},
		})
	}
	return result
}

// traceBoundary extracts all boundary rings from the grid, sorted by area (largest first).
// row=0 is the southernmost row; col=0 is the westernmost column.
func traceBoundary(grid [][]bool, rows, cols int, minLat, minLng, res float64) [][][2]float64 {
	// corner(r, c) gives the [lng, lat] of the SW corner of cell (r, c).
	corner := func(r, c int) [2]float64 {
		return [2]float64{minLng + float64(c)*res, minLat + float64(r)*res}
	}

	type edge struct{ from, to [2]float64 }

	// Collect boundary edges with CCW orientation (filled region to the LEFT).
	var edges []edge
	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			if !grid[r][c] {
				continue
			}
			sw := corner(r, c)
			se := corner(r, c+1)
			ne := corner(r+1, c+1)
			nw := corner(r+1, c)
			if r == 0 || !grid[r-1][c] {
				edges = append(edges, edge{sw, se})
			}
			if c == cols-1 || !grid[r][c+1] {
				edges = append(edges, edge{se, ne})
			}
			if r == rows-1 || !grid[r+1][c] {
				edges = append(edges, edge{ne, nw})
			}
			if c == 0 || !grid[r][c-1] {
				edges = append(edges, edge{nw, sw})
			}
		}
	}

	if len(edges) == 0 {
		return nil
	}

	fromMap := make(map[[2]float64][]int, len(edges))
	for i, e := range edges {
		fromMap[e.from] = append(fromMap[e.from], i)
	}

	used := make([]bool, len(edges))

	traceOneRing := func(startIdx int) [][2]float64 {
		ring := make([][2]float64, 0, 32)
		used[startIdx] = true
		start := edges[startIdx].from
		prev := start
		cur := edges[startIdx].to
		ring = append(ring, start)

		for cur != start {
			ring = append(ring, cur)
			dx := cur[0] - prev[0]
			dy := cur[1] - prev[1]

			bestIdx := -1
			bestCross := -math.MaxFloat64
			for _, i := range fromMap[cur] {
				if used[i] {
					continue
				}
				cdx := edges[i].to[0] - cur[0]
				cdy := edges[i].to[1] - cur[1]
				cross := dx*cdy - dy*cdx
				if cross > bestCross || bestIdx == -1 {
					bestCross = cross
					bestIdx = i
				}
			}
			if bestIdx == -1 {
				break
			}
			used[bestIdx] = true
			prev = cur
			cur = edges[bestIdx].to
		}
		ring = append(ring, start) // close
		return ring
	}

	ringArea2D := func(ring [][2]float64) float64 {
		n := len(ring)
		area := 0.0
		for i := 0; i < n; i++ {
			j := (i + 1) % n
			area += ring[i][0] * ring[j][1]
			area -= ring[j][0] * ring[i][1]
		}
		if area < 0 {
			area = -area
		}
		return area / 2.0
	}

	// Trace all rings.
	var allRings [][][2]float64
	for i := range edges {
		if used[i] {
			continue
		}
		ring := traceOneRing(i)
		if len(ring) < 4 {
			continue
		}
		allRings = append(allRings, ring)
	}

	// Sort by area, largest first.
	sort.Slice(allRings, func(i, j int) bool {
		return ringArea2D(allRings[i]) > ringArea2D(allRings[j])
	})

	return allRings
}

// removeCollinear removes redundant collinear points from a closed ring.
func removeCollinear(pts [][2]float64) [][2]float64 {
	n := len(pts) - 1 // last == first (closed ring)
	if n < 3 {
		return pts
	}
	out := make([][2]float64, 0, n)
	for i := 0; i < n; i++ {
		a, b, c := pts[(i+n-1)%n], pts[i], pts[(i+1)%n]
		if (a[0] == b[0] && b[0] == c[0]) || (a[1] == b[1] && b[1] == c[1]) {
			continue
		}
		out = append(out, b)
	}
	if len(out) == 0 {
		return pts
	}
	out = append(out, out[0]) // re-close
	return out
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

// AutoResolution picks a grid resolution appropriate for the isochrone size.
func AutoResolution(limitSeconds float32, mode Mode) float64 {
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
	res := reachKm / 20.0 / 111.0
	if res < 0.0005 {
		res = 0.0005
	}
	if res > 0.01 {
		res = 0.01
	}
	return res
}

// convexHull is kept for tests that reference it directly.
func convexHull(pts [][2]float64) [][2]float64 {
	n := len(pts)
	if n < 3 {
		return pts
	}
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
		return dist2(p0, a) < dist2(p0, b)
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
