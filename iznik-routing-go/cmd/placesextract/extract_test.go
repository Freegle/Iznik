package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"math"
	"os"
	"testing"
)

func TestClassifyPlace(t *testing.T) {
	cases := map[string]string{
		"city":              "city",
		"town":              "city",
		"village":           "city", // photon puts villages in the city layer (probed live 2026-08-31)
		"suburb":            "district",
		"neighbourhood":     "district",
		"quarter":           "district",
		"borough":           "district",
		"hamlet":            "district",
		"locality":          "locality",
		"isolated_dwelling": "locality",
		"farm":              "locality",
		"county":            "county",
		"state":             "state",
		"region":            "state",
		"island":            "other",
		"islet":             "other",
		"sea":               "", // skipped
		"ocean":             "",
		"continent":         "",
	}
	for v, want := range cases {
		if got := classifyPlace(v); got != want {
			t.Errorf("classifyPlace(%q) = %q, want %q", v, got, want)
		}
	}
}

func TestClassifyBoundary(t *testing.T) {
	cases := []struct {
		boundary, adminLevel, want string
	}{
		{"administrative", "2", ""}, // whole-country relation: not useful, UK file only
		{"administrative", "4", "state"},
		{"administrative", "5", "county"},
		{"administrative", "6", "county"},
		{"administrative", "7", "city"},
		{"administrative", "8", "city"},
		{"administrative", "9", "district"},
		{"administrative", "10", "district"},
		{"administrative", "11", "district"},
		{"administrative", "", ""},
		{"ceremonial", "", "other"},
		{"ceremonial", "6", "other"},
		{"statistical", "", "other"},
		{"political", "", ""},
		{"maritime", "", ""},
	}
	for _, c := range cases {
		if got := classifyBoundary(c.boundary, c.adminLevel); got != c.want {
			t.Errorf("classifyBoundary(%q,%q) = %q, want %q", c.boundary, c.adminLevel, got, c.want)
		}
	}
}

func TestParsePopulation(t *testing.T) {
	cases := map[string]int64{
		"28586":     28586,
		"1,234,567": 1234567,
		"12 345":    12345,
		"~5000":     5000,
		"unknown":   0,
		"":          0,
	}
	for s, want := range cases {
		if got := parsePopulation(s); got != want {
			t.Errorf("parsePopulation(%q) = %d, want %d", s, got, want)
		}
	}
}

// Ring assembly: four separate two-point ways forming a unit square, with one
// segment reversed, must join into a single closed ring, and point-in-polygon
// must agree with the square.
func TestRingAssemblyAndPIP(t *testing.T) {
	coord := map[int64][2]float64{
		1: {0, 0}, // {lng, lat}
		2: {1, 0},
		3: {1, 1},
		4: {0, 1},
	}
	ways := map[int64][]int64{
		10: {1, 2},
		11: {2, 3},
		12: {4, 3}, // reversed on purpose
		13: {4, 1},
	}
	get := func(id int64) ([]int64, bool) { refs, ok := ways[id]; return refs, ok }
	getCoord := func(id int64) ([2]float64, bool) { c, ok := coord[id]; return c, ok }

	rings := assembleRings([]int64{10, 11, 12, 13}, get, getCoord)
	if len(rings) != 1 {
		t.Fatalf("expected 1 ring, got %d", len(rings))
	}
	if len(rings[0]) < 4 {
		t.Fatalf("ring too short: %d points", len(rings[0]))
	}

	poly := polygon{rings: rings}
	poly.computeBounds()
	if !poly.contains(0.5, 0.5) {
		t.Errorf("centre of square should be inside")
	}
	if poly.contains(1.5, 0.5) {
		t.Errorf("point east of square should be outside")
	}
	if poly.contains(0.5, -0.5) {
		t.Errorf("point south of square should be outside")
	}
}

// An unnamed or unclosable ring set must not panic and should yield no rings.
func TestRingAssemblyGap(t *testing.T) {
	coord := map[int64][2]float64{1: {0, 0}, 2: {1, 0}, 3: {1, 1}}
	ways := map[int64][]int64{10: {1, 2}, 11: {2, 3}} // open chain, never closes
	get := func(id int64) ([]int64, bool) { refs, ok := ways[id]; return refs, ok }
	getCoord := func(id int64) ([2]float64, bool) { c, ok := coord[id]; return c, ok }
	rings := assembleRings([]int64{10, 11}, get, getCoord)
	if len(rings) != 0 {
		t.Fatalf("open chain should assemble no rings, got %d", len(rings))
	}
}

