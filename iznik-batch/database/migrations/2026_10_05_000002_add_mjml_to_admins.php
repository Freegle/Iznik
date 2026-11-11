<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds admins.mjml - an optional MJML version of an ADMIN body. When set, it is sanitised and
 * compiled into the HTML part of the email in place of the plain text; the text column stays
 * mandatory and is still the plain-text part.
 *
 * Nullable MEDIUMTEXT added with ALGORITHM=INSTANT: a metadata-only change, no table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admins', 'mjml')) {
            DB::statement('ALTER TABLE admins ADD COLUMN mjml MEDIUMTEXT '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'mjml')) {
            DB::statement('ALTER TABLE admins DROP COLUMN mjml');
        }
    }
};
