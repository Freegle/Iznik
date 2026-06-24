<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\ChatMessage;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
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
        // Rippling is OFF by default (ships dark); enable it so the engine actually does work here.
        config(['freegle.ripple.enabled' => true]);
        // Short, deterministic hazard schedule (3 ticks) + always-active window.
        config(['freegle.ripple.hazard_hours' => [1, 3, 6]]);
        config(['freegle.ripple.active_start_hour' => 0]);
        config(['freegle.ripple.active_end_hour' => 24]);
        // Disable the go-live arrival cutoff so fixtures with back-dated arrivals still ripple.
        config(['freegle.ripple.enabled_at' => '']);
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');
    }

    private function service(): ExpandService
    {
        return new ExpandService(new ReachService());
    }

    /** Seed an approved OFFER present in messages_spatial; returns the message id. */
    private function seedSpatialPost(Carbon $arrival, float $lat = 51.5, float $lng = -0.1): int
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
            'lat' => $lat,
            'lng' => $lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => $arrival,
        ]);
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText(?, 3857), ?, ?, ?)",
            [$message->id, "POINT($lng $lat)", $group->id, Message::TYPE_OFFER, $arrival]
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

    /** Master switch off: process() is inert - no reach computed, nothing rippled in (ships dark). */
    public function test_process_is_inert_when_rippling_is_disabled(): void
    {
        config(['freegle.ripple.enabled' => false]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, $stats['initialized'], 'no reach is initialised while rippling is off');
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'no rippling_reach rows are written while rippling is off'
        );
    }

    /**
     * The group experiment runs with global rippling OFF: a SCOPED run (--within-poly / --within-group)
     * still initialises reach for the in-scope posts, so only those groups ripple while everyone else
     * stays dark. The unscoped path (test above) remains inert.
     */
    public function test_scoped_run_proceeds_when_globally_disabled(): void
    {
        config(['freegle.ripple.enabled' => false]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // origin (51.5, -0.1)

        // A polygon covering the post's origin (mirrors the experiment's group-union scope).
        $poly = 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))';
        $stats = $this->service()->process(false, 500, null, $poly);

        $this->assertSame(1, $stats['initialized'], 'a scoped run initialises reach even while global rippling is off');
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'the in-scope post gets a rippling_reach row despite the global switch being off'
        );
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

    public function test_enabled_at_cutoff_excludes_posts_that_arrived_before_it(): void
    {
        $this->fakeRouting(3);
        // Go-live cutoff is "1 hour ago": a post from 2 hours ago is pre-cutoff and
        // must be left alone; a post from 30 minutes ago is post-cutoff and ripples.
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $oldMsgid = $this->seedSpatialPost(now()->subHours(2));
        $newMsgid = $this->seedSpatialPost(now()->subMinutes(30));

        $stats = $this->service()->process(false, 500);

        $this->assertSame(1, $stats['initialized'], 'only the post-cutoff post is initialised');
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $oldMsgid)->count(),
            'a post that arrived before the cutoff never starts rippling'
        );
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $newMsgid)->count(),
            'a post that arrived after the cutoff ripples normally'
        );
    }

    /**
     * Controlled single-message test run: passing $onlyMsgid restricts the whole run to that one
     * post, so exactly one reach row is written and every other eligible post is left untouched.
     */
    public function test_only_msgid_restricts_run_to_a_single_post(): void
    {
        $this->fakeRouting(3);
        $targetMsgid = $this->seedSpatialPost(now()->subMinutes(30));
        $otherMsgid = $this->seedSpatialPost(now()->subMinutes(30));

        $stats = $this->service()->process(false, 500, $targetMsgid);

        $this->assertSame(1, $stats['initialized'], 'only the targeted post is initialised');
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $targetMsgid)->count(),
            'the targeted post gets a reach row'
        );
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $otherMsgid)->count(),
            'a non-targeted eligible post is left untouched by a --msgid run'
        );
    }

    /**
     * The arrival cutoff is bypassed for a --msgid run, so a chosen post that predates go-live
     * still ripples (otherwise the test would silently select nothing).
     */
    public function test_only_msgid_bypasses_the_arrival_cutoff(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $oldMsgid = $this->seedSpatialPost(now()->subHours(2)); // pre-cutoff

        $stats = $this->service()->process(false, 500, $oldMsgid);

        $this->assertSame(1, $stats['initialized'], 'a targeted pre-cutoff post still ripples');
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $oldMsgid)->count(),
            '--msgid overrides the go-live arrival cutoff'
        );
    }

    /**
     * Area test: passing a WKT polygon restricts the run to posts whose origin point falls
     * inside it. A post in London ripples; an otherwise-identical post in Edinburgh does not.
     */
    public function test_within_poly_restricts_run_to_posts_inside_polygon(): void
    {
        $this->fakeRouting(3);
        $london = $this->seedSpatialPost(now()->subMinutes(30), 51.5, -0.1);
        $edinburgh = $this->seedSpatialPost(now()->subMinutes(30), 55.95, -3.19);

        // Box around London only.
        $poly = 'POLYGON((-0.5 51.3, 0.3 51.3, 0.3 51.8, -0.5 51.8, -0.5 51.3))';
        $stats = $this->service()->process(false, 500, null, $poly);

        $this->assertSame(1, $stats['initialized'], 'only the in-polygon post is initialised');
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $london)->count(),
            'the post inside the polygon ripples'
        );
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $edinburgh)->count(),
            'the post outside the polygon is left untouched'
        );
    }

    /**
     * An area run still RESPECTS the arrival cutoff (the polygon filters where, not when): a
     * pre-cutoff post inside the polygon is left alone, while a post-cutoff one inside ripples.
     */
    public function test_within_poly_respects_the_arrival_cutoff(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $old = $this->seedSpatialPost(now()->subHours(2), 51.5, -0.1);   // pre-cutoff, in London
        $new = $this->seedSpatialPost(now()->subMinutes(30), 51.5, -0.1); // post-cutoff, in London

        $poly = 'POLYGON((-0.5 51.3, 0.3 51.3, 0.3 51.8, -0.5 51.8, -0.5 51.3))';
        $stats = $this->service()->process(false, 500, null, $poly);

        $this->assertSame(1, $stats['initialized'], 'only the post-cutoff post in the area ripples');
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $old)->count(),
            'a pre-cutoff post is left alone even inside the area'
        );
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $new)->count(),
            'a post-cutoff post inside the area ripples'
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

    public function test_advance_reapplies_secondary_reject_clip(): void
    {
        // A secondary group rejected this post; ClipReachForRejectedGroup recorded the group
        // in rejected_groups. When advanceDue rewrites polygon from the cached (clip-unaware)
        // schedule, it must re-subtract the rejected group so the rejection survives the tick.
        $msgid = $this->seedSpatialPost(now()->subHours(7));
        $group = $this->createTestGroup();
        // Rejected group's area = the EASTERN half of the schedule polygon (lng -0.15..-0.10).
        DB::statement(
            "UPDATE `groups` SET polyindex = ST_GeomFromText(
                'POLYGON((-0.15 51.49,-0.10 51.49,-0.10 51.61,-0.15 51.61,-0.15 51.49))', 3857)
             WHERE id = ?",
            [$group->id]
        );

        $ticksJson = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'wkt' => self::WKT],
            ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'wkt' => self::WKT],
            ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'wkt' => self::WKT],
        ]);
        // Stuck at tick 1, overdue, with the rejected group recorded.
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, rejected_groups, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ?, 'drive', 1, 3, 90, 30, ?, ?, 'expanding', ?, NOW(), NOW())",
            [$msgid, self::WKT, now()->subHours(7), $ticksJson, now()->subHours(4), json_encode([(int) $group->id])]
        );
        Http::fake();

        $this->service()->process(false, 500);

        // Tick advanced (proves the schedule polygon was rewritten)...
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(3, (int) $row->tick);
        // ...yet a point inside the rejected group's eastern area is NO LONGER covered,
        // while the western origin area still is — the clip survived the overwrite.
        $covers = fn (float $lng, float $lat): int => (int) DB::selectOne(
            'SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT(?, ?), 3857)), 0) AS c
             FROM rippling_reach WHERE msgid = ?',
            [$lng, $lat, $msgid]
        )->c;
        $this->assertSame(0, $covers(-0.12, 51.55), 'rejected eastern area stays clipped after the tick');
        $this->assertSame(1, $covers(-0.18, 51.55), 'western origin area is still covered after the tick');
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

        // Rippled into B carrying the post's msgtype (else it is invisible to type-filtered
        // browse once approved — addApprovedMessage copies messages_groups.msgtype). With
        // rippled_in_pending_hours=0 (default) it is approved AT ripple-in time, so it never
        // flickers into the Pending mod queue.
        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($b, 'post rippled into group B whose area the reach covers');
        $this->assertSame('Approved', $b->collection);
        $this->assertNotNull($b->approvedat, 'approved at ripple-in time carries approvedat');
        $this->assertSame(Message::TYPE_OFFER, $b->msgtype);
        $this->assertGreaterThanOrEqual(1, $stats['rippled_in']);
        // §15/§16: the ripple-in is also recorded in the event metrics.
        $this->assertGreaterThanOrEqual(1, (int) DB::table('rippling_event_metrics')
            ->where('day', now()->toDateString())->where('event', 'rippled_in')->value('count'));

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

    /**
     * With ripple.rippled_in_pending_hours > 0 the rippled-in row is inserted Pending, so
     * AutoApproveService approves it after the mod-veto window (rather than at ripple-in).
     */
    public function test_positive_window_inserts_rippled_in_as_pending(): void
    {
        config(['freegle.ripple.rippled_in_pending_hours' => 2]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);

        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($b, 'post rippled into group B');
        $this->assertSame('Pending', $b->collection, 'a positive window leaves the rippled-in row Pending for the mod-veto');
    }

    /**
     * When the isochrone covers >=90% of the origin group's polyindex the stored
     * polygon must cover the whole group area (union applied).
     */
    public function test_union_with_origin_group_area_when_isochrone_covers_90_percent(): void
    {
        // The fake routing polygon is POLYGON((-0.10...-0.20, 51.50...51.60)), a 0.10° × 0.10° box.
        // The origin group's area is a slightly smaller box fully inside it — so the isochrone
        // covers 100% of the group area (>= 90%), triggering the union.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // Set the origin group's polyindex to a box fully contained within the fake reach polygon.
        $originGid = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');
        // Inner box: lng -0.18..-0.12, lat 51.52..51.58 — entirely inside the reach.
        $groupPolyWkt = 'POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))';
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, 3857) WHERE id = ?',
            [$groupPolyWkt, $originGid]
        );

        $this->service()->process(false, 500);

        // The stored polygon must contain a point inside the group area.
        $containsGroupPoint = (int) DB::selectOne(
            'SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT(-0.15, 51.55), 3857)), 0) AS c
             FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->c;
        $this->assertSame(1, $containsGroupPoint, 'stored polygon covers a point inside the origin group area');

        // The stored polygon must also contain a point outside the group but inside the reach,
        // confirming the union (not just the group area alone) was stored.
        $containsReachPoint = (int) DB::selectOne(
            'SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT(-0.105, 51.505), 3857)), 0) AS c
             FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->c;
        $this->assertSame(1, $containsReachPoint, 'stored polygon also covers the original reach beyond the group');
    }

    /**
     * A test/playground group (nameshort LIKE '%playground%') must never be rippled into
     * even when the reach polygon fully covers its area.
     */
    public function test_playground_group_is_not_rippled_into(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // Playground group: nameshort contains 'playground', area fully inside the reach.
        $uniqueId = uniqid('', true);
        $playgroundGroup = Group::create([
            'nameshort' => 'FreeglePlayground_' . $uniqueId,
            'namefull'  => 'Freegle Playground ' . $uniqueId,
            'type'      => Group::TYPE_FREEGLE,
            'region'    => 'TestRegion',
            'lat'       => 51.55,
            'lng'       => -0.15,
            'onhere'    => 1,
            'publish'   => 1,
        ]);
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, 3857) WHERE id = ?',
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', $playgroundGroup->id]
        );

        // Normal group with same area — must ripple in (confirms the reach does cover the area).
        $normalGroup = $this->createTestGroup();
        DB::statement(
            'UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, 3857) WHERE id = ?',
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', $normalGroup->id]
        );

        $stats = $this->service()->process(false, 500);

        // The normal group is rippled into — proves the reach covers the area.
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $normalGroup->id)->first(),
            'normal group within reach is rippled into'
        );
        $this->assertGreaterThanOrEqual(1, $stats['rippled_in']);

        // The playground group must NOT be rippled into despite the reach covering its area.
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $playgroundGroup->id)->first(),
            'playground group is never rippled into even when the reach covers it'
        );
    }

    /**
     * A TN post (tnpostid IS NOT NULL AND tnpostid != '') must never be rippled into
     * new groups. TN still cross-posts the same item to multiple Freegle groups itself,
     * so rippling in would duplicate the post across even more groups.
     */
    public function test_tn_post_is_not_rippled_into_new_groups(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // Mark the message as a TN post.
        DB::table('messages')->where('id', $msgid)->update(['tnpostid' => 'TN12345']);

        // Group whose area intersects the fake reach — would normally get the post.
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, $stats['rippled_in'], 'TN post must not be rippled into any new group');
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'TN post is not inserted into a new group via rippling'
        );
    }

    /**
     * A non-TN post (tnpostid IS NULL) must be rippled into new groups normally.
     * This is the baseline confirming the guard only fires for TN posts.
     */
    public function test_non_tn_post_null_tnpostid_is_rippled_into_new_groups(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // tnpostid is NULL by default (not a TN post).
        $this->assertNull(
            DB::table('messages')->where('id', $msgid)->value('tnpostid'),
            'pre-condition: tnpostid is null'
        );

        // Group whose area intersects the fake reach.
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['rippled_in'], 'non-TN post is rippled into the new group');
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'non-TN post (tnpostid NULL) is inserted into the intersecting group'
        );
    }

    /**
     * A post with tnpostid = '' (empty string) is treated as non-TN and must be
     * rippled into new groups normally. The guard is `tnpostid != ''` so an empty
     * string does not trigger it.
     */
    public function test_empty_tnpostid_is_treated_as_non_tn_and_ripples(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // tnpostid = '' → not a real TN post per the canonical detection rule.
        DB::table('messages')->where('id', $msgid)->update(['tnpostid' => '']);

        // Group whose area intersects the fake reach.
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['rippled_in'], 'empty-tnpostid post is rippled in (not TN)');
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post with empty tnpostid is inserted into the intersecting group'
        );
    }

    public function test_poster_is_added_as_member_of_rippled_into_group_immediate_downgraded_to_daily(): void
    {
        // When a post ripples into a new group the poster becomes a member of it (Member /
        // Approved, rippled=1). Email settings follow the home group EXCEPT immediate (-1) is
        // downgraded to daily (24) so an unrequested membership never floods the inbox; events
        // and volunteering are copied verbatim.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');
        $originGid = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');

        // Home-group membership on IMMEDIATE with events/volunteering off, to prove the downgrade
        // (-1 -> 24) and the verbatim copy of events/volunteering.
        DB::table('memberships')->insert([
            'userid' => $posterId, 'groupid' => $originGid, 'role' => 'Member',
            'collection' => 'Approved', 'emailfrequency' => -1, 'eventsallowed' => 0,
            'volunteeringallowed' => 0, 'added' => now(),
        ]);

        // Group B: area intersects the fake reach.
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $stats = $this->service()->process(false, 500);

        // Post rippled into B...
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post rippled into group B'
        );

        // ...and the poster is now a Member/Approved of B, marked rippled, with immediate downgraded
        // to daily and events/volunteering copied from home.
        $m = DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($m, 'poster added as a member of the rippled-into group');
        $this->assertSame('Member', $m->role);
        $this->assertSame('Approved', $m->collection);
        $this->assertSame(24, (int) $m->emailfrequency, 'immediate (-1) downgraded to daily (24)');
        $this->assertSame(0, (int) $m->eventsallowed, 'eventsallowed copied from home group');
        $this->assertSame(0, (int) $m->volunteeringallowed, 'volunteeringallowed copied from home group');
        $this->assertSame(1, (int) $m->rippled, 'rippled membership marked rippled=1');
        $this->assertGreaterThanOrEqual(1, $stats['memberships_added']);

        // memberships_history row written (abuse detection) with rippled=1 (welcome suppression).
        $hist = DB::table('memberships_history')->where('userid', $posterId)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($hist, 'memberships_history row recorded for the new membership');
        $this->assertSame(1, (int) $hist->rippled, 'history row marked rippled=1 to suppress the per-group welcome');
        $this->assertSame(1, (int) $hist->processingrequired, 'history row still requires processing for abuse detection');

        // The intro email is claimed exactly once for the post.
        $this->assertSame(
            1,
            (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('ripple_intro_sent'),
            'bundled intro email claimed once for the post'
        );

        // A Group/Joined log is written with the rippling-specific reason, so the join is
        // audited and distinguishable from a button click ('Manual') or other auto-join ('Auto').
        $this->assertDatabaseHas('logs', [
            'type' => 'Group',
            'subtype' => 'Joined',
            'user' => $posterId,
            'groupid' => $groupB->id,
            'text' => 'Rippled',
        ]);
    }

    public function test_rippling_does_not_overwrite_an_existing_banned_membership(): void
    {
        // A poster already Banned on a group the post ripples into must stay Banned — we
        // never silently convert a ban into a normal membership.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        DB::table('memberships')->insert([
            'userid' => $posterId, 'groupid' => $groupB->id, 'role' => 'Member',
            'collection' => 'Banned', 'added' => now(),
        ]);

        $this->service()->process(false, 500);

        $m = DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first();
        $this->assertSame('Banned', $m->collection, 'existing banned membership left untouched');
    }

    /** A no-email home setting (0) is preserved on the rippled membership - never forced to daily. */
    public function test_no_email_home_setting_is_preserved_on_rippled_membership(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');
        $originGid = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');

        // Home membership on NO email (emailfrequency 0).
        DB::table('memberships')->insert([
            'userid' => $posterId, 'groupid' => $originGid, 'role' => 'Member',
            'collection' => 'Approved', 'emailfrequency' => 0, 'eventsallowed' => 1,
            'volunteeringallowed' => 1, 'added' => now(),
        ]);

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);

        $m = DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($m, 'poster added as member of rippled-into group');
        $this->assertSame(0, (int) $m->emailfrequency, 'no-email (0) home setting preserved, not forced to daily');
    }

    /**
     * A poster who has actively LEFT a group (Group/Left log) is never re-joined AND their post is
     * never (re-)rippled into that group - rippling must not override an explicit departure.
     */
    public function test_left_group_is_not_rejoined_and_post_not_rippled_in(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // The poster previously left group B (any reason writes Group/Left).
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        $this->service()->process(false, 500);

        $this->assertNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first(),
            'poster who left B is not re-joined by rippling'
        );
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post is not rippled into a group the poster has left'
        );
    }

    /** Leaving a group the post rippled into pulls the post from that group (soft-delete + log). */
    public function test_leaving_a_rippled_group_pulls_the_post(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // First run: post ripples into B, poster auto-joined.
        $this->service()->process(false, 500);
        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($row, 'post rippled into B');
        $this->assertSame(0, (int) $row->deleted, 'rippled-in row live before leave');

        // Poster leaves B (Go leave path: delete membership + Group/Left log).
        DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->delete();
        DB::table('logs')->insert([
            'timestamp' => now(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        // Second run: reconciliation pulls the post from B.
        $stats = $this->service()->process(false, 500);

        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertSame(1, (int) $row->deleted, 'post soft-deleted from the group the poster left');
        $this->assertGreaterThanOrEqual(1, $stats['pulled_on_leave']);
        $this->assertDatabaseHas('logs', [
            'type' => 'Message', 'subtype' => 'Deleted', 'user' => $posterId,
            'groupid' => $groupB->id, 'msgid' => $msgid, 'text' => 'Rippling: removed on leave',
        ]);
    }

    /** The bundled intro email is claimed exactly once per post, even as more groups are added. */
    public function test_intro_email_claimed_once_per_post_across_groups(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);
        $this->assertSame(1, (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('ripple_intro_sent'));

        // A second group appears on a later tick; make the post due for a HIGHER tick (advanceDue
        // only ripples when the target tick exceeds the current one). Back-date arrival to ~4h so
        // tickForElapsedHours advances it past tick 1. The poster joins C but the intro must NOT
        // be re-claimed.
        $groupC = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.17 51.53,-0.13 51.53,-0.13 51.57,-0.17 51.57,-0.17 51.53))', 3857, $groupC->id]
        );
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'arrival' => now()->subHours(4),
            'next_expansion_at' => now()->subMinute(),
            'status' => 'expanding',
        ]);

        $this->service()->process(false, 500);
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupC->id)->first(),
            'poster joined the second rippled-into group'
        );
        $this->assertSame(
            1,
            (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('ripple_intro_sent'),
            'intro stays claimed once - not re-sent when more groups are added'
        );
    }

    /** The bundled intro email carries each rippled-into community's own welcome text. */
    public function test_intro_email_includes_rippled_group_welcome_text(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');
        // The poster needs a usable address for the intro to be sent.
        $this->createTestUserEmail(\App\Models\User::find($posterId));

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, onhere = 1, welcomemail = ?, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['Welcome to our lovely community! Please be kind.',
             'POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\Ripple\RippleIntroMail::class, function ($mail) use ($posterId) {
            return $mail->user->id === $posterId
                && collect($mail->welcomeGroups)->contains(
                    fn ($g) => str_contains($g['welcome'] ?? '', 'lovely community')
                );
        });
    }

    /** Seed $count DISTINCT users each leaving an Interested chat reply on the post. */
    private function seedInterestedRepliers(int $msgid, int $count): void
    {
        $poster = User::find((int) DB::table('messages')->where('id', $msgid)->value('fromuser'));
        for ($i = 0; $i < $count; $i++) {
            $replier = $this->createTestUser();
            $room = $this->createTestChatRoom($replier, $poster);
            $this->createTestChatMessage($room, $replier, [
                'type' => ChatMessage::TYPE_INTERESTED,
                'refmsgid' => $msgid,
            ]);
        }
    }

    /**
     * Reply-saturation stop (extent-governor T1.1): a post that already has the threshold number
     * of distinct repliers (5) has enough interest, so it never starts rippling.
     */
    public function test_saturated_post_does_not_start_rippling(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $this->seedInterestedRepliers($msgid, 5);

        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, $stats['initialized'], 'a post at the saturation threshold never starts rippling');
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'no rippling_reach row for an already-saturated post'
        );
    }

    /** A post below the saturation threshold ripples normally. */
    public function test_post_below_saturation_threshold_still_ripples(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $this->seedInterestedRepliers($msgid, 4);

        $stats = $this->service()->process(false, 500);

        $this->assertSame(1, $stats['initialized'], 'a post below the threshold ripples normally');
    }

    /** A post that crosses the saturation threshold mid-expansion stops expanding. */
    public function test_post_that_becomes_saturated_stops_expanding(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // First pass: reach is initialised and expanding.
        $this->service()->process(false, 500);
        $this->assertSame(
            'expanding',
            DB::table('rippling_reach')->where('msgid', $msgid)->value('status'),
            'reach is expanding before saturation'
        );

        // The post now saturates and its next tick falls due.
        $this->seedInterestedRepliers($msgid, 5);
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['next_expansion_at' => now()->subMinute()]);

        $stats = $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame('done', $row->status, 'a post crossing the saturation threshold stops expanding');
        $this->assertNull($row->next_expansion_at, 'a saturated post is not rescheduled');
        $this->assertGreaterThanOrEqual(1, $stats['completed']);
    }

    /**
     * computeSchedule is deterministic per blurred origin, so posts sharing an origin hit the
     * routing server only ONCE: initialiseNew de-dups origins before fanning the compute out.
     * Every post still gets its own reach row (the shared schedule is applied per post).
     */
    public function test_dedups_routing_calls_for_posts_sharing_a_blurred_origin(): void
    {
        $this->fakeRouting(3);

        // Two posts at the SAME origin -> one blurred origin -> one routing call.
        $a = $this->seedSpatialPost(now()->subMinutes(30), 51.5, -0.1);
        $b = $this->seedSpatialPost(now()->subMinutes(30), 51.5, -0.1);
        // A third at a DIFFERENT origin -> a second routing call.
        $c = $this->seedSpatialPost(now()->subMinutes(30), 52.2, -1.5);

        $stats = $this->service()->process(false, 500);

        // All three posts are initialised with their own reach rows...
        $this->assertSame(3, $stats['initialized']);
        $this->assertSame(3, DB::table('rippling_reach')->whereIn('msgid', [$a, $b, $c])->count());

        // ...but only TWO distinct origins were sent to the routing server (the two same-origin
        // posts shared one /v1/ripple-schedule call).
        $scheduleCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'ripple-schedule'))
            ->count();
        $this->assertSame(2, $scheduleCalls, 'same-origin posts dedup to a single routing call');
    }
}
