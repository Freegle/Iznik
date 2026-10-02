-- Production idempotent SQL: rippling_reach_geom + hash pointer columns.
--
-- Shared, content-addressed reach geometry: each distinct blob stored once,
-- keyed by MD5 of its WKB; rippling_reach points at it via polygon_hash /
-- max_polygon_hash. NULL hash means "not deduped, read the blob on the row",
-- so deploying this ahead of code or backfill changes nothing.
--
-- NO refs counter: the messages FK cascade and four explicit delete sites
-- bypass any counter, so on Galera it only drifts. GC proves non-reference by
-- anti-join (ripple:gc-reach-geometry); the FK RESTRICT constraints make
-- deleting a still-referenced geometry physically fail.
--
-- OPERATOR NOTES, per this table's own migration history:
--  * Statement 1 (CREATE TABLE) is TOI-safe.
--  * Statement 2 (ADD COLUMN, both nullable BINARY(16)) is ALGORITHM=INSTANT.
--  * Statement 3 (ADD INDEX x2 + ADD FOREIGN KEY x2, ONE combined ALTER so
--    the node-by-node dance happens once) is INPLACE. On rippling_reach an
--    in-place index add has previously sat 36 minutes at "checking
--    permissions" under TOI with thousands of write sets queued: run it
--    node-by-node under SET SESSION wsrep_OSU_method='RSU', same as the
--    has_overflow index. It MUST run with SESSION foreign_key_checks = 0
--    (set below): MySQL 8 only supports INPLACE for an FK add when checks are
--    off - with them on it silently falls back to ALGORITHM=COPY, a full
--    rebuild of a ~50 GB table. Disabling checks is safe: both columns are
--    entirely NULL, and NULLs are exempt from FK validation anyway. The
--    explicit ALGORITHM=INPLACE makes anything copy-shaped refuse rather
--    than rebuild. The guards below rebuild the DDL from whichever halves
--    are missing, so a partial earlier attempt cannot make it fail on a
--    duplicate index name.

SET @has_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_geom');
SET @ddl := IF(@has_table = 0,
    'CREATE TABLE rippling_reach_geom (
        hash BINARY(16) NOT NULL PRIMARY KEY,
        geom GEOMETRY NOT NULL SRID 3857,
        createdat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        SPATIAL INDEX rippling_reach_geom_geom (geom)
    ) ENGINE=InnoDB',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND column_name = 'polygon_hash');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE rippling_reach
        ADD COLUMN polygon_hash BINARY(16) NULL AFTER polygon,
        ADD COLUMN max_polygon_hash BINARY(16) NULL AFTER max_polygon,
        ALGORITHM=INSTANT',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- RSU node-by-node from here down: ONE combined ALTER (see operator notes above).
SET @old_fk_checks := @@SESSION.foreign_key_checks;
SET SESSION foreign_key_checks = 0;
SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND index_name = 'rippling_reach_polygon_hash');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
      AND constraint_name = 'rippling_reach_polygon_hash_foreign');
SET @ddl := IF(@has_idx > 0 AND @has_fk > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE rippling_reach ',
        IF(@has_idx = 0,
           'ADD INDEX rippling_reach_polygon_hash (polygon_hash),
            ADD INDEX rippling_reach_max_polygon_hash (max_polygon_hash), ',
           ''),
        IF(@has_fk = 0,
           'ADD CONSTRAINT rippling_reach_polygon_hash_foreign
                FOREIGN KEY (polygon_hash) REFERENCES rippling_reach_geom (hash)
                ON DELETE RESTRICT,
            ADD CONSTRAINT rippling_reach_max_polygon_hash_foreign
                FOREIGN KEY (max_polygon_hash) REFERENCES rippling_reach_geom (hash)
                ON DELETE RESTRICT, ',
           ''),
        'ALGORITHM=INPLACE'));
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET SESSION foreign_key_checks = @old_fk_checks;
