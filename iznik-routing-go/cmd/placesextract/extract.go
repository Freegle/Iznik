package main

import (
	"encoding/json"
	"io"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"
)

// coordStore resolves node ids to coordinates via a sorted id array with
// parallel coordinate arrays (the graph.go loading pattern): ~14M needed
// nodes fit in ~330MB where the equivalent maps peak beyond a GB — the
// production batch host swaps, so the build must stay lean.
type coordStore struct {
	ids []int64
	lng []float64
	lat []float64
}

func newCoordStore(rawIDs []int64) *coordStore {
	sort.Slice(rawIDs, func(a, b int) bool { return rawIDs[a] < rawIDs[b] })
	ids := rawIDs[:0]
	var prev int64 = -1
	for _, id := range rawIDs {
		if id != prev {
			ids = append(ids, id)
			prev = id
		}
	}
	c := &coordStore{ids: ids, lng: make([]float64, len(ids)), lat: make([]float64, len(ids))}
	for i := range c.lat {
		c.lat[i] = math.NaN()
	}
	return c
}

func (c *coordStore) idx(id int64) (int, bool) {
	i := sort.Search(len(c.ids), func(i int) bool { return c.ids[i] >= id })
	return i, i < len(c.ids) && c.ids[i] == id
}

func (c *coordStore) set(id int64, lng, lat float64) {
	if i, ok := c.idx(id); ok {
		c.lng[i], c.lat[i] = lng, lat
	}
}

func (c *coordStore) get(id int64) ([2]float64, bool) {
	if i, ok := c.idx(id); ok && !math.IsNaN(c.lat[i]) {
		return [2]float64{c.lng[i], c.lat[i]}, true
	}
	return [2]float64{}, false
}

func (c *coordStore) len() int { return len(c.ids) }

func (c *coordStore) resolved() int {
	n := 0
	for _, la := range c.lat {
		if !math.IsNaN(la) {
			n++
		}
	}
	return n
}

// Entry is one searchable place in the artifact consumed by iznik-spatial-go's
// photon-compatible /api. Extent order is [W,N,E,S] — the same order photon
// emits, which WhatJobsService destructures as [swlng, nelat, nelng, swlat].
type Entry struct {
	ID      int64     `json:"id"`
	OsmType string    `json:"ot"` // N, W or R
	Name    string    `json:"name"`
	Alt     []string  `json:"alt,omitempty"` // alt_name / loc_name / official_name / old_name / name:en / short_name
	Key     string    `json:"key"`           // "place" or "boundary" (photon osm_key)
	Value   string    `json:"val"`           // place value, or "administrative"/"ceremonial"/"statistical"
	Layer   string    `json:"layer"`         // photon type/layer: city, district, locality, county, state, other
	Lat     float64   `json:"lat"`
	Lng     float64   `json:"lng"`
	Extent  []float64 `json:"ext,omitempty"` // [W,N,E,S]
	County  string    `json:"county,omitempty"`
	State   string    `json:"state,omitempty"`
	Pop     int64     `json:"pop,omitempty"`

	// adminLevel is extract-time only (merge eligibility); not serialised.
	adminLevel string
}

// classifyPlace maps an OSM place= value to the photon layer it is served
// under. Probed against the live photon 2026-08-31: villages sit in the
// "city" layer (WhatJobs filters layer=city|locality|district for town
// lookups, so villages must stay findable there), hamlets in "district".
// Empty string = do not index.
func classifyPlace(v string) string {
	switch v {
	case "city", "town", "village", "municipality":
		return "city"
	case "suburb", "neighbourhood", "quarter", "borough", "hamlet", "city_block":
		return "district"
	case "locality", "isolated_dwelling", "farm", "allotments", "square":
		return "locality"
	case "county":
		return "county"
	case "state", "region":
		return "state"
	case "island", "islet", "archipelago":
		return "other"
	default:
		return ""
	}
}

