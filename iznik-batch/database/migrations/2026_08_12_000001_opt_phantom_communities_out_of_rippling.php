<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches rippling off, both directions, for the phantom and moderator-training
 * communities: `{"rippling": {"out": 0, "in": 0}}` in groups.settings.
 *
 * These communities carry practice posts, not real ones. Before this the only exclusion
 * rippling knew about was `nameshort NOT LIKE '%playground%'` on the ripple-IN target
 * list, and nothing gated ripple-OUT at all - so a FreeglePlayground practice post, which
 * is placed at a real Edinburgh postcode, would crosspost into the live Lothians
 * communities and surface in real members' nearby feeds. See
 * App\Services\Ripple\GroupRippleOptOut.
 *
 * Matched by name so it is portable across prod, dev and the test databases (group ids
 * differ), plus Outer Hebrides Freegle by nameshort - it is a phantom community whose area
 * is the real Outer Hebrides, so it does not follow the naming convention.
 *
 * Data-only and idempotent: re-running writes the same value. JSON_SET merges into whatever
 * else is in settings rather than replacing the blob, and the CASE guards the handful of
 * rows whose settings is empty or not valid JSON (JSON_SET would error on those).
 */
return new class extends Migration
{
    /** Communities that must never ripple, matched by name rather than id. */
    private const WHERE = "(nameshort LIKE '%playground%'
                            OR nameshort LIKE '%fresher%'
                            OR nameshort = 'OuterHebridesFreegle')";

    public function up(): void
    {
        if (!Schema::hasTable('groups') || !Schema::hasColumn('groups', 'settings')) {
            return;
        }

        DB::statement(
            "UPDATE `groups`
                SET settings = JSON_SET(
                        CASE WHEN settings IS NOT NULL AND JSON_VALID(settings)
                             THEN settings ELSE '{}' END,
                        '$.rippling', CAST('{\"out\": 0, \"in\": 0}' AS JSON))
              WHERE " . self::WHERE
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('groups') || !Schema::hasColumn('groups', 'settings')) {
            return;
        }

        DB::statement(
            "UPDATE `groups`
                SET settings = JSON_REMOVE(settings, '$.rippling')
              WHERE settings IS NOT NULL AND JSON_VALID(settings)
                AND JSON_EXTRACT(settings, '$.rippling') IS NOT NULL
                AND " . self::WHERE
        );
    }
};
