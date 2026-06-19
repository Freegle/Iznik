<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the pre-existing rippling curve-tuning metrics table under the rippling_ prefix too,
 * so every rippling-out table shares the convention. Idempotent and reference-free (no code
 * reads ripple_algorithm_metrics yet). On a fresh DB the create migration makes the old name
 * and this renames it; on a deployed DB this renames the existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::select("SHOW TABLES LIKE 'ripple_algorithm_metrics'");
        $new = DB::select("SHOW TABLES LIKE 'rippling_algorithm_metrics'");
        if (!empty($old) && empty($new)) {
            DB::statement('RENAME TABLE ripple_algorithm_metrics TO rippling_algorithm_metrics');
        }
    }

    public function down(): void
    {
        $old = DB::select("SHOW TABLES LIKE 'rippling_algorithm_metrics'");
        $new = DB::select("SHOW TABLES LIKE 'ripple_algorithm_metrics'");
        if (!empty($old) && empty($new)) {
            DB::statement('RENAME TABLE rippling_algorithm_metrics TO ripple_algorithm_metrics');
        }
    }
};
