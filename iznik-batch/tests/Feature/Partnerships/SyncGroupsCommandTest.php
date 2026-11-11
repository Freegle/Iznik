<?php

namespace Tests\Feature\Partnerships;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A council deal covers every community inside the council boundary, including ones set up
 * after the deal was agreed. The daily sync is what makes that true without anyone opening
 * the Partnerships page; these tests pin that it follows the boundary and never overrides a
 * decision someone made by hand.
 */
class SyncGroupsCommandTest extends TestCase
{
    private int $authorityId;

    private int $partnershipId;

    protected function setUp(): void
    {
        parent::setUp();

        // Near the equator, away from any UK group other tests leave in the shared database.
        $this->authorityId = (int) DB::table('authorities')->insertGetId([
            'name' => 'Sync Test Council ' . uniqid(),
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((-31 10, -29 10, -29 12, -31 12, -31 10))', 3857)"),
        ]);

        // Park other live deals so each test only syncs its own.
        DB::table('partnerships')->update(['enddate' => '2000-01-01']);

        $this->partnershipId = (int) DB::table('partnerships')->insertGetId([
            'authorityid' => $this->authorityId,
            'name' => 'Sync Test Council',
            'tagline' => 'Reuse locally',
            'startdate' => Carbon::today()->subMonth()->toDateString(),
            'enddate' => Carbon::today()->addYear()->toDateString(),
            'amount' => 1000,
            'status' => 'Confirmed',
            'visible' => 1,
        ]);
    }

    private function group(string $wkt): int
    {
        return (int) DB::table('groups')->insertGetId([
            'nameshort' => 'syncgrp' . uniqid(),
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'polyindex' => DB::raw("ST_GeomFromText('" . $wkt . "', 3857)"),
        ]);
    }

    private function inside(): int
    {
        return $this->group('POLYGON((-30.5 10.5, -29.5 10.5, -29.5 11.5, -30.5 11.5, -30.5 10.5))');
    }

    /** A big community that only has a thin strip along the council's eastern edge. */
    private function sliver(): int
    {
        return $this->group('POLYGON((-29.02 10.5, -20 10.5, -20 30.5, -29.02 30.5, -29.02 10.5))');
    }

    private function link(int $groupId, string $source): void
    {
        DB::table('partnerships_groups')->insert([
            'partnershipid' => $this->partnershipId,
            'groupid' => $groupId,
            'source' => $source,
        ]);
    }

    private function row(int $groupId): ?object
    {
        return DB::table('partnerships_groups')
            ->where('partnershipid', $this->partnershipId)->where('groupid', $groupId)->first();
    }

    public function test_covers_a_new_community_inside_the_boundary(): void
    {
        $groupId = $this->inside();

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $row = $this->row($groupId);
        $this->assertNotNull($row);
        $this->assertSame('Boundary', $row->source);
        $this->assertEqualsWithDelta(1.0, (float) $row->overlap, 0.001);

        $sponsor = DB::table('groups_sponsorship')->where('id', $row->sponsorshipid)->first();
        $this->assertNotNull($sponsor, 'members of the new community see the sponsor');
        $this->assertSame('Reuse locally', $sponsor->tagline);
        $this->assertSame(1, (int) $sponsor->visible);
    }

    public function test_drops_a_boundary_community_no_longer_inside(): void
    {
        $farAway = $this->group('POLYGON((50 50, 51 50, 51 51, 50 51, 50 50))');
        $this->link($farAway, 'Boundary');

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertNull($this->row($farAway));
    }

    public function test_does_not_cover_a_community_that_only_slightly_overlaps_the_boundary(): void
    {
        $sliver = $this->sliver();

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertNull($this->row($sliver));
    }

    public function test_drops_a_boundary_community_that_turns_out_to_be_a_sliver(): void
    {
        $sliver = $this->sliver();
        $this->link($sliver, 'Boundary');
        $sponsorshipId = DB::table('groups_sponsorship')->insertGetId(['groupid' => $sliver, 'name' => 'Sync Test Council']);
        DB::table('partnerships_groups')->where('groupid', $sliver)->update(['sponsorshipid' => $sponsorshipId]);

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertNull($this->row($sliver));
        $this->assertNull(DB::table('groups_sponsorship')->where('id', $sponsorshipId)->first(), 'its sponsor entry goes too');
    }

    public function test_keeps_a_sliver_community_that_was_added_or_left_out_by_hand(): void
    {
        $added = $this->sliver();
        $this->link($added, 'Added');
        $removed = $this->sliver();
        $this->link($removed, 'Removed');

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertSame('Added', $this->row($added)->source);
        $this->assertNotNull($this->row($added)->sponsorshipid);
        $this->assertSame('Removed', $this->row($removed)->source);
    }

    public function test_leaves_hand_decisions_alone(): void
    {
        $leftOut = $this->inside();
        $this->link($leftOut, 'Removed');
        $addedByHand = $this->group('POLYGON((50 50, 51 50, 51 51, 50 51, 50 50))');
        $this->link($addedByHand, 'Added');

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertSame('Removed', $this->row($leftOut)->source);
        $this->assertNull($this->row($leftOut)->sponsorshipid, 'a community left out gets no sponsor');
        $this->assertSame('Added', $this->row($addedByHand)->source);
        $this->assertNotNull($this->row($addedByHand)->sponsorshipid);
    }

    public function test_a_deal_not_yet_committed_is_not_shown_to_members(): void
    {
        DB::table('partnerships')->where('id', $this->partnershipId)->update(['status' => 'InPrinciple']);
        $groupId = $this->inside();

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $visible = DB::table('groups_sponsorship')->where('id', $this->row($groupId)->sponsorshipid)->value('visible');
        $this->assertSame(0, (int) $visible);
    }

    public function test_the_sponsor_contact_is_the_waste_team(): void
    {
        DB::table('partnerships_contacts')->insert([
            ['partnershipid' => $this->partnershipId, 'name' => 'Fred Finance', 'email' => 'f@example.gov.uk', 'role' => 'Finance'],
            ['partnershipid' => $this->partnershipId, 'name' => 'Wendy Waste', 'email' => 'w@example.gov.uk', 'role' => 'Waste'],
        ]);
        $groupId = $this->inside();

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $sponsor = DB::table('groups_sponsorship')->where('id', $this->row($groupId)->sponsorshipid)->first();
        $this->assertSame('Wendy Waste', $sponsor->contactname);
    }

    public function test_ended_deals_are_left_alone(): void
    {
        DB::table('partnerships')->where('id', $this->partnershipId)->update(['enddate' => '2020-01-01']);
        $groupId = $this->inside();

        $this->artisan('partnerships:sync-groups')->assertExitCode(0);

        $this->assertNull($this->row($groupId));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $groupId = $this->inside();

        $this->artisan('partnerships:sync-groups', ['--dry-run' => true])
            ->expectsOutputToContain('would cover')
            ->assertExitCode(0);

        $this->assertNull($this->row($groupId));
    }
}
