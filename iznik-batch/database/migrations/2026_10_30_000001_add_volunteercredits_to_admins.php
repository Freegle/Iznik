<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds admins.volunteercredits - whether the email ends by naming the community's volunteers
 * ("Your local volunteers are ..."). On by default; off for a message sent on behalf of someone
 * else, such as a council partner, where naming the volunteers would suggest they wrote it.
 *
 * TINYINT NOT NULL DEFAULT 1 added with ALGORITHM=INSTANT: a metadata-only change, no table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admins', 'volunteercredits')) {
            DB::statement('ALTER TABLE admins ADD COLUMN volunteercredits TINYINT(1) NOT NULL DEFAULT 1, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'volunteercredits')) {
            DB::statement('ALTER TABLE admins DROP COLUMN volunteercredits');
        }
    }
};
