<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach — the current "rippled out" reach of each active post.
 *
 * One row per message that is currently rippling. The set is a subset of
 * messages_spatial (the browsable, approved, not-taken OFFER/WANTED set), so the
 * table stays small relative to messages / chat_messages.
 *
 * The reach engine (`ripple:expand`, running in the existing iznik-batch
 * container — NOT a new container) computes the per-tick reach schedule from the
 * routing server's GET /v1/ripple-schedule and advances `tick` over wall-clock
 * time according to the hazard schedule. Browse, the unified digest and
 * reply-eligibility all read this row with
 *   ST_Contains(polygon, ST_SRID(POINT(lng, lat), 3857)).
 *
 * `polygon` is SRID 3857 to match messages_spatial.point (Freegle stores lng/lat
 * degrees under an SRID-3857 label; see iznik-server-go utils.SRID), so the
 * containment test works without reprojection.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rippling_reach')) {
            return;
        }

        Schema::create('rippling_reach', function (Blueprint $t) {
            // One reach per message (from the item's physical origin).
            $t->unsignedBigInteger('msgid')->primary();

            // Origin (degrees), copied from messages_spatial.point for the routing call.
            $t->double('lat');
            $t->double('lng');

            // The post's arrival time (copied from messages_spatial.arrival), used
            // to compute elapsed hours → current hazard tick. NOT created_at, which
            // is when this reach row was created (differs for back-filled posts).
            $t->timestamp('arrival')->nullable();

            // Travel mode used to compute the reach.
            $t->string('mode', 8)->default('drive');

            // Schedule cursor: which hazard tick the reach is currently at (1-based
            // once started). 0 only transiently before first compute.
            $t->unsignedSmallInteger('tick')->default(0);
            $t->unsignedSmallInteger('total_ticks')->default(0);

            // Provenance / monitoring.
            $t->unsignedInteger('total_freeglers')->default(0);
            $t->float('max_drive_min')->nullable();

            // Cached per-tick schedule from the routing server:
            // [{ "tick": n, "drive_min": f, "cumulative_users": n, "wkt": "POLYGON((...))" }, ...]
            // Cached so each expansion advances without re-running Dijkstra.
            $t->longText('schedule')->nullable();

            // When the next tick is due (NULL once done/stopped).
            $t->timestamp('next_expansion_at')->nullable();

            $t->enum('status', ['expanding', 'stopped', 'done'])->default('expanding');

            $t->timestamps();

            $t->index('next_expansion_at');
            $t->index('status');
        });

        // Geometry column + spatial index via raw SQL: NOT NULL + SRID 3857 to match
        // messages_spatial.point. Spatial indexes require NOT NULL geometry; rows are
        // only ever inserted once a reach polygon exists, so NOT NULL is satisfiable.
        DB::statement(
            'ALTER TABLE rippling_reach
                ADD COLUMN polygon GEOMETRY NOT NULL SRID 3857 AFTER lng,
                ADD SPATIAL INDEX rippling_reach_polygon (polygon)'
        );

        // Clean up reach when the underlying message is hard-deleted.
        DB::statement(
            'ALTER TABLE rippling_reach
                ADD CONSTRAINT rippling_reach_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach');
    }
};
