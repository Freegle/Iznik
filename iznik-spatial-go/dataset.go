package main

import (
	"database/sql"
	"errors"
	"time"

	"github.com/peterstace/simplefeatures/geom"
)

// QueryParams holds the parameters for a spatial query.
type QueryParams struct {
	Lng        float64
	Lat        float64
	Limit      int
	Polygon    *geom.Geometry // optional; restrict results to items within this polygon
	TypeFilter string         // optional dataset-specific filter (e.g. "Postcode" for locations)
}

// QueryResult is a single result returned by a KNN or Within query.
type QueryResult struct {
	ID       int64          `json:"id"`
	Distance float64        `json:"distance"`
	Extra    map[string]any `json:"extra,omitempty"`
}

// ErrDeltaNotSupported is returned by ApplyDelta for rebuild-only datasets.
var ErrDeltaNotSupported = errors.New("dataset does not support incremental updates")

// ErrTooManyResults is returned by Within when the polygon exceeds the ID cap.
var ErrTooManyResults = errors.New("polygon matches too many results; narrow the polygon")

// maxWithinResults is the cap on IDs returned by a Within query.
// Raised from 10k so dense urban isochrones (central London ~50k freeglers
// within 30-minute drive) don't trip the cap.  Memory cost is small (~4 bytes
// per ID × 100k = ~400 KB per request).
const maxWithinResults = 100_000

// Dataset is the interface each spatial dataset must implement.
type Dataset interface {
	// Name returns the dataset identifier used in URL paths (e.g. "locations").
	Name() string

	// Load populates idx from mysqlDB with a full table scan.
	Load(mysqlDB *sql.DB, idx *Index) error

	// ApplyDelta loads rows modified since `since` and updates idx.
	// Returns ErrDeltaNotSupported for rebuild-only datasets.
	ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error

	// Query returns the nearest items to (params.Lng, params.Lat), up to params.Limit.
	// If params.Polygon is non-nil, only items within that polygon are returned.
	Query(idx *Index, params QueryParams) ([]QueryResult, error)

	// Within returns all external IDs of items that intersect params.Polygon.
	// Returns ErrTooManyResults if the result set exceeds maxWithinResults.
	Within(idx *Index, params QueryParams) ([]int64, error)

	// RebuildInterval is how often the dataset is fully rebuilt from MySQL.
	RebuildInterval() time.Duration

	// DeltaInterval is how often ApplyDelta is called; 0 for rebuild-only datasets.
	DeltaInterval() time.Duration
}

// PointContainer is an optional interface for datasets that answer "which
// items' geometry contains this point?" (GET /v1/:dataset/containing).
// `in` items definitely contain the point; `partial` items might — the point
// falls in a raster boundary band — and the caller must resolve them against
// the exact source geometry. See ReachDataset.
type PointContainer interface {
	Containing(idx *Index, lng, lat float64) (in []int64, partial []int64, err error)
}

// PointContainerFiltered is an optional interface for containment datasets whose
// items are stamped with a category the caller must select on - currently the
// overflow rings, where the same post admits one member's density band and
// refuses another's. Callers name their categories and get plain external ids
// back, so no caller needs the dataset's own encoding.
type PointContainerFiltered interface {
	ContainingFiltered(idx *Index, lng, lat float64, filter []string) (in []int64, partial []int64, err error)
}

// DriftChecker is an optional interface for delta datasets whose source table
// can change in ways the incremental delta cannot self-heal.
//
// The WhatJobs sync RENAME-swaps the `jobs` table, hard-DELETEing postings that
// have closed/left the feed. The add/update-only delta (ApplyDelta keys on
// `seenat > since`) never sees those vanished ids, so their index entries linger
// as orphans until the next full (nightly) rebuild — leaving the server to serve
// stale/closed jobs. In sparse areas every nearest result can be an orphan, so
// users see no jobs at all.
//
// A dataset implementing this reports, on the delta cadence, when a full rebuild
// is warranted so the orphans are cleared promptly rather than waiting up to 24h.
type DriftChecker interface {
	// CheckDrift inspects the source DB and the live index and returns whether a
	// full rebuild is warranted, plus a short human-readable reason for logs.
	CheckDrift(mysqlDB *sql.DB, idx *Index) (need bool, reason string, err error)

	// NoteReconciled records that the index is now in sync with the source —
	// called after a successful (re)build — so the next CheckDrift compares
	// against this fresh baseline rather than re-triggering on the same change.
	NoteReconciled(mysqlDB *sql.DB) error
}

// Item is the generic unit stored in the spatial index.
// For polygon datasets: MinLng != MaxLng (actual envelope), WKB non-nil.
// For point datasets: MinLng == MaxLng, MinLat == MaxLat (degenerate bbox), WKB nil.
type Item struct {
	ExtID  int64          // external ID from source table
	MinLng float64        // bounding box west (or point longitude)
	MaxLng float64        // bounding box east (or point longitude)
	MinLat float64        // bounding box south (or point latitude)
	MaxLat float64        // bounding box north (or point latitude)
	Area   float64        // polygon area in decimal degrees²; 0 for point datasets
	WKB    []byte         // polygon WKB; nil for point datasets
	Extra  map[string]any // dataset-specific extra fields, stored as JSON
}