// classifyBoundary maps a boundary relation to a photon layer.
// admin_level 4 = England/Scotland/Wales/NI, 5/6 = regions, counties and
// unitaries, 7/8 = districts (photon serves LADs as "city"), 9+ = parishes
// and wards. Ceremonial counties ("Devon") and statistical regions ("West
// Midlands", "East of England") are what the WhatJobs region lookups match,
// as layer "other".
func classifyBoundary(boundary, adminLevel string) string {
	switch boundary {
	case "administrative":
		switch adminLevel {
		case "4":
			return "state"
		case "5", "6":
			return "county"
		case "7", "8":
			return "city"
		case "9", "10", "11":
			return "district"
		}
		return ""
	case "ceremonial", "statistical":
		return "other"
	}
	return ""
}

// parsePopulation reads an OSM population tag leniently ("28,586", "~5000").
func parsePopulation(s string) int64 {
	var digits strings.Builder
	for _, r := range s {
		if r >= '0' && r <= '9' {
			digits.WriteRune(r)
		}
	}
	if digits.Len() == 0 {
		return 0
	}
	n, err := strconv.ParseInt(digits.String(), 10, 64)
	if err != nil {
		return 0
	}
	return n
}

// normName is the loose comparison key for merging a place node with its
// same-named boundary relation.
func normName(s string) string {
	return strings.Join(strings.Fields(strings.ToLower(s)), " ")
}

// polygon is an assembled boundary used for county/state context assignment.
type polygon struct {
	rings                  [][][2]float64 // each ring is a closed list of {lng,lat}
	minX, minY, maxX, maxY float64
	area                   float64 // rough planar area, for smallest-wins
}

func (p *polygon) computeBounds() {
	p.minX, p.minY = 1e9, 1e9
	p.maxX, p.maxY = -1e9, -1e9
	p.area = 0
	for _, ring := range p.rings {
		a := 0.0
		for i, pt := range ring {
			if pt[0] < p.minX {
				p.minX = pt[0]
			}
			if pt[0] > p.maxX {
				p.maxX = pt[0]
			}
			if pt[1] < p.minY {
				p.minY = pt[1]
			}
			if pt[1] > p.maxY {
				p.maxY = pt[1]
			}
			j := (i + 1) % len(ring)
			a += ring[i][0]*ring[j][1] - ring[j][0]*ring[i][1]
		}
		if a < 0 {
			a = -a
		}
		p.area += a / 2
	}
}

// contains does an even-odd ray cast across all rings, with a bbox short cut.
func (p *polygon) contains(lng, lat float64) bool {
	if lng < p.minX || lng > p.maxX || lat < p.minY || lat > p.maxY {
		return false
	}
	inside := false
	for _, ring := range p.rings {
		n := len(ring)
		for i := 0; i < n; i++ {
			j := (i + n - 1) % n
			yi, yj := ring[i][1], ring[j][1]
			if (yi > lat) != (yj > lat) {
				xi, xj := ring[i][0], ring[j][0]
				if lng < (xj-xi)*(lat-yi)/(yj-yi)+xi {
					inside = !inside
				}
			}
		}
	}
	return inside
}

// assembleRings joins way segments into closed rings by matching endpoint node
// ids, tolerating reversed segments. Unclosable chains are dropped: context
// assignment then falls back to nothing rather than a wrong polygon.
func assembleRings(wayIDs []int64, getWay func(int64) ([]int64, bool), getCoord func(int64) ([2]float64, bool)) [][][2]float64 {
	type seg struct {
		refs []int64
		used bool
	}
	var segs []*seg
	for _, wid := range wayIDs {
		refs, ok := getWay(wid)
		if !ok || len(refs) < 2 {
			continue
		}
		segs = append(segs, &seg{refs: refs})
	}

	var rings [][][2]float64
	for i := range segs {
		if segs[i].used {
			continue
		}
		segs[i].used = true
		chain := append([]int64(nil), segs[i].refs...)
		for chain[0] != chain[len(chain)-1] {
			extended := false
			tail := chain[len(chain)-1]
			for _, s := range segs {
				if s.used {
					continue
				}
				if s.refs[0] == tail {
					chain = append(chain, s.refs[1:]...)
					s.used = true
					extended = true
					break
				}
				if s.refs[len(s.refs)-1] == tail {
					for k := len(s.refs) - 2; k >= 0; k-- {
						chain = append(chain, s.refs[k])
					}
					s.used = true
					extended = true
					break
				}
			}
			if !extended {
				break
			}
		}
		if len(chain) < 4 || chain[0] != chain[len(chain)-1] {
			continue
		}
		ring := make([][2]float64, 0, len(chain)-1)
		complete := true
		for _, ref := range chain[:len(chain)-1] {
			c, ok := getCoord(ref)
			if !ok {
				complete = false
				break
			}
			ring = append(ring, c)
		}
		if complete && len(ring) >= 3 {
			rings = append(rings, ring)
		}
	}
	return rings
}

