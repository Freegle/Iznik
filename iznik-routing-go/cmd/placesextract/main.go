// placesextract builds the places artifact served by iznik-spatial-go's
// photon-compatible /api endpoint (the Photon geocoder replacement).
//
// It scans an OSM PBF in three passes (relations → ways → nodes), keeping
// named place=* features and boundary=administrative/ceremonial/statistical
// relations, then computes extents, assigns county/state context by
// point-in-polygon against the assembled county and nation boundaries, and
// merges place nodes with their same-named parish boundary so towns carry a
// usable extent (what WhatJobsService reads as the bbox).
//
// Usage:
//
//	go run ./cmd/placesextract -pbf data/uk-latest.osm.pbf -out data/places.jsonl.gz
//
// -out ending in .gz is gzip-compressed; "-" writes plain JSONL to stdout
// (handy for docker exec into the routing container, whose /data is
// read-only: redirect stdout to the host file instead).
package main

import (
	"compress/gzip"
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"runtime"
	"runtime/debug"
	"strings"

	"github.com/paulmach/osm"
	"github.com/paulmach/osm/osmpbf"
)

// altNameKeys are the extra name tags indexed as search aliases.
var altNameKeys = []string{"alt_name", "loc_name", "official_name", "old_name", "short_name", "name:en"}

// mergeAdminLevels are the boundary levels eligible for linked-place merging:
// parish/community-scale, matching photon's linked-place pairs (e.g. Kendal
// place node ↔ Kendal civil parish). County scale and above never merge.
var mergeAdminLevels = []string{"7", "8", "9", "10", "11"}

type relInfo struct {
	id          int64
	name        string
	alt         []string
	key         string // place | boundary
	value       string
	layer       string
	adminLevel  string
	pop         int64
	wayRefs     []int64 // all way members, for the bbox
	outerRefs   []int64 // outer-role ways, for polygon assembly
	centreNode  int64   // admin_centre or label member, if any
	minX, minY  float64
	maxX, maxY  float64
	hasGeometry bool
}

type wayInfo struct {
	id    int64
	name  string
	alt   []string
	value string
	layer string
	pop   int64
	refs  []int64
}

func altNames(tags osm.Tags) []string {
	var alts []string
	seen := map[string]bool{}
	name := tags.Find("name")
	for _, k := range altNameKeys {
		v := tags.Find(k)
		if v == "" {
			continue
		}
		for _, part := range strings.Split(v, ";") {
			part = strings.TrimSpace(part)
			if part != "" && part != name && !seen[part] {
				seen[part] = true
				alts = append(alts, part)
			}
		}
	}
	return alts
}

