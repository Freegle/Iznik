<?php

namespace Tests\Feature\Services;

use App\Services\CommunityEventService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommunityEventServiceTest extends TestCase
{
    private CommunityEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommunityEventService();
    }

    private function makeEvent(string $externalId, string $title = 'Repair Cafe', string $location = 'Town Hall'): int
    {
        return $this->service->createEvent(null, $title, $location, null, null, null, null, null, $externalId);
    }

    private function makeEventWithDate(string $externalId, string $start, string $title = 'Repair Cafe', string $location = 'Town Hall'): int
    {
        $id = $this->makeEvent($externalId, $title, $location);
        $this->service->addDate($id, $start, date('Y-m-d H:i:s', strtotime($start) + 7200));

        return $id;
    }

    private function uniq(): string
    {
        return 'CES' . uniqid() . '-';
    }

    public function test_find_by_external_id(): void
    {
        $ext = $this->uniq() . '1';
        $id = $this->makeEvent($ext);

        $found = $this->service->findByExternalId($ext);
        $this->assertNotNull($found);
        $this->assertEquals($id, $found->id);
        $this->assertNull($this->service->findByExternalId($ext . 'nope'));
    }

    public function test_create_event_stores_values_and_pending(): void
    {
        $ext = $this->uniq() . '1';
        $id = $this->service->createEvent(null, 'T', 'L', 'Name', '0123', 'a@b.com', 'http://x', 'Desc', $ext);

        $this->assertGreaterThan(0, $id);
        $row = DB::table('communityevents')->where('id', $id)->first();
        $this->assertEquals(1, $row->pending);
        $this->assertNull($row->userid);
        $this->assertEquals('T', $row->title);
        $this->assertEquals('L', $row->location);
        $this->assertEquals('Name', $row->contactname);
        $this->assertEquals('0123', $row->contactphone);
        $this->assertEquals('a@b.com', $row->contactemail);
        $this->assertEquals('http://x', $row->contacturl);
        $this->assertEquals('Desc', $row->description);
        $this->assertEquals($ext, $row->externalid);
    }

    public function test_create_event_accepts_nulls(): void
    {
        $id = $this->service->createEvent(null, 'T', 'L', null, null, null, null, null);

        $row = DB::table('communityevents')->where('id', $id)->first();
        $this->assertNull($row->userid);
        $this->assertNull($row->description);
        $this->assertNull($row->externalid);
        $this->assertNull($row->contactname);
        $this->assertEquals(1, $row->pending);
    }

    public function test_update_event(): void
    {
        $id = $this->makeEvent($this->uniq() . '1');
        $this->service->updateEvent($id, ['title' => 'New title', 'description' => 'New desc']);

        $row = DB::table('communityevents')->where('id', $id)->first();
        $this->assertEquals('New title', $row->title);
        $this->assertEquals('New desc', $row->description);
    }

    public static function addDateProvider(): array
    {
        return [
            'end after start stored verbatim' => ['2030-01-01 10:00:00', '2030-01-01 12:00:00', '2030-01-01 12:00:00'],
            'end before start becomes start+1h' => ['2030-01-01 10:00:00', '2030-01-01 09:00:00', '2030-01-01 11:00:00'],
            'end equal to start stored verbatim' => ['2030-01-01 10:00:00', '2030-01-01 10:00:00', '2030-01-01 10:00:00'],
            'end long before start' => ['2030-06-15 23:30:00', '2020-01-01 00:00:00', '2030-06-16 00:30:00'],
        ];
    }

    #[DataProvider('addDateProvider')]
    public function test_add_date(string $start, string $end, string $expectedEnd): void
    {
        $id = $this->makeEvent($this->uniq() . '1');
        $this->service->addDate($id, $start, $end);

        $rows = DB::table('communityevents_dates')->where('eventid', $id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals($start, $rows[0]->start);
        $this->assertEquals($expectedEnd, $rows[0]->end);
    }

    public function test_remove_dates_only_affects_given_event(): void
    {
        $a = $this->makeEvent($this->uniq() . 'a');
        $b = $this->makeEvent($this->uniq() . 'b');
        $this->service->addDate($a, '2030-01-01 10:00:00', '2030-01-01 11:00:00');
        $this->service->addDate($a, '2030-01-02 10:00:00', '2030-01-02 11:00:00');
        $this->service->addDate($b, '2030-01-01 10:00:00', '2030-01-01 11:00:00');

        $this->service->removeDates($a);

        $this->assertEquals(0, DB::table('communityevents_dates')->where('eventid', $a)->count());
        $this->assertEquals(1, DB::table('communityevents_dates')->where('eventid', $b)->count());
    }

    public function test_add_group_is_idempotent(): void
    {
        $group = $this->createTestGroup();
        $id = $this->makeEvent($this->uniq() . '1');

        $this->service->addGroup($id, $group->id);
        $this->assertEquals(1, DB::table('communityevents_groups')->where('eventid', $id)->where('groupid', $group->id)->count());

        $this->service->addGroup($id, $group->id);
        $this->assertEquals(1, DB::table('communityevents_groups')->where('eventid', $id)->where('groupid', $group->id)->count());
    }

    public function test_mark_deleted(): void
    {
        $id = $this->makeEvent($this->uniq() . '1');
        $this->assertEquals(0, DB::table('communityevents')->where('id', $id)->value('deleted'));

        $this->service->markDeleted($id);

        $this->assertEquals(1, DB::table('communityevents')->where('id', $id)->value('deleted'));
    }

    public function test_find_same_occurrence_match_and_non_matches(): void
    {
        $prefix = $this->uniq();
        $id = $this->makeEventWithDate($prefix . '1', '2030-03-01 10:00:00');

        $found = $this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 10:00:00');
        $this->assertNotNull($found);
        $this->assertEquals($id, $found->id);
        $this->assertEquals($prefix . '1', $found->externalid);

        $this->assertNull($this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 11:00:00'));
        $this->assertNull($this->service->findSameOccurrence($prefix, 'Other', 'Town Hall', '2030-03-01 10:00:00'));
        $this->assertNull($this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Elsewhere', '2030-03-01 10:00:00'));
        $this->assertNull($this->service->findSameOccurrence('ZZ' . $prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 10:00:00'));
    }

    public function test_find_same_occurrence_unparseable_start_returns_null(): void
    {
        $prefix = $this->uniq();
        $this->makeEventWithDate($prefix . '1', '2030-03-01 10:00:00');

        $this->assertNull($this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', 'not a date'));
    }

    public function test_find_same_occurrence_ignores_deleted(): void
    {
        $prefix = $this->uniq();
        $id = $this->makeEventWithDate($prefix . '1', '2030-03-01 10:00:00');
        $this->assertNotNull($this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 10:00:00'));

        $this->service->markDeleted($id);

        $this->assertNull($this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 10:00:00'));
    }

    public function test_find_same_occurrence_picks_correct_event_at_shared_venue(): void
    {
        $prefix = $this->uniq();
        $a = $this->makeEventWithDate($prefix . 'a', '2030-03-01 10:00:00');
        $b = $this->makeEventWithDate($prefix . 'b', '2030-03-08 10:00:00');
        $c = $this->makeEventWithDate($prefix . 'c', '2030-03-15 10:00:00');

        $this->assertEquals($a, $this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-01 10:00:00')->id);
        $this->assertEquals($b, $this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-08 10:00:00')->id);
        $this->assertEquals($c, $this->service->findSameOccurrence($prefix, 'Repair Cafe', 'Town Hall', '2030-03-15 10:00:00')->id);
    }

    public static function upcomingProvider(): array
    {
        return [
            'prefix matches start of id' => ['prefix', 'PFX-', 2],
            'prefix does not match middle' => ['prefix', 'MID-', 0],
            'substring matches middle' => ['substring', 'MID-', 1],
            'substring matches start' => ['substring', 'PFX-', 2],
            'substring no match' => ['substring', 'NOPE-', 0],
        ];
    }

    #[DataProvider('upcomingProvider')]
    public function test_get_upcoming_by_external_id(string $mode, string $needle, int $expected): void
    {
        $tag = uniqid();
        $this->makeEventWithDate("PFX-$tag-1", '2030-05-01 10:00:00');
        $this->makeEventWithDate("PFX-$tag-2", '2030-05-02 10:00:00');
        $this->makeEventWithDate("old-MID-$tag", '2030-05-03 10:00:00');
        $this->makeEventWithDate("PFX-$tag-past", '2020-01-01 10:00:00');

        // The tag scopes the match to this test's rows.
        $from = '2030-01-01 00:00:00';
        if ($mode === 'prefix') {
            $rows = $this->service->getUpcomingByExternalIdPrefix($needle . $tag, $from);
        } else {
            $rows = $this->service->getUpcomingByExternalIdSubstring($needle === 'NOPE-' ? 'NOPE-' . $tag : $needle . $tag, $from);
        }

        $this->assertCount($expected, $rows);
    }

    public function test_get_upcoming_boundary_and_exclusion(): void
    {
        $tag = uniqid();
        $exact = $this->makeEventWithDate("B-$tag-exact", '2030-05-01 00:00:00');
        $after = $this->makeEventWithDate("B-$tag-after", '2030-05-01 00:00:01');
        $this->makeEventWithDate("B-$tag-before", '2030-04-30 23:59:59');

        $rows = $this->service->getUpcomingByExternalIdPrefix("B-$tag", '2030-05-01 00:00:00');
        $ids = array_map(fn($r) => $r->id, $rows);
        sort($ids);
        $this->assertEquals([$exact, $after], $ids);
        $this->assertObjectHasProperty('externalid', $rows[0]);

        $ids = array_map(fn($r) => $r->id, $this->service->getUpcomingByExternalIdSubstring("-$tag-", '2030-05-01 00:00:00'));
        sort($ids);
        $this->assertEquals([$exact, $after], $ids);
    }
}
