package main

import (
	"testing"
)

// testPlaces builds a small in-memory index exercising every ranking rule.
func testPlaces() *placesIndex {
	entries := []PlaceEntry{
		{ID: 8292370, OsmType: "R", Name: "Kendal", Key: "place", Value: "town", Layer: "city",
			Lat: 54.3290, Lng: -2.7472, Extent: []float64{-2.7691, 54.3515, -2.7053, 54.2962},
			County: "Westmorland and Furness", State: "England", Pop: 28586},
		{ID: 2, OsmType: "N", Name: "Kendleshire", Key: "place", Value: "hamlet", Layer: "district",
			Lat: 51.5219, Lng: -2.4861, County: "South Gloucestershire", State: "England"},
		{ID: 3, OsmType: "N", Name: "Kensington", Key: "place", Value: "suburb", Layer: "district",
			Lat: 51.5, Lng: -0.19, County: "Greater London", State: "England"},
		{ID: 4, OsmType: "R", Name: "Kent", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 51.2, Lng: 0.7, Extent: []float64{0.0, 51.5, 1.5, 50.9}, State: "England"},
		{ID: 5, OsmType: "R", Name: "Devon", Key: "boundary", Value: "ceremonial", Layer: "other",
			Lat: 50.7, Lng: -3.8, Extent: []float64{-4.7, 51.2, -2.9, 50.2}, State: "England"},
		{ID: 6, OsmType: "R", Name: "East Devon", Key: "boundary", Value: "administrative", Layer: "city",
			Lat: 50.75, Lng: -3.2, Extent: []float64{-3.6, 50.9, -2.9, 50.6}, State: "England"},
		{ID: 7, OsmType: "R", Name: "West Midlands", Key: "boundary", Value: "statistical", Layer: "other",
			Lat: 52.5, Lng: -2.0, Extent: []float64{-3.2, 53.0, -1.2, 51.9}, State: "England"},
		{ID: 8, OsmType: "R", Name: "West Midlands", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 52.48, Lng: -1.9, Extent: []float64{-2.2, 52.7, -1.4, 52.3}, State: "England"},
		{ID: 9, OsmType: "R", Name: "Kenwyn", Key: "boundary", Value: "administrative", Layer: "district",
			Lat: 50.278, Lng: -5.118, Extent: []float64{-5.1701, 50.3042, -5.0516, 50.2517},
			County: "Cornwall", State: "England"},
		{ID: 10, OsmType: "N", Name: "Kenwyn", Key: "place", Value: "village", Layer: "city",
			Lat: 53.0, Lng: -1.0, County: "Derbyshire", State: "England"},
		{ID: 11, OsmType: "N", Name: "Milton", Key: "place", Value: "village", Layer: "city",
			Lat: 52.245, Lng: 0.177, County: "Cambridgeshire", State: "England", Pop: 4500},
		{ID: 12, OsmType: "N", Name: "Milton", Key: "place", Value: "suburb", Layer: "district",
			Lat: 50.79, Lng: -1.05, County: "City of Portsmouth", State: "England"},
		{ID: 13, OsmType: "N", Name: "Manchester", Key: "place", Value: "city", Layer: "city",
			Lat: 53.48, Lng: -2.24, County: "Greater Manchester", State: "England", Pop: 550000},
		{ID: 14, OsmType: "N", Name: "Grasmere", Key: "place", Value: "village", Layer: "city",
			Lat: 54.46, Lng: -3.02, County: "Westmorland and Furness", State: "England",
			Alt: []string{"Grasmere Village"}},
		{ID: 15, OsmType: "N", Name: "Ashby-de-la-Zouch", Key: "place", Value: "town", Layer: "city",
			Lat: 52.746, Lng: -1.476, County: "Leicestershire", State: "England", Pop: 12000},
		{ID: 16, OsmType: "R", Name: "Glasgow City", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 55.86, Lng: -4.25, Extent: []float64{-4.4, 55.93, -4.07, 55.78}, State: "Scotland"},
		{ID: 17, OsmType: "R", Name: "Vale of Glamorgan", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 51.44, Lng: -3.42, Extent: []float64{-3.65, 51.52, -3.16, 51.38}, State: "Wales"},
		{ID: 18, OsmType: "R", Name: "Stoke-on-Trent", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 53.0, Lng: -2.18, Extent: []float64{-2.24, 53.09, -2.08, 52.95}, State: "England"},
		{ID: 19, OsmType: "R", Name: "Devon", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 50.7, Lng: -3.8, Extent: []float64{-4.7, 51.2, -2.9, 50.2}, State: "England"},
		{ID: 20, OsmType: "N", Name: "Cambridge", Key: "place", Value: "city", Layer: "city",
			Lat: 52.2053, Lng: 0.1218, County: "Cambridgeshire", State: "England", Pop: 145700},
		{ID: 21, OsmType: "R", Name: "Cambridgeshire", Key: "boundary", Value: "administrative", Layer: "county",
			Lat: 52.3, Lng: 0.1, Extent: []float64{-0.5, 52.75, 0.5, 52.0}, State: "England"},
	}
	return buildPlacesIndex(entries)
}