// extractPlaces runs the full pipeline and returns the entries.
func extractPlaces(pbfPath string) ([]Entry, error) {
	f, err := os.Open(pbfPath)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	// Fixed 4 decode procs, like the routing server's own PBF passes: this
	// runs on the shared production batch host, where saturating all cores
	// (and paying per-proc decode buffers) is worse than a slower build.
	procs := 4
	if n := runtime.NumCPU(); n < procs {
		procs = n
	}

	// Pass 1: relations. Keep named place/boundary relations, remember which
	// ways (and centre nodes) we need geometry for.
	var rels []*relInfo
	neededWay := map[int64]bool{}
	sc := osmpbf.New(context.Background(), f, procs)
	sc.SkipNodes = true
	sc.SkipWays = true
	for sc.Scan() {
		r, ok := sc.Object().(*osm.Relation)
		if !ok {
			continue
		}
		name := r.Tags.Find("name")
		if name == "" {
			continue
		}
		var key, value, layer string
		adminLevel := r.Tags.Find("admin_level")
		if b := r.Tags.Find("boundary"); b != "" {
			layer = classifyBoundary(b, adminLevel)
			key, value = "boundary", b
		}
		if p := r.Tags.Find("place"); layer == "" && p != "" {
			layer = classifyPlace(p)
			key, value = "place", p
		}
		if layer == "" {
			continue
		}
		ri := &relInfo{
			id: int64(r.ID), name: name, alt: altNames(r.Tags),
			key: key, value: value, layer: layer, adminLevel: adminLevel,
			pop:  parsePopulation(r.Tags.Find("population")),
			minX: 1e9, minY: 1e9, maxX: -1e9, maxY: -1e9,
		}
		for _, m := range r.Members {
			switch m.Type {
			case osm.TypeWay:
				ri.wayRefs = append(ri.wayRefs, m.Ref)
				if m.Role == "outer" || m.Role == "" {
					ri.outerRefs = append(ri.outerRefs, m.Ref)
				}
				neededWay[m.Ref] = true
			case osm.TypeNode:
				if m.Role == "admin_centre" || m.Role == "label" {
					ri.centreNode = m.Ref
				}
			}
		}
		rels = append(rels, ri)
	}
	if err := sc.Err(); err != nil {
		return nil, fmt.Errorf("relations pass: %w", err)
	}
	sc.Close()
	log.Printf("placesextract: %d relations kept, %d member ways needed", len(rels), len(neededWay))

	// Pass 2: ways. Node refs for relation members, plus place=* ways. Needed
	// node ids go into a slice for a sorted-array coordinate store (the
	// graph.go pattern) — ~14M ids as maps cost over a GB of peak heap, which
	// the production batch host cannot spare.
	if _, err := f.Seek(0, 0); err != nil {
		return nil, err
	}
	wayRefs := map[int64][]int64{}
	var placeWays []*wayInfo
	neededIDs := make([]int64, 0, 16_000_000)
	sc = osmpbf.New(context.Background(), f, procs)
	sc.SkipNodes = true
	sc.SkipRelations = true
	for sc.Scan() {
		w, ok := sc.Object().(*osm.Way)
		if !ok {
			continue
		}
		wid := int64(w.ID)
		keep := neededWay[wid]
		var pw *wayInfo
		if p := w.Tags.Find("place"); p != "" {
			if name := w.Tags.Find("name"); name != "" {
				if layer := classifyPlace(p); layer != "" {
					pw = &wayInfo{
						id: wid, name: name, alt: altNames(w.Tags),
						value: p, layer: layer,
						pop: parsePopulation(w.Tags.Find("population")),
					}
				}
			}
		}
		if !keep && pw == nil {
			continue
		}
		refs := make([]int64, 0, len(w.Nodes))
		for _, n := range w.Nodes {
			refs = append(refs, int64(n.ID))
		}
		neededIDs = append(neededIDs, refs...)
		if keep {
			wayRefs[wid] = refs
		}
		if pw != nil {
			pw.refs = refs
			placeWays = append(placeWays, pw)
		}
	}
	if err := sc.Err(); err != nil {
		return nil, fmt.Errorf("ways pass: %w", err)
	}
	sc.Close()
	for _, ri := range rels {
		if ri.centreNode != 0 {
			neededIDs = append(neededIDs, ri.centreNode)
		}
	}
	coords := newCoordStore(neededIDs)
	neededIDs = nil
	log.Printf("placesextract: %d member ways resolved, %d place ways, %d node coords needed",
		len(wayRefs), len(placeWays), coords.len())

	// Pass 3: nodes. Coordinates for needed nodes, plus place=* node entries.
	if _, err := f.Seek(0, 0); err != nil {
		return nil, err
	}
	var entries []Entry
	sc = osmpbf.New(context.Background(), f, procs)
	sc.SkipWays = true
	sc.SkipRelations = true
	for sc.Scan() {
		n, ok := sc.Object().(*osm.Node)
		if !ok {
			continue
		}
		nid := int64(n.ID)
		coords.set(nid, n.Lon, n.Lat)
		p := ""
		for _, t := range n.Tags {
			if t.Key == "place" {
				p = t.Value
				break
			}
		}
		if p == "" {
			continue
		}
		name := n.Tags.Find("name")
		if name == "" {
			continue
		}
		layer := classifyPlace(p)
		if layer == "" {
			continue
		}
		entries = append(entries, Entry{
			ID: nid, OsmType: "N", Name: name, Alt: altNames(n.Tags),
			Key: "place", Value: p, Layer: layer,
			Lat: n.Lat, Lng: n.Lon,
			Pop: parsePopulation(n.Tags.Find("population")),
		})
	}
	if err := sc.Err(); err != nil {
		return nil, fmt.Errorf("nodes pass: %w", err)
	}
	sc.Close()
	log.Printf("placesextract: %d place nodes, %d coords resolved", len(entries), coords.resolved())

	// Way entries: bbox + centre from resolved coords.
	for _, pw := range placeWays {
		e, ok := boxEntry(pw.refs, coords.get)
		if !ok {
			continue
		}
		e.ID, e.OsmType, e.Name, e.Alt = pw.id, "W", pw.name, pw.alt
		e.Key, e.Value, e.Layer, e.Pop = "place", pw.value, pw.layer, pw.pop
		entries = append(entries, e)
	}

	// Relation bboxes from member ways, centre from admin_centre/label node
	// when present. Relations whose members fall outside the file (clipped
	// extracts) are dropped.
	getWay := func(id int64) ([]int64, bool) { r, ok := wayRefs[id]; return r, ok }
	getCoord := coords.get
	relEntries := make(map[int64]int) // relation id -> entries index
	for _, ri := range rels {
		for _, wid := range ri.wayRefs {
			for _, ref := range wayRefs[wid] {
				if c, ok := coords.get(ref); ok {
					ri.hasGeometry = true
					if c[0] < ri.minX {
						ri.minX = c[0]
					}
					if c[0] > ri.maxX {
						ri.maxX = c[0]
					}
					if c[1] < ri.minY {
						ri.minY = c[1]
					}
					if c[1] > ri.maxY {
						ri.maxY = c[1]
					}
				}
			}
		}
		if !ri.hasGeometry {
			continue
		}
		lat := (ri.minY + ri.maxY) / 2
		lng := (ri.minX + ri.maxX) / 2
		if c, ok := coords.get(ri.centreNode); ri.centreNode != 0 && ok {
			lng, lat = c[0], c[1]
		}
		relEntries[ri.id] = len(entries)
		entries = append(entries, Entry{
			ID: ri.id, OsmType: "R", Name: ri.name, Alt: ri.alt,
			Key: ri.key, Value: ri.value, Layer: ri.layer,
			Lat: lat, Lng: lng,
			Extent:     []float64{ri.minX, ri.maxY, ri.maxX, ri.minY},
			Pop:        ri.pop,
			adminLevel: ri.adminLevel,
		})
	}

	// Context polygons: counties (admin 5/6 + ceremonial) and nations (admin 4).
	type contextPoly struct {
		name string
		poly polygon
	}
	var counties, states []contextPoly
	for _, ri := range rels {
		if !ri.hasGeometry {
			continue
		}
		isCounty := (ri.value == "administrative" && (ri.adminLevel == "5" || ri.adminLevel == "6")) ||
			ri.value == "ceremonial"
		isState := ri.value == "administrative" && ri.adminLevel == "4"
		if !isCounty && !isState {
			continue
		}
		rings := assembleRings(ri.outerRefs, getWay, getCoord)
		if len(rings) == 0 {
			continue
		}
		p := polygon{rings: rings}
		p.computeBounds()
		cp := contextPoly{name: ri.name, poly: p}
		if isCounty {
			counties = append(counties, cp)
		} else {
			states = append(states, cp)
		}
	}
	log.Printf("placesextract: %d county polygons, %d nation polygons assembled", len(counties), len(states))

	// Assign county/state context. Smallest containing county wins (ceremonial
	// counties nest unitaries; the tighter name is the one photon shows).
	for i := range entries {
		e := &entries[i]
		if e.Layer == "state" || e.Layer == "county" || e.Layer == "other" {
			// Regions/counties/nations don't get county context...
		} else {
			best := -1
			for ci := range counties {
				if counties[ci].poly.contains(e.Lng, e.Lat) {
					if best == -1 || counties[ci].poly.area < counties[best].poly.area {
						best = ci
					}
				}
			}
			if best >= 0 && counties[best].name != e.Name {
				e.County = counties[best].name
			}
		}
		for si := range states {
			if states[si].poly.contains(e.Lng, e.Lat) {
				e.State = states[si].name
				break
			}
		}
	}

	entries = mergeLinkedPlaces(entries, mergeAdminLevels)
	return entries, nil
}

