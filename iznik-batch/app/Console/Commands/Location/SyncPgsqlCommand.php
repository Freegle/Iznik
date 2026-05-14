<?php

namespace App\Console\Commands\Location;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\PostcodeRemapService;
use Illuminate\Console\Command;

/**
 * Nightly full sync of locations from MySQL to PostgreSQL/PostGIS, then
 * remaps postcodes to their nearest enclosing area using KNN queries.
 *
 * Replaces V1 cron/locations_pgsql (locations_pgsql_update.php + locations_pgsql_map.php).
 */
class SyncPgsqlCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'locations:sync-pgsql';

    protected $description = 'Sync locations to PostgreSQL and remap postcodes to areas';

    public function handle(PostcodeRemapService $service): int
    {
        $remapped = $service->remapPostcodes();

        $this->info("Remapped {$remapped} postcodes.");

        return self::SUCCESS;
    }
}
