<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename indexes so the migrations use the same names as production.
     *
     * These 17 indexes cover the same columns with the same uniqueness in both
     * places, but Laravel's generated names (foo_bar_index, foo_bar_unique) do
     * not match the shorter names live actually has. That is not cosmetic: a
     * hand-written `DROP INDEX` or `RENAME INDEX` in a runbook only works against
     * one of the two environments, and any tooling that compares schemas has to
     * special-case the difference.
     *
     * Only clean one-to-one matches are renamed. Cases where the same columns
     * carry two indexes on both sides (chat_messages.refchatid/refchatid_2,
     * jobs.job_reference/job_reference_2, locations_dodgy.locationid/locationid_2)
     * are left alone, because there is no unambiguous mapping - those duplicates
     * want removing rather than renaming. PRIMARY keys are excluded too, since a
     * primary key has no renameable name.
     */
    private const RENAMES = [
        ['background_tasks', 'background_tasks_task_type_index', 'idx_task_type'],
        ['batch_email_progress', 'batch_email_progress_job_type_unique', 'job_type'],
        ['chat_room_redirects', 'chat_room_redirects_new_id_index', 'idx_new_id'],
        ['cron_job_status', 'cron_job_status_command_unique', 'command'],
        ['email_tracking_clicks', 'email_tracking_clicks_clicked_at_index', 'clicked_at'],
        ['email_tracking_clicks', 'email_tracking_clicks_email_tracking_id_index', 'email_tracking_id'],
        ['email_tracking_images', 'email_tracking_images_email_tracking_id_index', 'email_tracking_id'],
        ['housekeeper_tasks', 'housekeeper_tasks_task_key_unique', 'task_key'],
        ['jobs', 'jobs_canonical_title_index', 'canonical_title'],
        ['messages_spatial', 'messages_spatial_modified_index', 'idx_messages_spatial_modified'],
        ['rippling_algorithm_metrics', 'ripple_algorithm_metrics_week_start_index', 'week_start'],
        ['rippling_hotspots', 'rippling_hotspots_area_type_area_id_index', 'rippling_hotspots_area'],
        ['rippling_hotspots', 'rippling_hotspots_metric_index', 'rippling_hotspots_metric'],
        ['rippling_hotspots', 'rippling_hotspots_period_start_index', 'rippling_hotspots_period'],
        // These three differ only in case. MySQL index names are case-insensitive
        // for lookup but the stored spelling is preserved, so the rename is still
        // worth doing and RENAME INDEX handles it.
        ['users_donations', 'grossamount', 'GrossAmount'],
        ['users_donations', 'payer', 'Payer'],
        ['users_donations', 'transactionid', 'TransactionID'],
    ];

    /**
     * The set of index names on a table, read with SHOW INDEX rather than
     * information_schema.statistics.
     *
     * information_schema.statistics is served from a cache governed by
     * information_schema_stats_expiry (86400s by default), so straight after a
     * rename it can still report the old name - which made an earlier version of
     * this migration believe an index was there and then fail the ALTER with
     * "Key ... doesn't exist". SHOW INDEX always reads current metadata.
     *
     * Comparison is done in PHP with strict string equality because MySQL matches
     * identifiers case-insensitively, and three of these renames differ only in
     * case ('payer' -> 'Payer').
     */
    private function indexNames(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return array_map(
            static fn ($row) => $row->Key_name,
            DB::select(sprintf('SHOW INDEX FROM `%s`', $table))
        );
    }

    private function rename(string $table, string $from, string $to): void
    {
        $names = $this->indexNames($table);

        if (in_array($to, $names, true)) {
            return;                                  // already named as production has it
        }
        if (!in_array($from, $names, true)) {
            return;                                  // nothing to rename
        }
        DB::statement(sprintf(
            'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
            $table,
            $from,
            $to
        ));
    }

    public function up(): void
    {
        foreach (self::RENAMES as [$table, $from, $to]) {
            $this->rename($table, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as [$table, $from, $to]) {
            $this->rename($table, $to, $from);
        }
    }
};
