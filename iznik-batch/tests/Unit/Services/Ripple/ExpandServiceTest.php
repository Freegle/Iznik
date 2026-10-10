<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\Ripple\CellSetService;
use App\Services\Ripple\ExpandService;
use App\Services\Ripple\ReachService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsReachCells;
use Tests\Support\SeedsSpatialIndex;
use Tests\TestCase;

class ExpandServiceTest extends TestCase
{
    use SeedsReachCells;
    use SeedsSpatialIndex;

    private const WKT = 'POLYGON((-0.1 51.5, -0.2 51.5, -0.2 51.6, -0.1 51.6, -0.1 51.5))';

    /** A second, disjoint shape - distinct bytes, distinct hash, from WKT. */
    private const WKT2 = 'POLYGON((1.0 52.0, 1.3 52.0, 1.3 52.3, 1.0 52.3, 1.0 52.0))';

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
        DB::statement('DELETE FROM rippling_held_replies');
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
            'collection' => Message::COLLECTION_APPROVED,
        ]);
        DB::insert(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival)
             VALUES (?, ST_GeomFromText(?, 3857), ?, ?)",
            [$message->id, "POINT($lng $lat)", Message::TYPE_OFFER, $arrival]
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
        $this->fakeSpatialHttp(['*ripple-schedule*' => Http::response([
            'total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $schedule,
        ], 200)]);
    }

    /**
     * Stub the spatial server's nearest-freeglers lookup with $count people, the
     * furthest $miles away - i.e. dictate which density band the origin falls in.
     */
    private function fakeDensity(int $count, float $miles, float $lat = 51.5, float $lng = -0.1): void
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = ($miles * ($i + 1) / $count) / 69.05;
            $results[] = ['id' => $i + 1, 'extra' => ['lat' => $lat + $offset, 'lng' => $lng]];
        }
        $this->fakeSpatialHttp(['*userapproxlocs/knn*' => Http::response(['results' => $results], 200)]);
    }

    /** The max_minutes asked of the routing server on the first ripple-schedule call. */
    private function requestedMaxMinutes(): ?string
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'ripple-schedule')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);

                return $q['max_minutes'] ?? null;
            }
        }

        return null;
    }

    public function test_a_city_post_still_ripples_to_the_ceiling_so_rural_members_can_reach_it(): void
    {
        // The cap belongs to the person who would travel, not to the item. Sizing a city
        // post at its own 20 minutes means a rural member 38 minutes away can NEVER see
        // their nearest town's posts, at any setting - measured on live for member 488811
        // and Peterborough. So every post ripples to the ceiling and each member is held
        // to their own band by their travel-time preference on the way out.
        config(['freegle.ripple.density.enabled' => true, 'freegle.ripple.density.k' => 400]);
        $this->fakeDensity(400, 1.0);   // 400 freeglers within a mile: a city
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $this->service()->process(false, 500);

        $this->assertSame('45', $this->requestedMaxMinutes(), 'the routing server is asked for the ceiling');
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        // The origin band is still recorded - the density analytics read rows back by it -
        // but it describes where the post IS, not how far it goes.
        $this->assertSame('dense', $row->density_band);
        $this->assertSame(45.0, (float) $row->max_minutes_cap);
        $this->assertEqualsWithDelta(1.0, (float) $row->density_radius_miles, 0.05);
    }

    public function test_a_countryside_post_ripples_to_the_ceiling_too(): void
    {
        config(['freegle.ripple.density.enabled' => true, 'freegle.ripple.density.k' => 400]);
        $this->fakeDensity(400, 6.0);   // it takes six miles to find 400 people
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $this->service()->process(false, 500);

        $this->assertSame('45', $this->requestedMaxMinutes());
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame('sparse', $row->density_band);
        $this->assertSame(45.0, (float) $row->max_minutes_cap);
    }

    public function test_the_density_killswitch_puts_every_post_back_on_the_flat_cap(): void
    {
        // One switch has to undo the whole scheme: with density off there are no bands to
        // hold a member to, so a ripple grown to the ceiling would reach people nothing
        // narrows again.
        config([
            'freegle.ripple.density.enabled' => false,
            'freegle.ripple.max_minutes' => 30,
        ]);
        $this->fakeDensity(400, 6.0);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $this->service()->process(false, 500);

        $this->assertSame('30', $this->requestedMaxMinutes());
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame('unknown', $row->density_band);
    }

    public function test_an_unmeasurable_origin_still_reaches_the_ceiling_and_says_so_on_the_row(): void
    {
        // The measurement decides how the row READS, not how far it goes: an unreachable
        // spatial server must not shrink a post's reach, only leave its band unrecorded.
        config(['freegle.ripple.density.enabled' => true, 'freegle.ripple.max_minutes' => 30]);
        $this->fakeSpatialHttp(['*userapproxlocs/knn*' => Http::response('boom', 500)]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $this->service()->process(false, 500);

        $this->assertSame('45', $this->requestedMaxMinutes());
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame('unknown', $row->density_band, 'the row records that no measurement was made');
        $this->assertNull($row->density_radius_miles);
    }

    public function test_a_co_located_post_does_not_inherit_a_schedule_sized_under_a_different_cap(): void
    {
        // Schedule reuse is keyed on the blurred origin, which is not enough on its own:
        // the first post at an origin would fix the budget for everything that followed
        // there, and retuning the ceiling would only take effect where nobody had posted
        // before.
        config([
            'freegle.ripple.density.enabled' => true,
            'freegle.ripple.density.k' => 400,
            'freegle.ripple.reuse_reach' => true,
        ]);
        $this->fakeDensity(400, 1.0);   // dense: 20 minutes
        $this->fakeRouting(3);
        $this->seedSpatialPost(now()->subMinutes(90));
        $this->service()->process(false, 500);

        // The ceiling is retuned; a new post at the SAME origin must be recomputed.
        config(['freegle.ripple.density.max_minutes.sparse' => 50]);
        $this->fakeDensity(400, 1.0);
        $this->fakeRouting(3);
        $msgid2 = $this->seedSpatialPost(now()->subMinutes(90));
        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid2)->first();
        $this->assertSame(50.0, (float) $row->max_minutes_cap, 'recomputed under the new ceiling, not reused');
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
     * An area experiment runs with global rippling OFF: a SCOPED run (--within-poly)
     * still initialises reach for the in-scope posts, so only that area ripples while everyone else
     * stays dark. The unscoped path (test above) remains inert.
     */
    public function test_scoped_run_proceeds_when_globally_disabled(): void
    {
        config(['freegle.ripple.enabled' => false]);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30)); // origin (51.5, -0.1)

        // A polygon covering the post's origin (an area scope).
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
        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells'),
            'the reach grid is stored'
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
            if (str_contains($request->url(), 'reach/rasterize')) {
                return null; // the real rasteriser answers
            }
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

    private function logMessageEvent(int $msgid, string $subtype, Carbon $at): void
    {
        DB::table('logs')->insert([
            'timestamp' => $at,
            'type' => 'Message',
            'subtype' => $subtype,
            'msgid' => $msgid,
        ]);
    }

    public function test_repost_of_a_live_post_keeps_its_original_reach_start(): void
    {
        // A member's own repost turns the post back into a draft, which drops every copy and,
        // a minute later, the reach row. Re-approval re-initialises it; the reach should carry
        // on from when the post was first approved rather than start again at tick 1, or
        // people it had already reached are told "not yet" (Discourse 9808/827).
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(10)); // re-approved 10 minutes ago
        $firstApproved = now()->subHours(20);
        $this->logMessageEvent($msgid, 'Approved', $firstApproved);
        $this->logMessageEvent($msgid, 'Autoreposted', now()->subHours(2));
        $this->logMessageEvent($msgid, 'Repost', now()->subMinutes(20));
        $this->logMessageEvent($msgid, 'Approved', now()->subMinutes(10));

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->tick, '20h since first approval is past the final 6h step');
        $this->assertSame('done', $row->status);
        $this->assertSame($firstApproved->format('Y-m-d H:i:s'), Carbon::parse($row->arrival)->format('Y-m-d H:i:s'));
    }

    public function test_repost_on_an_unmoderated_community_keeps_its_original_reach_start(): void
    {
        // A community that does not moderate logs no approval, only the post being received.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(10));
        $firstReceived = now()->subHours(20);
        $this->logMessageEvent($msgid, 'Received', $firstReceived);
        $this->logMessageEvent($msgid, 'Repost', now()->subMinutes(10));
        $this->logMessageEvent($msgid, 'Received', now()->subMinutes(10));

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->tick);
        $this->assertSame($firstReceived->format('Y-m-d H:i:s'), Carbon::parse($row->arrival)->format('Y-m-d H:i:s'));
    }

    public function test_repost_after_a_long_gap_starts_reach_afresh(): void
    {
        // Reposted weeks after it was last live: the people nearby have not seen it for a
        // long time, so it spreads from the start again like a new post.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(10));
        $this->logMessageEvent($msgid, 'Approved', now()->subDays(30));
        $this->logMessageEvent($msgid, 'Repost', now()->subMinutes(20));
        $this->logMessageEvent($msgid, 'Approved', now()->subMinutes(10));

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->tick);
        $this->assertSame('expanding', $row->status);
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
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), ?, 'drive', 1, 3, 90, 30, ?, ?, 'expanding', NOW(), NOW())",
            [$msgid, $this->reachCellsFor(self::WKT), self::WKT, now()->subHours(7), $ticksJson, now()->subHours(4)]
        );
        $this->fakeSpatialHttp(); // no routing call expected on advance (uses cached schedule)

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

    /**
     * Say that a post has actually gone.
     *
     * removeStaleAndRetract does not act on a post just because it is missing from
     * messages_spatial - a live post can be absent while the index job is down or
     * mid-run (and historically its age pass deleted thousands of still-qualifying
     * posts every run off their dead memberships). It asks whether the post still
     * belongs in the index, so a test about a post that has gone has to make it
     * genuinely gone rather than only removing the index row.
     */
    private function markPostGone(int $msgid): void
    {
        DB::table('messages')->where('id', $msgid)->update(['deleted' => now()]);
        DB::table('messages_spatial')->where('msgid', $msgid)->delete();
    }

    public function test_removes_reach_for_post_no_longer_in_spatial(): void
    {
        $this->fakeSpatialHttp();
        // A message that has a reach row but is NOT in messages_spatial (taken/withdrawn).
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $user->id,
            'subject' => 'OFFER: gone', 'textbody' => 'x', 'source' => 'Platform',
            'date' => now()->subDays(1), 'arrival' => now()->subDays(1), 'lat' => 51.5, 'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), ?, 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::WKT), self::WKT, now()->subDays(1)]
        );

        // Genuinely gone, not just absent from the index - see markPostGone.
        $this->markPostGone((int) $message->id);

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
        $this->fakeSpatialHttp(['*ripple-schedule*' => Http::response([
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

    /**
     * A post whose community has no setting still ripples: the regression guard for the whole
     * feature, since an absent setting must mean "on" for every community on the network.
     */
    public function test_post_on_a_community_with_no_setting_still_ripples_out(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $stats = $this->service()->process(false, 500);

        $this->assertSame(1, $stats['initialized'], 'the default is unchanged: communities ripple');
        $this->assertNotNull(DB::table('rippling_reach')->where('msgid', $msgid)->first());
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

    // ── Stop-and-retract on origin removal (rejected/withdrawn/expired → left spatial) ──

    // ── Retract rippled copies when the HOME post is deleted / moved back to pending ──
    // A mod Delete or Back-to-Pending on the origin group leaves the rippled-in copies live
    // and Approved elsewhere, so the post still has messages_spatial rows and the
    // spatial-IS-NULL trigger in removeStaleAndRetract never fires - the copies are stranded.

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
            if (str_contains($request->url(), 'reach/rasterize')) {
                return null; // the real rasteriser answers
            }
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

    }

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
        // messages.arrival to NOW(). messages_spatial.arrival is still the old value.
        DB::table('messages')->where('id', $msgid)->update([
            'arrival' => now(),
            'autoreposts' => DB::raw('autoreposts + 1'),
        ]);

        // The spatial-index cron refreshes messages_spatial.arrival from messages.arrival
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

    // --- ripple:backfill-reach (reach-algorithm change backfill) ------------------

    /**
     * Every reach write must leave a verified sandwich-bounds row behind
     * (docs/developers/reference/rippling-algorithm.md section 11): outer_bound ⊇ reach and
     * inner_bound ⊆ reach (or NULL), derived from the FINAL stored grid.
     *
     * The check runs against the grid's bounding box (its header, no network):
     * the outer must contain the whole box (it is derived buffered outward, so
     * this holds exactly when the derivation ran on the final grid), and the
     * inner must at least sit within it. The precise inner-inside-reach
     * invariant is pinned in ReachBoundsServiceTest; this is the per-write-path
     * smoke check.
     */
    private function assertBoundsSandwich(int $msgid, string $context): void
    {
        $cells = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
        $this->assertNotNull($cells, "$context: the reach row exists with a grid");
        $bbox = $this->cellSets()->boundsWkt($cells);
        $this->assertNotNull($bbox, "$context: the grid header is readable");

        $check = DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o,
                    (inner_bound IS NULL OR MBRWithin(inner_bound, ST_GeomFromText(?, 3857))) AS i
               FROM rippling_reach WHERE msgid = ?',
            [$bbox, $bbox, $msgid]
        );
        $this->assertNotNull($check, "$context: the reach row exists with bounds");
        $this->assertSame(1, (int) $check->o, "$context: outer_bound contains the stored reach");
        $this->assertSame(1, (int) $check->i, "$context: inner_bound is NULL or within the reach extent");
    }

    public function test_initialise_writes_reach_bounds(): void
    {
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $this->service()->process(false, 500);

        $this->assertBoundsSandwich($msgid, 'initialiseNew');
    }

    public function test_slim_schedule_stores_routing_provided_bounds(): void
    {
        // Slim schedules (polygons=0, the live form) materialise each tick's geometry
        // via /v1/catchment — which now ships sandwich bounds derived on the routing
        // server's own grid. Those must be stored (after verification) in preference to
        // deriving bounds from the polygon in MySQL. The fixture group has no polyindex,
        // so no origin-group union happens and verified bounds are stored verbatim.
        $square = fn (float $w, float $s, float $e, float $n): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s],
            ]]],
        ];
        $outerWkt = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';
        $innerWkt = 'POLYGON((-0.19 51.41, -0.01 51.41, -0.01 51.59, -0.19 51.59, -0.19 51.41))';
        $this->fakeSpatialHttp([
            '*ripple-schedule*' => Http::response([
                'total_freeglers' => 90, 'max_drive_min' => 30,
                'schedule' => [
                    ['tick' => 1, 'drive_min' => 5.0, 'cumulative_users' => 30],
                    ['tick' => 2, 'drive_min' => 10.0, 'cumulative_users' => 60],
                    ['tick' => 3, 'drive_min' => 15.0, 'cumulative_users' => 90],
                ],
            ], 200),
            '*catchment*' => Http::response([
                'catchment' => $square(-0.2, 51.4, 0.0, 51.6),
                'catchment_outer' => $square(-0.21, 51.39, 0.01, 51.61),
                'catchment_inner' => $square(-0.19, 51.41, -0.01, 51.59),
            ], 200),
        ]);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $this->service()->process(false, 500);

        $this->assertBoundsSandwich($msgid, 'slim init');
        $check = DB::selectOne(
            'SELECT ST_Equals(outer_bound, ST_GeomFromText(?, 3857)) AS oe,
                    ST_Equals(inner_bound, ST_GeomFromText(?, 3857)) AS ie
               FROM rippling_reach WHERE msgid = ?',
            [$outerWkt, $innerWkt, $msgid]
        );
        $this->assertNotNull($check);
        $this->assertSame(1, (int) $check->oe, 'the routing-provided outer bound is stored, not a MySQL derivation');
        $this->assertSame(1, (int) $check->ie, 'the routing-provided inner bound is stored, not a MySQL derivation');
    }

    public function test_advance_resyncs_stale_reach_bounds(): void
    {
        // Start the post stuck at tick 1 with an overdue expansion AND a deliberately
        // stale/wrong bounds row (tiny box far from the real polygon). The tick advance
        // rewrites the polygon and must re-derive the bounds from it.
        $msgid = $this->seedSpatialPost(now()->subHours(7));
        $ticksJson = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'wkt' => self::WKT],
            ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'wkt' => self::WKT],
            ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'wkt' => self::WKT],
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), ?, 'drive', 1, 3, 90, 30, ?, ?, 'expanding', NOW(), NOW())",
            [$msgid, $this->reachCellsFor(self::WKT), self::WKT, now()->subHours(7), $ticksJson, now()->subHours(4)]
        );
        DB::statement(
            "UPDATE rippling_reach SET outer_bound = ST_GeomFromText('POLYGON((5 5, 5.1 5, 5.1 5.1, 5 5.1, 5 5))', 3857),
                    inner_bound = NULL WHERE msgid = ?",
            [$msgid]
        );
        $this->fakeSpatialHttp(); // no routing call expected on advance (uses cached schedule)

        $this->service()->process(false, 500);

        $this->assertBoundsSandwich($msgid, 'advanceDue');
    }

    // ── The compact cell-set columns (plans/2026-08-24-rippling-reach-raster-storage.md) ──
    //
    // Every one of these goes through the REAL rasteriser in iznik-spatial-go
    // (CellSetService::rasterize, the ONE place a polygon becomes its canonical
    // compact form) rather than a stub, because a stub would agree with itself
    // about a format the reading side has to agree with instead. Http::fake()
    // would intercept that call and hand back an empty 200, so these tests
    // deliberately fake ONLY the routing endpoints and let the rasterise
    // request go to the live service.

    /**
     * Fake the routing endpoints while letting the rasterise call through to
     * the real spatial server - Laravel's stubs are matched in order, so the
     * unmatched-request passthrough has to be explicit.
     */
    private function fakeRoutingButNotRasterize(string $wkt): void
    {
        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $schedule = [];
        for ($k = 1; $k <= 3; $k++) {
            $schedule[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => 30 * $k, 'polygon' => $polygon];
        }
        Http::fake(function ($request) use ($schedule) {
            if (str_contains($request->url(), 'reach/rasterize')) {
                // Not stubbed: let the real rasteriser answer.
                return null;
            }
            if (str_contains($request->url(), 'ripple-schedule')) {
                return Http::response([
                    'total_freeglers' => 90, 'max_drive_min' => 30, 'schedule' => $schedule,
                ], 200);
            }

            return Http::response([], 200);
        });
    }

    private function cellSets(): \App\Services\Ripple\CellSetService
    {
        return new \App\Services\Ripple\CellSetService();
    }

    /** Probe the stored reach grid - the only stored form - for one point. */
    private function storedReachContains(int $msgid, float $lng, float $lat): bool
    {
        $cells = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
        $this->assertNotNull($cells, 'the row must hold a reach grid to probe');

        return $this->cellSets()->containsEncoded($cells, $lng, $lat) === true;
    }

    public function test_init_stores_the_reach_as_a_cell_set(): void
    {
        $this->fakeRoutingButNotRasterize(self::WKT);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $this->service()->process(false, 500);

        $cells = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
        $this->assertNotNull($cells, 'init must store the cell set - it IS the reach');

        // The cell set must classify points as the routed shape (self::WKT,
        // lng -0.2..-0.1, lat 51.5..51.6) does.
        $decoded = $this->cellSets()->decode($cells);
        foreach ([[-0.15, 51.55, true], [-0.12, 51.52, true], [-0.18, 51.58, true], [5.0, 5.0, false], [-1.0, 50.0, false]] as [$lng, $lat, $want]) {
            $this->assertSame(
                $want,
                $this->cellSets()->contains($decoded, $lng, $lat),
                "cell set classifies ($lng, $lat) wrongly"
            );
        }
    }

    public function test_a_rasterise_failure_fails_the_advance_rather_than_writing_a_stale_reach(): void
    {
        // The grid IS the stored reach, so an advance that cannot rasterise
        // must not write anything: the post keeps its previous reach and tick
        // and is retried next sweep. Writing a row whose reach nobody can
        // read - or leaving a stale, larger grid - would misgate replies.
        $msgid = $this->seedSpatialPost(now()->subHours(7));

        $ticksJson = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'wkt' => self::WKT],
            ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'wkt' => self::WKT],
            ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'wkt' => self::WKT],
        ]);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), ?, 'drive', 1, 3, 90, 30, ?, ?, 'expanding', NOW(), NOW())",
            [$msgid, $this->reachCellsFor(self::WKT), self::WKT, now()->subHours(7), $ticksJson, now()->subHours(4)]
        );

        // Give the row a real cell set first, from the real rasteriser.
        $this->fakeRoutingButNotRasterize(self::WKT);
        $seeded = $this->cellSets()->rasterize(self::WKT);
        $this->assertNotNull($seeded, 'the real rasteriser must seed a cell set for this test to mean anything');
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['polygon_cells' => $seeded]);

        // Now break the rasteriser and let the tick run.
        Http::fake(function ($request) use ($ticksJson) {
            if (str_contains($request->url(), 'reach/rasterize')) {
                return Http::response('rasteriser is down', 500);
            }

            return Http::response([], 200);
        });

        $stats = $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(1, (int) $row->tick, 'a failed rasterise leaves the tick where it was, for the next sweep');
        $this->assertSame(
            $seeded,
            $row->polygon_cells,
            'the previous reach grid survives untouched - never cleared, never replaced'
        );
        $this->assertGreaterThanOrEqual(1, $stats['errors'], 'the failure is counted, not swallowed');
    }

    public function test_the_overflow_rings_are_stored_as_cell_sets_on_the_same_paths(): void
    {
        // The rings are the table's worst case (860KB a row, 37k vertices each).
        // Their cell sets have to land on the SAME JSON paths as the rings, or
        // iznik-spatial-go asks for a lane and gets nothing.
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));

        $polygon = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.10, 51.50], [-0.20, 51.50], [-0.20, 51.60], [-0.10, 51.60], [-0.10, 51.50],
            ]]],
        ];
        $ring = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-0.05, 51.45], [-0.25, 51.45], [-0.25, 51.65], [-0.05, 51.65], [-0.05, 51.45],
            ]]],
        ];
        $schedule = [];
        for ($k = 1; $k <= 3; $k++) {
            $schedule[] = ['tick' => $k, 'drive_min' => 5.0 * $k, 'cumulative_users' => 30 * $k, 'polygon' => $polygon];
        }
        Http::fake(function ($request) use ($schedule, $ring) {
            if (str_contains($request->url(), 'reach/rasterize')) {
                return null; // the real rasteriser
            }
            if (str_contains($request->url(), 'ripple-schedule')) {
                return Http::response([
                    'total_freeglers' => 90,
                    'max_drive_min' => 30,
                    'schedule' => $schedule,
                    'overflow_rural' => ['sparse' => $ring],
                ], 200);
            }

            return Http::response([], 200);
        });

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNotNull($row->overflow_cells, 'the ring must be stored as a cell set');

        $cellsByLane = json_decode($row->overflow_cells, true);
        $this->assertArrayHasKey(
            'sparse',
            $cellsByLane['rural'] ?? [],
            'the cell set must sit on the same lane path the ring WKT used'
        );
        // The scalars ride in the same document - it is their only home now.
        $this->assertArrayHasKey('bbox', $cellsByLane, 'the bbox scalar lives in the cells document');

        $decoded = $this->cellSets()->decode(base64_decode($cellsByLane['rural']['sparse']));
        $this->assertTrue(
            $this->cellSets()->contains($decoded, -0.15, 51.55),
            "a point inside the ring must be inside the ring's cell set"
        );
        $this->assertFalse(
            $this->cellSets()->contains($decoded, 5.0, 5.0),
            'a point nowhere near the ring must not be'
        );
    }

    public function test_a_retired_grid_stays_drained_through_a_tick_advance(): void
    {
        // Once a post has its stored label and its home-area number, the
        // per-tick writers stop materialising the square grid: the advance
        // still moves the tick, but polygon_cells stays NULL - and the
        // geometric home-area union is decided from the stored number, not
        // recomputed.
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(30));
        $this->service()->process(false, 500);
        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells'),
            'premise: an unretired post materialises its grid at initialise'
        );

        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'reach_labels' => 'label-bytes',
            'origin_union_secs' => -1,
            'polygon_cells' => null,
            'arrival' => now()->subHours(4),
            'next_expansion_at' => now()->subMinute(),
            'status' => 'expanding',
        ]);

        $this->service()->process(false, 500);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertGreaterThan(1, (int) $row->tick, 'the tick still advances');
        $this->assertNull($row->polygon_cells, 'a retired row never gets its grid re-materialised');
    }

    public function test_an_expired_time_box_stops_the_run_at_the_row_boundary(): void
    {
        // The single-instance lock's TTL cannot be tuned above an open-ended run
        // length (2026-08-30: a backlogged run outlived the lock, the every-minute
        // schedule stacked runs to the routing server's 8 gate slots, goodput hit
        // zero). The run therefore bounds ITSELF: with the box already expired,
        // it must take no rows and report that it stopped - the rows stay due for
        // the next tick, nothing is half-processed.
        config(['freegle.ripple.expand_time_box_seconds' => -1]);
        $this->fakeDensity(400, 1.0);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $stats = $this->service()->process(false, 500);

        $this->assertGreaterThanOrEqual(1, $stats['timeboxed'], 'the run must say it was time-boxed');
        $this->assertSame(0, $stats['initialized']);
        $this->assertNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->first(),
            'a boxed run must leave unprocessed posts untouched for the next tick'
        );
    }

    public function test_a_zero_time_box_disables_the_bound(): void
    {
        config(['freegle.ripple.expand_time_box_seconds' => 0]);
        $this->fakeDensity(400, 1.0);
        $this->fakeRouting(3);
        $msgid = $this->seedSpatialPost(now()->subMinutes(90));

        $stats = $this->service()->process(false, 500);

        $this->assertSame(0, $stats['timeboxed']);
        $this->assertSame(1, $stats['initialized']);
        $this->assertNotNull(DB::table('rippling_reach')->where('msgid', $msgid)->first());
    }

}
