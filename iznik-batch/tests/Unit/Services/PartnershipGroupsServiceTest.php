<?php

namespace Tests\Unit\Services;

use App\Services\AuthorityStatsService;
use App\Services\PartnershipGroupsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins every branch of PartnershipGroupsService::sync() with the council boundary lookup
 * stubbed, so the sync logic is tested apart from the spatial maths (which
 * SyncGroupsCommandTest covers end to end).
 */
class PartnershipGroupsServiceTest extends TestCase
{
    private int $partnershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnershipId = $this->partnership();
    }

    private function partnership(array $overrides = []): int
    {
        $authorityId = (int) DB::table('authorities')->insertGetId([
            'name' => 'Svc Test Authority ' . uniqid(),
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((50 50, 51 50, 51 51, 50 51, 50 50))', 3857)"),
        ]);

        return (int) DB::table('partnerships')->insertGetId($overrides + [
            'authorityid' => $authorityId,
            'name' => 'Svc Test Council',
            'tagline' => 'Reuse locally',
            'startdate' => Carbon::today()->subMonth()->toDateString(),
            'enddate' => Carbon::today()->addYear()->toDateString(),
            'amount' => 1000,
            'status' => 'Confirmed',
            'visible' => 1,
        ]);
    }

    private function group(): int
    {
        return (int) DB::table('groups')->insertGetId([
            'nameshort' => 'svcgrp' . uniqid(),
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'polyindex' => DB::raw("ST_GeomFromText('POLYGON((50 50, 51 50, 51 51, 50 51, 50 50))', 3857)"),
        ]);
    }

    /** @param array<int, float> $overlaps groupid => overlap */
    private function service(?array $result): PartnershipGroupsService
    {
        $stats = Mockery::mock(AuthorityStatsService::class);
        $stats->shouldReceive('getSignificantGroups')->andReturn($result);

        return new PartnershipGroupsService($stats);
    }

    /** @param array<int, float> $overlaps */
    private function inside(array $overlaps): PartnershipGroupsService
    {
        $groups = [];
        foreach ($overlaps as $id => $overlap) {
            $groups[] = ['id' => $id, 'overlap' => $overlap];
        }

        return $this->service(['groups' => $groups]);
    }

    private function link(int $groupId, string $source, ?float $overlap = null, ?int $sponsorshipId = null, ?int $partnershipId = null): void
    {
        DB::table('partnerships_groups')->insert([
            'partnershipid' => $partnershipId ?? $this->partnershipId,
            'groupid' => $groupId,
            'source' => $source,
            'overlap' => $overlap,
            'sponsorshipid' => $sponsorshipId,
        ]);
    }

    private function row(int $groupId): ?object
    {
        return DB::table('partnerships_groups')
            ->where('partnershipid', $this->partnershipId)->where('groupid', $groupId)->first();
    }

    private function sponsor(int $groupId): ?object
    {
        $id = $this->row($groupId)?->sponsorshipid;

        return $id ? DB::table('groups_sponsorship')->where('id', $id)->first() : null;
    }

    public function test_unknown_partnership_returns_empty_result(): void
    {
        $stats = Mockery::mock(AuthorityStatsService::class);
        $stats->shouldNotReceive('getSignificantGroups');

        $this->assertSame(
            ['added' => [], 'dropped' => []],
            (new PartnershipGroupsService($stats))->sync(999999999)
        );
    }

    public function test_dry_run_reports_added_and_dropped_but_writes_nothing(): void
    {
        $new = $this->group();
        $stale = $this->group();
        $this->link($stale, 'Boundary', 0.9);

        $result = $this->inside([$new => 0.8])->sync($this->partnershipId, true);

        $this->assertSame([$new], $result['added']);
        $this->assertSame([$stale], $result['dropped']);
        $this->assertNull($this->row($new));
        $this->assertNotNull($this->row($stale));
        $this->assertSame(0, DB::table('groups_sponsorship')->where('groupid', $stale)->count());
    }

    public function test_adds_boundary_row_with_overlap_and_sponsorship(): void
    {
        $new = $this->group();

        $result = $this->inside([$new => 0.75])->sync($this->partnershipId);

        $this->assertSame([$new], $result['added']);
        $this->assertSame([], $result['dropped']);
        $row = $this->row($new);
        $this->assertSame('Boundary', $row->source);
        $this->assertEqualsWithDelta(0.75, (float) $row->overlap, 0.001);
        $this->assertSame('Svc Test Council', $this->sponsor($new)->name);
    }

    public function test_existing_boundary_row_gets_overlap_refreshed_and_is_not_re_added(): void
    {
        $g = $this->group();
        $this->link($g, 'Boundary', 0.5);

        $result = $this->inside([$g => 0.95])->sync($this->partnershipId);

        $this->assertSame([], $result['added']);
        $this->assertSame([], $result['dropped']);
        $this->assertEqualsWithDelta(0.95, (float) $this->row($g)->overlap, 0.001);
        $this->assertSame(1, DB::table('partnerships_groups')->where('groupid', $g)->where('partnershipid', $this->partnershipId)->count());
    }

    public function test_hand_added_row_outside_boundary_has_overlap_nulled_and_is_kept(): void
    {
        $g = $this->group();
        $this->link($g, 'Added', 0.4);

        $result = $this->inside([])->sync($this->partnershipId);

        $this->assertSame([], $result['dropped']);
        $row = $this->row($g);
        $this->assertSame('Added', $row->source);
        $this->assertNull($row->overlap);
        $this->assertNotNull($row->sponsorshipid);
    }

    public function test_removed_row_inside_boundary_is_neither_re_added_nor_given_a_sponsor(): void
    {
        $g = $this->group();
        $this->link($g, 'Removed');

        $result = $this->inside([$g => 1.0])->sync($this->partnershipId);

        $this->assertSame([], $result['added']);
        $row = $this->row($g);
        $this->assertSame('Removed', $row->source);
        $this->assertNull($row->sponsorshipid);
        $this->assertEqualsWithDelta(1.0, (float) $row->overlap, 0.001, 'overlap is still recorded');
    }

    public function test_dropping_a_boundary_row_without_sponsorship_still_deletes_the_row(): void
    {
        $g = $this->group();
        $this->link($g, 'Boundary', 0.2);

        $result = $this->inside([])->sync($this->partnershipId);

        $this->assertSame([$g], $result['dropped']);
        $this->assertNull($this->row($g));
    }

    public function test_dropping_a_boundary_row_deletes_its_sponsorship(): void
    {
        $g = $this->group();
        $sid = (int) DB::table('groups_sponsorship')->insertGetId(['groupid' => $g, 'name' => 'old']);
        $this->link($g, 'Boundary', 0.2, $sid);

        $this->inside([])->sync($this->partnershipId);

        $this->assertNull($this->row($g));
        $this->assertNull(DB::table('groups_sponsorship')->where('id', $sid)->first());
    }

    public function test_existing_sponsorship_is_updated_not_duplicated(): void
    {
        $g = $this->group();
        $sid = (int) DB::table('groups_sponsorship')->insertGetId(['groupid' => $g, 'name' => 'stale name']);
        $this->link($g, 'Boundary', 0.9, $sid);

        $this->inside([$g => 0.9])->sync($this->partnershipId);

        $this->assertSame($sid, (int) $this->row($g)->sponsorshipid);
        $this->assertSame(1, DB::table('groups_sponsorship')->where('groupid', $g)->count());
        $this->assertSame('Svc Test Council', DB::table('groups_sponsorship')->where('id', $sid)->value('name'));
    }

    public static function visibilityProvider(): array
    {
        return [
            'confirmed visible' => ['Confirmed', 1, 1],
            'paid visible' => ['Paid', 1, 1],
            'overdue visible' => ['Overdue', 1, 1],
            'confirmed but hidden' => ['Confirmed', 0, 0],
            'in principle visible' => ['InPrinciple', 1, 0],
            'in principle hidden' => ['InPrinciple', 0, 0],
        ];
    }

    #[DataProvider('visibilityProvider')]
    public function test_sponsorship_visible_only_when_committed_and_visible(string $status, int $visible, int $expected): void
    {
        DB::table('partnerships')->where('id', $this->partnershipId)->update(['status' => $status, 'visible' => $visible]);
        $g = $this->group();

        $this->inside([$g => 1.0])->sync($this->partnershipId);

        $this->assertSame($expected, (int) $this->sponsor($g)->visible);
    }

    public function test_no_contacts_leaves_contact_fields_blank(): void
    {
        $g = $this->group();

        $this->inside([$g => 1.0])->sync($this->partnershipId);

        $sponsor = $this->sponsor($g);
        $this->assertSame('', $sponsor->contactname);
        $this->assertSame('', $sponsor->contactemail);
    }

    public function test_without_a_waste_contact_the_first_contact_is_used(): void
    {
        DB::table('partnerships_contacts')->insert([
            ['partnershipid' => $this->partnershipId, 'name' => 'Fred Finance', 'email' => 'f@example.gov.uk', 'role' => 'Finance'],
            ['partnershipid' => $this->partnershipId, 'name' => 'Sam Second', 'email' => 's@example.gov.uk', 'role' => 'Finance'],
        ]);
        $g = $this->group();

        $this->inside([$g => 1.0])->sync($this->partnershipId);

        $this->assertSame('Fred Finance', $this->sponsor($g)->contactname);
        $this->assertSame('f@example.gov.uk', $this->sponsor($g)->contactemail);
    }

    public function test_waste_contact_wins_over_earlier_contacts(): void
    {
        DB::table('partnerships_contacts')->insert([
            ['partnershipid' => $this->partnershipId, 'name' => 'Fred Finance', 'email' => 'f@example.gov.uk', 'role' => 'Finance'],
            ['partnershipid' => $this->partnershipId, 'name' => 'Wendy Waste', 'email' => 'w@example.gov.uk', 'role' => 'Waste'],
        ]);
        $g = $this->group();

        $this->inside([$g => 1.0])->sync($this->partnershipId);

        $this->assertSame('Wendy Waste', $this->sponsor($g)->contactname);
    }

    public function test_sponsorship_fields_copy_the_deal(): void
    {
        DB::table('partnerships')->where('id', $this->partnershipId)->update([
            'linkurl' => 'https://example.gov.uk/reuse',
            'notes' => 'Some notes',
            'imageurl' => 'https://example.gov.uk/logo.png',
            'description' => 'Long text',
            'amount' => 2500,
        ]);
        $g = $this->group();

        $this->inside([$g => 1.0])->sync($this->partnershipId);

        $s = $this->sponsor($g);
        $this->assertSame('https://example.gov.uk/reuse', $s->linkurl);
        $this->assertSame('Some notes', $s->notes);
        $this->assertSame('https://example.gov.uk/logo.png', $s->imageurl);
        $this->assertSame('Long text', $s->description);
        $this->assertSame('Reuse locally', $s->tagline);
        $this->assertSame(2500, (int) $s->amount);
    }

    public function test_boundary_lookup_returning_null_drops_boundary_rows_but_keeps_hand_rows(): void
    {
        // Characterises current behaviour: an unknown authority is treated as having no
        // communities inside it.
        $boundary = $this->group();
        $added = $this->group();
        $this->link($boundary, 'Boundary', 0.9);
        $this->link($added, 'Added');

        $result = $this->service(null)->sync($this->partnershipId);

        $this->assertSame([$boundary], $result['dropped']);
        $this->assertNull($this->row($boundary));
        $this->assertNotNull($this->row($added));
    }

    public function test_boundary_lookup_with_no_groups_key_is_treated_as_empty(): void
    {
        $result = $this->service([])->sync($this->partnershipId);

        $this->assertSame(['added' => [], 'dropped' => []], $result);
    }

    public function test_other_partnerships_rows_are_untouched(): void
    {
        $other = $this->partnership(['name' => 'Other Council']);
        $g = $this->group();
        $this->link($g, 'Boundary', 0.9, null, $other);

        $this->inside([])->sync($this->partnershipId);

        $this->assertSame(1, DB::table('partnerships_groups')->where('partnershipid', $other)->where('groupid', $g)->count());
    }
}