// Photon shows one Kent, not the administrative, ceremonial and place-node
// variants separately: near-identical same-name entries collapse to the best.
func TestSameNameOverlapDedupe(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Devon", searchOpts{limit: 10})
	devons := 0
	for _, r := range res {
		if r.e.Name == "Devon" {
			devons++
		}
	}
	if devons != 1 {
		t.Fatalf("identical-extent Devons should collapse to one, got %d: %v", devons, firstNames(res, 5))
	}
}

// For as-you-type prefixes photon's importance puts the city above its county
// (Cambridge over Cambridgeshire). The county footprint boost is an
// exact-match disambiguator only.
func TestPrefixPrefersCityOverCounty(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Camb", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.Name != "Cambridge" {
		t.Fatalf("prefix should rank Cambridge first, got %v", firstNames(res, 5))
	}
}

// Real WhatJobs feed spellings that photon's looser scoring absorbed: filler
// words the OSM name does not carry must not blank the search.
func TestStopwordRelaxation(t *testing.T) {
	ix := testPlaces()
	cases := map[string]int64{
		"City of Glasgow":        16,
		"The Vale of Glamorgan":  17,
		"city of stoke on trent": 18,
	}
	for q, wantID := range cases {
		res := ix.search(q, searchOpts{limit: 5})
		if len(res) == 0 || res[0].e.ID != wantID {
			t.Errorf("search(%q) = %v, want entry %d first", q, firstNames(res, 3), wantID)
		}
	}
	// The relaxed pass must not fire when the strict pass succeeds: an exact
	// "Vale of Glamorgan" stays itself.
	res := ix.search("Vale of Glamorgan", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.ID != 17 {
		t.Errorf("strict match regressed: %v", firstNames(res, 3))
	}
}

func TestNormPlace(t *testing.T) {
	cases := map[string]string{
		"Batten&apos;s Green": "battens green",
		"batten&#039;s green": "battens green",
		"Bishop's Stortford":  "bishops stortford",
		"Ashby-de-la-Zouch":   "ashby de la zouch",
		"Ynys Môn":            "ynys mon",
		"Tŷ-du":               "ty du",
		"St. Columb":          "st columb",
		"  spaced   out  ":    "spaced out",
		"Bletchley & Fenny":   "bletchley fenny",
		"Bletchley &amp; F":   "bletchley f",
		"O’Brien’s Bridge":    "obriens bridge",
		"UPPER case":          "upper case",
		"james@phdcc.com":     "james phdcc com",
	}
	for in, want := range cases {
		if got := normPlace(in); got != want {
			t.Errorf("normPlace(%q) = %q, want %q", in, got, want)
		}
	}
}

func firstNames(res []scoredPlace, n int) []string {
	var names []string
	for i, r := range res {
		if i >= n {
			break
		}
		names = append(names, r.e.Name)
	}
	return names
}

func TestExactNameWins(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Kendal", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.ID != 8292370 {
		t.Fatalf("expected Kendal first, got %v", firstNames(res, 3))
	}
}

func TestLastTokenPrefix(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Kendlesh", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.Name != "Kendleshire" {
		t.Fatalf("prefix search failed: %v", firstNames(res, 3))
	}

	res = ix.search("Ken", searchOpts{limit: 10})
	found := false
	for _, r := range res {
		if r.e.Name == "Kendal" {
			found = true
		}
	}
	if !found {
		t.Fatalf("'Ken' should surface Kendal, got %v", firstNames(res, 10))
	}
}

