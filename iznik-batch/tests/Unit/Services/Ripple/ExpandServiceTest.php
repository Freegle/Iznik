<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\Ripple\ExpandService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpandServiceTest extends TestCase
{
    private const WKT = 'POLYGON((-0.1 51.5, -0.2 51.5, -0.2 51.6, -0.1 51.6, -0.1 51.5))';

    protected function setUp(): void
    {
        parent::setUp();
        // Short, deterministic hazard schedule (3 ticks) + always-active window.
        config(['freegle.ripple.hazard_hours' => [1, 3, 6]]);
        config(['freegle.ripple.active_start_hour' => 0]);
        config(['freegle.ripple.active_end_hour' => 24]);
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');
    }

    private function service(): ExpandService
    {
        return new ExpandService(new ReachService());
    }

    /** Seed an approved OFFER present in messages_spatial; returns the message id. */
    private function seedSpatialPost(Carbon $arrival): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: sofa (London)',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => $arrival,
            'arrival' => $arrival,
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => $arrival,
        ]);
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$message->id, $group->id, Message::TYPE_OFFER, $arrival]
        );

        return (int) $message->id;
    }

    private function fakeRouting(int $ticks = 3): void
    {
        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $schedule = [];
        for ($k = 1; $k <= $ticks; $k++) {
            $schedule[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => 30 * $k, 'polygon' => $polygon];
        }
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $schedule,
        ], 200)]);
    }

    public function test_initialises_reach_for_new_spatial_post(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // 0.5h → tick 1

        $stats = $this->service()->process(false, 500);

        $this->assertSame(1, $stats['initialized']);
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->tick);
        $this->assertSame(3, (int) $row->total_ticks);
        $this->assertSame('expanding', $row->status);
        $this->assertNotNull($row->next_expansion_at);
        $this->assertSame(
            'POLYGON',
            DB::selectOne('SELECT ST_GeometryType(polygon) AS t FROM rippling_reach WHERE msgid = ?', [$msgid])->t
        );
    }

    public function test_backfilled_old_post_starts_at_correct_tick_and_completes(): void
    {
        $this->fakeRouting(3);
        // 7h old → past the final hazard threshold (6h) → tick 3 → done.
        $msgid = $this->seedSpatialPost(now()->subHours(7));

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(3, (int) $row->tick);
        $this->assertSame('done', $row->status);
        $this->assertNull($row->next_expansion_at);
    }

    public function test_advances_due_reach_to_current_tick(): void
    {
        $msgid = $this->seedSpatialPost(now()->subHours(7));
        $ticksJson = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'wkt' => self::WKT],
            ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'wkt' => self::WKT],
            ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'wkt' => self::WKT],
        ]);
        // Start the post stuck at tick 1 with an overdue expansion.
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ?, 'drive', 1, 3, 90, 30, ?, ?, 'expanding', NOW(), NOW())",
            [$msgid, self::WKT, now()->subHours(7), $ticksJson, now()->subHours(4)]
        );
        Http::fake(); // no routing call expected on advance (uses cached schedule)

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['expanded']);
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(3, (int) $row->tick);  // 7h elapsed → final tick
        $this->assertSame('done', $row->status);
    }

    public function test_blurs_poster_origin_for_reach(): void
    {
        // The reach origin (and stored centre) must be blurred ~400m like the locations
        // Freegle exposes elsewhere, so the reach polygon is not a precise location oracle.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // exact origin 51.5, -0.1

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotEquals(51.5, (float) $row->lat, 'stored latitude is blurred, not exact');
        $this->assertNotEquals(-0.1, (float) $row->lng, 'stored longitude is blurred, not exact');
        // ...but still within ~1-2km of the true location (a small privacy blur, not a move).
        $this->assertLessThan(0.02, abs((float) $row->lat - 51.5));
        $this->assertLessThan(0.02, abs((float) $row->lng + 0.1));
    }

    public function test_removes_reach_for_post_no_longer_in_spatial(): void
    {
        Http::fake();
        // A message that has a reach row but is NOT in messages_spatial (taken/withdrawn).
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: gone', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subDays(1), 'arrival' => now()->subDays(1), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ?, 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::WKT, now()->subDays(1)]
        );

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['removed']);
        $this->assertSame(0, DB::table('rippling_reach')->where('msgid', $message->id)->count());
    }

    public function test_handles_filtered_empty_polygon_tick_and_still_completes(): void
    {
        // Routing returns all 3 ticks, but tick 2's polygon is empty → it is filtered
        // out by ReachService. total_ticks must remain the hazard count (3), the post
        // must reach the final tick and transition to 'done' (regression: previously a
        // filtered tick left total_ticks < hazard count and the post never completed).
        $poly = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[
            [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
        ]]]];
        $empty = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[]]]];
        Http::fake(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 90, 'max_drive_min' => 30,
            'schedule' => [
                ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'polygon' => $poly],
                ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'polygon' => $empty],
                ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'polygon' => $poly],
            ],
        ], 200)]);
        $msgid = $this->seedSpatialPost(now()->subHours(7)); // 7h → final tick

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(3, (int) $row->total_ticks); // hazard count, not the 2 usable polygons
        $this->assertSame(3, (int) $row->tick);
        $this->assertSame('done', $row->status);
        $this->assertNull($row->next_expansion_at);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $stats = $this->service()->process(true, 500);

        $this->assertSame(1, $stats['initialized']); // counted
        $this->assertSame(0, DB::table('rippling_reach')->where('msgid', $msgid)->count()); // but not written
    }

    public function test_ripples_post_into_groups_whose_area_the_reach_covers(): void
    {
        // #6: as reach crosses into a group's area (DPA poly if present, else CGA
        // polyofficial — i.e. groups.polyindex), the post is added Pending to that group.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // → tick 1
        $originGid = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');

        // Group B: area intersects the fake reach (-0.20..-0.10 lng, 51.50..51.60 lat).
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // Group C: far away → must NOT ripple in.
        $groupC = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((1.0 53.0,1.1 53.0,1.1 53.1,1.0 53.1,1.0 53.0))', 3857, $groupC->id]
        );

        // Group D: area intersects the reach BUT not a live Freegle group → must NOT ripple in.
        $groupD = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, type = 'Other', onhere = 0, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.16 51.53,-0.13 51.53,-0.13 51.57,-0.16 51.57,-0.16 51.53))', 3857, $groupD->id]
        );

        $stats = $this->service()->process(false, 500);

        // Rippled into B as fresh Pending, carrying the post's msgtype (else it is invisible
        // to type-filtered browse once approved — addApprovedMessage copies messages_groups.msgtype).
        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($b, 'post rippled into group B whose area the reach covers');
        $this->assertSame('Pending', $b->collection);
        $this->assertSame(Message::TYPE_OFFER, $b->msgtype);
        $this->assertGreaterThanOrEqual(1, $stats['rippled_in']);

        // A non-Freegle / not-onhere group is never rippled into, even inside the reach.
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupD->id)->first(),
            'post not rippled into a non-Freegle group'
        );

        // Origin group untouched (still Approved).
        $origin = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $originGid)->first();
        $this->assertSame('Approved', $origin->collection);

        // Far group C not touched.
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupC->id)->first(),
            'post not rippled into a group whose area the reach does not cover'
        );

        // Idempotent — re-running never duplicates the rippled-in row.
        $this->service()->process(false, 500);
        $this->assertSame(
            1,
            (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->count()
        );
    }
}
