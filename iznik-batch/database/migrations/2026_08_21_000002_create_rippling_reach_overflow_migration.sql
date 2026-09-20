-- Production SQL: rippling_reach_overflow. APPLIED BY HAND 2026-08-21.
--
-- WHY A SEPARATE TABLE RATHER THAN AN INDEX ON rippling_reach. The rings are
-- JSON in rippling_reach.overflow_bounds and the read surfaces ask a
-- JSON_EXTRACT bbox question no index can serve. ORing it into the spatial
-- containment predicate removed the SPATIAL index:
--
--   before:  key=rippling_reach_polygon   rows=1
--   after:   key=NULL                     rows=62,534   (full scan of ~17GB)
--
-- That took the site down on 2026-08-21. The obvious repair - a generated column
-- plus an index on rippling_reach - was worse: the ALTER sat 36 minutes at
-- `checking permissions` under TOI without starting, holding the cluster's total
-- order until 3,400+ write sets had queued behind it on the write node, and it
-- blocks the node under RSU as well.
--
-- CREATE TABLE has nothing to build: no scan of the hot table, no lock on it.
-- Safe under TOI, returns immediately.
CREATE TABLE IF NOT EXISTS rippling_reach_overflow (
    msgid      BIGINT UNSIGNED NOT NULL,
    bbox       POLYGON NOT NULL SRID 3857,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (msgid),
    SPATIAL KEY rippling_reach_overflow_bbox (bbox),
    KEY rippling_reach_overflow_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- POPULATE IT with the chunked console command, which walks msgid ranges:
--
--   ripple:backfill-overflow-index --chunk=500 --sleep=200
--
-- Do NOT use a single INSERT...SELECT over rippling_reach: that is one write set
-- spanning a 17GB scan, the shape that has caused a Galera lock storm here.
-- The command is idempotent and resumable, so it is safe to re-run or interrupt.
--
-- VERIFY - a check to RUN, not a result to assume. Before the backfill the table
-- is empty and any plan is meaningless; after it, this is what was measured on
-- db2 on 2026-08-21 (4,257 rows):
--
--   EXPLAIN SELECT msgid FROM rippling_reach_overflow
--    WHERE ST_Contains(bbox, ST_SRID(POINT(-2.9, 53.7), 3857));
--
--   got: key=rippling_reach_overflow_bbox, 189 candidates, 8ms
--   (the equivalent JSON scan on rippling_reach: 49,434ms)
--
-- If key comes back NULL the prefilter is not being used and the change has
-- bought nothing - say so rather than closing the ticket.
