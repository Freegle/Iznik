package rippling

import (
	"fmt"
	"sync"

	"gorm.io/gorm"
)

// Content-addressed sharing of rippling_reach's big geometry blobs
// (plans/2026-08-23-rippling-reach-polygon-dedup.md): each distinct geometry is
// stored once in rippling_reach_geom keyed by MD5 of its WKB, and reach rows
// point at it via polygon_hash / max_polygon_hash. The Laravel side
// (App\Services\Ripple\GeomShareService) is the same contract in PHP; the SQL
// fragments here must stay byte-compatible with it so writer, reader and
// checker can never disagree about canonicalisation.
//
// Invariants (the PHP twin documents the full set):
//   - hash NULL means "read the blob on the row"; readers are always
//     LEFT JOIN + COALESCE(shared, blob), so they are correct before the
//     backfill, during it, and after the drain replaces backfilled polygon
//     blobs with a sentinel POINT.
//   - anything that mutates a blob in place (ClipReachForRejectedGroup) NULLs
//     the hash in that same statement - a shared geom row is never mutated,
//     because up to 261 posts have been observed pointing at one - then
//     re-points with GeomUpsertFromRow + GeomRehashFromRow.
//   - there is NO reference counter: upserts are idempotent no-op ODKU and
//     garbage collection (ripple:gc-reach-geometry) proves non-reference by
//     anti-join, so nothing can drift on Galera.

var geomShareOnce sync.Once
var geomShareExists bool

// GeomShareReady reports whether the rippling_reach_geom migration has run.
// Checked once per process, like ReachBoundsReady: deploying this code before
// the migration keeps every query on the local blob columns; restart the Go
// API after the schema migration to pick the shared table up.
func GeomShareReady(db *gorm.DB) bool {
	if db == nil {
		return false
	}
	geomShareOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon_hash'").
			Count(&n)
		geomShareExists = n > 0
	})
	return geomShareExists
}

// assertShareable keeps column names honest before they are interpolated into
// SQL: a typo must fail loudly here, not parse as something else there.
func assertShareable(col string) {
	if col != "polygon" && col != "max_polygon" {
		panic(fmt.Sprintf("not a shareable geometry column: %s", col))
	}
}

// GeomJoin returns the reader join fragment for one hash column (leading
// space), or "" when sharing is not migrated / not wanted. rowAlias must
// already be bound in the enclosing query.
func GeomJoin(share bool, rowAlias, col, geomAlias string) string {
	assertShareable(col)
	if !share {
		return ""
	}
	return fmt.Sprintf(" LEFT JOIN rippling_reach_geom %s ON %s.hash = %s.%s_hash",
		geomAlias, geomAlias, rowAlias, col)
}

// GeomExpr returns the geometry a reader should test: the shared row when the
// hash points at one, else the blob on the row.
func GeomExpr(share bool, rowAlias, col, geomAlias string) string {
	assertShareable(col)
	if !share {
		return rowAlias + "." + col
	}
	return fmt.Sprintf("COALESCE(%s.geom, %s.%s)", geomAlias, rowAlias, col)
}

// GeomUpsertFromRow ensures the shared row for a blob already stored on a
// reach row exists, hashing the stored bytes themselves. Idempotent ODKU, so
// concurrent writers cannot conflict; runs before GeomRehashFromRow because
// the FK on the hash columns insists the geom row exists first. Best-effort
// like the rest of the clip path: an error just leaves the hash NULL, which
// every reader treats as "use the blob".
//
// The duplicate arm refreshes createdat - the GC age clock, which must mean
// "last touched" so a resurrected shared geometry re-arms its grace period
// before anything references it (see the PHP twin for the full argument).
func GeomUpsertFromRow(db *gorm.DB, msgid uint64, col string) {
	assertShareable(col)
	// keep-raw: INSERT..SELECT of the row's own stored geometry with a dynamic
	// (allowlisted) column name - GORM cannot render this statement.
	db.Exec(fmt.Sprintf(
		`INSERT INTO rippling_reach_geom (hash, geom)
		 SELECT UNHEX(MD5(ST_AsBinary(%s))), %s
		   FROM rippling_reach
		  WHERE msgid = ? AND %s IS NOT NULL
		 ON DUPLICATE KEY UPDATE createdat = CURRENT_TIMESTAMP`, col, col, col), msgid)
}

// GeomRehashFromRow points a reach row's hash at its own stored blob.
// updated_at is held still: this changes no geometry, and the reach mailer
// and spatial-go's delta poll both key off updated_at.
func GeomRehashFromRow(db *gorm.DB, msgid uint64, col string) {
	assertShareable(col)
	// keep-raw: UPDATE with a dynamic (allowlisted) column name in SET and the
	// updated_at self-assignment - GORM cannot render this statement.
	db.Exec(fmt.Sprintf(
		`UPDATE rippling_reach
		    SET %s_hash = UNHEX(MD5(ST_AsBinary(%s))),
		        updated_at = updated_at
		  WHERE msgid = ? AND %s IS NOT NULL`, col, col, col), msgid)
}
