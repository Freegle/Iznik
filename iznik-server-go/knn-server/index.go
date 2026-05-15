package main

import (
	"database/sql"
	"fmt"
	"runtime"

	_ "modernc.org/sqlite"
)

// Index wraps a SQLite database with an R-tree spatial index.
type Index struct {
	db *sql.DB
}

// LocationRow is a single indexed location entry.
type LocationRow struct {
	LocationID int64
	Name       string
	Type       string
	Area       float64
	WKB        []byte
	MinLng     float64
	MaxLng     float64
	MinLat     float64
	MaxLat     float64
}

// CreateIndex creates a new SQLite database at path with the required schema.
// Pass ":memory:" for an ephemeral in-memory index (useful for tests).
func CreateIndex(path string) (*Index, error) {
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, fmt.Errorf("open sqlite %q: %w", path, err)
	}
	if _, err := db.Exec(`PRAGMA journal_mode=WAL`); err != nil {
		db.Close()
		return nil, fmt.Errorf("set WAL mode: %w", err)
	}
	if err := initSchema(db); err != nil {
		db.Close()
		return nil, err
	}
	return &Index{db: db}, nil
}

// setReadConcurrency configures the connection pool for concurrent reads.
// WAL mode allows multiple simultaneous readers via separate connections.
func setReadConcurrency(db *sql.DB) {
	n := runtime.NumCPU()
	if n < 2 {
		n = 2
	}
	db.SetMaxOpenConns(n)
}

// OpenIndex opens an existing SQLite database at path for querying.
func OpenIndex(path string) (*Index, error) {
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, fmt.Errorf("open sqlite %q: %w", path, err)
	}
	// Verify the schema exists.
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='locations'`).Scan(&n); err != nil || n == 0 {
		db.Close()
		return nil, fmt.Errorf("index at %q has no locations table", path)
	}
	setReadConcurrency(db)
	return &Index{db: db}, nil
}

// Close releases the SQLite connection.
func (idx *Index) Close() error {
	return idx.db.Close()
}

func initSchema(db *sql.DB) error {
	stmts := []string{
		`CREATE TABLE IF NOT EXISTS locations (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			locationid INTEGER NOT NULL UNIQUE,
			name       TEXT,
			type       TEXT,
			area       REAL NOT NULL,
			wkb        BLOB NOT NULL
		)`,
		`CREATE VIRTUAL TABLE IF NOT EXISTS locations_rtree USING rtree(
			id,
			min_lng, max_lng,
			min_lat, max_lat
		)`,
	}
	for _, stmt := range stmts {
		if _, err := db.Exec(stmt); err != nil {
			return fmt.Errorf("init schema: %w", err)
		}
	}
	return nil
}

// InsertLocations bulk-inserts location rows into the index in a single transaction.
// For best R-tree node quality, sort rows by spatial position before calling.
// progress is called after each insertion with (done, total); pass nil to skip.
func InsertLocations(idx *Index, rows []LocationRow, progress func(done, total int)) error {
	tx, err := idx.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	locStmt, err := tx.Prepare(`INSERT OR REPLACE INTO locations(locationid, name, type, area, wkb) VALUES (?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer locStmt.Close()

	rtreeStmt, err := tx.Prepare(`INSERT OR REPLACE INTO locations_rtree(id, min_lng, max_lng, min_lat, max_lat) VALUES (?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer rtreeStmt.Close()

	total := len(rows)
	for i, row := range rows {
		res, err := locStmt.Exec(row.LocationID, row.Name, row.Type, row.Area, row.WKB)
		if err != nil {
			return fmt.Errorf("insert location %d: %w", row.LocationID, err)
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := rtreeStmt.Exec(id, row.MinLng, row.MaxLng, row.MinLat, row.MaxLat); err != nil {
			return fmt.Errorf("insert rtree %d: %w", row.LocationID, err)
		}
		if progress != nil {
			progress(i+1, total)
		}
	}

	return tx.Commit()
}

// QueryBBox returns locations whose bounding box overlaps [minLng,maxLng]×[minLat,maxLat].
func QueryBBox(idx *Index, minLng, maxLng, minLat, maxLat float64) ([]LocationRow, error) {
	rows, err := idx.db.Query(`
		SELECT l.locationid, l.name, l.type, l.area, l.wkb
		FROM   locations_rtree r
		JOIN   locations l ON r.id = l.id
		WHERE  r.max_lng >= ? AND r.min_lng <= ?
		  AND  r.max_lat >= ? AND r.min_lat <= ?
	`, minLng, maxLng, minLat, maxLat)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []LocationRow
	for rows.Next() {
		var lr LocationRow
		if err := rows.Scan(&lr.LocationID, &lr.Name, &lr.Type, &lr.Area, &lr.WKB); err != nil {
			return nil, err
		}
		result = append(result, lr)
	}
	return result, rows.Err()
}
