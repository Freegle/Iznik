<?php

namespace App\Console\Commands\User;

use App\Services\ListModsService;
use Illuminate\Console\Command;

/**
 * Port of the V1 script scripts/fix/fix_listmods.php: CSV of every group
 * moderator/owner with their emails, name, last access and mod groups.
 */
class ListModsCommand extends Command
{
    protected $signature = 'user:list-mods
                            {--output= : Write CSV to this file path instead of stdout}';

    protected $description = 'List all group moderators/owners as CSV (port of V1 fix_listmods)';

    public function handle(ListModsService $service): int
    {
        $path = $this->option('output');
        $handle = fopen($path ?: 'php://stdout', 'w');

        if ($handle === FALSE) {
            $this->error("Could not open {$path} for writing");

            return Command::FAILURE;
        }

        foreach ($service->rows() as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return Command::SUCCESS;
    }
}
