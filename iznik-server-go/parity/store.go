package parity

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"regexp"
	"sync"
)

// goldens.json is generated. `node tools/orm-migration/extract.mjs update` writes it from the raw
// SQL manifest, and `check` fails when it is stale, so it cannot drift from the manifest. It sits in
// this module because the Go tests run in a container that holds only iznik-server-go, where
// tools/ does not exist, and go:embed cannot reach outside the module.
//
//go:embed goldens.json
var embeddedGoldens []byte

// pgPlaceholders matches PostgreSQL's numbered placeholders.
var pgPlaceholders = regexp.MustCompile(`\$[0-9]+`)

// ApprovedDiff is a reviewer's record that a converted site legitimately renders something other
// than its golden, with the SQL it renders and why that is the same statement.
type ApprovedDiff struct {
	SQL    string `json:"sql"`
	Reason string `json:"reason"`
}

// Site is what the harness knows about one manifest entry.
type Site struct {
	File         string        `json:"file"`
	Function     string        `json:"function"`
	Surface      string        `json:"surface"`
	Status       string        `json:"status"`
	GoldenSQL    string        `json:"goldenSql"`
	ApprovedDiff *ApprovedDiff `json:"approvedDiff,omitempty"`
}

type goldenFile struct {
	Sites map[string]Site `json:"sites"`
}

var (
	storeMu    sync.Mutex
	storeSites map[string]Site
)

func loadStore() (map[string]Site, error) {
	storeMu.Lock()
	defer storeMu.Unlock()
	if storeSites != nil {
		return storeSites, nil
	}
	var f goldenFile
	if err := json.Unmarshal(embeddedGoldens, &f); err != nil {
		return nil, fmt.Errorf("goldens.json is not valid JSON: %w", err)
	}
	if len(f.Sites) == 0 {
		return nil, fmt.Errorf("goldens.json holds no sites: run node tools/orm-migration/extract.mjs update")
	}
	storeSites = f.Sites
	return storeSites, nil
}

// LookupSite returns the manifest entry for id. A typo in an id fails here, so a test cannot quietly
// prove nothing.
func LookupSite(id string) (Site, error) {
	sites, err := loadStore()
	if err != nil {
		return Site{}, err
	}
	s, ok := sites[id]
	if !ok {
		return Site{}, fmt.Errorf("no manifest entry for site %q: use a site id from tools/orm-migration/manifest.json "+
			"and run node tools/orm-migration/extract.mjs update if the manifest is newer than goldens.json", id)
	}
	return s, nil
}

// setStoreForTest replaces the store so the harness's own tests pin exact content. It returns a
// function that restores the real one.
func setStoreForTest(sites map[string]Site) func() {
	storeMu.Lock()
	prev := storeSites
	storeSites = sites
	storeMu.Unlock()
	return func() {
		storeMu.Lock()
		storeSites = prev
		storeMu.Unlock()
	}
}
