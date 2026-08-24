-- Production idempotent SQL: rippling_reach.overflow_cells.
--
-- The overflow rings in compact cell-set form (plans/2026-08-24-rippling-
-- reach-raster-storage.md). The rings are the table's worst case: measured
-- 2026-08-23, overflow_bounds was HALF the table at 860KB a row, its rings
-- average 37,000 vertices, and every vertex already sits on the 0.0003-degree
-- lattice a cell set uses (they are traced from a routing-server raster).
--
-- Same JSON nesting and paths as overflow_bounds, each ring's WKT replaced by
-- base64 cell-set bytes, so iznik-spatial-go asks for a lane with the
-- identical JSON_EXTRACT it already uses. overflow_bounds STAYS and is still
-- the authority: the map overlay needs the vector, the lane-presence test
-- reads it, and has_overflow is GENERATED from it and indexed.
--
-- Nullable, and NULL is the normal state before the backfill: spatial-go
-- falls back to parsing a lane's WKT whenever that lane's cells are absent, so
-- a partly-converted table is a valid state rather than a migration window.
--
-- ADD COLUMN of a nullable JSON column is ALGORITHM=INSTANT on Percona 8.0;
-- pinned explicitly so anything that would need a copy refuses loudly instead
-- of running one under TOI. (has_overflow is GENERATED ... VIRTUAL, which is
-- metadata only, so it does not force a rebuild here.)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'overflow_cells');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN overflow_cells JSON NULL AFTER overflow_bounds,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