// boxEntry builds the positional part of an entry from a ring of node refs.
func boxEntry(refs []int64, get func(int64) ([2]float64, bool)) (Entry, bool) {
	minX, minY, maxX, maxY := 1e9, 1e9, -1e9, -1e9
	found := false
	for _, ref := range refs {
		c, ok := get(ref)
		if !ok {
			continue
		}
		found = true
		if c[0] < minX {
			minX = c[0]
		}
		if c[0] > maxX {
			maxX = c[0]
		}
		if c[1] < minY {
			minY = c[1]
		}
		if c[1] > maxY {
			maxY = c[1]
		}
	}
	if !found {
		return Entry{}, false
	}
	return Entry{
		Lat:    (minY + maxY) / 2,
		Lng:    (minX + maxX) / 2,
		Extent: []float64{minX, maxY, maxX, minY},
	}, true
}

func main() {
	pbf := flag.String("pbf", getenvDefault("OSM_PBF_PATH", "data/uk-latest.osm.pbf"), "input OSM PBF")
	out := flag.String("out", "-", "output path (.gz = gzipped; - = stdout)")
	flag.Parse()
	log.SetFlags(log.Ltime)

	// Offline batch tool that runs on the shared production batch host: trade
	// a little CPU for a much smaller peak footprint.
	debug.SetGCPercent(50)

	entries, err := extractPlaces(*pbf)
	if err != nil {
		log.Fatalf("placesextract: %v", err)
	}
	log.Printf("placesextract: %d entries total", len(entries))

	var w *os.File
	if *out == "-" {
		w = os.Stdout
	} else {
		w, err = os.Create(*out)
		if err != nil {
			log.Fatalf("placesextract: %v", err)
		}
	}
	source := filepath.Base(*pbf)
	if strings.HasSuffix(*out, ".gz") {
		gz := gzip.NewWriter(w)
		if err := writeJSONL(gz, entries, source); err != nil {
			log.Fatalf("placesextract: write: %v", err)
		}
		if err := gz.Close(); err != nil {
			log.Fatalf("placesextract: gzip close: %v", err)
		}
	} else if err := writeJSONL(w, entries, source); err != nil {
		log.Fatalf("placesextract: write: %v", err)
	}
	if *out != "-" {
		if err := w.Close(); err != nil {
			log.Fatalf("placesextract: close: %v", err)
		}
		log.Printf("placesextract: wrote %s", *out)
	}
}

func getenvDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