// A place node sharing a (case-insensitive) name with a same-located boundary
// relation adopts the relation's id and extent — the photon "linked place"
// behaviour that gives towns their bbox (e.g. Kendal = R8292370).
func TestMergeLinkedPlace(t *testing.T) {
	node := Entry{ID: 100, OsmType: "N", Name: "Kendal", Key: "place", Value: "town",
		Layer: "city", Lat: 54.3289, Lng: -2.7471}
	rel := Entry{ID: 8292370, OsmType: "R", Name: "kendal", Key: "boundary", Value: "administrative",
		Layer: "district", Lat: 54.32, Lng: -2.73,
		Extent:     []float64{-2.7690627, 54.3514945, -2.7052521, 54.2962041},
		adminLevel: "10"}
	other := Entry{ID: 200, OsmType: "N", Name: "Elsewhere", Key: "place", Value: "village",
		Layer: "city", Lat: 51.0, Lng: -1.0}

	out := mergeLinkedPlaces([]Entry{node, rel, other}, []string{"10"})

	var kendal *Entry
	for i := range out {
		if out[i].Name == "Kendal" {
			kendal = &out[i]
		}
	}
	if kendal == nil {
		t.Fatal("Kendal entry lost in merge")
	}
	if kendal.OsmType != "R" || kendal.ID != 8292370 {
		t.Errorf("merged entry should carry relation identity, got %s%d", kendal.OsmType, kendal.ID)
	}
	if kendal.Value != "town" || kendal.Layer != "city" || kendal.Key != "place" {
		t.Errorf("merged entry should keep place classification, got key=%s val=%s layer=%s",
			kendal.Key, kendal.Value, kendal.Layer)
	}
	if len(kendal.Extent) != 4 {
		t.Errorf("merged entry should gain the relation extent")
	}
	if math.Abs(kendal.Lat-54.3289) > 1e-6 {
		t.Errorf("merged entry should keep the place node position")
	}
	// The consumed relation must not appear separately.
	count := 0
	for _, e := range out {
		if e.OsmType == "R" && e.ID == 8292370 {
			count++
		}
	}
	if count != 1 {
		t.Errorf("relation should appear exactly once after merge, got %d", count)
	}
	// mergeLinkedPlaces only consumes relations at the given admin levels.
	out2 := mergeLinkedPlaces([]Entry{node, rel, other}, []string{"6"})
	merged := false
	for _, e := range out2 {
		if e.Name == "Kendal" && e.OsmType == "R" {
			merged = true
		}
	}
	if merged {
		t.Errorf("relation admin level not in merge set should not merge")
	}
}

// End-to-end against the committed Bristol extract. Structural assertions only:
// the extract clips relations at its edges, so nation-level context is not
// asserted here.
func TestBristolExtract(t *testing.T) {
	pbf := "../../testdata/bristol.osm.pbf"
	if _, err := os.Stat(pbf); err != nil {
		t.Skipf("fixture missing: %v", err)
	}

	entries, err := extractPlaces(pbf)
	if err != nil {
		t.Fatalf("extractPlaces: %v", err)
	}
	if len(entries) < 150 {
		t.Fatalf("expected at least 150 place entries around Bristol, got %d", len(entries))
	}

	var bristol, clifton *Entry
	withExtent := 0
	for i := range entries {
		e := &entries[i]
		if e.Name == "" {
			t.Fatalf("entry with empty name: %+v", e)
		}
		if e.Lat < 50.5 || e.Lat > 52.5 || e.Lng < -4.5 || e.Lng > -1.5 {
			t.Errorf("entry outside Bristol extract area: %s at %f,%f", e.Name, e.Lat, e.Lng)
		}
		if len(e.Extent) == 4 {
			withExtent++
			w, n, ee, s := e.Extent[0], e.Extent[1], e.Extent[2], e.Extent[3]
			if !(w < ee && s < n) {
				t.Errorf("extent must be [W,N,E,S] with W<E and S<N: %s %v", e.Name, e.Extent)
			}
		}
		if e.Name == "Bristol" && e.Layer == "city" {
			bristol = e
		}
		if e.Name == "Clifton" && e.Layer == "district" && bristolish(e.Lat, e.Lng) {
			clifton = e
		}
	}
	if bristol == nil {
		t.Fatal("no Bristol city entry found")
	}
	if math.Abs(bristol.Lat-51.45) > 0.15 || math.Abs(bristol.Lng+2.59) > 0.15 {
		t.Errorf("Bristol at unexpected position: %f,%f", bristol.Lat, bristol.Lng)
	}
	if clifton == nil {
		t.Fatal("no Clifton suburb entry found")
	}
	if withExtent < 5 {
		t.Errorf("expected some entries with extents, got %d", withExtent)
	}

	// Round-trip through the JSONL writer.
	var buf bytes.Buffer
	if err := writeJSONL(&buf, entries, "bristol.osm.pbf"); err != nil {
		t.Fatalf("writeJSONL: %v", err)
	}
	sc := bufio.NewScanner(&buf)
	sc.Buffer(make([]byte, 1024*1024), 1024*1024)
	if !sc.Scan() {
		t.Fatal("no meta line")
	}
	var meta map[string]any
	if err := json.Unmarshal(sc.Bytes(), &meta); err != nil {
		t.Fatalf("meta line not JSON: %v", err)
	}
	if meta["format"] != "freegle-places" {
		t.Errorf("meta format = %v", meta["format"])
	}
	n := 0
	for sc.Scan() {
		var e Entry
		if err := json.Unmarshal(sc.Bytes(), &e); err != nil {
			t.Fatalf("entry line %d not JSON: %v", n, err)
		}
		n++
	}
	if n != len(entries) {
		t.Errorf("round-trip count %d != %d", n, len(entries))
	}
}

func bristolish(lat, lng float64) bool {
	return math.Abs(lat-51.46) < 0.1 && math.Abs(lng+2.61) < 0.1
}
