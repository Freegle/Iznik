<?php

namespace App\Console\Commands\Group;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteGroupCommand extends Command
{
    protected $signature = 'group:delete
                            {--group= : Short name of the group to delete (required)}
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be deleted without making changes}';

    protected $description = 'Permanently delete a Freegle group and all its associated data';

    public function handle(): int
    {
        $groupName = $this->option('group');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        if (!$groupName) {
            $this->error('--group is required');
            return self::FAILURE;
        }

        $group = DB::table('groups')->where('nameshort', $groupName)->first();
        if (!$group) {
            $this->error("Group not found: {$groupName}");
            return self::FAILURE;
        }

        $gid = $group->id;
        $dbName = DB::getDatabaseName();

        $refs = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_NAME = 'groups' AND TABLE_NAME != 'groups' AND TABLE_SCHEMA = ?",
            [$dbName]
        );

        $this->line("Group: {$groupName} (#{$gid})");
        $hasData = false;
        foreach ($refs as $ref) {
            $count = DB::table($ref->TABLE_NAME)->where($ref->COLUMN_NAME, $gid)->count();
            if ($count > 0) {
                $this->line("  {$ref->TABLE_NAME}.{$ref->COLUMN_NAME}: {$count} rows");
                $hasData = true;
            }
        }

        if (!$hasData) {
            $this->line('  (no dependent rows)');
        }

        if ($dryRun) {
            $this->info('[DRY RUN] No changes made.');
            return self::SUCCESS;
        }

        if (!$force && !$this->confirm("Type the group name '{$groupName}' to confirm deletion", false)) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        foreach ($refs as $ref) {
            $table = $ref->TABLE_NAME;
            $col = $ref->COLUMN_NAME;
            do {
                $deleted = DB::table($table)->where($col, $gid)->limit(100)->delete();
            } while ($deleted > 0);
        }

        DB::table('groups')->where('id', $gid)->delete();

        $this->info("Deleted group {$groupName} (#{$gid})");
        return self::SUCCESS;
    }
}
