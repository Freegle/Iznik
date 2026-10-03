<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Seeds a self-contained authority scenario for the authority statistics tests:
 * one authority, a postcode's worth of activity inside it, members inside and
 * outside it, and two in-area member stories.
 *
 * All geometry is in degree coordinates tagged SRID 3857, the convention the
 * tables use. The quarter under test is Q2 2025 (Apr-Jun).
 */
trait SeedsAuthorityStats
{
    protected int $authorityId = 900001;
    protected string $quarterStart = '2025-05-15';

    protected function seedAuthorityScenario(): void
    {
        // Authority: a 2x2 degree square near the equator (lat ~11), well away from
        // anything else in the shared test DB.
        $this->insertGeom(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, 3857))',
            [$this->authorityId, 'Test Authority (B)', 'POLYGON((-1 10, 1 10, 1 12, -1 12, -1 10))']
        );

        // Order matters for foreign keys: locations and users before the rows
        // that reference them.
        $this->seedLocations();
        $this->seedUsers();
        $this->seedPostcodeActivity();
        $this->seedStories();
    }

    private function seedLocations(): void
    {
        // One postcode inside the authority, one outside it (locations before
        // locations_spatial, which has a foreign key to it).
        DB::table('locations')->insert([
            ['id' => 900500, 'name' => 'AB1 2CD', 'type' => 'Postcode', 'lat' => 11, 'lng' => 0],
            ['id' => 900501, 'name' => 'ZZ9 9ZZ', 'type' => 'Postcode', 'lat' => 19, 'lng' => 0],
        ]);
        $this->insertGeom(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857)), (?, ST_GeomFromText(?, 3857))',
            [900500, 'POINT(0 11)', 900501, 'POINT(0 19)']
        );
    }

    private function seedUsers(): void
    {
        // Members living inside the authority: two joined before the quarter, one in May,
        // one who joined in April and left in June. One member lives outside it.
        DB::table('users')->insert([
            ['id' => 900201, 'lastlocation' => 900500, 'added' => '2025-01-01', 'deleted' => null,
                'settings' => json_encode(['mylocation' => ['lat' => 11, 'lng' => 0]])],
            ['id' => 900202, 'lastlocation' => 900501, 'added' => '2025-01-01', 'deleted' => null,
                'settings' => json_encode(['mylocation' => ['lat' => 19, 'lng' => 0]])],
            ['id' => 900203, 'lastlocation' => 900500, 'added' => '2025-01-01', 'deleted' => null, 'settings' => null],
            ['id' => 900204, 'lastlocation' => 900500, 'added' => '2025-05-10', 'deleted' => null, 'settings' => null],
            ['id' => 900205, 'lastlocation' => 900500, 'added' => '2025-04-10', 'deleted' => '2025-06-10', 'settings' => null],
        ]);
    }

    private function seedPostcodeActivity(): void
    {
        // Messages at the inside postcode within the quarter.
        $this->message(900600, 'Offer', '2025-04-10 09:00:00', 900500);
        $this->message(900601, 'Wanted', '2025-05-10 09:00:00', 900500);
        $this->message(900602, 'Offer', '2025-06-10 09:00:00', 900500);

        // And one outside, which must not count.
        $this->message(900603, 'Offer', '2025-06-10 09:00:00', 900501);

        // Completed gifts with known item weights.
        DB::table('items')->insert(['id' => 900700, 'name' => 'chair', 'weight' => 25, 'popularity' => 1]);
        DB::table('messages_items')->insert([
            ['msgid' => 900602, 'itemid' => 900700],
            ['msgid' => 900603, 'itemid' => 900700],
        ]);
        DB::table('messages_outcomes')->insert([
            ['msgid' => 900602, 'outcome' => 'Taken', 'timestamp' => '2025-06-11 10:00:00'],
            ['msgid' => 900603, 'outcome' => 'Taken', 'timestamp' => '2025-06-11 10:00:00'],
        ]);

        // Two searches at the inside postcode.
        DB::table('search_history')->insert([
            ['term' => 'chair', 'locationid' => 900500, 'date' => '2025-05-05 12:00:00'],
            ['term' => 'table', 'locationid' => 900500, 'date' => '2025-05-06 12:00:00'],
        ]);
    }

    private function seedStories(): void
    {
        DB::table('users_stories')->insert([
            ['id' => 900301, 'userid' => 900201, 'headline' => 'Older inside', 'story' => 'Older inside body', 'public' => 1, 'reviewed' => 1, 'date' => '2025-06-01 08:00:00'],
            ['id' => 900302, 'userid' => 900201, 'headline' => 'Newer inside', 'story' => 'Newer inside body', 'public' => 1, 'reviewed' => 1, 'date' => '2025-06-10 08:00:00'],
            ['id' => 900303, 'userid' => 900202, 'headline' => 'Outside', 'story' => 'Outside body', 'public' => 1, 'reviewed' => 1, 'date' => '2025-06-12 08:00:00'],
        ]);
    }

    // --- low-level insert helpers ------------------------------------------

    private function insertGeom(string $sql, array $bindings): void
    {
        DB::insert($sql, $bindings);
    }

    private function message(int $id, string $type, string $arrival, int $locationid): void
    {
        DB::table('messages')->insert([
            'id' => $id, 'type' => $type, 'arrival' => $arrival, 'locationid' => $locationid,
            'fromuser' => 900201, 'lat' => 11, 'lng' => 0, 'message' => '',
        ]);
    }
}
