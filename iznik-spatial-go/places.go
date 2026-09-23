package main

// The places dataset: a photon-compatible forward geocoder for UK place names,
// replacing the Photon+Elasticsearch JVMs that used to serve
// geocode.ilovefreegle.org. The artifact is built offline by
// iznik-routing-go/cmd/placesextract from uk-latest.osm.pbf and served here
// from memory (~200k entries, tens of MB).
//
// Unlike the SQLite datasets this is file-backed, not MySQL-backed: the file
// is polled by mtime and swapped atomically. Where the file is absent (the
// db-node instances) nothing loads and /api answers 503 — which nginx's
// proxy_cache_valid 200 never caches.

import (
	"bufio"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"time"
)

// PlaceEntry is one line of the places artifact (see cmd/placesextract in
// iznik-routing-go for the writer; field meanings match photon's properties).
type PlaceEntry struct {
	ID      int64     `json:"id"`
	OsmType string    `json:"ot"` // N, W or R
	Name    string    `json:"name"`
	Alt     []string  `json:"alt"`
	Key     string    `json:"key"` // place | boundary
	Value   string    `json:"val"`
	Layer   string    `json:"layer"` // city, district, locality, county, state, other
	Lat     float64   `json:"lat"`
	Lng     float64   `json:"lng"`
	Extent  []float64 `json:"ext"` // [W,N,E,S], photon's order
	County  string    `json:"county"`
	State   string    `json:"state"`
	Pop     int64     `json:"pop"`

	// Computed at index build time. Token bags live only in the postings map
	// on the index, not per entry — 195k retained maps cost ~100MB.
	nameNorms []string
	areaKm2   float64
}

// placesIdx is the currently-served index; nil until a file loads.
var placesIdx atomic.Pointer[placesIndex]

type placesFileState struct {
	mtime        time.Time
	size         int64
	loggedAbsent bool
}

// loadPlacesFile parses a places artifact (gzipped JSONL with a meta first
// line) and builds the search index. Zero entries is an error: an empty file
// must never displace a working index.
func loadPlacesFile(path string) (*placesIndex, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	var r io.Reader = f
	if strings.HasSuffix(path, ".gz") {
		gz, err := gzip.NewReader(f)
		if err != nil {
			return nil, fmt.Errorf("gzip: %w", err)
		}
		defer gz.Close()
		r = gz
	}

	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 1024*1024), 1024*1024)
	if !sc.Scan() {
		return nil, fmt.Errorf("empty places file")
	}
	var meta struct {
		Format  string `json:"format"`
		Version int    `json:"version"`
	}
	if err := json.Unmarshal(sc.Bytes(), &meta); err != nil {
		return nil, fmt.Errorf("meta line: %w", err)
	}
	if meta.Format != "freegle-places" || meta.Version != 1 {
		return nil, fmt.Errorf("unexpected places format %q v%d", meta.Format, meta.Version)
	}

	var entries []PlaceEntry
	for sc.Scan() {
		var e PlaceEntry
		if err := json.Unmarshal(sc.Bytes(), &e); err != nil {
			return nil, fmt.Errorf("entry %d: %w", len(entries)+1, err)
		}
		if e.Name == "" || e.Layer == "" {
			continue
		}
		entries = append(entries, e)
	}
	if err := sc.Err(); err != nil {
		return nil, err
	}
	if len(entries) == 0 {
		return nil, fmt.Errorf("places file has no entries")
	}
	return buildPlacesIndex(entries), nil
}

// maybeLoadPlaces stats the file and (re)loads it when the mtime or size has
// changed since the last attempt. A load failure keeps the previous index
// serving and is not retried until the file changes again, so a permanently
// corrupt file logs once, and a file caught mid-write loads on the next poll.
func maybeLoadPlaces(path string, st *placesFileState) {
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			if !st.loggedAbsent {
				log.Printf("places: %s absent, /api not serving (normal where the artifact is not deployed)", path)
				st.loggedAbsent = true
			}
		} else {
			log.Printf("places: WARNING stat %s: %v", path, err)
		}
		return
	}
	st.loggedAbsent = false
	if info.ModTime().Equal(st.mtime) && info.Size() == st.size {
		return
	}
	st.mtime, st.size = info.ModTime(), info.Size()

	start := time.Now()
	ix, err := loadPlacesFile(path)
	if err != nil {
		log.Printf("places: WARNING load %s failed, keeping previous index: %v", path, err)
		return
	}
	placesIdx.Store(ix)
	log.Printf("places: loaded %d entries, %d tokens from %s in %dms",
		len(ix.entries), len(ix.tokens), path, time.Since(start).Milliseconds())
}

// startPlaces does the initial load and starts the mtime poller. The default
// path sits inside SPATIAL_INDEX_DIR so instances without the artifact (the
// db nodes) skip quietly.
func startPlaces() {
	path := getenv("PLACES_FILE", filepath.Join(getenv("SPATIAL_INDEX_DIR", "/data"), "places.jsonl.gz"))
	st := &placesFileState{}
	maybeLoadPlaces(path, st)
	go func() {
		for {
			time.Sleep(time.Minute)
			maybeLoadPlaces(path, st)
		}
	}()
}
