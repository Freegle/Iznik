package main

import (
	"bufio"
	"math"
	"os"
	"strconv"
	"strings"
)

// connCentroid is one area centroid carrying a DfT overall connectivity score.
type connCentroid struct {
	lat, lng float32
	conn     uint8 // 0-100
}

// ConnectivityIndex is a spatial index of area centroids (LSOA) with their DfT
// transport-connectivity scores, for nearest-centroid lookup. Mirrors the shape of
// DeprivationIndex so the two stay easy to reason about together.
type ConnectivityIndex struct {
	cells map[[2]int16][]connCentroid
}

// connRes is the grid cell size in degrees (~5km at UK latitudes), matching deprivation.
const connRes = 0.05

// LoadConnectivity reads a CSV with header and columns lat,lng,conn (conn 0-100).
// Returns nil on error (treated as "no connectivity data" → plain isochrone everywhere).
func LoadConnectivity(path string) *ConnectivityIndex {
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer f.Close()

	idx := &ConnectivityIndex{cells: make(map[[2]int16][]connCentroid, 60_000)}
	sc := bufio.NewScanner(f)
	sc.Scan() // skip header
	for sc.Scan() {
		parts := strings.SplitN(sc.Text(), ",", 4)
		if len(parts) < 3 {
			continue
		}
		lat, err1 := strconv.ParseFloat(strings.TrimSpace(parts[0]), 64)
		lng, err2 := strconv.ParseFloat(strings.TrimSpace(parts[1]), 64)
		conn, err3 := strconv.ParseFloat(strings.TrimSpace(parts[2]), 64)
		if err1 != nil || err2 != nil || err3 != nil {
			continue
		}
		if conn < 0 {
			conn = 0
		}
		if conn > 100 {
			conn = 100
		}
		c := connCentroid{lat: float32(lat), lng: float32(lng), conn: uint8(math.Round(conn))}
		key := [2]int16{int16(lat / connRes), int16(lng / connRes)}
		idx.cells[key] = append(idx.cells[key], c)
	}
	return idx
}

// Lookup returns the connectivity score of the nearest centroid, or 0 (unknown) when
// none is found within ~50km (e.g. Scotland/NI, which the DfT dataset does not cover).
func (idx *ConnectivityIndex) Lookup(lat, lng float64) uint8 {
	baseRow := int16(lat / connRes)
	baseCol := int16(lng / connRes)

	var best uint8
	found := false
	bestDist := math.MaxFloat64

	for radius := int16(0); radius <= 5; radius++ {
		for dr := -radius; dr <= radius; dr++ {
			for dc := -radius; dc <= radius; dc++ {
				if dr != -radius && dr != radius && dc != -radius && dc != radius {
					continue
				}
				key := [2]int16{baseRow + dr, baseCol + dc}
				for _, c := range idx.cells[key] {
					d := haversineM(lat, lng, float64(c.lat), float64(c.lng))
					if d < bestDist {
						bestDist = d
						best = c.conn
						found = true
					}
				}
			}
		}
		if found {
			break
		}
	}
	return best
}

// TagConnectivity sets Node.Conn for every node from the nearest centroid in ci.
// No-op when ci is nil. Called once after graph build.
func TagConnectivity(g *Graph, ci *ConnectivityIndex) {
	if ci == nil {
		return
	}
	for i := NodeID(1); i < NodeID(len(g.Nodes)); i++ {
		nd := &g.Nodes[i]
		nd.Conn = ci.Lookup(float64(nd.Lat), float64(nd.Lng))
	}
}
