package main

import (
	"compress/gzip"
	"os"
	"path/filepath"
	"testing"
)

func writeTestArtifact(t *testing.T, dir, name string, lines []string) string {
	t.Helper()
	path := filepath.Join(dir, name)
	f, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	gz := gzip.NewWriter(f)
	for _, l := range lines {
		if _, err := gz.Write([]byte(l + "\n")); err != nil {
			t.Fatal(err)
		}
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	if err := f.Close(); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestLoadPlacesFile(t *testing.T) {
	dir := t.TempDir()
	path := writeTestArtifact(t, dir, "places.jsonl.gz", []string{
		`{"format":"freegle-places","version":1,"count":2}`,
		`{"id":8292370,"ot":"R","name":"Kendal","key":"place","val":"town","layer":"city","lat":54.329,"lng":-2.747,"ext":[-2.769,54.351,-2.705,54.296],"county":"Westmorland and Furness","state":"England","pop":28586}`,
		`{"id":2,"ot":"N","name":"Grasmere","key":"place","val":"village","layer":"city","lat":54.46,"lng":-3.02}`,
	})

	ix, err := loadPlacesFile(path)
	if err != nil {
		t.Fatalf("loadPlacesFile: %v", err)
	}
	if len(ix.entries) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(ix.entries))
	}
	res := ix.search("Kendal", searchOpts{limit: 5})
	if len(res) == 0 || res[0].e.ID != 8292370 {
		t.Fatalf("loaded index should be searchable")
	}
}

func TestLoadPlacesFileWrongFormat(t *testing.T) {
	dir := t.TempDir()
	path := writeTestArtifact(t, dir, "bad.jsonl.gz", []string{
		`{"format":"something-else","version":1}`,
		`{"id":1,"ot":"N","name":"X","key":"place","val":"town","layer":"city","lat":54,"lng":-2}`,
	})
	if _, err := loadPlacesFile(path); err == nil {
		t.Fatal("wrong format should be rejected")
	}
}

func TestLoadPlacesFileEmpty(t *testing.T) {
	dir := t.TempDir()
	path := writeTestArtifact(t, dir, "empty.jsonl.gz", []string{
		`{"format":"freegle-places","version":1,"count":0}`,
	})
	if _, err := loadPlacesFile(path); err == nil {
		t.Fatal("an artifact with zero entries should be rejected, not served")
	}
}

// An absent file is the normal state on db-node instances: no error spam, no
// index, and the poller keeps checking so a file that appears later loads.
func TestMaybeLoadPlacesAbsent(t *testing.T) {
	placesIdx.Store(nil)
	maybeLoadPlaces(filepath.Join(t.TempDir(), "nope.jsonl.gz"), &placesFileState{})
	if placesIdx.Load() != nil {
		t.Fatal("absent file should leave no index")
	}
}

func TestMaybeLoadPlacesLoadsAndReloads(t *testing.T) {
	dir := t.TempDir()
	path := writeTestArtifact(t, dir, "places.jsonl.gz", []string{
		`{"format":"freegle-places","version":1,"count":1}`,
		`{"id":1,"ot":"N","name":"Kendal","key":"place","val":"town","layer":"city","lat":54.3,"lng":-2.7}`,
	})
	st := &placesFileState{}
	placesIdx.Store(nil)
	maybeLoadPlaces(path, st)
	ix := placesIdx.Load()
	if ix == nil || len(ix.entries) != 1 {
		t.Fatal("initial load failed")
	}

	// Unchanged mtime: no reload (same pointer).
	maybeLoadPlaces(path, st)
	if placesIdx.Load() != ix {
		t.Fatal("unchanged file should not rebuild the index")
	}

	// Rewrite with different content and a bumped mtime.
	writeTestArtifact(t, dir, "places.jsonl.gz", []string{
		`{"format":"freegle-places","version":1,"count":2}`,
		`{"id":1,"ot":"N","name":"Kendal","key":"place","val":"town","layer":"city","lat":54.3,"lng":-2.7}`,
		`{"id":2,"ot":"N","name":"Grasmere","key":"place","val":"village","layer":"city","lat":54.46,"lng":-3.02}`,
	})
	bumpMtime(t, path)
	maybeLoadPlaces(path, st)
	ix2 := placesIdx.Load()
	if ix2 == nil || len(ix2.entries) != 2 {
		t.Fatalf("changed file should reload, got %v", ix2)
	}

	// A corrupt replacement must NOT clobber the good index.
	if err := os.WriteFile(path, []byte("not gzip"), 0o644); err != nil {
		t.Fatal(err)
	}
	bumpMtime(t, path)
	maybeLoadPlaces(path, st)
	if placesIdx.Load() != ix2 {
		t.Fatal("corrupt file must leave the previous index serving")
	}
	placesIdx.Store(nil)
}

func bumpMtime(t *testing.T, path string) {
	t.Helper()
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(path, info.ModTime().Add(2e9), info.ModTime().Add(2e9)); err != nil {
		t.Fatal(err)
	}
}
