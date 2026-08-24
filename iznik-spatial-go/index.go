package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"time"

	"github.com/peterstace/simplefeatures/geom"
	_ "modernc.org/sqlite"
)

// Index wraps a SQLite database with an R-tree spatial index.
// The schema is generic: polygon datasets store WKB; point datasets use degenerate bboxes.
type Index struct {
	db *sql.DB
}

// CreateIndex creates a new SQLite database at path with the required schema.
// Pass ":memory:" for an ephemeral in-memory index (useful in tests).
func CreateIndex(path string) (*Index, error) {
	if path != ":memory:" {
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
			return nil, fmt.Errorf("create data dir %q: %w", filepath.Dir(path), err)
		}
	}
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, fmt.Errorf("open sqlite %q: %w", path, err)
	}
	// Build on a single connection so the loader and the pre-rename WAL
	// checkpoint share one connection — the checkpoint must flush every page into
	// the main .db file, because rebuild renames only the .db (not the -wal/-shm).
	db.SetMaxOpenConns(1)
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
	var n int
	if err := db.QueryRow(`SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='items'`).Scan(&n); err != nil || n == 0 {
		db.Close()
		return nil, fmt.Errorf("index at %q has no items table", path)
	}
	setReadConcurrency(db)
	return &Index{db: db}, nil
}

// Close releases the SQLite connection.
func (idx *Index) Close() error {
	return idx.db.Close()
}

// Checkpoint flushes the WAL fully into the main .db file and truncates it, so
// the .db is self-contained. rebuild() renames only the .db file, so without
// this a large index keeps its newest pages (including the schema) in the
// orphaned -wal and reopens as "no items table".
func (idx *Index) Checkpoint() error {
	_, err := idx.db.Exec(`PRAGMA wal_checkpoint(TRUNCATE)`)
	return err
}

