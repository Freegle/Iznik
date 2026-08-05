<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\Membership;
use App\Models\Message;
use App\Services\FirstReply\MaxReachService;
use App\Services\FirstReply\ScoutService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScoutServiceTest extends TestCase
{
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        DB::statement('DELETE FROM firstreply_scouts');
        DB::statement('DELETE FROM rippling_reach');

        config([
            'freegle.firstreply.enabled' => true,
            'freegle.firstreply.scouts.enabled' => true,
            'freegle.firstreply.scouts.quiet_minutes' => 0,
            'freegle.firstreply.scouts.max_per_post' => 10,
            'freegle.firstreply.scouts.min_score' => 1.0,
        ]);
    }

    private function service(): ScoutService
    {
        return app(ScoutService::class);
    }

    public function test_keywords_drop_the_words_that_would_match_everything(): void
    {
        $this->assertSame(
            ['table'],
            $this->service()->keywords('OFFER: Free small table (Edinburgh EH1)'),
            '"free", "small" and the location suffix match half the site; only "table" says what it is'
        );
    }

    public function test_keywords_survive_a_subject_with_no_prefix_or_location(): void
    {
        $this->assertContains('bookcase', $this->service()->keywords('Pine bookcase'));
    }

    public function test_keywords_are_capped_so_one_post_cannot_run_a_dozen_like_scans(): void
    {
        $subject = 'OFFER: bookcase wardrobe cabinet dresser sideboard cupboard shelving (Edinburgh)';

        $this->assertLessThanOrEqual(5, count($this->service()->keywords($subject)));
    }

    /** A silent OFFER, rippling, with its eventual reach known. */
    private function seedSilentOffer(): Message
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.1]);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Pine bookcase (TestLocation)',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(-0.1, 51.5), 3857), 0, 0, ?, ?, NOW())',
            [$message->id, $group->id, Message::TYPE_OFFER]
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
            [$message->id, self::TICK1, self::TICK1, $schedule]
        );

        app(MaxReachService::class)->populate();

        return $message;
    }

    /** A member at (lat,lng), located the way the reach queries resolve locations. */
    private function memberAt(float $lat, float $lng): \App\Models\User
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['mylocation' => ['lat' => $lat, 'lng' => $lng]]),
            'lastaccess' => now(),
        ]);

        return $user->fresh();
    }

    private function scoutsFor(int $msgid): array
    {
        return DB::table('firstreply_scouts')->where('msgid', $msgid)
            ->pluck('reason', 'userid')->all();
    }

    public function test_picks_someone_whose_open_wanted_matches(): void
    {
        $message = $this->seedSilentOffer();

        // Lives somewhere the post has NOT reached yet but eventually will -
        // exactly the person the current reach schedule makes wait days for.
        $wanter = $this->memberAt(51.9, 0.8);
        $group = $this->createTestGroup();
        $wanted = $this->createTestMessage($wanter, $group, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Pine bookcase (TestLocation)',
        ]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(0.8, 51.9), 3857), 0, 0, ?, ?, NOW())',
            [$wanted->id, $group->id, Message::TYPE_WANTED]
        );

        $this->service()->run(true);
        $this->service()->run();

        $this->assertArrayHasKey($wanter->id, $this->scoutsFor((int) $message->id));
        $this->assertSame('wanted', $this->scoutsFor((int) $message->id)[$wanter->id]);
    }

    public function test_picks_someone_who_saved_a_matching_search(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);

        DB::table('users_searches')->insert([
            'userid' => $searcher->id,
            'term' => 'bookcase',
            'deleted' => 0,
            'date' => now(),
        ]);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));
    }

    public function test_ignores_someone_the_post_will_never_reach(): void
    {
        $message = $this->seedSilentOffer();
        $farAway = $this->memberAt(57.1, -2.1);

        DB::table('users_searches')->insert([
            'userid' => $farAway->id,
            'term' => 'bookcase',
            'deleted' => 0,
            'date' => now(),
        ]);

        $this->service()->run();

        $this->assertArrayNotHasKey($farAway->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_member_is_not_scouted_twice_in_the_cooldown(): void
    {
        config(['freegle.firstreply.scouts.user_cooldown_hours' => 24]);

        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users_searches')->insert([
            'userid' => $searcher->id, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        // Already scouted about something else an hour ago.
        $other = $this->seedSilentOffer();
        DB::table('firstreply_scouts')->insert([
            'msgid' => $other->id,
            'userid' => $searcher->id,
            'reason' => 'search',
            'score' => 3,
            'sent_at' => now()->subHour(),
        ]);

        $this->service()->run();

        $this->assertArrayNotHasKey(
            $searcher->id,
            $this->scoutsFor((int) $message->id),
            'being good at replying must not turn into being mailed constantly'
        );
    }

    public function test_a_post_is_only_scouted_once(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users_searches')->insert([
            'userid' => $searcher->id, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        $this->service()->run();
        $before = count($this->scoutsFor((int) $message->id));
        $this->service()->run();

        $this->assertSame($before, count($this->scoutsFor((int) $message->id)));
    }

    public function test_scouted_members_are_marked_so_the_reach_mailer_does_not_repeat_the_post(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users_searches')->insert([
            'userid' => $searcher->id, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        $this->service()->run();

        $this->assertTrue(
            DB::table('rippling_reach_notified')
                ->where('msgid', $message->id)->where('userid', $searcher->id)->exists(),
            'the same post arriving twice is worse than it arriving once, late'
        );
    }

    public function test_never_scouts_the_poster_about_their_own_post(): void
    {
        $message = $this->seedSilentOffer();

        DB::table('users')->where('id', $message->fromuser)->update([
            'settings' => json_encode(['mylocation' => ['lat' => 51.5, 'lng' => -0.1]]),
        ]);
        DB::table('users_searches')->insert([
            'userid' => $message->fromuser, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        $this->service()->run();

        $this->assertArrayNotHasKey((int) $message->fromuser, $this->scoutsFor((int) $message->id));
    }

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        config(['freegle.firstreply.scouts.enabled' => false]);
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users_searches')->insert([
            'userid' => $searcher->id, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        $this->assertSame(0, $this->service()->run()['mailed']);
        $this->assertSame([], $this->scoutsFor((int) $message->id));
    }

    public function test_frequent_repliers_are_only_drawn_from_the_posts_own_communities(): void
    {
        // The frequent-replier signal says nothing about THIS item, so it is
        // deliberately bounded to the communities the post is on rather than
        // being cast across the whole eventual reach.
        $message = $this->seedSilentOffer();
        $groupid = DB::table('messages_groups')->where('msgid', $message->id)->value('groupid');

        $replier = $this->memberAt(51.5, -0.1);
        Membership::create([
            'userid' => $replier->id,
            'groupid' => $groupid,
            'collection' => Membership::COLLECTION_APPROVED,
            'added' => now(),
        ]);

        config(['freegle.firstreply.scouts.frequent_replier_min' => 1]);

        $poster = $this->createTestUser();
        $room = $this->createTestChatRoom($replier, $poster);
        $group2 = $this->createTestGroup();
        $other = $this->createTestMessage($poster, $group2);
        $this->createTestChatMessage($room, $replier, [
            'type' => \App\Models\ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $other->id,
        ]);

        $this->service()->run();

        $this->assertArrayHasKey($replier->id, $this->scoutsFor((int) $message->id));
    }
}
