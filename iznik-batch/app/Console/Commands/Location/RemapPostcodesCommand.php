<?php

namespace App\Console\Commands\Location;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\PostcodeRemapService;
use Illuminate\Console\Command;

/**
 * Nightly full remap of every Postcode location to its nearest enclosing
 * Freegle area, using the spatial server's KNN queries over the MySQL
 * locations_spatial index. No PostgreSQL — the spatial migration replaced the
 * previous PostGIS copy with the iznik-spatial-go service.
 *
 * Replaces V1 cron/locations_pgsql (locations_pgsql_update.php + locations_pgsql_map.php).
 * DoogalService relies on this nightly pass to map newly-imported postcodes onto
 * group areas (it deliberately does not remap inline).
 */
class RemapPostcodesCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'locations:remap-postcodes';

    protected $description = 'Remap all postcodes to their nearest Freegle area (via the spatial server)';

    public function handle(PostcodeRemapService $service): int
    {
        $remapped = $service->remapPostcodes();

        $this->info("Remapped {$remapped} postcodes.");

        return self::SUCCESS;
    }
}
