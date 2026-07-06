<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Seeds a self-contained authority scenario for the authority statistics tests:
 * one authority, three overlapping groups (one full, one half, one trivial),
 * their daily stats, shortlinks and clicks, a postcode's worth of activity and
 * two in-area member stories.
 *
 * All geometry is a set of squares in degree coordinates tagged SRID 3857, the
 * convention the tables use. The quarter under test is Q2 2025 (Apr-Jun).
 */
trait SeedsAuthorityStats
{
    protected int $authorityId = 900001;
    protected int $groupFullId = 900101;   // fully inside the authority (overlap 1)
    protected int $groupHalfId = 900102;   // half inside (overlap 0.5, gets a "*")
    protected int $groupTrivialId = 900103; // inside but < 3 kg over the quarter
    protected string $quarterStart = '2025-05-15';

    protected function seedAuthorityScenario(): void
    {
        // Authority: a 2x2 degree square. Placed near the equator (lat ~11), well
        // away from any UK-located group other tests may leave committed in the
        // shared test DB, so the spatial overlap query only sees this scenario's
        // groups.
        $this->insertGeom(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, 3857))',
            [$this->authorityId, 'Test Authority (B)', 'POLYGON((-1 10, 1 10, 1 12, -1 12, -1 10))']
        );

        // Groups. Full sits wholly inside; half straddles the eastern edge;
        // trivial sits inside but barely reuses anything.
        $this->insertGroup($this->groupFullId, 'fullgrp', 'Full Group', 'POLYGON((-0.5 10.5, 0.5 10.5, 0.5 11.5, -0.5 11.5, -0.5 10.5))');
        $this->insertGroup($this->groupHalfId, 'halfgrp', 'Half Group', 'POLYGON((0.5 10.5, 1.5 10.5, 1.5 11.5, 0.5 11.5, 0.5 10.5))');
        $this->insertGroup($this->groupTrivialId, 'trivgrp', 'Trivial Group', 'POLYGON((-0.4 10.6, 0.4 10.6, 0.4 11.4, -0.4 11.4, -0.4 10.6))');

        // Stats. count is integer kg / member counts / outcome counts.
        // Full group (overlap 1).
        $this->stat($this->groupFullId, self::AMC, '2025-04-01', 90);
        $this->stat($this->groupFullId, self::AMC, '2025-04-30', 100);
        $this->stat($this->groupFullId, self::AMC, '2025-05-31', 110);
        $this->stat($this->groupFullId, self::AMC, '2025-06-30', 120);
        $this->stat($this->groupFullId, self::WEIGHT, '2025-04-10', 50);
        $this->stat($this->groupFullId, self::WEIGHT, '2025-04-20', 50);
        $this->stat($this->groupFullId, self::WEIGHT, '2025-05-15', 200);
        $this->stat($this->groupFullId, self::WEIGHT, '2025-06-15', 150);
        $this->stat($this->groupFullId, self::OUTCOMES, '2025-04-10', 5);
        $this->stat($this->groupFullId, self::OUTCOMES, '2025-04-20', 5);
        $this->stat($this->groupFullId, self::OUTCOMES, '2025-05-15', 20);
        $this->stat($this->groupFullId, self::OUTCOMES, '2025-06-15', 15);

        // Half group (overlap 0.5).
        $this->stat($this->groupHalfId, self::AMC, '2025-04-30', 40);
        $this->stat($this->groupHalfId, self::AMC, '2025-05-31', 44);
        $this->stat($this->groupHalfId, self::AMC, '2025-06-30', 50);
        $this->stat($this->groupHalfId, self::WEIGHT, '2025-04-10', 100);
        $this->stat($this->groupHalfId, self::WEIGHT, '2025-05-15', 100);
        $this->stat($this->groupHalfId, self::WEIGHT, '2025-06-15', 100);
        $this->stat($this->groupHalfId, self::OUTCOMES, '2025-04-10', 8);
        $this->stat($this->groupHalfId, self::OUTCOMES, '2025-06-15', 8);

        // Trivial group: 2 kg over the quarter -> below the 3 kg cutoff.
        $this->stat($this->groupTrivialId, self::AMC, '2025-06-30', 10);
        $this->stat($this->groupTrivialId, self::WEIGHT, '2025-04-10', 2);

        // Order matters for foreign keys: users and locations before the rows
        // that reference them.
        $this->seedUsers();
        $this->seedShortlinks();
        $this->seedPostcodeActivity();
        $this->seedStories();
    }

    private function seedUsers(): void
    {
        // One member inside the authority (via settings.mylocation), one north
        // of it (outside).
        DB::table('users')->insert([
            ['id' => 900201, 'settings' => json_encode(['mylocation' => ['lat' => 11, 'lng' => 0]])],
            ['id' => 900202, 'settings' => json_encode(['mylocation' => ['lat' => 19, 'lng' => 0]])],
        ]);
    }

    private function seedShortlinks(): void
    {
        // Full group has two shortlinks; half group one. Names chosen so the
        // case-insensitive sort order is apple link < Middle < Zebra Link.
        DB::table('shortlinks')->insert([
            ['id' => 900801, 'name' => 'apple link', 'type' => 'Group', 'groupid' => $this->groupFullId],
            ['id' => 900802, 'name' => 'Zebra Link', 'type' => 'Group', 'groupid' => $this->groupFullId],
            ['id' => 900803, 'name' => 'Middle', 'type' => 'Group', 'groupid' => $this->groupHalfId],
        ]);

        // Multiple clicks on some days, so "total clicks" differs from "days".
        $this->clicks(900801, '2025-04-05', 2);
        $this->clicks(900801, '2025-05-10', 3);
        $this->clicks(900801, '2025-07-01', 9);  // outside the quarter
        $this->clicks(900803, '2025-06-20', 5);
        $this->clicks(900802, '2025-04-15', 1);
        $this->clicks(900802, '2025-06-25', 4);
    }

    private function seedPostcodeActivity(): void
    {
        // One postcode inside the authority (locations before locations_spatial,
        // which has a foreign key to it).
        DB::table('locations')->insert([
            'id' => 900500, 'name' => 'AB1 2CD', 'type' => 'Postcode', 'lat' => 11, 'lng' => 0,
        ]);
        $this->insertGeom(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, 3857))',
            [900500, 'POINT(0 11)']
        );

        // Messages at that postcode within the quarter.
        $this->message(900600, 'Offer', '2025-04-10 09:00:00', 900500);
        $this->message(900601, 'Wanted', '2025-05-10 09:00:00', 900500);
        $this->message(900602, 'Offer', '2025-06-10 09:00:00', 900500);

        // One completed gift with a known item weight.
        DB::table('items')->insert(['id' => 900700, 'name' => 'chair', 'weight' => 25, 'popularity' => 1]);
        DB::table('messages_items')->insert(['msgid' => 900602, 'itemid' => 900700]);
        DB::table('messages_outcomes')->insert([
            'msgid' => 900602, 'outcome' => 'Taken', 'timestamp' => '2025-06-11 10:00:00',
        ]);

        // Two searches at that postcode.
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

    private const AMC = 'ApprovedMemberCount';
    private const WEIGHT = 'Weight';
    private const OUTCOMES = 'Outcomes';

    private function insertGeom(string $sql, array $bindings): void
    {
        DB::insert($sql, $bindings);
    }

    private function insertGroup(int $id, string $nameshort, string $namefull, string $wkt): void
    {
        $this->insertGeom(
            'INSERT INTO `groups` (id, nameshort, namefull, type, publish, onmap, lat, lng, polyindex)
             VALUES (?, ?, ?, ?, 1, 1, 11, 0, ST_GeomFromText(?, 3857))',
            [$id, $nameshort, $namefull, 'Freegle', $wkt]
        );
    }

    private function stat(int $groupid, string $type, string $date, int $count): void
    {
        DB::table('stats')->insert([
            'groupid' => $groupid, 'type' => $type, 'date' => $date, 'end' => $date, 'count' => $count,
        ]);
    }

    private function clicks(int $shortlinkid, string $date, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['shortlinkid' => $shortlinkid, 'timestamp' => $date . ' 12:00:00'];
        }
        DB::table('shortlink_clicks')->insert($rows);
    }

    private function message(int $id, string $type, string $arrival, int $locationid): void
    {
        DB::table('messages')->insert([
            'id' => $id, 'type' => $type, 'arrival' => $arrival, 'locationid' => $locationid,
            'fromuser' => 900201, 'lat' => 11, 'lng' => 0, 'message' => '',
        ]);
    }
}