// mergeLinkedPlaces reproduces photon's linked-place behaviour: a place node
// whose name matches a boundary relation containing it adopts the relation's
// identity and extent (Kendal the place=town node is served as R8292370 with
// the parish bbox). Only relations at the given admin levels are eligible —
// parish-scale boundaries, not counties. The consumed relation entry is
// dropped so the place appears once, as it does in photon.
func mergeLinkedPlaces(entries []Entry, adminLevels []string) []Entry {
	levelOK := make(map[string]bool, len(adminLevels))
	for _, l := range adminLevels {
		levelOK[l] = true
	}

	relByName := make(map[string][]int)
	for i, e := range entries {
		if e.OsmType == "R" && e.Key == "boundary" && levelOK[e.adminLevel] && len(e.Extent) == 4 {
			relByName[normName(e.Name)] = append(relByName[normName(e.Name)], i)
		}
	}

	consumed := make(map[int]bool)
	merged := make([]Entry, len(entries))
	copy(merged, entries)
	for i := range merged {
		e := &merged[i]
		if e.OsmType != "N" || e.Key != "place" {
			continue
		}
		for _, ri := range relByName[normName(e.Name)] {
			if consumed[ri] {
				continue
			}
			r := entries[ri]
			if e.Lng >= r.Extent[0] && e.Lng <= r.Extent[2] && e.Lat >= r.Extent[3] && e.Lat <= r.Extent[1] {
				e.ID = r.ID
				e.OsmType = "R"
				e.Extent = r.Extent
				consumed[ri] = true
				break
			}
		}
	}

	out := make([]Entry, 0, len(merged))
	for i := range merged {
		if consumed[i] {
			continue
		}
		out = append(out, merged[i])
	}
	return out
}

// writeJSONL writes a meta line followed by one entry per line.
func writeJSONL(w io.Writer, entries []Entry, source string) error {
	meta := map[string]any{
		"format":    "freegle-places",
		"version":   1,
		"count":     len(entries),
		"source":    source,
		"generated": time.Now().UTC().Format(time.RFC3339),
	}
	enc := json.NewEncoder(w)
	if err := enc.Encode(meta); err != nil {
		return err
	}
	sort.Slice(entries, func(a, b int) bool {
		if entries[a].OsmType != entries[b].OsmType {
			return entries[a].OsmType < entries[b].OsmType
		}
		return entries[a].ID < entries[b].ID
	})
	for i := range entries {
		// Round every coordinate to 4dp (~11m) before it hits the artifact.
		// OSM's own grid is 7dp, but nothing consuming a PLACE needs better
		// than a house-length: the centroid centres a map and the extent
		// frames a town. Full float64 repr ("51.507445600000004") is pure
		// noise costing ~12 bytes a coordinate in the artifact and every
		// /api response body. 4dp per Edward 2026-08-31.
		entries[i].Lat = round4(entries[i].Lat)
		entries[i].Lng = round4(entries[i].Lng)
		for j := range entries[i].Extent {
			entries[i].Extent[j] = round4(entries[i].Extent[j])
		}
		if err := enc.Encode(&entries[i]); err != nil {
			return err
		}
	}
	return nil
}

// round4 clamps a coordinate to a 4-decimal-place (~11m) grid.
func round4(v float64) float64 {
	return math.Round(v*1e4) / 1e4
}
