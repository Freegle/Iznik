<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\Message;
use App\Services\FirstReply\MaxReachService;
use App\Services\FreegleApiClient;
use App\Services\FirstReply\MatchMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MatchMailServiceTest extends TestCase
{
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        // The match mail really goes through the digest spool, because the ledger
        // and the reach-notified stamp are both keyed on who was ACTUALLY mailed
        // rather than who was picked.
        Mail::fake();
        DB::statement('DELETE FROM firstreply_scouts');
        DB::statement('DELETE FROM rippling_reach');

        // Both match signals are vector now and go through apiv2. Default to
        // "nothing matched" so a test that does not set up a match cannot be
        // handed one by accident.
        $this->apiMatchIds = [];
        $this->apiSearchUserIds = [];
        $this->refreshApiFake();

        config([
            'freegle.firstreply.enabled' => true,
            // Whole-network arm: the rollout percentage is exercised separately.
            'freegle.firstreply.rollout_percent' => 100,
            'freegle.firstreply.matchmail.enabled' => true,
            'freegle.firstreply.matchmail.quiet_minutes' => 0,
            'freegle.firstreply.matchmail.max_per_post' => 10,
            'freegle.firstreply.matchmail.min_score' => 1.0,
            // The run() waking-hours gate (shared with the ripple) would zero
            // every test that happens to execute overnight - CI runs at all
            // hours. Hold the window open; the gate itself is pinned by
            // test_match_mail_sleeps_outside_waking_hours.
            'freegle.ripple.active_start_hour' => 0,
            'freegle.ripple.active_end_hour' => 24,
        ]);
    }

    /**
     * Rebuild the apiv2 fake from everything seeded so far.
     *
     * Both match signals are vector and go through apiv2 now, so seeding a
     * matching WANTED or saved search is only half the setup - the API has to
     * report it. Accumulating here means the seeding helpers can do both, and a
     * test cannot seed a match and forget to declare it.
     *
     * Scores are already above MinMatchedPostScore: the threshold lives in the
     * endpoint, so a test cannot assert on a match the real thing would drop.
     */
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

        // Consumed in order and exhausting to null, so repeat the pair rather
        // than making every test count the service's API calls.
        $responses = [];
        for ($i = 0; $i < 20; $i++) {
            $responses[] = $wanted;
            $responses[] = $search;
        }

        FreegleApiClient::clearFake();
        FreegleApiClient::fake($responses);
    }

    /** A saved search for this post's item, declared to the matcher. */
    private function savedSearchFor(\App\Models\User $user, string $term = 'bookcase'): void
    {
        DB::table('users_searches')->insert([
            'userid' => $user->id, 'term' => $term, 'deleted' => 0, 'date' => now(),
        ]);

        $this->apiSearchUserIds[] = (int) $user->id;
        $this->refreshApiFake();
    }

    private function service(): MatchMailService
    {
        return app(MatchMailService::class);
    }

    /** A silent OFFER, rippling, with its eventual reach known. */
    private function seedSilentOffer(bool $populateMaxReach = true): Message
    {
        return $this->seedSilentPost(Message::TYPE_OFFER, $populateMaxReach);
    }

    /** The mirror image: a silent WANTED, rippling, reach known. */
    private function seedSilentWanted(bool $populateMaxReach = true): Message
    {
        return $this->seedSilentPost(Message::TYPE_WANTED, $populateMaxReach);
    }

    /** A silent post of either type, rippling, with its eventual reach known. */
    private function seedSilentPost(string $type, bool $populateMaxReach = true): Message
    {
        $poster = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.1]);
        $message = $this->createTestMessage($poster, $group, [
            'type' => $type,
            'subject' => strtoupper($type) . ': Pine bookcase (TestLocation)',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(-0.1, 51.5), 3857), 0, 0, ?, ?, NOW())',
            [$message->id, $group->id, $type]
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

        if ($populateMaxReach) {
            app(MaxReachService::class)->populate();
        }

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

    /**
     * A member with an open matching post, standing where callers put them.
     *
     * The signal is the same whichever way round the match runs, so both the
     * OFFER-finds-WANTED and WANTED-finds-OFFER tests build their people here.
     */
    private function matchHolderAt(float $lat, float $lng, string $type = Message::TYPE_WANTED): \App\Models\User
    {
        $user = $this->memberAt($lat, $lng);
        $type === Message::TYPE_WANTED
            ? $this->wantedAt($user, $lat, $lng)
            : $this->offerAt($user, $lat, $lng);

        return $user;
    }

    /** Pretend this member's daily digest already went out at $when. */
    private function digestSentAt(int $userId, \Carbon\Carbon $when): void
    {
        DB::table('users_digests')->insert([
            'userid' => $userId,
            'mode' => 'daily',
            'lastsent' => $when->toDateTimeString(),
        ]);
    }

    /** Now, London time, expressed as the UTC instant actually stored. */
    private function earlierToday(): \Carbon\Carbon
    {
        return \Carbon\Carbon::now('Europe/London')->startOfDay()->addHours(8)->setTimezone('UTC');
    }

    private function mailedFor(int $msgid): array
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
        $this->wantedAt($wanter, 51.9, 0.8);

        $this->service()->run();

        $this->assertArrayHasKey($wanter->id, $this->mailedFor((int) $message->id));
        $this->assertSame('wanted', $this->mailedFor((int) $message->id)[$wanter->id]);
    }

    public function test_a_wanted_finds_the_people_sitting_on_a_matching_offer(): void
    {
        // The mirror of the test above, and the reason it is a separate one: an
        // exchange has two sides, and only one of them posted second. Somebody
        // holding an unwanted bookcase should hear that a neighbour has asked for
        // one, just as the asker hears when one is offered. Nothing about the
        // match is different in this direction, so the whole value of it lies in
        // running the pass over WANTEDs at all.
        $message = $this->seedSilentWanted();

        $offerer = $this->memberAt(51.9, 0.8);
        $this->offerAt($offerer, 51.9, 0.8);

        $this->service()->run();

        $mailed = $this->mailedFor((int) $message->id);
        $this->assertArrayHasKey($offerer->id, $mailed, 'both sides get the chance to start the exchange');
        $this->assertSame('wanted', $mailed[$offerer->id]);
    }

    public function test_picks_someone_who_saved_a_matching_search(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);

        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->mailedFor((int) $message->id));
    }

    public function test_ignores_someone_the_post_will_never_reach(): void
    {
        $message = $this->seedSilentOffer();
        $farAway = $this->memberAt(57.1, -2.1);

        $this->savedSearchFor($farAway);

        $this->service()->run();

        $this->assertArrayNotHasKey($farAway->id, $this->mailedFor((int) $message->id));
    }

    public function test_a_member_is_not_mailed_twice_in_the_cooldown(): void
    {
        config(['freegle.firstreply.matchmail.user_cooldown_hours' => 24]);

        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        // Already mailed about something else an hour ago.
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
            $this->mailedFor((int) $message->id),
            'being good at replying must not turn into being mailed constantly'
        );
    }

    public function test_a_post_is_only_matched_once(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();
        $before = count($this->mailedFor((int) $message->id));
        $this->service()->run();

        $this->assertSame($before, count($this->mailedFor((int) $message->id)));
    }

    public function test_the_rationed_slots_go_to_whoever_stands_nearest_the_reach_edge(): void
    {
        config(['freegle.firstreply.matchmail.max_per_post' => 1]);

        $message = $this->seedSilentOffer();
        // Both sit outside today's reach and inside the eventual one, and both
        // want the item equally, so only distance can separate them. The far one
        // is created first, so an insertion-ordered pick would take it: the slot
        // must go to whoever is just past the edge, because that is who the reach
        // is about to cover anyway.
        $far = $this->matchHolderAt(51.5, 0.8);
        $near = $this->matchHolderAt(51.5, 0.0);

        $this->service()->run();

        $mailed = $this->mailedFor((int) $message->id);
        $this->assertArrayHasKey($near->id, $mailed, 'the rationed slot goes nearest-first');
        $this->assertArrayNotHasKey($far->id, $mailed, 'the distant member waits for the reach itself');
    }

    public function test_mailed_members_are_marked_so_the_reach_mailer_does_not_repeat_the_post(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertTrue(
            DB::table('rippling_reach_notified')
                ->where('msgid', $message->id)->where('userid', $searcher->id)->exists(),
            'the same post arriving twice is worse than it arriving once, late'
        );
    }

    public function test_never_mails_the_poster_about_their_own_post(): void
    {
        $message = $this->seedSilentOffer();

        DB::table('users')->where('id', $message->fromuser)->update([
            'settings' => json_encode(['mylocation' => ['lat' => 51.5, 'lng' => -0.1]]),
        ]);
        DB::table('users_searches')->insert([
            'userid' => $message->fromuser, 'term' => 'bookcase', 'deleted' => 0, 'date' => now(),
        ]);

        // The matcher DOES return them - otherwise this would pass because
        // nothing matched, rather than because the poster is excluded.
        $this->apiSearchUserIds[] = (int) $message->fromuser;
        $this->refreshApiFake();

        $this->service()->run();

        $this->assertArrayNotHasKey((int) $message->fromuser, $this->mailedFor((int) $message->id));
    }

    public function test_earned_reach_gate_defers_scouting_until_a_mod_look(): void
    {
        // Scouts are hand-picked invitations to people BEYOND the current reach
        // edge, so for a post no human has looked at they would bypass the
        // earned-reach gate (spread earned by clean exposure - see
        // Ripple\ExpandService), and a scout reply would then pull the reach out
        // to meet them. While the gate is on, a silent unreviewed post waits for
        // a moderator look before it is scouted.
        config(['freegle.ripple.earned_reach_enabled' => true]);
        $message = $this->seedSilentOffer(); // approvedby NULL = no human look
        $wanter = $this->memberAt(51.9, 0.8);
        $this->wantedAt($wanter, 51.9, 0.8);

        $this->service()->run();

        $this->assertSame([], $this->mailedFor((int) $message->id),
            'no scouts for an unreviewed post while the earned-reach gate is on');
    }

    public function test_earned_reach_gate_scouts_normally_after_a_mod_look(): void
    {
        config(['freegle.ripple.earned_reach_enabled' => true]);
        $message = $this->seedSilentOffer();
        DB::table('messages_groups')->where('msgid', $message->id)->update(['checkedat' => now()]);
        $wanter = $this->memberAt(51.9, 0.8);
        $this->wantedAt($wanter, 51.9, 0.8);

        $this->service()->run();

        $this->assertArrayHasKey($wanter->id, $this->mailedFor((int) $message->id),
            'a mod look clears the gate and scouting resumes');
    }

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        config(['freegle.firstreply.matchmail.enabled' => false]);
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->assertSame(0, $this->service()->run()['mailed']);
        $this->assertSame([], $this->mailedFor((int) $message->id));
    }

    public function test_match_mail_sleeps_outside_waking_hours(): void
    {
        // Nothing Freegle sends about a post goes out
        // at 3am (observed live before the gate existed). Narrow the window to
        // exclude the current hour and the run must not even look.
        $hour = (int) now()->format('G');
        config([
            'freegle.ripple.active_start_hour' => ($hour + 2) % 24,
            'freegle.ripple.active_end_hour' => ($hour + 3) % 24,
        ]);

        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $stats = $this->service()->run();

        $this->assertSame(0, $stats['considered'], 'outside waking hours the run must not even look');
        $this->assertSame([], $this->mailedFor((int) $message->id));
    }

    // --- What justifies the mail decides whether it may be an extra one -------

    public function test_a_matching_search_may_be_an_extra_mail_even_after_todays_digest(): void
    {
        // They saved a search for this. That is a request, item by item, so it is
        // allowed to be a mail they would not otherwise have had today.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);
        $this->digestSentAt((int) $searcher->id, $this->earlierToday());

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->mailedFor((int) $message->id));
    }

    public function test_a_match_still_respects_the_suggested_posts_consent(): void
    {
        // relevantallowed is the existing "Suggested posts for you" setting, and a
        // match mail is exactly a suggested post.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users')->where('id', $searcher->id)->update(['relevantallowed' => 0]);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayNotHasKey($searcher->id, $this->mailedFor((int) $message->id));
    }

    public function test_a_skipped_candidate_is_replaced_rather_than_leaving_a_hole(): void
    {
        // "If they have had one, find someone else" - the slot goes to the next
        // candidate rather than going unused, so the post still gets its full
        // complement. Filtering therefore has to happen before the cap.
        config([
            'freegle.firstreply.matchmail.max_per_post' => 1,
            'freegle.firstreply.matchmail.user_cooldown_hours' => 24,
        ]);

        $message = $this->seedSilentOffer();
        $alreadyMailed = $this->matchHolderAt(51.9, 0.8);
        $available = $this->matchHolderAt(51.9, 0.8);

        // Mailed about something else an hour ago, so inside the cooldown. The
        // other post carries no spatial row, so it is not itself a candidate and
        // cannot mail anybody during this run.
        $elsewhere = $this->createTestMessage($this->createTestUser(), $this->createTestGroup());
        DB::table('firstreply_scouts')->insert([
            'msgid' => $elsewhere->id,
            'userid' => $alreadyMailed->id,
            'reason' => 'wanted',
            'score' => 5,
            'sent_at' => now()->subHour(),
        ]);

        $this->service()->run();

        $mailed = $this->mailedFor((int) $message->id);
        $this->assertArrayNotHasKey($alreadyMailed->id, $mailed);
        $this->assertArrayHasKey($available->id, $mailed);
        $this->assertCount(1, $mailed);
    }

    public function test_everyone_who_asked_for_the_item_is_mailed_not_just_a_handful(): void
    {
        // Everyone reaching this point asked for the item, so there is no sense
        // telling the first and not the second. The ceiling sits far above the
        // size of a normal match set (setUp holds it at 10) precisely so that it
        // does not ration one, and this pins that: three matches, three mails.
        $message = $this->seedSilentOffer();
        $a = $this->memberAt(51.9, 0.8);
        $b = $this->memberAt(51.9, 0.8);
        $c = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($a);
        $this->savedSearchFor($b);
        $this->savedSearchFor($c);

        $this->service()->run();

        $mailed = $this->mailedFor((int) $message->id);
        $this->assertCount(3, $mailed, 'all three asked, so all three hear');
    }

    public function test_the_ceiling_still_bounds_a_mailbomb(): void
    {
        // Uncapped is not safe either: a common term like "sofa" is held by
        // hundreds of members on live, so there has to be SOME ceiling, and it has
        // to bind when a post really does match that many people.
        config(['freegle.firstreply.matchmail.max_per_post' => 2]);

        $message = $this->seedSilentOffer();
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->savedSearchFor($this->memberAt(51.9, 0.8));

        $this->service()->run();

        $this->assertCount(2, $this->mailedFor((int) $message->id));
    }

    public function test_the_number_mailed_can_be_changed_without_a_deploy(): void
    {
        // The cap is the whole mail bill of the feature, and the moment you want
        // to move it is the moment it is mailing too many people - which is the
        // worst possible time to be waiting for a release.
        config(['freegle.firstreply.matchmail.max_per_post' => 5]);
        DB::table('config')->insert([
            'key' => MatchMailService::CONFIG_MAX_PER_POST,
            'value' => '1',
        ]);

        $message = $this->seedSilentOffer();
        $this->matchHolderAt(51.9, 0.8);
        $this->matchHolderAt(51.9, 0.8);
        $this->matchHolderAt(51.9, 0.8);

        $this->service()->run();

        $this->assertCount(
            1,
            $this->mailedFor((int) $message->id),
            'the runtime override should win over the deployed default'
        );
    }

    public function test_without_an_override_the_deployed_default_stands(): void
    {
        // An empty config table means "no opinion", so the deployed default has
        // to stand. Otherwise the override would be a behaviour change in itself.
        config(['freegle.firstreply.matchmail.max_per_post' => 2]);

        $message = $this->seedSilentOffer();
        $this->matchHolderAt(51.9, 0.8);
        $this->matchHolderAt(51.9, 0.8);
        $this->matchHolderAt(51.9, 0.8);

        $this->service()->run();

        $this->assertCount(2, $this->mailedFor((int) $message->id));
    }

    public function test_setting_the_number_to_zero_stops_the_mail(): void
    {
        // Zero is the stop button: the one setting you would reach for to halt the
        // mail without touching a deploy. A candidate who would otherwise be
        // mailed is seeded, so this fails if zero is read as "no opinion".
        DB::table('config')->insert([
            'key' => MatchMailService::CONFIG_MAX_PER_POST,
            'value' => '0',
        ]);

        $message = $this->seedSilentOffer();
        $this->matchHolderAt(51.9, 0.8);

        $stats = $this->service()->run();

        $this->assertSame(0, $stats['mailed']);
        $this->assertCount(0, $this->mailedFor((int) $message->id));
    }

    public function test_a_matched_member_keeps_their_digest(): void
    {
        // Their mail was an extra, justified by their own saved search. Taking
        // the digest away as well would be a straight loss to them.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->mailedFor((int) $message->id));
        $this->assertNull(
            DB::table('users_digests')
                ->where('userid', $searcher->id)->where('mode', 'daily')->value('lastsent'),
            'match mail is an extra, so it must not consume their digest'
        );
    }

    public function test_a_reply_is_attributed_so_the_signal_can_be_judged(): void
    {
        // Without this there is no way to tell whether any of it does anything.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();
        $this->assertArrayHasKey($searcher->id, $this->mailedFor((int) $message->id));

        // They reply to the post we told them about.
        $poster = \App\Models\User::find($message->fromuser);
        $room = $this->createTestChatRoom($searcher, $poster);
        $this->createTestChatMessage($room, $searcher, [
            'type' => \App\Models\ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $message->id,
        ]);

        $this->assertSame(1, $this->service()->attributeReplies());
        $this->assertNotNull(
            DB::table('firstreply_scouts')
                ->where('msgid', $message->id)->where('userid', $searcher->id)->value('replied_at')
        );
    }

    public function test_attribution_ignores_a_reply_to_a_different_post(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);
        $this->service()->run();

        $poster = $this->createTestUser();
        $elsewhere = $this->createTestMessage($poster, $this->createTestGroup());
        $room = $this->createTestChatRoom($searcher, $poster);
        $this->createTestChatMessage($room, $searcher, [
            'type' => \App\Models\ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $elsewhere->id,
        ]);

        $this->assertSame(0, $this->service()->attributeReplies());
    }

    public function test_a_brand_new_post_is_matched_without_waiting_for_the_background_pass(): void
    {
        // This fires as soon as a post is seen, so it regularly arrives before
        // firstreply:maxreach has worked out the eventual reach. Without that
        // nobody is eligible, so this path fills it in itself rather than
        // making the post wait a minute for a different cron.
        $message = $this->seedSilentOffer(false);

        $this->assertNull(
            DB::table('rippling_reach')->where('msgid', $message->id)->value('max_cumulative_users'),
            'precondition: the background pass has not run for this post'
        );

        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->mailedFor((int) $message->id));
    }

    /** An open WANTED at (lat,lng), declared to the matcher as a match. */
    private function wantedAt(\App\Models\User $user, float $lat, float $lng): void
    {
        $this->openPostAt($user, $lat, $lng, Message::TYPE_WANTED);
    }

    /** An open OFFER at (lat,lng), declared to the matcher as a match. */
    private function offerAt(\App\Models\User $user, float $lat, float $lng): void
    {
        $this->openPostAt($user, $lat, $lng, Message::TYPE_OFFER);
    }

    /**
     * An open post of this member's own, of either type, that matches.
     *
     * Which type is a match for which is the matcher's rule, not this service's,
     * so the fake is told the post matched and the type only has to be the one
     * the test is talking about.
     */
    private function openPostAt(\App\Models\User $user, float $lat, float $lng, string $type): void
    {
        $group = $this->createTestGroup();
        $post = $this->createTestMessage($user, $group, [
            'type' => $type,
            'subject' => strtoupper($type) . ': Pine bookcase (TestLocation)',
        ]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, NOW())',
            [$post->id, $lng, $lat, $group->id, $type]
        );

        $this->apiMatchIds[] = (int) $post->id;
        $this->refreshApiFake();
    }

    /**
     * A recipient is somebody the ripple has NOT reached yet.
     *
     * Tick 1 is the reach the post has now. Someone inside it will be told anyway
     * on the ordinary schedule, so mailing them spends a slot, a mail and
     * a per-member cooldown to change nothing. Reaching past the current edge is
     * the entire point.
     */
    public function test_does_not_mail_someone_already_inside_the_current_reach(): void
    {
        $message = $this->seedSilentOffer();

        $inside = $this->memberAt(51.50, -0.10);
        $this->wantedAt($inside, 51.50, -0.10);

        $outside = $this->memberAt(51.90, 0.80);
        $this->wantedAt($outside, 51.90, 0.80);

        $this->service()->run();

        $mailed = $this->mailedFor((int) $message->id);
        $this->assertArrayNotHasKey($inside->id, $mailed, 'already in reach - the ripple has them');
        $this->assertArrayHasKey($outside->id, $mailed, 'past the edge is the whole point');
    }

    /**
     * A matched member who replies pulls the reach out to cover them, so the people around
     * them get the same chance instead of waiting on the clock.
     *
     * Recorded as a floor rather than written as a polygon: advancing reach means
     * tick geometry, the origin-group union, bounds and rejection clips, and
     * ExpandService already does all of it.
     */
    public function test_a_matched_member_who_replies_pulls_the_reach_out_to_them(): void
    {
        $message = $this->seedSilentOffer();

        $matched = $this->memberAt(51.90, 0.80);
        $this->wantedAt($matched, 51.90, 0.80);

        $this->service()->run();
        $this->assertArrayHasKey($matched->id, $this->mailedFor((int) $message->id));

        DB::table('chat_messages')->insert([
            'chatid' => $this->chatRoomFor((int) $matched->id),
            'userid' => $matched->id,
            'refmsgid' => $message->id,
            'message' => 'Is this still available?',
            'type' => 'Interested',
            'date' => now(),
        ]);

        $this->service()->attributeReplies();

        $row = DB::table('rippling_reach')->where('msgid', $message->id)->first();
        $this->assertNotNull($row->min_tick, 'the reply must set a floor');
        $this->assertGreaterThan(1, (int) $row->min_tick, 'past the tick the post was on');
        $this->assertNotNull($row->next_expansion_at, 'and it must be due to expand');
    }

    /** A reply from inside the current reach teaches nothing new, so moves nothing. */
    public function test_a_reply_from_within_reach_does_not_move_the_floor(): void
    {
        $message = $this->seedSilentOffer();

        $near = $this->memberAt(51.50, -0.10);
        DB::table('firstreply_scouts')->insert([
            'msgid' => $message->id,
            'userid' => $near->id,
            'reason' => 'wanted',
            'score' => 5.0,
            'sent_at' => now()->subHour(),
        ]);

        DB::table('chat_messages')->insert([
            'chatid' => $this->chatRoomFor((int) $near->id),
            'userid' => $near->id,
            'refmsgid' => $message->id,
            'message' => 'Yes please',
            'type' => 'Interested',
            'date' => now(),
        ]);

        $this->service()->attributeReplies();

        $row = DB::table('rippling_reach')->where('msgid', $message->id)->first();
        $this->assertNull($row->min_tick, 'tick 1 already covers them - nothing to pull out to');
    }

    /**
     * A chat room to hang a reply off. Both sides are real users: chat_rooms has
     * foreign keys onto users, so an invented id fails the insert rather than the
     * assertion, which tells you nothing about the code under test.
     */
    private function chatRoomFor(int $userId): int
    {
        $other = $this->createTestUser();

        return (int) DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $userId,
            'user2' => $other->id,
        ]);
    }
}
