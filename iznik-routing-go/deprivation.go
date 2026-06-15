package main

import (
	"bufio"
	"math"
	"os"
	"strconv"
	"strings"
)

// Quintile is an IMD deprivation quintile: 1 = most deprived, 5 = least deprived.
// 0 means no data.
type Quintile uint8

// lsoaCentroid is one LSOA centroid entry in the deprivation index.
type lsoaCentroid struct {
	lat, lng float32
	quintile Quintile
}

// DeprivationIndex is a spatial index of LSOA centroids for nearest-centroid lookup.
type DeprivationIndex struct {
	cells map[[2]int16][]lsoaCentroid
}

// deprivRes is the grid cell size in degrees (~5km at UK latitudes).
const deprivRes = 0.05

// LoadDeprivation reads a CSV file with columns: lat,lng,quintile (header required).
// Returns nil on error (treated as "no deprivation data").
func LoadDeprivation(path string) *DeprivationIndex {
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer f.Close()

	idx := &DeprivationIndex{cells: make(map[[2]int16][]lsoaCentroid, 10_000)}
	sc := bufio.NewScanner(f)
	sc.Scan() // skip header
	for sc.Scan() {
		parts := strings.SplitN(sc.Text(), ",", 4)
		if len(parts) < 3 {
			continue
		}
		lat, err1 := strconv.ParseFloat(strings.TrimSpace(parts[0]), 64)
		lng, err2 := strconv.ParseFloat(strings.TrimSpace(parts[1]), 64)
		q, err3 := strconv.Atoi(strings.TrimSpace(parts[2]))
		if err1 != nil || err2 != nil || err3 != nil || q < 1 || q > 5 {
			continue
		}
		c := lsoaCentroid{lat: float32(lat), lng: float32(lng), quintile: Quintile(q)}
		key := [2]int16{int16(lat / deprivRes), int16(lng / deprivRes)}
		idx.cells[key] = append(idx.cells[key], c)
	}
	return idx
}

// Lookup returns the deprivation quintile of the nearest LSOA centroid.
// Returns 0 (unknown) if no centroid is found within ~50km.
func (idx *DeprivationIndex) Lookup(lat, lng float64) Quintile {
	baseRow := int16(lat / deprivRes)
	baseCol := int16(lng / deprivRes)

	var best Quintile
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
						best = c.quintile
					}
				}
			}
		}
		if best != 0 {
			break
		}
	}
	return best
}
