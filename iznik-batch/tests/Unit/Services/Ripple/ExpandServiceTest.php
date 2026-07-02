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
use Tests\Support\SeedsSpatialIndex;
use Tests\TestCase;

class ExpandServiceTest extends TestCase
{
    use SeedsSpatialIndex;

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

    /**
     * Fakes the routing server's /v1/group-proximity response used by
     * ReachService::groupProximity(). Layers on top of any Http::fake() already
     * registered (e.g. fakeRouting()'s '*ripple-schedule*' stub) rather than
     * replacing it.
     */
    private function fakeGroupProximity(
        bool $reachable,
        bool $quicker = true,
        float $pLat = 0,
        float $pLng = 0,
        float $qLat = 0,
        float $qLng = 0,
        int $status = 200
    ): void {
        if ($status !== 200) {
            Http::fake(['*group-proximity*' => Http::response('', $status)]);
            return;
        }
        if (!$reachable) {
            Http::fake(['*group-proximity*' => Http::response(['reachable' => false], 200)]);
            return;
        }
        Http::fake(['*group-proximity*' => Http::response([
            'reachable' => true,
            'closest' => ['lat' => $pLat, 'lng' => $pLng, 'drive_min' => 4.0],
            'furthest' => ['lat' => $qLat, 'lng' => $qLng, 'drive_min' => 22.0],
            'quicker' => $quicker,
        ], 200)]);
    }

    /**
     * Seeds a postcode location (with an associated area location it points to via
     * areaid) into both the test DB (for Location::describeNearest's by-id enrich)
     * and the spatial server's live "postcodes" KNN index — mirrors
     * LocationTest::seedPostcode, extended with an area row so describeNearest()'s
     * "POSTCODE (Area)" branch is exercised.
     */
    private function seedPostcodeWithArea(int $id, string $name, float $lat, float $lng, int $areaId, string $areaName): void
    {
        $srid = (int) config('freegle.srid', 3857);
        DB::table('locations')->updateOrInsert(['id' => $areaId], [
            'name' => $areaName,
            'type' => 'Point',
            'lat' => $lat,
            'lng' => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);
        DB::table('locations')->updateOrInsert(['id' => $id], [
            'name' => $name,
            'type' => 'Postcode',
            'areaid' => $areaId,
            'lat' => $lat,
            'lng' => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);
        $this->seedSpatialPoint('postcodes', $id, $lat, $lng);
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

    /**
     * BUG FIX: blurOrigin can snap a post's origin onto a DISCONNECTED routing node (a driveway
     * stub / isolated segment) whose drive-isochrone reaches almost nothing, so the blurred origin
     * returns an EMPTY schedule and the post is skipped on EVERY run. Because the blur is
     * deterministic this is permanent - ~16% of live candidates were stranded this way. initialiseNew
     * must fall back to the post's RAW origin (geocoded onto the connected network) so it still ripples.
     */
    public function test_blurred_origin_off_graph_falls_back_to_raw_origin(): void
    {
        $rawLat = 51.5;
        $rawLng = -0.1;
        $msgid = $this->seedSpatialPost(now()->subMinutes(30), $rawLat, $rawLng);

        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $validSchedule = [['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 30, 'polygon' => $polygon]];

        // Routing returns an EMPTY schedule for the (off-graph) blurred origin but a valid one for
        // the raw coordinates. initialiseNew hits the blurred origin first, gets nothing, and must
        // retry with the raw origin (which differs by ~0.0006 lat / ~0.006 lng for this point).
        Http::fake(function ($request) use ($rawLat, $rawLng, $validSchedule) {
            if (! str_contains($request->url(), 'ripple-schedule')) {
                return Http::response([], 200);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $isRaw = abs((float) ($q['lat'] ?? 0) - $rawLat) < 3e-4 && abs((float) ($q['lng'] ?? 0) - $rawLng) < 3e-4;

            return Http::response($isRaw
                ? ['total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $validSchedule]
                : ['total_freeglers' => 0, 'max_drive_min' => 30, 'schedule' => []], 200);
        });

        $stats = $this->service()->process(false, 500);

        $this->assertSame(1, $stats['initialized'], 'post ripples via raw-origin fallback when the blurred origin is off-graph');
        $this->assertSame(0, $stats['skipped'], 'not skipped once the raw origin is tried');
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row, 'a reach row is written');
        // The stored origin is the RAW location (the fallback path), not the off-graph blurred point.
        $this->assertEqualsWithDelta($rawLat, (float) $row->lat, 3e-4);
        $this->assertEqualsWithDelta($rawLng, (float) $row->lng, 3e-4);
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
     * Task #23: after a post rippled in, ripple:proximity-notes (out of the hot expander) resolves
     * quicker=true from /v1/group-proximity + the P/Q postcodes and stores the note in
     * rippling_proximity. The expander itself no longer computes or stores the note.
     */
    public function test_ripple_proximity_note_set_when_quicker(): void
    {
        // Far-out-at-sea coordinates (well beyond the KNN's 0.32° buffer from any real
        // postcode, per LocationTest) so the seeded P/Q are unambiguously nearest.
        $pId = 99100001;
        $qId = 99100002;
        $pAreaId = 99100011;
        $qAreaId = 99100012;
        $pLat = 58.000;
        $pLng = 1.000;
        $qLat = 58.500;
        $qLng = 1.500;

        $this->fakeRouting(1);
        $this->fakeGroupProximity(true, true, $pLat, $pLng, $qLat, $qLng);
        $this->seedPostcodeWithArea($pId, 'AB10 1XG', $pLat, $pLng, $pAreaId, 'Gilcomston');
        $this->seedPostcodeWithArea($qId, 'AB11 5QN', $qLat, $qLng, $qAreaId, 'Aberdeen');

        try {
            $msgid = $this->seedSpatialPost(now()->subMinutes(30));
            $groupB = $this->createTestGroup();
            DB::statement(
                "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
                ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
            );

            $this->service()->process(false, 500);
            // The note is computed out-of-band, not by the expander.
            $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
            $this->assertNotNull($b, 'post rippled into group B');
            $this->artisan('ripple:proximity-notes')->assertExitCode(0);

            $note = DB::table('rippling_proximity')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
            $this->assertNotNull($note, 'proximity note written for the rippled-in copy');
            $this->assertSame('AB10 1XG (Gilcomston)', $note->p);
            $this->assertSame('AB11 5QN (Aberdeen)', $note->q);
        } finally {
            $this->removeSpatial('postcodes', [$pId, $qId]);
            DB::table('locations')->whereIn('id', [$pId, $qId, $pAreaId, $qAreaId])->delete();
        }
    }

    /** quicker=false from /v1/group-proximity → no rippling_proximity row is written. */
    public function test_ripple_proximity_note_omitted_when_not_quicker(): void
    {
        $this->fakeRouting(1);
        $this->fakeGroupProximity(true, false, 58.0, 1.0, 58.5, 1.5);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post still rippled into group B'
        );
        $this->artisan('ripple:proximity-notes')->assertExitCode(0);

        $this->assertNull(
            DB::table('rippling_proximity')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'not quicker - no note'
        );
    }

    /**
     * reachable=false from /v1/group-proximity (group outside the routing horizon) → no note, and
     * both the expander run AND the notes command complete normally.
     */
    public function test_ripple_proximity_note_omitted_when_group_proximity_unreachable(): void
    {
        $this->fakeRouting(1);
        $this->fakeGroupProximity(false);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $stats = $this->service()->process(false, 500);
        $this->assertGreaterThanOrEqual(1, $stats['rippled_in'], 'ripple-in still counted');
        $this->assertSame(0, $stats['errors'], 'expander no longer calls proximity, so no error either way');
        $this->artisan('ripple:proximity-notes')->assertExitCode(0);

        $this->assertNull(
            DB::table('rippling_proximity')->where('msgid', $msgid)->where('groupid', $groupB->id)->first()
        );
    }

    /**
     * An HTTP 500 from /v1/group-proximity must never break ripple:proximity-notes: it writes no
     * note for that row and exits cleanly (best-effort, so a slow/erroring routing server is safe).
     */
    public function test_ripple_proximity_note_never_breaks_on_http_failure(): void
    {
        $this->fakeRouting(1);
        $this->fakeGroupProximity(true, status: 500);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $this->service()->process(false, 500);
        $this->artisan('ripple:proximity-notes')->assertExitCode(0); // must not throw on the 500

        $this->assertNull(
            DB::table('rippling_proximity')->where('msgid', $msgid)->where('groupid', $groupB->id)->first()
        );
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

    /**
     * A poster banned from a group (users_banned row) must NOT have their post rippled into it,
     * nor be re-joined to it. A ban is an explicit mod ejection; rippling must not silently undo it.
     */
    public function test_post_is_not_rippled_into_a_group_the_poster_is_banned_from(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        // Group B's area intersects the reach — it would ripple in but for the ban.
        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // The poster is banned from group B (authoritative users_banned record, no membership row).
        DB::table('users_banned')->insert([
            'userid' => $posterId, 'groupid' => $groupB->id, 'byuser' => null, 'date' => now(),
        ]);

        $this->service()->process(false, 500);

        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post not rippled into a group the poster is banned from'
        );
        $this->assertNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first(),
            'banned poster not re-joined to the group'
        );
    }

    /** A legacy collection='Banned' membership also blocks the post rippling into that group. */
    public function test_post_is_not_rippled_into_a_group_with_a_banned_collection_membership(): void
    {
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

        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post not rippled into a group where the poster has a Banned-collection membership'
        );
    }

    /**
     * Backfill: pullBannedRippleMemberships removes ripple-join memberships into groups the member
     * is banned from and soft-deletes their rippled-in posts there. Dry run writes nothing.
     */
    public function test_backfill_removes_ripple_joins_for_banned_users(): void
    {
        $poster = $this->createTestUser();
        $groupB = $this->createTestGroup();

        // A rippled-in post copy of the poster's message, live in group B.
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $poster->id,
            'subject' => 'OFFER: lamp', 'textbody' => 'A lamp.', 'source' => 'Platform',
            'date' => now()->subDay(), 'arrival' => now()->subDay(), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $groupB->id, 'collection' => 'Approved',
            'arrival' => now()->subDay(), 'rippled_in' => 1, 'deleted' => 0,
        ]);
        // The ripple-join membership and the ban that should have blocked it.
        DB::table('memberships')->insert([
            'userid' => $poster->id, 'groupid' => $groupB->id, 'role' => 'Member',
            'collection' => 'Approved', 'rippled' => 1, 'added' => now()->subDay(),
        ]);
        DB::table('users_banned')->insert([
            'userid' => $poster->id, 'groupid' => $groupB->id, 'byuser' => null, 'date' => now()->subDays(2),
        ]);

        // Dry run changes nothing.
        $dry = ['pairs' => 0, 'memberships_removed' => 0, 'posts_pulled' => 0];
        $this->service()->pullBannedRippleMemberships(null, 1000, true, $dry);
        $this->assertSame(1, $dry['pairs']);
        $this->assertSame(1, $dry['memberships_removed']);
        $this->assertSame(1, $dry['posts_pulled']);
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $poster->id)->where('groupid', $groupB->id)->first(),
            'dry run leaves the membership in place'
        );
        $this->assertSame(0, (int) DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $groupB->id)->value('deleted'),
            'dry run leaves the post live');

