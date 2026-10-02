<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_geom - shared, content-addressed reach geometry.
 *
 * rippling_reach's big geometry blobs are exact duplicates across posts:
 * polygon = f(origin, tick) byte-for-byte, so posts from the same origin at the
 * same tick store identical ~370 KB blobs (measured 42.3% redundant; the table
 * is the estate's binding disk constraint at ~48 GB). This table stores each
 * distinct geometry once, keyed by the MD5 of its WKB, and rippling_reach rows
 * point at it via polygon_hash / max_polygon_hash.
 *
 * Keyed on the content hash, not a surrogate id: the write is then one
 * idempotent statement with no read-back race (ExpandService backfill shards
 * and the maxreach cron can hit the same geometry concurrently), and the hash
 * self-verifies - a checker can recompute it from the stored bytes.
 *
 * NO refs counter, deliberately. The messages FK cascade deletes reach rows
 * inside InnoDB with no application hook, and four explicit delete sites
 * (three PHP, one Go) would each have to decrement it; on a Galera cluster a
 * counter like that only ever drifts. Instead upserts are no-op ODKU
 * (idempotent, nothing to get out of step) and garbage collection PROVES a
 * geometry is unreferenced by anti-join, twice, with an age grace
 * (ripple:gc-reach-geometry). The FK RESTRICT constraints below are the
 * backstop that makes deleting a still-referenced geometry physically fail.
 *
 * The hash columns are NULLABLE: NULL means "not deduped, read the blob on the
 * row", so a deploy ahead of the backfill changes nothing, and the clip paths
 * (ST_Difference in ExpandService::reapplyClips and the Go
 * ClipReachForRejectedGroup) can atomically detach a row from a shared
 * geometry by nulling the hash in the same statement that mutates the blob -
 * a shared geom row is never mutated in place, because up to 261 posts have
 * been observed sharing one geometry.
 *
 * SRID 3857 matches the source columns (lng/lat degrees under an SRID-3857
 * label - see ExpandService); the bytes are copied verbatim, never
 * reprojected, or the hashes would not match.
 *
 * createdat exists for the GC age grace and means "last upserted", not "first
 * created" - every writer's upsert refreshes it - so a geometry touched
 * within the grace is never a deletion candidate and the upsert-then-reference
 * write window can never race the sweep, even for a shared geometry
 * resurrected after its references dropped to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rippling_reach_geom')) {
            DB::statement(
                'CREATE TABLE rippling_reach_geom (
                    hash BINARY(16) NOT NULL PRIMARY KEY,
                    geom GEOMETRY NOT NULL SRID 3857,
                    createdat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    SPATIAL INDEX rippling_reach_geom_geom (geom)
                ) ENGINE=InnoDB'
            );
        }

        if (Schema::hasTable('rippling_reach')) {
            // Each piece guarded independently: these ALTERs are not atomic as a
            // group, so a failure partway must leave a re-run able to add exactly
            // the missing pieces rather than being skipped forever because the
            // columns happen to exist.
            if (!Schema::hasColumn('rippling_reach', 'polygon_hash')) {
                DB::statement(
                    'ALTER TABLE rippling_reach
                        ADD COLUMN polygon_hash BINARY(16) NULL AFTER polygon,
                        ADD COLUMN max_polygon_hash BINARY(16) NULL AFTER max_polygon'
                );
            }

            $hasIndex = !empty(DB::select(
                "SHOW INDEX FROM rippling_reach WHERE Key_name = 'rippling_reach_polygon_hash'"
            ));
            $hasFk = !empty(DB::select(
                "SELECT 1 FROM information_schema.table_constraints
                  WHERE table_schema = DATABASE() AND table_name = 'rippling_reach'
                    AND constraint_name = 'rippling_reach_polygon_hash_foreign'"
            ));

            $pieces = [];
            if (!$hasIndex) {
                $pieces[] = 'ADD INDEX rippling_reach_polygon_hash (polygon_hash),
                             ADD INDEX rippling_reach_max_polygon_hash (max_polygon_hash)';
            }
            if (!$hasFk) {
                $pieces[] = 'ADD CONSTRAINT rippling_reach_polygon_hash_foreign
                                 FOREIGN KEY (polygon_hash) REFERENCES rippling_reach_geom (hash)
                                 ON DELETE RESTRICT,
                             ADD CONSTRAINT rippling_reach_max_polygon_hash_foreign
                                 FOREIGN KEY (max_polygon_hash) REFERENCES rippling_reach_geom (hash)
                                 ON DELETE RESTRICT';
            }
            if ($pieces !== []) {
                DB::statement('ALTER TABLE rippling_reach ' . implode(', ', $pieces));
            }
        }
    }

    public function down(): void
    {
        // Refuse rather than destroy: after ripple:drain-deduped-blobs has run,
        // rippling_reach_geom holds the ONLY copy of the real geometry for every
        // drained row (the blob is a sentinel POINT). Rolling back is only safe
        // where the feature was never exercised.
        if (Schema::hasTable('rippling_reach_geom') && DB::table('rippling_reach_geom')->exists()) {
            throw new \RuntimeException(
                'Refusing to roll back: rippling_reach_geom is non-empty and may hold the sole '
                . 'surviving copy of drained reach geometry (see ripple:drain-deduped-blobs). '
                . 'Restore the blobs or empty the table deliberately first.'
            );
        }

        if (Schema::hasTable('rippling_reach') && Schema::hasColumn('rippling_reach', 'polygon_hash')) {
            DB::statement(
                'ALTER TABLE rippling_reach
                    DROP FOREIGN KEY rippling_reach_polygon_hash_foreign,
                    DROP FOREIGN KEY rippling_reach_max_polygon_hash_foreign'
            );
            DB::statement(
                'ALTER TABLE rippling_reach
                    DROP INDEX rippling_reach_polygon_hash,
                    DROP INDEX rippling_reach_max_polygon_hash,
                    DROP COLUMN polygon_hash,
                    DROP COLUMN max_polygon_hash'
            );
        }

        Schema::dropIfExists('rippling_reach_geom');
    }
};