func TestLayerFilter(t *testing.T) {
	ix := testPlaces()
	town := map[string]bool{"city": true, "locality": true, "district": true}

	res := ix.search("Kendal", searchOpts{limit: 5, layers: town})
	if len(res) == 0 || res[0].e.Name != "Kendal" {
		t.Fatalf("town layers should still find Kendal: %v", firstNames(res, 3))
	}

	res = ix.search("Kent", searchOpts{limit: 5, layers: town})
	for _, r := range res {
		if r.e.Layer == "county" {
			t.Fatalf("county leaked through town layer filter: %v", firstNames(res, 5))
		}
	}
}

func TestBboxFilter(t *testing.T) {
	ix := testPlaces()
	cambs := &[4]float64{-0.5, 51.8, 1.0, 52.7} // swlng, swlat, nelng, nelat
	res := ix.search("Milton", searchOpts{limit: 5, bbox: cambs})
	if len(res) != 1 || res[0].e.ID != 11 {
		t.Fatalf("bbox should keep only the Cambridgeshire Milton, got %v", firstNames(res, 5))
	}
}

func TestProximityBias(t *testing.T) {
	ix := testPlaces()
	lat, lng := 50.8, -1.09 // Portsmouth
	res := ix.search("Milton", searchOpts{limit: 5, biasLat: &lat, biasLng: &lng})
	if len(res) < 2 || res[0].e.ID != 12 {
		t.Fatalf("bias near Portsmouth should rank its Milton first, got %v", firstNames(res, 5))
	}
}

// The WhatJobs region lookups: exact-name area entries must rank the big
// statistical region above the metropolitan county of the same name, because
// the region bbox is what constrains the subsequent city searches.
func TestRegionBeatsCountySameName(t *testing.T) {
	ix := testPlaces()
	res := ix.search("West Midlands", searchOpts{limit: 5})
	if len(res) < 2 {
		t.Fatalf("expected both West Midlands entries, got %v", firstNames(res, 5))
	}
	if res[0].e.Value != "statistical" {
		t.Fatalf("statistical region should rank first, got %s/%s", res[0].e.Name, res[0].e.Value)
	}
}

func TestExactBeatsSuperstring(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Devon", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.Name != "Devon" {
		t.Fatalf("exact 'Devon' should beat 'East Devon', got %v", firstNames(res, 5))
	}
}

func TestCommaContextDisambiguates(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Kenwyn, Cornwall", searchOpts{limit: 5})
	if len(res) != 1 || res[0].e.ID != 9 {
		t.Fatalf("context should pick the Cornwall Kenwyn only, got %v", firstNames(res, 5))
	}
	// Without context, both surface.
	res = ix.search("Kenwyn", searchOpts{limit: 5})
	if len(res) != 2 {
		t.Fatalf("plain Kenwyn should surface both, got %v", firstNames(res, 5))
	}
}

func TestFuzzyEditDistanceOne(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Mancester", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.Name != "Manchester" {
		t.Fatalf("edit-1 typo should find Manchester, got %v", firstNames(res, 5))
	}
}

func TestAltNamesSearchable(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Grasmere Village", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.Name != "Grasmere" {
		t.Fatalf("alt name should match, got %v", firstNames(res, 5))
	}
}

func TestHyphenatedQuery(t *testing.T) {
	ix := testPlaces()
	res := ix.search("ashby de la zouch", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.ID != 15 {
		t.Fatalf("normalised hyphen query should match, got %v", firstNames(res, 5))
	}
}

func TestEmptyAndJunkQueries(t *testing.T) {
	ix := testPlaces()
	if res := ix.search("", searchOpts{limit: 5}); len(res) != 0 {
		t.Errorf("empty query should return nothing")
	}
	if res := ix.search("zzzqqqxxx", searchOpts{limit: 5}); len(res) != 0 {
		t.Errorf("junk query should return nothing, got %v", firstNames(res, 5))
	}
}

func TestLimit(t *testing.T) {
	ix := testPlaces()
	res := ix.search("Ken", searchOpts{limit: 2})
	if len(res) > 2 {
		t.Errorf("limit 2 exceeded: %d", len(res))
	}
}