func initSchema(db *sql.DB) error {
	stmts := []string{
		`CREATE TABLE IF NOT EXISTS items (
			id    INTEGER PRIMARY KEY AUTOINCREMENT,
			extid INTEGER NOT NULL UNIQUE,
			area  REAL NOT NULL DEFAULT 0,
			wkb   BLOB,
			extra TEXT
		)`,
		`CREATE VIRTUAL TABLE IF NOT EXISTS items_rtree USING rtree(
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

// InsertItems bulk-inserts items into the index in a single transaction.
// For best R-tree node quality, sort rows by spatial position before calling.
// progress is called after each insertion with (done, total); pass nil to skip.
func InsertItems(idx *Index, items []Item, progress func(done, total int)) error {
	tx, err := idx.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	itemStmt, err := tx.Prepare(`INSERT OR REPLACE INTO items(extid, area, wkb, extra) VALUES (?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer itemStmt.Close()

	rtreeStmt, err := tx.Prepare(`INSERT OR REPLACE INTO items_rtree(id, min_lng, max_lng, min_lat, max_lat) VALUES (?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer rtreeStmt.Close()

	total := len(items)
	for i, item := range items {
		var extraJSON []byte
		if item.Extra != nil {
			extraJSON, err = json.Marshal(item.Extra)
			if err != nil {
				return fmt.Errorf("marshal extra for extid %d: %w", item.ExtID, err)
			}
		}
		res, err := itemStmt.Exec(item.ExtID, item.Area, item.WKB, extraJSON)
		if err != nil {
			return fmt.Errorf("insert item extid=%d: %w", item.ExtID, err)
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := rtreeStmt.Exec(id, item.MinLng, item.MaxLng, item.MinLat, item.MaxLat); err != nil {
			return fmt.Errorf("insert rtree extid=%d: %w", item.ExtID, err)
		}
		if progress != nil {
			progress(i+1, total)
		}
	}

	return tx.Commit()
}

// DeleteByExtID removes an item from the index by its external ID.
// Returns nil if the ID is not present (idempotent).
func (idx *Index) DeleteByExtID(extID int64) error {
	var rowID int64
	err := idx.db.QueryRow(`SELECT id FROM items WHERE extid = ?`, extID).Scan(&rowID)
	if err == sql.ErrNoRows {
		return nil
	}
	if err != nil {
		return fmt.Errorf("lookup extid %d: %w", extID, err)
	}
	if _, err = idx.db.Exec(`DELETE FROM items_rtree WHERE id = ?`, rowID); err != nil {
		return fmt.Errorf("delete rtree row %d: %w", rowID, err)
	}
	if _, err = idx.db.Exec(`DELETE FROM items WHERE id = ?`, rowID); err != nil {
		return fmt.Errorf("delete item row %d: %w", rowID, err)
	}
	return nil
}

// SetMetaTime persists a named timestamp inside the index database itself, so
// it survives restarts alongside the data it describes (used for last_sync:
// an adopted on-disk index must resume deltas from where THEY left off, not
// from "now minus one interval"). Creates the meta table lazily because
// indexes built before this existed don't have it.
func (idx *Index) SetMetaTime(key string, t time.Time) error {
	if _, err := idx.db.Exec(`CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)`); err != nil {
		return fmt.Errorf("meta schema: %w", err)
	}
	_, err := idx.db.Exec(`INSERT OR REPLACE INTO meta(k, v) VALUES (?, ?)`, key, t.UTC().Format(time.RFC3339Nano))
	return err
}

// GetMetaTime reads a named timestamp persisted by SetMetaTime. Returns the
// zero time (no error) when the table or key is absent — callers treat that
// as "unknown" exactly as they treated the zero lastSync before.
func (idx *Index) GetMetaTime(key string) (time.Time, error) {
	var v string
	err := idx.db.QueryRow(`SELECT v FROM meta WHERE k = ?`, key).Scan(&v)
	if err != nil {
		// Missing table or missing row both mean "unknown".
		return time.Time{}, nil
	}
	t, perr := time.Parse(time.RFC3339Nano, v)
	if perr != nil {
		return time.Time{}, nil
	}
	return t, nil
}

// ExtIDs returns the set of all external IDs in the index. Used by datasets
// that reconcile against their source table's full id list (cheap: ids only).
func (idx *Index) ExtIDs() (map[int64]struct{}, error) {
	rows, err := idx.db.Query(`SELECT extid FROM items`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	ids := make(map[int64]struct{})
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids[id] = struct{}{}
	}
	return ids, rows.Err()
}

// CountRows returns the number of items in the index.
func (idx *Index) CountRows() (int64, error) {
	var n int64
	err := idx.db.QueryRow(`SELECT COUNT(*) FROM items`).Scan(&n)
	return n, err
}

// QueryBBox returns items whose bounding box overlaps [minLng,maxLng]×[minLat,maxLat].
// GetByExtID fetches one item by its external id, or nil when the index does
// not hold it. Keyed, so a caller that already knows WHICH item it is asking
// about does not walk the R-tree to find it - the overflow rings' mail-side
// question ("does THIS post's ring admit these members") knows the post.
func (idx *Index) GetByExtID(extID int64) (*Item, error) {
	var it Item
	var extraJSON []byte
	err := idx.db.QueryRow(`
		SELECT i.extid, i.area, i.wkb, i.extra,
		       r.min_lng, r.max_lng, r.min_lat, r.max_lat
		FROM   items i
		JOIN   items_rtree r ON r.id = i.id
		WHERE  i.extid = ?
	`, extID).Scan(&it.ExtID, &it.Area, &it.WKB, &extraJSON,
		&it.MinLng, &it.MaxLng, &it.MinLat, &it.MaxLat)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if extraJSON != nil {
		if err := json.Unmarshal(extraJSON, &it.Extra); err != nil {
			return nil, fmt.Errorf("unmarshal extra for extid %d: %w", it.ExtID, err)
		}
	}
	return &it, nil
}

func QueryBBox(idx *Index, minLng, maxLng, minLat, maxLat float64) ([]Item, error) {
	rows, err := idx.db.Query(`
		SELECT i.extid, i.area, i.wkb, i.extra,
		       r.min_lng, r.max_lng, r.min_lat, r.max_lat
		FROM   items_rtree r
		JOIN   items i ON r.id = i.id
		WHERE  r.max_lng >= ? AND r.min_lng <= ?
		  AND  r.max_lat >= ? AND r.min_lat <= ?
	`, minLng, maxLng, minLat, maxLat)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var result []Item
	for rows.Next() {
		var it Item
		var extraJSON []byte
		if err := rows.Scan(&it.ExtID, &it.Area, &it.WKB, &extraJSON,
			&it.MinLng, &it.MaxLng, &it.MinLat, &it.MaxLat); err != nil {
			return nil, err
		}
		if extraJSON != nil {
			if err := json.Unmarshal(extraJSON, &it.Extra); err != nil {
				return nil, fmt.Errorf("unmarshal extra for extid %d: %w", it.ExtID, err)
			}
		}
		result = append(result, it)
	}
	return result, rows.Err()
}

// QueryWithinFull returns all items (with Extra/coordinates) whose geometry
// intersects polygon. For point datasets (WKB nil) the point is tested for
// containment. Stops and returns ErrTooManyResults if over maxWithinResults.
func (idx *Index) QueryWithinFull(polygon geom.Geometry) ([]Item, error) {
	env := polygon.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok {
		return nil, fmt.Errorf("polygon has no envelope")
	}

	candidates, err := QueryBBox(idx, min.X, max.X, min.Y, max.Y)
	if err != nil {
		return nil, err
	}

	var items []Item
	for _, c := range candidates {
		var g geom.Geometry
		if c.WKB != nil {
			g, err = geom.UnmarshalWKB(c.WKB, geom.NoValidate{})
			if err != nil {
				continue
			}
		} else {
			wkt := fmt.Sprintf("POINT(%.10f %.10f)", c.MinLng, c.MinLat)
			g, err = geom.UnmarshalWKT(wkt, geom.NoValidate{})
			if err != nil {
				continue
			}
		}
		if geom.Intersects(g, polygon) {
			items = append(items, c)
			if len(items) > maxWithinResults {
				return nil, ErrTooManyResults
			}
		}
	}
	return items, nil
}

// QueryWithin returns the external IDs of all items whose geometry intersects polygon.
// For point datasets (WKB nil) the point itself is tested for containment.
// Stops and returns ErrTooManyResults if more than maxWithinResults items are found.
func (idx *Index) QueryWithin(polygon geom.Geometry) ([]int64, error) {
	env := polygon.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok {
		return nil, fmt.Errorf("polygon has no envelope")
	}

	candidates, err := QueryBBox(idx, min.X, max.X, min.Y, max.Y)
	if err != nil {
		return nil, err
	}

	var ids []int64
	for _, c := range candidates {
		var g geom.Geometry
		if c.WKB != nil {
			g, err = geom.UnmarshalWKB(c.WKB, geom.NoValidate{})
			if err != nil {
				continue
			}
		} else {
			wkt := fmt.Sprintf("POINT(%.10f %.10f)", c.MinLng, c.MinLat)
			g, err = geom.UnmarshalWKT(wkt, geom.NoValidate{})
			if err != nil {
				continue
			}
		}
		if geom.Intersects(g, polygon) {
			ids = append(ids, c.ExtID)
			if len(ids) > maxWithinResults {
				return nil, ErrTooManyResults
			}
		}
	}
	return ids, nil
}
