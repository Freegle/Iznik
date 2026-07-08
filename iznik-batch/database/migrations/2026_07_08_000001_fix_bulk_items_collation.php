<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert messages_bulk_items and messages_bulk_items_interest to the schema-wide
 * utf8mb4_unicode_ci collation.
 *
 * On production both tables were created with a bare CREATE TABLE that inherited the
 * MySQL 8 server default (utf8mb4_0900_ai_ci) instead of the iznik-wide
 * utf8mb4_unicode_ci. Stats generation joins `items i ON i.name = bi.name`, and
 * because items.name is utf8mb4_unicode_ci the mismatch throws SQLSTATE[HY000] 1267
 * "Illegal mix of collations". See StatsGenerationService::buildDailyContext.
 *
 * Idempotent: only converts when the table is not already utf8mb4_unicode_ci, so it
 * is a no-op on dev/CI databases (created via the connection's utf8mb4_unicode_ci
 * collation). CONVERT TO only rewrites character columns; the integer key columns
 * that back the foreign keys are untouched.
 *
 * PRODUCTION: do NOT run `artisan migrate` against the Galera cluster. Apply the
 * paired .sql file manually instead (both tables are tiny, so the rebuild is a
 * sub-second TOI operation). This .php migration exists so dev/CI stay in sync.
 */
return new class extends Migration
{
    private const TARGET = 'utf8mb4_unicode_ci';

    private array $tables = [
        'messages_bulk_items',
        'messages_bulk_items_interest',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if ($this->currentCollation($table) === self::TARGET) {
                continue;
            }

            DB::statement(
                "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE " . self::TARGET
            );
        }
    }

    public function down(): void
    {
        // No-op: reverting to the incorrect utf8mb4_0900_ai_ci collation would
        // re-introduce the bug, so this migration is intentionally irreversible.
    }

    private function currentCollation(string $table): ?string
    {
        $row = DB::selectOne(
            'SELECT TABLE_COLLATION AS c FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return $row->c ?? null;
    }
};