        // Commit removes the membership and pulls the post.
        $stats = ['pairs' => 0, 'memberships_removed' => 0, 'posts_pulled' => 0];
        $this->service()->pullBannedRippleMemberships(null, 1000, false, $stats);
        $this->assertSame(1, $stats['pairs']);
        $this->assertSame(1, $stats['memberships_removed']);
        $this->assertSame(1, $stats['posts_pulled']);
        $this->assertNull(
            DB::table('memberships')->where('userid', $poster->id)->where('groupid', $groupB->id)->first(),
            'ripple-join membership removed for the banned user'
        );
        $this->assertSame(1, (int) DB::table('messages_groups')
            ->where('msgid', $message->id)->where('groupid', $groupB->id)->value('deleted'),
            'rippled-in post soft-deleted');
    }

    /** Backfill never touches an organic (rippled=0) membership, even if the user is banned. */
    public function test_backfill_leaves_organic_membership_of_banned_user(): void
    {
        $poster = $this->createTestUser();
        $groupB = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid' => $poster->id, 'groupid' => $groupB->id, 'role' => 'Member',
            'collection' => 'Approved', 'rippled' => 0, 'added' => now()->subDay(),
        ]);
        DB::table('users_banned')->insert([
            'userid' => $poster->id, 'groupid' => $groupB->id, 'byuser' => null, 'date' => now()->subDays(2),
        ]);

        $stats = ['pairs' => 0, 'memberships_removed' => 0, 'posts_pulled' => 0];
        $this->service()->pullBannedRippleMemberships(null, 1000, false, $stats);

        $this->assertSame(0, $stats['pairs'], 'organic membership is not a ripple-join and is skipped');
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $poster->id)->where('groupid', $groupB->id)->first(),
            'organic membership left untouched'
        );
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
     * A poster who was RIPPLED into a group (Group/Joined, text='Rippled') and then LEFT it is
     * never re-joined AND their post is never (re-)rippled in: leaving a group you were rippled
     * into is the opt-out signal rippling must respect.
     */
    public function test_rippled_in_then_left_is_not_rejoined_and_post_not_rippled_in(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // The poster was rippled into B (Group/Joined, text='Rippled') and then LEFT it. The
        // Joined/Rippled log is inserted first so it has the lower id (the leave post-dates it).
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(2), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $posterId, 'groupid' => $groupB->id, 'text' => 'Rippled',
        ]);
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        $this->service()->process(false, 500);

        $this->assertNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first(),
            'poster rippled into B then left is not re-joined by rippling'
        );
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post is not (re-)rippled into a group the poster was rippled into then left'
        );
    }

    /**
     * BUG FIX: a poster who left a group they were NOT rippled into (an ordinary/manual membership
     * they later left) IS still rippled in. Only a rippled-in-then-left opt-out blocks rippling -
     * an unrelated prior departure must not bar the post or the membership.
     */
    public function test_ordinary_left_does_not_block_rippling(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // Poster joined B manually (text='Manual') and later left - no rippled-in history at all.
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(2), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $posterId, 'groupid' => $groupB->id, 'text' => 'Manual',
        ]);
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        $this->service()->process(false, 500);

        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post IS rippled into a group the poster only ordinarily left'
        );
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first(),
            'poster IS re-added as a member (an ordinary prior leave does not block rippling)'
        );
    }

    /**
     * "Most recent join wins": a poster rippled into B, left, then MANUALLY rejoined and left again
     * is NOT blocked - their last join was ordinary, so the rippled opt-out no longer applies and
     * the post is rippled back in. Only a group whose MOST RECENT join was a ripple-join (then left)
     * stays barred.
     */
    public function test_manual_rejoin_after_rippled_left_allows_rippling(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // History oldest -> newest (insertion order = ascending log id):
        // rippled in -> left -> MANUALLY rejoined -> left again. The latest Joined is 'Manual'.
        $events = [
            ['Joined', 'Rippled', 40],
            ['Left', null, 30],
            ['Joined', 'Manual', 20],
            ['Left', null, 10],
        ];
        foreach ($events as [$subtype, $text, $minsAgo]) {
            DB::table('logs')->insert([
                'timestamp' => now()->subMinutes($minsAgo), 'type' => 'Group', 'subtype' => $subtype,
                'user' => $posterId, 'groupid' => $groupB->id, 'text' => $text,
            ]);
        }

        $this->service()->process(false, 500);

        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first(),
            'post IS rippled in - the most recent join was manual, not a ripple opt-out'
        );
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->first(),
            'poster IS re-added (most-recent-join-wins: the last join was ordinary)'
        );
    }

    /**
     * BUG FIX (pull side): a freshly rippled-in post is NOT pulled just because the poster has an
     * ordinary Group/Left log that pre-dates the ripple-in. Without this, a post rippling into a
     * group the poster once normally-left would be added and then immediately pulled back out.
     */
    public function test_ordinary_left_does_not_pull_a_freshly_rippled_post(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // An ordinary leave of B, recorded BEFORE the post ripples in (so its log id is lower than
        // the Joined/Rippled log process() writes at ripple-in time).
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        // Run 1 ripples the post into B (writes the Joined/Rippled log). Run 2 exercises the
        // pull-on-leave reconciliation, which runs at the start of a process() pass.
        $this->service()->process(false, 500);
        $this->service()->process(false, 500);

        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($row, 'post rippled into B despite the ordinary prior leave');
        $this->assertSame(0, (int) $row->deleted, 'freshly rippled-in post is NOT pulled by a pre-existing ordinary leave');
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

    /**
     * Regression (Discourse rippling-out #176/#179, msg 117580503): the group experiment runs
     * SCOPED (global rippling off). A post rippled into B while its origin group was in the trial;
     * the poster then LEFT B; later the origin group was REMOVED from the trial, so the post's
     * origin fell OUTSIDE the current scope union. The leave-retraction must STILL pull the copy -
     * cleanup of a committed rippled_in artifact is not gated by the current area scope, otherwise
     * the post is stranded in a group the poster explicitly opted out of.
     */
    public function test_leave_retraction_is_not_gated_by_current_area_scope(): void
    {
        config(['freegle.ripple.enabled' => false]); // mirror production: scoped experiment only
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // origin (51.5, -0.1) London
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        // Origin group still in the trial: scope union COVERS the origin -> post ripples into B,
        // poster auto-joined (Group/Joined text='Rippled').
        $coveringScope = 'POLYGON((-0.30 51.40,0.10 51.40,0.10 51.70,-0.30 51.70,-0.30 51.40))';
        $this->service()->process(false, 500, null, $coveringScope);
        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($row, 'precondition: post rippled into B');
        $this->assertSame(0, (int) $row->deleted, 'precondition: rippled-in row live before leave');

        // Poster leaves B (Go leave path: delete membership + Group/Left log).
        DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB->id)->delete();
        DB::table('logs')->insert([
            'timestamp' => now(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB->id,
        ]);

        // Origin group REMOVED from the trial: the scope union no longer covers the post's origin
        // (a far-away Edinburgh polygon). Retraction must still run.
        $nonCoveringScope = 'POLYGON((-3.30 55.90,-3.10 55.90,-3.10 56.00,-3.30 56.00,-3.30 55.90))';
        $stats = $this->service()->process(false, 500, null, $nonCoveringScope);

        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertSame(1, (int) $row->deleted,
            'rippled copy must be pulled on leave even though the origin is outside the current trial scope');
        $this->assertGreaterThanOrEqual(1, $stats['pulled_on_leave']);
        $this->assertDatabaseHas('logs', [
            'type' => 'Message', 'subtype' => 'Deleted', 'user' => $posterId,
            'groupid' => $groupB->id, 'msgid' => $msgid, 'text' => 'Rippling: removed on leave',
        ]);
    }

    /**
     * Companion to the leave case: when the origin post leaves the browsable set (withdrawn / taken
     * / deleted) AFTER its origin group has left the trial, removeStaleAndRetract must still drop the
     * reach and retract the rippled copies - it is no longer area-scoped, so an out-of-scope origin
     * does not leave stale reach rows and orphaned copies behind.
     */
    public function test_stale_origin_retraction_is_not_gated_by_current_area_scope(): void
    {
        config(['freegle.ripple.enabled' => false]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // origin (51.5, -0.1) London
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');

        $groupB = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $groupB->id]
        );

        $coveringScope = 'POLYGON((-0.30 51.40,0.10 51.40,0.10 51.70,-0.30 51.70,-0.30 51.40))';
        $this->service()->process(false, 500, null, $coveringScope);
        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count(), 'precondition: reach exists');
        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertNotNull($b, 'precondition: post rippled into B');
        $this->assertSame(0, (int) $b->deleted, 'precondition: rippled-in row live');

        // The post leaves the browsable set (withdrawn/taken/deleted -> gone from messages_spatial).
        DB::table('messages_spatial')->where('msgid', $msgid)->delete();

        // Origin group removed from the trial: scope no longer covers the origin.
        $nonCoveringScope = 'POLYGON((-3.30 55.90,-3.10 55.90,-3.10 56.00,-3.30 56.00,-3.30 55.90))';
        $stats = $this->service()->process(false, 500, null, $nonCoveringScope);

        $this->assertSame(0, DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'reach dropped even though the origin is outside the current trial scope');
        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB->id)->first();
        $this->assertSame(1, (int) $b->deleted,
            'rippled copy retracted on origin removal regardless of the current area scope');
        $this->assertGreaterThanOrEqual(1, $stats['removed']);
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

    /** Seed a terminal outcome (Taken/Received/Withdrawn) on a post. */
    private function seedOutcome(int $msgid, string $outcome): void
    {
        DB::table('messages_outcomes')->insert([
            'msgid' => $msgid,
            'outcome' => $outcome,
            'timestamp' => now(),
        ]);
    }

    /** Make a covering group whose polyindex intersects the fake reach polygon. */
    private function seedCoveringGroup(): int
    {
        $group = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.18 51.52,-0.12 51.52,-0.12 51.58,-0.18 51.58,-0.18 51.52))', 3857, $group->id]
        );

        return (int) $group->id;
    }

    /**
     * BUG FIX: the exact reported failure. A post with a terminal outcome (here Received) that is
     * STILL in messages_spatial (messages:update-spatial-index lags the outcome) must never be
     * rippled into a covering group. The outcome is checked directly against messages_outcomes, not
     * inferred from the spatial index, so the lag window cannot leak a taken post into new groups.
     */
    public function test_taken_post_still_in_spatial_is_never_rippled_in(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // still in messages_spatial
        $this->seedOutcome($msgid, \App\Models\MessageOutcome::OUTCOME_RECEIVED);
        $groupB = $this->seedCoveringGroup();

        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, $stats['rippled_in'], 'a post with a terminal outcome is never rippled in, even while still spatial');
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->first(),
            'post with a terminal outcome is not inserted into a covering group via the tick-0 init ripple'
        );
    }

    /**
     * A post that becomes Taken mid-expansion stops expanding (status done, not rescheduled) and is
     * not rippled into a group whose area its reach now covers - mirrors the saturation-stop, but
     * driven by the outcome. Covers advanceDue's outcome-stop on a scoped-or-unscoped tick where
     * removeStale's spatial cleanup has not yet caught up.
     */
    public function test_post_taken_mid_expansion_stops_expanding(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        // First pass: reach initialised and expanding (no covering group yet → nothing rippled in).
        $this->service()->process(false, 500);
        $this->assertSame(
            'expanding',
            DB::table('rippling_reach')->where('msgid', $msgid)->value('status'),
            'reach is expanding before the outcome'
        );

        // The post is now Taken, a covering group appears, and its next tick falls due.
        $this->seedOutcome($msgid, \App\Models\MessageOutcome::OUTCOME_TAKEN);
        $groupB = $this->seedCoveringGroup();
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'arrival' => now()->subHours(4),
            'next_expansion_at' => now()->subMinute(),
            'status' => 'expanding',
        ]);

        $stats = $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame('done', $row->status, 'a taken post stops expanding');
        $this->assertNull($row->next_expansion_at, 'a taken post is not rescheduled');
        $this->assertGreaterThanOrEqual(1, $stats['completed']);
        $this->assertNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->first(),
            'a taken post is not rippled into a new group even though its reach now covers it'
        );
    }

    /**
     * 'Repost' is NOT a terminal outcome - a reposted item is still active and must ripple normally.
     * Guards against the outcome check over-reaching to any messages_outcomes row.
     */
    public function test_repost_outcome_is_not_terminal_and_post_still_ripples(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $this->seedOutcome($msgid, \App\Models\MessageOutcome::OUTCOME_REPOST);
        $groupB = $this->seedCoveringGroup();

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['rippled_in'], 'a Repost outcome does not stop rippling');
        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->first(),
            'post with only a Repost outcome still ripples into a covering group'
        );
    }

    // ── Stop-and-retract on origin removal (rejected/withdrawn/expired → left spatial) ──

    /**
     * Ripple a fresh post into one covering group via the real engine and return
     * [msgid, posterId, groupBId]. Afterwards the post is live on its origin group AND
     * rippled (rippled_in=1, deleted=0) into group B, with the poster auto-joined to B
     * (memberships.rippled=1) and a Group/Joined text='Rippled' log written.
     */
    private function rippleIntoOneGroup(): array
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $posterId = (int) DB::table('messages')->where('id', $msgid)->value('fromuser');
        $groupB = $this->seedCoveringGroup();

        $this->service()->process(false, 500);

        $row = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->first();
        $this->assertNotNull($row, 'precondition: post rippled into B');
        $this->assertSame(0, (int) $row->deleted, 'precondition: rippled-in copy live');

        return [$msgid, $posterId, $groupB];
    }

    /** The post is removed from the browsable set (rejected on origin / withdrawn): it leaves messages_spatial. */
    private function leaveSpatial(int $msgid): void
    {
        DB::table('messages_spatial')->where('msgid', $msgid)->delete();
    }

    /** A post with a reach row but NOT in messages_spatial, with one rippled-in copy + ripple-membership on a fresh group. Returns [msgid, groupB, posterId]. */
    private function seedStaleReachWithRippledCopy(float $lat = 51.5, float $lng = -0.1): array
    {
        $user = $this->createTestUser();
        $origin = $this->createTestGroup();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: stale', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subHours(2), 'arrival' => now()->subHours(2), 'lat' => $lat, 'lng' => $lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id, 'groupid' => $origin->id,
            'collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subHours(2),
        ]);
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $groupB->id, 'collection' => 'Approved',
            'approvedat' => now(), 'arrival' => now(), 'autoreposts' => 0,
            'msgtype' => Message::TYPE_OFFER, 'rippled_in' => 1, 'deleted' => 0,
        ]);
        DB::table('memberships')->insert([
            'userid' => $user->id, 'groupid' => $groupB->id, 'role' => 'Member', 'collection' => 'Approved',
            'emailfrequency' => 24, 'eventsallowed' => 1, 'volunteeringallowed' => 1, 'rippled' => 1, 'added' => now(),
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ST_GeomFromText(?, 3857), ?, 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $lat, $lng, self::WKT, now()->subHours(2)]
        );

        return [(int) $message->id, (int) $groupB->id, (int) $user->id];
    }

    /** Origin removal pulls every rippled-in copy (soft-delete + Message/Deleted log) and drops the reach row. */
    public function test_origin_removal_pulls_rippled_copies_and_drops_reach(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();
        $this->assertSame(1, (int) DB::table('rippling_reach')->where('msgid', $msgid)->count(), 'precondition: reach row exists');

        $this->leaveSpatial($msgid);
        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $msgid)->count(), 'reach row dropped - expansion stopped');
        $b = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->first();
        $this->assertSame(1, (int) $b->deleted, 'rippled-in copy pulled on origin removal');
        $this->assertGreaterThanOrEqual(1, $stats['pulled_on_removal']);
        $this->assertDatabaseHas('logs', [
            'type' => 'Message', 'subtype' => 'Deleted', 'user' => $posterId,
            'groupid' => $groupB, 'msgid' => $msgid, 'text' => 'Rippling: removed on origin removal',
        ]);
    }

    /** The poster's ripple-membership is removed when the retracted post was their only post on that group. */
    public function test_origin_removal_removes_ripple_membership_when_no_other_post(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();
        $m = DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->first();
        $this->assertNotNull($m, 'precondition: poster ripple-joined to B');
        $this->assertSame(1, (int) $m->rippled);

        $this->leaveSpatial($msgid);
        $stats = $this->service()->process(false, 500);

        $this->assertNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->first(),
            'ripple-membership removed when the retracted post was the poster\'s only post on the group'
        );
        $this->assertGreaterThanOrEqual(1, $stats['memberships_removed']);
    }

    /** The membership is KEPT when the poster still has another live post on the group. */
    public function test_origin_removal_keeps_membership_when_poster_has_another_live_post(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();

        // A second, live post by the same poster directly on group B.
        $other = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $posterId,
            'subject' => 'OFFER: other', 'textbody' => 'y', 'source' => 'Platform',
            'date' => now(), 'arrival' => now(), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        MessageGroup::create([
            'msgid' => $other->id, 'groupid' => $groupB,
            'collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now(),
        ]);

        $this->leaveSpatial($msgid);
        $this->service()->process(false, 500);

        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->first(),
            'membership kept because the poster still has another live post on the group'
        );
        $this->assertSame(
            1,
            (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->value('deleted'),
            'the retracted post is still pulled from the group'
        );
    }

    /** An organic (non-ripple) membership is never removed by retraction. */
    public function test_origin_removal_keeps_organic_membership(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();
        DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->update(['rippled' => 0]);

        $this->leaveSpatial($msgid);
        $this->service()->process(false, 500);

        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->first(),
            'organic (rippled=0) membership is never removed by retraction'
        );
    }

    /**
     * Membership removal writes NO Group/Left log (which would poison the re-ripple guard),
     * so a later post by the same poster still ripples into the same group and re-adds the membership.
     */
    public function test_origin_removal_logs_no_group_left_and_future_reripple_works(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();

        $this->leaveSpatial($msgid);
        $this->service()->process(false, 500);

        $this->assertSame(
            0,
            (int) DB::table('logs')->where('user', $posterId)->where('groupid', $groupB)
                ->where('type', 'Group')->where('subtype', 'Left')->count(),
            'system retraction must not log a Group/Left (it is not a user opt-out)'
        );

        // A later post by the SAME poster, in the covering area, must still ripple into B.
        $newMsg = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $posterId,
            'subject' => 'OFFER: later', 'textbody' => 'z', 'source' => 'Platform',
            'date' => now()->subMinutes(10), 'arrival' => now()->subMinutes(10), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        $newOrigin = $this->createTestGroup();
        MessageGroup::create([
            'msgid' => $newMsg->id, 'groupid' => $newOrigin->id,
            'collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()->subMinutes(10),
        ]);
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, ?, ?)",
            [$newMsg->id, $newOrigin->id, Message::TYPE_OFFER, now()->subMinutes(10)]
        );

        $this->service()->process(false, 500);

        $this->assertNotNull(
            DB::table('messages_groups')->where('msgid', $newMsg->id)->where('groupid', $groupB)->first(),
            'a later post by the same poster still ripples into B (retraction did not poison re-ripple)'
        );
        $this->assertNotNull(
            DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->first(),
            'membership re-added by the later ripple'
        );
    }

    // ── Retract rippled copies when the HOME post is deleted / moved back to pending ──
    // A mod Delete or Back-to-Pending on the origin group leaves the rippled-in copies live
    // and Approved elsewhere, so the post still has messages_spatial rows and the
    // spatial-IS-NULL trigger in removeStaleAndRetract never fires - the copies are stranded.

    /**
     * A post with a live rippled-in copy on group B (+ ripple membership + reach + a
     * messages_spatial row, so it is NOT stale-by-spatial), whose ORIGIN row is in $originState:
     *   'deleted'  - origin messages_groups row hard-deleted (mod Delete on home)
     *   'pending'  - origin row collection=Pending (mod Back to Pending on home)
     *   'approved' - origin row still live Approved (control)
     * Returns [msgid, groupB, posterId].
     */
    private function seedRippledCopyWithOrigin(string $originState, float $lat = 51.5, float $lng = -0.1): array
    {
        $user = $this->createTestUser();
        $origin = $this->createTestGroup();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: home-removed', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subHours(2), 'arrival' => now()->subHours(2), 'lat' => $lat, 'lng' => $lng,
        ]);
        if ($originState !== 'deleted') {
            MessageGroup::create([
                'msgid' => $message->id, 'groupid' => $origin->id,
                'collection' => $originState === 'pending'
                    ? MessageGroup::COLLECTION_PENDING
                    : MessageGroup::COLLECTION_APPROVED,
                'arrival' => now()->subHours(2),
            ]);
        }
        $groupB = $this->createTestGroup();
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $groupB->id, 'collection' => 'Approved',
            'approvedat' => now(), 'arrival' => now(), 'autoreposts' => 0,
            'msgtype' => Message::TYPE_OFFER, 'rippled_in' => 1, 'deleted' => 0,
        ]);
        DB::table('memberships')->insert([
            'userid' => $user->id, 'groupid' => $groupB->id, 'role' => 'Member', 'collection' => 'Approved',
            'emailfrequency' => 24, 'eventsallowed' => 1, 'volunteeringallowed' => 1, 'rippled' => 1, 'added' => now(),
        ]);
        // A messages_spatial row (the live rippled copy has one) so the post is NOT stale-by-spatial.
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText(?, 3857), ?, ?, ?)",
            [$message->id, "POINT($lng $lat)", $groupB->id, Message::TYPE_OFFER, now()]
        );
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ST_GeomFromText(?, 3857), ?, 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $lat, $lng, self::WKT, now()->subHours(2)]
        );

        return [(int) $message->id, (int) $groupB->id, (int) $user->id];
    }

    /** Deleting the post on its home community pulls the rippled-in copies left live elsewhere. */
    public function test_delete_on_home_group_retracts_rippled_copies(): void
    {
        Http::fake();
        [$msgid, $groupB, $posterId] = $this->seedRippledCopyWithOrigin('deleted');
        // Precondition: still in spatial, so the existing spatial-null retraction cannot be what fires.
        $this->assertSame(1, (int) DB::table('messages_spatial')->where('msgid', $msgid)->count());

        $this->service()->process(false, 500);

        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->value('deleted'),
            'rippled-in copy pulled after the home post was deleted');
        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'reach dropped so the deleted post stops spreading');
        $this->assertDatabaseHas('logs', [
            'type' => 'Message', 'subtype' => 'Deleted', 'user' => $posterId,
            'groupid' => $groupB, 'msgid' => $msgid, 'text' => 'Rippling: removed on origin removal',
        ]);
    }

    /** Moving the post back to pending on its home community pulls the rippled-in copies. */
    public function test_back_to_pending_on_home_group_retracts_rippled_copies(): void
    {
        Http::fake();
        [$msgid, $groupB] = $this->seedRippledCopyWithOrigin('pending');

        $this->service()->process(false, 500);

        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->value('deleted'),
            'rippled-in copy pulled after the home post was moved back to pending');
        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $msgid)->count());
    }

    /** A post still live+approved on its home community keeps its rippled-in copies. */
    public function test_live_home_post_keeps_rippled_copies(): void
    {
        Http::fake();
        [$msgid, $groupB] = $this->seedRippledCopyWithOrigin('approved');

        $this->service()->process(false, 500);

        $this->assertSame(0, (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->value('deleted'),
            'a live approved home post must not have its rippled copies retracted');
        $this->assertSame(1, (int) DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'reach kept for a live post');
    }

    /** A scoped (onlyMsgid) run retracts only the in-scope post, leaving others untouched. */
    public function test_scoped_only_msgid_retracts_only_in_scope_post(): void
    {
        Http::fake();
        [$m1, $g1] = $this->seedStaleReachWithRippledCopy();
        [$m2, $g2] = $this->seedStaleReachWithRippledCopy();

        $this->service()->process(false, 500, $m1);

        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $m1)->count(), 'in-scope reach dropped');
        $this->assertSame(1, (int) DB::table('rippling_reach')->where('msgid', $m2)->count(), 'out-of-scope reach untouched');
        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $m1)->where('groupid', $g1)->value('deleted'), 'in-scope copy pulled');
        $this->assertSame(0, (int) DB::table('messages_groups')->where('msgid', $m2)->where('groupid', $g2)->value('deleted'), 'out-of-scope copy untouched');
    }

    /**
     * The withinPolyWkt area scope limits EXPANSION only, NOT retraction: a scoped run retracts
     * EVERY stale post, including one whose origin is outside the polygon. (Previously this asserted
     * the out-of-polygon post was left untouched - that area-scoped retraction stranded rippled
     * copies when a group was removed from the trial; see the two _not_gated_by_current_area_scope
     * tests and Discourse rippling-out #176/#179.) Area-scoped expansion is still covered by
     * test_within_poly_restricts_run_to_posts_inside_polygon.
     */
    public function test_scoped_within_poly_does_not_limit_retraction(): void
    {
        Http::fake();
        [$london] = $this->seedStaleReachWithRippledCopy(51.5, -0.1);
        [$edinburgh] = $this->seedStaleReachWithRippledCopy(55.95, -3.19);

        $poly = 'POLYGON((-0.5 51.3, 0.3 51.3, 0.3 51.8, -0.5 51.8, -0.5 51.3))';
        $this->service()->process(false, 500, null, $poly);

        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $london)->count(), 'London post (inside polygon) retracted');
        $this->assertSame(0, (int) DB::table('rippling_reach')->where('msgid', $edinburgh)->count(), 'Edinburgh post (outside polygon) ALSO retracted - cleanup is not area-scoped');
    }

    /** The poster-leaves-a-rippled-group pull also runs under a scoped run (was dark during the experiment). */
    public function test_pull_on_leave_runs_under_scoped_run(): void
    {
        [$msgid, $posterId, $groupB] = $this->rippleIntoOneGroup();

        // Poster leaves B (Go leave path: delete membership + Group/Left log).
        DB::table('memberships')->where('userid', $posterId)->where('groupid', $groupB)->delete();
        DB::table('logs')->insert([
            'timestamp' => now(), 'type' => 'Group', 'subtype' => 'Left',
            'user' => $posterId, 'groupid' => $groupB,
        ]);

        // Scoped run (the post is still in spatial, so only the leave-pull should act).
        $stats = $this->service()->process(false, 500, $msgid);

        $this->assertSame(
            1,
            (int) DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupB)->value('deleted'),
            'leave-pull runs under a scoped run'
        );
        $this->assertGreaterThanOrEqual(1, $stats['pulled_on_leave']);
    }

    /** recomputeReach is a no-op unless the audience cap is actually enabled. */
    public function test_recompute_reach_is_noop_when_cap_disabled(): void
    {
        config(['freegle.ripple.extent.enabled' => false]);
        config(['freegle.ripple.extent.target_users' => 50]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $this->service()->process(false, 500); // creates reach (total_freeglers = 90)

        $r = $this->service()->recomputeReach(false, 500);

        $this->assertSame(0, $r['candidates'], 'cap disabled -> nothing considered');
        $this->assertSame(
            90,
            (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('total_freeglers'),
            'reach left untouched'
        );
    }

    /** An over-reached post is shrunk to the capped schedule, with updated_at preserved (no re-mail). */
    public function test_recompute_reach_shrinks_over_reached_post_preserving_updated_at(): void
    {
        // A SINGLE request-aware fake that mimics the routing server: it spreads the
        // curve over min(pool, target_users) when target_users is sent. (Two separate
        // Http::fake() calls would NOT work — Laravel accumulates stubs and the first
        // registered match wins, so a later "capped" stub never overrides the first.)
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $total = 90;
            $cap = (int) ($q['target_users'] ?? 0);
            $eff = ($cap > 0 && $cap < $total) ? $cap : $total;
            $poly = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]]];
            $sched = [];
            foreach ([1, 2, 3] as $k) {
                $sched[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => (int) round($eff * $k / 3), 'polygon' => $poly];
            }
            return Http::response(['total_freeglers' => $total, 'max_drive_min' => 30, 'schedule' => $sched], 200);
        });

        // 1) Create reach with the cap OFF -> stored uncapped (pool 90, cumulative 30/60/90).
        config(['freegle.ripple.extent.enabled' => false]);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // 0.5h -> tick 1
        $this->service()->process(false, 500);

        $this->assertSame(90, (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('total_freeglers'));
        // Pin updated_at to a known past value to prove the recompute preserves it.
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['updated_at' => '2020-01-01 00:00:00']);

        // 2) Turn the cap ON and recompute -> the fake now returns a capped schedule
        //    (target_users=50 -> cumulative 17/33/50), so the reach shrinks.
        config(['freegle.ripple.extent.enabled' => true]);
        config(['freegle.ripple.extent.target_users' => 50]);

        $r = $this->service()->recomputeReach(false, 500);

        $this->assertSame(1, $r['candidates']);
        $this->assertSame(1, $r['shrunk']);
        $after = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $sched = json_decode($after->schedule, true);
        $last = end($sched);
        $this->assertSame(50, (int) $last['cumulative_users'], 'stored schedule now caps at 50');
        $this->assertSame('2020-01-01 00:00:00', (string) $after->updated_at, 'updated_at preserved (no reach-mail trigger)');

        // Crosspost-breadth stat is reported and the cap never widens reach.
        $this->assertArrayHasKey('groups_before', $r);
        $this->assertArrayHasKey('groups_after', $r);
        $this->assertGreaterThanOrEqual($r['groups_after'], $r['groups_before']);
    }

    /** Out-of-reach retraction pulls only the far copy; the post + near copy + organic stay. */
    public function test_retract_out_of_reach_pulls_only_far_copies(): void
    {
        $srid = 3857;
        $poster = $this->createTestUser();
        $origin = $this->createTestGroup();
        $near = $this->createTestGroup();
        $far = $this->createTestGroup();

        // near overlaps the reach square (0 0,1 1); far is disjoint.
        DB::statement("UPDATE `groups` SET polyindex = ST_GeomFromText('POLYGON((0.5 0.5,0.5 1.5,1.5 1.5,1.5 0.5,0.5 0.5))', $srid) WHERE id = ?", [$near->id]);
        DB::statement("UPDATE `groups` SET polyindex = ST_GeomFromText('POLYGON((10 10,10 11,11 11,11 10,10 10))', $srid) WHERE id = ?", [$far->id]);

        $msg = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $poster->id,
            'subject' => 'OFFER: chair', 'textbody' => 'a chair', 'source' => 'Platform',
            'date' => now(), 'arrival' => now(),
        ]);
        foreach ([[$origin->id, 0], [$near->id, 1], [$far->id, 1]] as [$gid, $ri]) {
            DB::table('messages_groups')->insert([
                'msgid' => $msg->id, 'groupid' => $gid, 'collection' => 'Approved',
                'arrival' => now(), 'rippled_in' => $ri, 'deleted' => 0,
            ]);
        }
        foreach ([$near->id, $far->id] as $gid) {
            DB::table('memberships')->insert([
                'userid' => $poster->id, 'groupid' => $gid, 'role' => 'Member',
                'collection' => 'Approved', 'rippled' => 1,
            ]);
        }
        // Capped reach polygon = unit square at origin (overlaps near, not far).
        DB::statement(
            "INSERT INTO rippling_reach (msgid,lat,lng,polygon,arrival,mode,tick,total_ticks,total_freeglers,max_drive_min,schedule,next_expansion_at,status,created_at,updated_at)
             VALUES (?,?,?,ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))',$srid),?,?,?,?,?,?,?,?,?,NOW(),NOW())",
            [$msg->id, 0.5, 0.5, now(), 'drive', 1, 1, 5000, 10, json_encode([]), null, 'expanding']
        );

        $stats = [];
        $n = $this->service()->retractOutOfReachCopies($msg->id, false, $stats);

        $this->assertSame(1, $n, 'exactly the one far copy is retracted');
        $this->assertSame(0, (int) DB::table('messages_groups')->where('msgid', $msg->id)->where('groupid', $near->id)->value('deleted'), 'near (in-reach) copy kept');
        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $msg->id)->where('groupid', $far->id)->value('deleted'), 'far (out-of-reach) copy retracted');
        $this->assertSame(0, (int) DB::table('messages_groups')->where('msgid', $msg->id)->where('groupid', $origin->id)->value('deleted'), 'native copy untouched');
        $this->assertDatabaseHas('messages', ['id' => $msg->id]); // the message itself stays
        $this->assertSame(1, DB::table('memberships')->where('userid', $poster->id)->where('groupid', $near->id)->count(), 'near ripple-membership kept');
        $this->assertSame(0, DB::table('memberships')->where('userid', $poster->id)->where('groupid', $far->id)->count(), 'far ripple-membership removed');
    }

    // ── ripple:backfill (ExpandService::backfill) ─────────────────────────────────────────────
    //
    // The go-live arrival cutoff (freegle.ripple.enabled_at) leaves every post that arrived
    // before go-live without a reach row, so it would never ripple. backfill() lifts ONLY that
    // cutoff and reuses initialiseNew, seeding those still-live posts identically to new ones.

    /**
     * The gap the backfill fixes: a live post whose messages_spatial.arrival predates the go-live
     * cutoff is excluded by initialiseNew (so process() seeds nothing), but backfill() — which lifts
     * the cutoff — seeds it, and the seeded row is a normal reach row (polygon, tick, status).
     */
    public function test_backfill_seeds_reach_for_a_live_post_predating_go_live(): void
    {
        $this->fakeRouting(3);
        // Go-live was an hour ago; this post arrived two hours ago (pre-cutoff).
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $msgid = $this->seedSpatialPost(now()->subHours(2));

        // The normal path leaves it alone (proves the gap the backfill exists to close).
        $this->service()->process(false, 500);
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'the normal cron never seeds a pre-go-live post'
        );

        $stats = $this->service()->backfill(false, 500);

        $this->assertSame(1, $stats['initialized'], 'backfill seeds the pre-go-live post');
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row, 'a reach row is written by the backfill');
        $this->assertContains($row->status, ['expanding', 'done']);
        $this->assertSame(3, (int) $row->total_ticks);
        $this->assertSame(
            'POLYGON',
            DB::selectOne('SELECT ST_GeometryType(polygon) AS t FROM rippling_reach WHERE msgid = ?', [$msgid])->t
        );
    }

    /**
     * Idempotent/resumable: a second backfill run seeds nothing new and leaves the existing reach
     * row untouched (it is skipped by the same LEFT JOIN rippling_reach ... IS NULL the live path
     * uses), so the command can be re-run until the backlog is drained.
     */
    public function test_backfill_is_idempotent_and_leaves_existing_rows_untouched(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $msgid = $this->seedSpatialPost(now()->subHours(2));

        $first = $this->service()->backfill(false, 500);
        $this->assertSame(1, $first['initialized']);
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->first();

        $second = $this->service()->backfill(false, 500);

        $this->assertSame(0, $second['initialized'], 'a second run seeds nothing new');
        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'still exactly one reach row for the post'
        );
        $after = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertEquals($before->updated_at, $after->updated_at, 'the existing reach row is not rewritten');
        $this->assertEquals($before->tick, $after->tick);
    }

    /**
     * Same eligibility rules as the live path: a pre-go-live post that is already reply-saturated
     * (>= threshold distinct Interested repliers) has enough interest and is NOT seeded by the
     * backfill — it applies initialiseNew's saturation stop just like the normal cron.
     */
    public function test_backfill_respects_reply_saturation_eligibility(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $msgid = $this->seedSpatialPost(now()->subHours(2));
        $this->seedInterestedRepliers($msgid, 5); // at the saturation threshold

        $stats = $this->service()->backfill(false, 500);

        $this->assertSame(0, $stats['initialized'], 'a saturated post is skipped by the backfill');
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'no reach row for an already-saturated post, even in a backfill'
        );
    }

    /**
     * Gated like process(): while global rippling is off an UNSCOPED backfill is inert (seeds
     * nothing), matching the master activation switch. (A within-poly scope is still allowed
     * through — that path is the experiment case and is covered by the scoped process() tests.)
     */
    public function test_backfill_is_inert_when_rippling_disabled_and_unscoped(): void
    {
        config(['freegle.ripple.enabled' => false]);
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $msgid = $this->seedSpatialPost(now()->subHours(2));

        $stats = $this->service()->backfill(false, 500);

        $this->assertSame(0, $stats['initialized'], 'no seeding while global rippling is off');
        $this->assertSame(0, DB::table('rippling_reach')->where('msgid', $msgid)->count());
    }

    /**
     * Repost re-qualification (forward-looking, no code change needed — this proves the existing
     * mechanism). A pre-go-live post is excluded by the arrival cutoff. When it is auto-reposted,
     * AutoRepostService bumps messages_groups.arrival to NOW(); the messages:update-spatial-index
     * cron (MessageSpatialService) then refreshes messages_spatial.arrival to match, which now
     * satisfies initialiseNew's `ms.arrival >= enabled_at` gate — so the next ripple:expand tick
     * seeds a reach row with no backfill involved. This test exercises that whole chain with the
     * REAL spatial-refresh service.
     */
    public function test_reposted_pre_go_live_post_gets_reach_after_spatial_refresh(): void
    {
        // fakeRouting stubs the ripple-schedule endpoint; any other HTTP (the spatial-admin
        // removeItems call in updateSpatialIndex) falls through to Laravel's default no-op 200
        // while a fake is active. Do NOT add a catch-all Http::fake() before this: a catch-all
        // registered first shadows the ripple-schedule stub (first matching stub wins), so the
        // reach computation gets an empty response, no schedule, and nothing is seeded.
        $this->fakeRouting(3);
        // Cutoff is one hour ago; the post arrived two hours ago (pre-cutoff).
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $msgid = $this->seedSpatialPost(now()->subHours(2));

        // Control: the normal cron does not seed the pre-cutoff post.
        $this->service()->process(false, 500);
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'pre-cutoff post has no reach before it is reposted'
        );

        // Simulate the auto-repost's essential DB effect (AutoRepostService::repost): bump
        // messages_groups.arrival to NOW(). messages_spatial.arrival is still the old value.
        DB::table('messages_groups')->where('msgid', $msgid)->update([
            'arrival' => now(),
            'autoreposts' => DB::raw('autoreposts + 1'),
        ]);

        // The spatial-index cron refreshes messages_spatial.arrival from messages_groups.arrival
        // (upsertRecentMessages re-writes any row whose arrival differs). Use the REAL service.
        app(\App\Services\MessageSpatialService::class)->updateSpatialIndex(false);
        $this->assertTrue(
            (bool) DB::selectOne(
                'SELECT arrival >= ? AS ok FROM messages_spatial WHERE msgid = ?',
                [config('freegle.ripple.enabled_at'), $msgid]
            )->ok,
            'the repost pushed messages_spatial.arrival past the go-live cutoff'
        );

        // updateSpatialIndex() only refreshes messages_spatial; it does not create reach rows,
        // so the reposted post still has none until the expand tick below.
        $this->assertSame(
            0,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'no reach for the reposted post until the expand tick runs'
        );

        // Next expand tick now picks the reposted post up through the normal gate — no backfill.
        // Assert on THIS post's reach row, not the global initialised count: the REAL
        // updateSpatialIndex() above upserts every recent approved message it can see into
        // messages_spatial, and this suite runs in parallel (paratest) against one shared
        // iznik_batch_test database, so the number of other posts seeded on this tick is
        // non-deterministic. What this test actually proves is that the reposted (now
        // post-cutoff) post specifically ripples, which the msgid-scoped assertion below does.
        $this->service()->process(false, 500);

        $this->assertSame(
            1,
            DB::table('rippling_reach')->where('msgid', $msgid)->count(),
            'a reposted pre-go-live post ends up with a reach row via the arrival refresh'
        );
    }

    /**
     * Sharding partitions the candidate set by msgid % shards, so several backfill instances can
     * drain DISJOINT slices in parallel. A shard only seeds posts whose msgid maps to it; the
     * complementary shard seeds the rest. No post is seeded by the wrong shard, none is missed.
     */
    public function test_backfill_sharding_partitions_candidates_by_msgid(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $a = $this->seedSpatialPost(now()->subHours(2));
        $b = $this->seedSpatialPost(now()->subHours(2));
        $c = $this->seedSpatialPost(now()->subHours(2));

        // Shard 0 of 2 seeds only even msgids; shard 1 only odd.
        $this->service()->backfill(false, 500, null, 2, 0);
        foreach ([$a, $b, $c] as $msgid) {
            $this->assertSame(
                $msgid % 2 === 0 ? 1 : 0,
                DB::table('rippling_reach')->where('msgid', $msgid)->count(),
                "shard 0/2 seeds msgid {$msgid} iff it is even"
            );
        }

        // Shard 1 of 2 seeds the remainder; together the two shards cover everything exactly once.
        $this->service()->backfill(false, 500, null, 2, 1);
        foreach ([$a, $b, $c] as $msgid) {
            $this->assertSame(
                1,
                DB::table('rippling_reach')->where('msgid', $msgid)->count(),
                "both shards together seed msgid {$msgid} exactly once"
            );
        }
    }

    /** Insert a placeholder "DPA" reach seed (group-area polygon, no schedule) like the quick
     *  geometry pass lays down: status='stopped', schedule NULL — the recompute candidate shape. */
    private function seedDpaPlaceholder(int $msgid, float $lat, float $lng, Carbon $arrival): void
    {
        DB::insert(
            "INSERT INTO rippling_reach
                (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, total_freeglers,
                 status, schedule, next_expansion_at, created_at, updated_at)
             VALUES (?, ?, ?, ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857),
                     ?, 'drive', 0, 0, 0, 'stopped', NULL, NULL, NOW(), NOW())",
            [$msgid, $lat, $lng, $arrival]
        );
    }

    /**
     * Recompute: the placeholder (DPA) seed — status='stopped', schedule NULL — is upgraded to real
     * routed reach IN PLACE (upsert, so the post is never momentarily without a reach row). A plain
     * backfill leaves it alone (it is not in the anti-join candidate set); only --recompute touches it.
     */
    public function test_recompute_replaces_placeholder_dpa_seed_in_place(): void
    {
        $this->fakeRouting(3);
        $arrival = now()->subMinutes(30);
        $msgid = $this->seedSpatialPost($arrival); // origin (51.5, -0.1)
        $this->seedDpaPlaceholder($msgid, 51.5, -0.1, $arrival);
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNull($before->schedule, 'placeholder starts with no schedule');
        $this->assertSame('stopped', $before->status);

        // A plain (non-recompute) backfill must NOT touch a placeholder: the anti-join excludes it.
        $noop = $this->service()->backfill(false, 500);
        $this->assertSame(0, $noop['initialized'], 'plain backfill ignores placeholders');
        $this->assertNull(DB::table('rippling_reach')->where('msgid', $msgid)->value('schedule'));

        // Recompute upgrades it in place.
        $stats = $this->service()->backfill(false, 500, null, null, null, true);
        $this->assertSame(1, $stats['initialized'], 'recompute processes the placeholder');
        $after = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(1, DB::table('rippling_reach')->where('msgid', $msgid)->count(), 'still exactly one row (upsert)');
        $this->assertNotNull($after->schedule, 'placeholder now carries a real schedule');
        $this->assertContains($after->status, ['expanding', 'done']);
        $this->assertSame(3, (int) $after->total_ticks);
        $this->assertEquals($before->created_at, $after->created_at, 'created_at preserved — replaced in place, never deleted/reinserted');
    }

    /**
     * Reuse: reach is deterministic per blurred origin, so a co-located post copies an existing
     * computed reach instead of hitting the routing server again. Post B at the same origin as an
     * already-computed post A reuses A's schedule (stats['reused'] rises) and gets the same ticks.
     */
    public function test_recompute_reuses_a_colocated_reach_without_a_routing_call(): void
    {
        $this->fakeRouting(3);
        config(['freegle.ripple.enabled_at' => now()->subHour()->toDateTimeString()]);
        $arrival = now()->subMinutes(30);

        // Post A at origin L gets a real reach via a normal backfill (routing computed once).
        $a = $this->seedSpatialPost($arrival, 51.5, -0.1);
        $this->service()->backfill(false, 500);
        $aReach = DB::table('rippling_reach')->where('msgid', $a)->first();
        $this->assertNotNull($aReach->schedule, 'post A has a real reach');

        // Post B at the SAME raw origin, with a placeholder to recompute.
        $b = $this->seedSpatialPost($arrival, 51.5, -0.1);
        $this->seedDpaPlaceholder($b, 51.5, -0.1, $arrival);

        $stats = $this->service()->backfill(false, 500, null, null, null, true);

        $this->assertSame(1, $stats['initialized'], 'B is recomputed');
        $this->assertGreaterThanOrEqual(1, $stats['reused'], 'B reused a co-located reach rather than routing');
        $bReach = DB::table('rippling_reach')->where('msgid', $b)->first();
        $this->assertNotNull($bReach->schedule);
        $this->assertEquals(
            json_decode($aReach->schedule, true),
            json_decode($bReach->schedule, true),
            'B got the same reach schedule as the co-located post A'
        );
    }
}
