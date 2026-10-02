<?php

namespace Tests\Feature\Partnerships;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Council sponsorships set up before the Partnerships page were rows in groups_sponsorship,
 * one per community. The import turns each deal into a partnership so the page shows the
 * council's history, without changing what members see.
 */
class ImportSponsorshipsCommandTest extends TestCase
{
    private int $authorityId;

    private string $council;

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's rows should be candidates.
        DB::table('groups_sponsorship')->delete();

        $this->council = 'Importshire ' . uniqid();
        $this->authorityId = (int) DB::table('authorities')->insertGetId([
            'name' => $this->council,
            'area_code' => 'CTY',
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((-41 10, -39 10, -39 12, -41 12, -41 10))', 3857)"),
        ]);
    }

    private function group(string $wkt): int
    {
        return (int) DB::table('groups')->insertGetId([
            'nameshort' => 'importgrp' . uniqid(),
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'polyindex' => DB::raw("ST_GeomFromText('" . $wkt . "', 3857)"),
        ]);
    }

    private function sponsorship(int $groupId, string $name, int $amount = 200, array $overrides = []): int
    {
        return (int) DB::table('groups_sponsorship')->insertGetId(array_merge([
            'groupid' => $groupId,
            'name' => $name,
            'linkurl' => 'https://example.gov.uk',
            'startdate' => '2024-01-01',
            'enddate' => '2025-12-31',
            'contactname' => 'Wendy Waste',
            'contactemail' => 'wendy@example.gov.uk',
            'amount' => $amount,
            'visible' => 1,
            'tagline' => 'Reuse it',
        ], $overrides));
    }

    public function test_imports_a_deal_against_the_council_its_name_matches(): void
    {
        $inside = $this->group('POLYGON((-40.5 10.5, -39.5 10.5, -39.5 11.5, -40.5 11.5, -40.5 10.5))');
        $outside = $this->group('POLYGON((60 60, 61 60, 61 61, 60 61, 60 60))');
        $s1 = $this->sponsorship($inside, $this->council . ' Council', 200);
        $s2 = $this->sponsorship($outside, $this->council . ' Council', 300);

        $this->artisan('partnerships:import-sponsorships')->assertExitCode(0);

        $p = DB::table('partnerships')->where('authorityid', $this->authorityId)->first();
        $this->assertNotNull($p, '"X Council" matches the authority "X"');
        $this->assertSame('Confirmed', $p->status);
        $this->assertEqualsWithDelta(500, (float) $p->amount, 0.001, 'the value is the sum across communities');
        $this->assertSame('Reuse it', $p->tagline);

        $links = DB::table('partnerships_groups')->where('partnershipid', $p->id)->get()->keyBy('groupid');
        $this->assertSame('Boundary', $links[$inside]->source);
        $this->assertSame($s1, (int) $links[$inside]->sponsorshipid, 'the existing row is linked, not copied');
        $this->assertSame('Added', $links[$outside]->source);
        $this->assertSame($s2, (int) $links[$outside]->sponsorshipid);
        $this->assertSame(2, DB::table('groups_sponsorship')->count(), 'members see no change');

        $contact = DB::table('partnerships_contacts')->where('partnershipid', $p->id)->first();
        $this->assertSame('Wendy Waste', $contact->name);
        $this->assertSame('Waste', $contact->role);
    }

    public function test_running_twice_imports_once(): void
    {
        $this->sponsorship($this->group('POLYGON((60 60, 61 60, 61 61, 60 61, 60 60))'), $this->council);

        $this->artisan('partnerships:import-sponsorships')->assertExitCode(0);
        $this->artisan('partnerships:import-sponsorships')->assertExitCode(0);

        $this->assertSame(1, DB::table('partnerships')->where('authorityid', $this->authorityId)->count());
    }

    public function test_different_dates_are_different_deals(): void
    {
        $g = $this->group('POLYGON((60 60, 61 60, 61 61, 60 61, 60 60))');
        $this->sponsorship($g, $this->council);
        $this->sponsorship($g, $this->council, 200, ['startdate' => '2026-01-01', 'enddate' => '2026-12-31']);

        $this->artisan('partnerships:import-sponsorships')->assertExitCode(0);

        $this->assertSame(2, DB::table('partnerships')->where('authorityid', $this->authorityId)->count(),
            'each run of dates is one deal in the council\'s history');
    }

    public function test_an_unknown_sponsor_is_skipped_unless_mapped(): void
    {
        $this->sponsorship($this->group('POLYGON((60 60, 61 60, 61 61, 60 61, 60 60))'), 'Wasteshire Recycles');

        $this->artisan('partnerships:import-sponsorships')
            ->expectsOutputToContain('No council found for "Wasteshire Recycles"')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('partnerships')->where('authorityid', $this->authorityId)->count());

        $this->artisan('partnerships:import-sponsorships', ['--map' => ['Wasteshire Recycles=' . $this->authorityId]])
            ->assertExitCode(0);
        $this->assertSame('Wasteshire Recycles',
            DB::table('partnerships')->where('authorityid', $this->authorityId)->value('name'));
    }

    public function test_a_bad_map_is_refused(): void
    {
        $this->artisan('partnerships:import-sponsorships', ['--map' => ['nonsense']])->assertExitCode(1);
    }

    public function test_dry_run_imports_nothing(): void
    {
        $this->sponsorship($this->group('POLYGON((60 60, 61 60, 61 61, 60 61, 60 60))'), $this->council);

        $this->artisan('partnerships:import-sponsorships', ['--dry-run' => true])
            ->expectsOutputToContain('Would import')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('partnerships')->where('authorityid', $this->authorityId)->count());
    }
}
