<?php

namespace App\Console\Commands\CommunityNews;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the UK gazetteer in database/data/uk-places.csv into `places`.
 *
 * Idempotent: rows are keyed on (name, lat, lng), so running it again updates
 * populations and adds anything new rather than duplicating. Safe to run on a
 * live system - nothing reads `places` except the Community News research
 * prompt, which simply gets a better list of what an area covers.
 */
class LoadPlacesCommand extends Command
{
    protected $signature = 'community-news:load-places
                            {--file= : CSV to load (defaults to database/data/uk-places.csv)}';

    protected $description = 'Load the UK places gazetteer used to say what a Community News area covers';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/uk-places.csv');
        if (!is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Cannot open {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('Empty file');

            return self::FAILURE;
        }

        $srid = (int) config('freegle.srid', 3857);
        $rows = [];
        $loaded = 0;

        while (($r = fgetcsv($handle)) !== false) {
            if (count($r) < 4 || trim((string) $r[0]) === '') {
                continue;
            }
            $rows[] = [
                'name' => mb_substr(trim($r[0]), 0, 255),
                'lat' => round((float) $r[1], 5),
                'lng' => round((float) $r[2], 5),
                'population' => max(0, (int) $r[3]),
            ];

            if (count($rows) >= 500) {
                $loaded += $this->flush($rows, $srid);
                $rows = [];
            }
        }
        fclose($handle);

        if ($rows) {
            $loaded += $this->flush($rows, $srid);
        }

        $this->info("Loaded {$loaded} places; table now holds " . DB::table('places')->count() . '.');

        return self::SUCCESS;
    }

    /**
     * @param array<int,array{name:string,lat:float,lng:float,population:int}> $rows
     */
    private function flush(array $rows, int $srid): int
    {
        $values = [];
        $bind = [];
        foreach ($rows as $r) {
            // POINT takes X then Y, so longitude first.
            $values[] = "(?, ?, ?, ?, ST_SRID(POINT(?, ?), {$srid}))";
            array_push($bind, $r['name'], $r['lat'], $r['lng'], $r['population'], $r['lng'], $r['lat']);
        }

        DB::statement(
            'INSERT INTO places (name, lat, lng, population, position) VALUES '
            . implode(',', $values)
            . ' ON DUPLICATE KEY UPDATE population = VALUES(population), position = VALUES(position)',
            $bind
        );

        return count($rows);
    }
}
