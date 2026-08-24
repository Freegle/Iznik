<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\Message;
use App\Models\User;
use App\Services\FirstReply\MatchMailService;
use App\Services\FirstReply\MaxReachService;
use App\Services\FreegleApiClient;
use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

/**
 * MatchMailService::filterEligible reads BOTH rr.polygon (exclude - already
 * reached) and rr.max_polygon (include - will eventually be reached) through
 * GeomShareService's COALESCE join. This is the same scenario
 * MatchMailServiceTest::test_does_not_mail_someone_already_inside_the_current_reach
 * uses, run through the same public entry point (run(), via the real
 * candidates()/filterEligible() code path - filterEligible is private and
 * this file has no reflection precedent), repeated across the three dedup
 * states of EACH column independently:
 *
 *   polygon:     undeduped / deduped / drained
 *   max_polygon: undeduped (hash stripped, as a pre-migration row would be) /
 *                deduped / drained
 *
 * A member already inside the current reach must stay unmailed in every
 * state (proves the polygon exclusion reads the real geometry); a member
 * outside today's reach but inside the eventual one must be mailed in every
 * state (proves the max_polygon inclusion does too) - including when the
 * blob is gone and only the shared row remains.
 */
class GeomShareMatchMailMatrixTest extends TestCase
{
    use FakesRingIndex;

    private array $apiMatchIds = [];

    private array $apiSearchUserIds = [];

    // Tick 1: the current reach. Tick 3: the eventual one - a much wider box
    // that contains tick 1. Same fixture as MatchMailServiceTest.
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        MaxReachService::forgetAvailability();
        Mail::fake();
        DB::statement('DELETE FROM firstreply_scouts');
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');

        $this->apiMatchIds = [];
        $this->apiSearchUserIds = [];
        $this->refreshApiFake();

        config([
            'freegle.firstreply.enabled' => true,
            'freegle.firstreply.rollout_percent' => 100,
            'freegle.firstreply.matchmail.enabled' => true,
            'freegle.firstreply.matchmail.quiet_minutes' => 0,
            'freegle.firstreply.matchmail.max_per_post' => 10,
            'freegle.firstreply.matchmail.min_score' => 1.0,
            'freegle.ripple.active_start_hour' => 0,
            'freegle.ripple.active_end_hour' => 24,
        ]);
    }

    private function refreshApiFake(): void
    {
        $wanted = ['body' => array_map(
            static fn ($id) => ['id' => $id, 'score' => 0.93],
            $this->apiMatchIds
        )];
        $search = ['body' => array_map(
            static fn ($uid) => ['userid' => $uid, 'searchid' => $uid, 'term' => 'bookcase', 'score' => 0.91],
            $this->apiSearchUserIds
        )];

        $responses = [];
        for ($i = 0; $i < 20; $i++) {
            $responses[] = $wanted;
            $responses[] = $search;
        }

        FreegleApiClient::clearFake();
        FreegleApiClient::fake($responses);
    }

    private function service(): MatchMailService
    {
        return app(MatchMailService::class);
    }

    /**
     * A silent OFFER, rippling on TICK1/TICK3, with its polygon and
     * max_polygon columns each independently put into $polyState / $maxState
     * ('undeduped', 'deduped' or 'drained').
     */
    private function seedSilentOffer(string $polyState, string $maxState): int
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.1]);
        $message = $this->createTestMessage($poster, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Pine bookcase (TestLocation)',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        $msgid = (int) $message->id;

        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(-0.1, 51.5), 3857), 0, 0, ?, ?, NOW())',
            [$msgid, $group->id, Message::TYPE_OFFER]
        );

        $schedule = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$msgid, self::TICK1, self::TICK1, $schedule]
        );

        if ($polyState !== 'undeduped') {
            GeomShareService::upsertFromRow($msgid, 'polygon');
            GeomShareService::rehashFromRow($msgid, 'polygon');
        }
        if ($polyState === 'drained') {
            DB::statement(
                "UPDATE rippling_reach SET polygon = ST_GeomFromText('POINT(0 0)', 3857) WHERE msgid = ?",
                [$msgid]
            );
        }

        // populate() dual-writes max_polygon_hash unconditionally now that
        // GeomShareService::ready() is true in the test schema, so 'undeduped'
        // has to be simulated afterwards - a pre-migration row, not one this
        // write path can still produce on its own.
        app(MaxReachService::class)->populate();
        if ($maxState === 'undeduped') {
            DB::table('rippling_reach')->where('msgid', $msgid)->update(['max_polygon_hash' => null]);
        } elseif ($maxState === 'drained') {
            DB::table('rippling_reach')->where('msgid', $msgid)->update(['max_polygon' => null]);
        }

        return $msgid;
    }

    private function memberAt(float $lat, float $lng): User
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['mylocation' => ['lat' => $lat, 'lng' => $lng]]),
            'lastaccess' => now(),
        ]);

        return $user->fresh();
    }

    private function wantedAt(User $user, float $lat, float $lng): void
    {
        $group = $this->createTestGroup();
        $post = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Pine bookcase (TestLocation)',
        ]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, NOW())',
            [$post->id, $lng, $lat, $group->id, Message::TYPE_WANTED]
        );

        $this->apiMatchIds[] = (int) $post->id;
        $this->refreshApiFake();
    }

    private function mailedFor(int $msgid): array
    {
        return DB::table('firstreply_scouts')->where('msgid', $msgid)
            ->pluck('reason', 'userid')->all();
    }

    private function assertPolygonExclusionAndMaxPolygonInclusionAgree(string $state): void
    {
        $msgid = $this->seedSilentOffer($state, $state);

        // Inside TICK1 (the current reach): the polygon exclusion must catch them
        // in every state, reading the real geometry via the COALESCE join.
        $inside = $this->memberAt(51.50, -0.10);
        $this->wantedAt($inside, 51.50, -0.10);

        // Outside TICK1 but inside TICK3 (the eventual reach): the max_polygon
        // inclusion must find them in every state, including drained.
        $outside = $this->memberAt(51.90, 0.80);
        $this->wantedAt($outside, 51.90, 0.80);

        $this->service()->run();

        $mailed = $this->mailedFor($msgid);
        $this->assertArrayNotHasKey(
            $inside->id,
            $mailed,
            "state={$state}: already inside the current polygon - the exclusion must read the real geometry"
        );
        $this->assertArrayHasKey(
            $outside->id,
            $mailed,
            "state={$state}: outside today's reach but inside the eventual one - the max_polygon inclusion must read the real geometry"
        );
    }

    public function test_reach_predicates_agree_when_undeduped(): void
    {
        $this->assertPolygonExclusionAndMaxPolygonInclusionAgree('undeduped');
    }

    public function test_reach_predicates_agree_when_deduped(): void
    {
        $this->assertPolygonExclusionAndMaxPolygonInclusionAgree('deduped');
    }

    public function test_reach_predicates_agree_when_drained(): void
    {
        $this->assertPolygonExclusionAndMaxPolygonInclusionAgree('drained');
    }
}
