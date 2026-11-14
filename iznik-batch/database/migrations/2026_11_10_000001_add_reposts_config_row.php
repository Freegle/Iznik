<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repost delays and counts are one system-wide setting, not one per community: a row in `config`
 * with key `reposts` (offer and wanted are days between reposts, max is how many reposts, chaseups
 * is days between chase-up emails). Inserted only when absent, so a value set by hand is kept.
 *
 * A community's own settings.reposts is not touched: it is ignored, and stays in place so the
 * change can be reverted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('config')->where('key', 'reposts')->exists();

        if (!$exists) {
            DB::table('config')->insert([
                'key' => 'reposts',
                'value' => json_encode(['offer' => 3, 'wanted' => 7, 'max' => 3, 'chaseups' => 5]),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('config')->where('key', 'reposts')->delete();
    }
};
