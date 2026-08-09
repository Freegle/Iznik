<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\Membership;
use App\Models\Message;
use App\Services\FirstReply\MaxReachService;
use App\Services\FreegleApiClient;
use App\Services\FirstReply\ScoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScoutServiceTest extends TestCase
{
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        // The scout mail really goes through the digest spool now, because the
        // ledger and the digest-brought-forward stamp are both keyed on who was
        // ACTUALLY mailed rather than who was picked.
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
            'freegle.firstreply.scouts.enabled' => true,
            'freegle.firstreply.scouts.quiet_minutes' => 0,
            'freegle.firstreply.scouts.max_per_post' => 10,
            'freegle.firstreply.scouts.min_score' => 1.0,
            // The run() waking-hours gate (shared with the ripple) would zero
            // every test that happens to execute overnight - CI runs at all
            // hours. Hold the window open; the gate itself is pinned by
            // test_scouts_sleep_outside_waking_hours.
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

    private function service(): ScoutService
    {
        return app(ScoutService::class);
    }

    /** A silent OFFER, rippling, with its eventual reach known. */
    private function seedSilentOffer(bool $populateMaxReach = true): Message
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

    /** Give this member a community, on a daily digest unless told otherwise. */
    private function joinGroup(int $userId, int $groupId, int $emailFrequency = 24): void
    {
        Membership::create([
            'userid' => $userId,
            'groupid' => $groupId,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => $emailFrequency,
            'added' => now(),
        ]);
    }

    /**
     * A frequent replier on this post's own community: the WEAK signal, which may
     * only ever be their daily digest arriving early.
     */
    /**
     * A frequent replier on the post's own group.
     *
     * Callers place them OUTSIDE the current reach polygon and inside the
     * eventual one, because that is what a scout is. Being on the group and
     * outside the current polygon is the normal case rather than a contrivance:
     * a group covers a wide area and tick 1 is a five-minute drive from the post.
     */
    private function frequentReplierOn(int $msgid, float $lat, float $lng, int $emailFrequency = 24): \App\Models\User
    {
        config(['freegle.firstreply.scouts.frequent_replier_min' => 1]);

        $user = $this->memberAt($lat, $lng);
        $groupid = (int) DB::table('messages_groups')->where('msgid', $msgid)->value('groupid');
        $this->joinGroup((int) $user->id, $groupid, $emailFrequency);

        // One Interested reply somewhere else, so they count as a replier.
        $poster = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $poster);
        $other = $this->createTestMessage($poster, $this->createTestGroup());
        $this->createTestChatMessage($room, $user, [
            'type' => \App\Models\ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $other->id,
        ]);

        return $user;
    }

    /**
     * Make this member's Interested replies land $minutes after the post arrived,
     * so a test can say how FAST they are rather than only how often.
     */
    private function setReplyLatency(int $userId, int $minutes): void
    {
        DB::table('chat_messages')
            ->join('messages', 'messages.id', '=', 'chat_messages.refmsgid')
            ->where('chat_messages.userid', $userId)
            ->where('chat_messages.type', \App\Models\ChatMessage::TYPE_INTERESTED)
            ->update([
                'chat_messages.date' => DB::raw(
                    'DATE_ADD(messages.arrival, INTERVAL ' . (int) $minutes . ' MINUTE)'
                ),
            ]);
    }

    /** Average minutes between a post arriving and this member replying to it. */
    private function replyLatencyOf(int $userId): ?float
    {
        $v = DB::table('chat_messages')
            ->join('messages', 'messages.id', '=', 'chat_messages.refmsgid')
            ->where('chat_messages.userid', $userId)
            ->where('chat_messages.type', \App\Models\ChatMessage::TYPE_INTERESTED)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, messages.arrival, chat_messages.date)) AS m')
            ->value('m');

        return $v === null ? null : (float) $v;
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

    /** The last instant that still counts as yesterday in London. */
    private function yesterday(): \Carbon\Carbon
    {
        return \Carbon\Carbon::now('Europe/London')->startOfDay()->subMinute()->setTimezone('UTC');
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
        $this->wantedAt($wanter, 51.9, 0.8);

        $this->service()->run();

        $this->assertArrayHasKey($wanter->id, $this->scoutsFor((int) $message->id));
        $this->assertSame('wanted', $this->scoutsFor((int) $message->id)[$wanter->id]);
    }

    public function test_picks_someone_who_saved_a_matching_search(): void
    {
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);

        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));
    }

    public function test_ignores_someone_the_post_will_never_reach(): void
    {
        $message = $this->seedSilentOffer();
        $farAway = $this->memberAt(57.1, -2.1);

        $this->savedSearchFor($farAway);

        $this->service()->run();

        $this->assertArrayNotHasKey($farAway->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_member_is_not_scouted_twice_in_the_cooldown(): void
    {
        config(['freegle.firstreply.scouts.user_cooldown_hours' => 24]);

        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

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
        $this->savedSearchFor($searcher);

        $this->service()->run();
        $before = count($this->scoutsFor((int) $message->id));
        $this->service()->run();

        $this->assertSame($before, count($this->scoutsFor((int) $message->id)));
    }

    public function test_the_rationed_slots_go_to_whoever_stands_nearest_the_reach_edge(): void
    {
        config(['freegle.firstreply.scouts.max_per_post' => 1]);

        $message = $this->seedSilentOffer();
        // Both sit outside today's reach and inside the eventual one. The far
        // one is created first so an insertion-ordered tie (the old behaviour)
        // would pick it - the slot must go to whoever is just past the edge,
        // because that is who the reach is about to cover anyway.
        $far = $this->frequentReplierOn((int) $message->id, 51.5, 0.8);
        $near = $this->frequentReplierOn((int) $message->id, 51.5, 0.0);

        $this->service()->run();

        $scouts = $this->scoutsFor((int) $message->id);
        $this->assertArrayHasKey($near->id, $scouts, 'the rationed slot goes nearest-first');
        $this->assertArrayNotHasKey($far->id, $scouts, 'the distant member waits for the reach itself');
    }

    public function test_scouted_members_are_marked_so_the_reach_mailer_does_not_repeat_the_post(): void
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

    public function test_never_scouts_the_poster_about_their_own_post(): void
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

        $this->assertArrayNotHasKey((int) $message->fromuser, $this->scoutsFor((int) $message->id));
    }

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        config(['freegle.firstreply.scouts.enabled' => false]);
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->assertSame(0, $this->service()->run()['mailed']);
        $this->assertSame([], $this->scoutsFor((int) $message->id));
    }

    public function test_scouts_sleep_outside_waking_hours(): void
    {
        // A frequent scout is a digest brought forward, and no digest goes out
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
        $this->assertSame([], $this->scoutsFor((int) $message->id));
    }

    public function test_frequent_repliers_are_only_drawn_from_the_posts_own_communities(): void
    {
        // The frequent-replier signal says nothing about THIS item, so it is
        // deliberately bounded to the communities the post is on rather than
        // being cast across the whole eventual reach.
        $message = $this->seedSilentOffer();
        $replier = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        $this->assertArrayHasKey($replier->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_fast_replier_is_chosen_over_a_slow_one(): void
    {
        // The whole point of the lever is a FAST first reply, and reply COUNT
        // says nothing about speed: measured on live over 14 days, members with
        // 3+ replies average ~4.5 of them if they answer within half an hour and
        // ~5.0 if they take over two days. Volume is flat across every latency
        // band, while latency itself spans two orders of magnitude. Ordering on
        // volume therefore ranked the axis that does not matter - and mailing a
        // three-day replier buys nothing the ripple would not have delivered.
        config(['freegle.firstreply.scouts.max_per_post' => 1]);

        $message = $this->seedSilentOffer();

        $slow = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $fast = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->setReplyLatency((int) $slow->id, 3 * 24 * 60);
        $this->setReplyLatency((int) $fast->id, 15);

        // Prove the FIXTURE before trusting what the service does with it - if
        // the latencies never landed, the assertion below would be testing the
        // ordering of two identical rows.
        $this->assertEqualsWithDelta(15, $this->replyLatencyOf((int) $fast->id), 1, 'fast fixture');
        $this->assertEqualsWithDelta(4320, $this->replyLatencyOf((int) $slow->id), 1, 'slow fixture');

        $this->service()->run();

        $scouts = $this->scoutsFor((int) $message->id);
        $this->assertSame(
            [(int) $fast->id],
            array_map('intval', array_keys($scouts)),
            'expected only the member who answers in fifteen minutes; fast=' . $fast->id
                . ' slow=' . $slow->id . ' got=' . json_encode($scouts)
        );
    }

    // --- What justifies the mail decides whether it may be an extra one -------

    public function test_a_frequent_replier_who_already_had_todays_digest_is_not_mailed_again(): void
    {
        // Nothing about this signal is about THIS item, so the mail can only be
        // their daily digest arriving early. Theirs has already been.
        $message = $this->seedSilentOffer();
        $replier = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->digestSentAt((int) $replier->id, $this->earlierToday());

        $this->service()->run();

        $this->assertArrayNotHasKey($replier->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_frequent_replier_whose_digest_has_not_been_today_is_mailed(): void
    {
        $message = $this->seedSilentOffer();
        $replier = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->digestSentAt((int) $replier->id, $this->yesterday());

        $this->service()->run();

        $this->assertArrayHasKey($replier->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_frequent_replier_who_takes_no_post_email_anywhere_is_skipped(): void
    {
        // emailfrequency 0 is "never". There is no digest to bring forward.
        $message = $this->seedSilentOffer();
        $replier = $this->frequentReplierOn((int) $message->id, 51.9, 0.8, 0);

        $this->service()->run();

        $this->assertArrayNotHasKey($replier->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_matching_search_may_be_an_extra_mail_even_after_todays_digest(): void
    {
        // They saved a search for this. That is a request, item by item, so it is
        // allowed to be a mail they would not otherwise have had today.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);
        $this->digestSentAt((int) $searcher->id, $this->earlierToday());

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_match_still_respects_the_suggested_posts_consent(): void
    {
        // relevantallowed is the existing "Suggested posts for you" setting, and a
        // match-driven scout mail is exactly a suggested post.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        DB::table('users')->where('id', $searcher->id)->update(['relevantallowed' => 0]);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayNotHasKey($searcher->id, $this->scoutsFor((int) $message->id));
    }

    public function test_a_skipped_candidate_is_replaced_rather_than_leaving_a_hole(): void
    {
        // "If they have had one, find other scouts" - the slot goes to the next
        // candidate rather than going unused, so the post still gets its full
        // complement. Filtering therefore has to happen before the cap.
        config(['freegle.firstreply.scouts.max_per_post' => 1]);

        $message = $this->seedSilentOffer();
        $alreadyMailed = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->digestSentAt((int) $alreadyMailed->id, $this->earlierToday());
        $available = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        $scouts = $this->scoutsFor((int) $message->id);
        $this->assertArrayNotHasKey($alreadyMailed->id, $scouts);
        $this->assertArrayHasKey($available->id, $scouts);
        $this->assertCount(1, $scouts);
    }

    public function test_people_who_asked_for_the_item_are_not_limited_by_the_scout_cap(): void
    {
        // The small cap rations the GUESS ("you reply to a lot of things"). It has
        // no business rationing people who have a saved search for this exact
        // thing - they asked, and there is no sense telling the first one and not
        // the second.
        config(['freegle.firstreply.scouts.max_per_post' => 1]);

        $message = $this->seedSilentOffer();
        $a = $this->memberAt(51.9, 0.8);
        $b = $this->memberAt(51.9, 0.8);
        $c = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($a);
        $this->savedSearchFor($b);
        $this->savedSearchFor($c);

        $this->service()->run();

        $scouts = $this->scoutsFor((int) $message->id);
        $this->assertCount(3, $scouts, 'all three asked, so all three hear');
    }

    public function test_the_strong_ceiling_still_bounds_a_mailbomb(): void
    {
        // Uncapped is not safe either: a common term like "sofa" is held by
        // hundreds of members on live, so there has to be SOME ceiling. It sits
        // far above the normal case rather than at the propensity cap.
        config([
            'freegle.firstreply.scouts.max_per_post' => 10,
            'freegle.firstreply.scouts.max_strong_per_post' => 2,
        ]);

        $message = $this->seedSilentOffer();
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->savedSearchFor($this->memberAt(51.9, 0.8));

        $this->service()->run();

        $this->assertCount(2, $this->scoutsFor((int) $message->id));
    }

    public function test_propensity_scouts_do_not_eat_into_the_strong_allowance(): void
    {
        // Both kinds on one post: the frequent replier is capped at its own small
        // number, the searchers are not, and neither cap consumes the other's
        // budget.
        config([
            'freegle.firstreply.scouts.max_per_post' => 1,
            'freegle.firstreply.scouts.max_strong_per_post' => 50,
        ]);

        $message = $this->seedSilentOffer();
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->savedSearchFor($this->memberAt(51.9, 0.8));
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        // scoutsFor() is userid => reason, so the values are the reasons already.
        $reasons = array_count_values($this->scoutsFor((int) $message->id));

        $this->assertSame(2, $reasons['search'] ?? 0, 'both searchers hear');
        $this->assertSame(1, $reasons['frequent'] ?? 0, 'the guess stays rationed');
    }

    public function test_the_number_of_scouts_can_be_changed_without_a_deploy(): void
    {
        // The cap is the whole mail bill of the feature, and the moment you want
        // to move it is the moment it is mailing too many people - which is the
        // worst possible time to be waiting for a release.
        config(['freegle.firstreply.scouts.max_per_post' => 5]);
        DB::table('config')->insert([
            'key' => ScoutService::CONFIG_MAX_PER_POST,
            'value' => '1',
        ]);

        $message = $this->seedSilentOffer();
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        $this->assertCount(
            1,
            $this->scoutsFor((int) $message->id),
            'the runtime override should win over the deployed default'
        );
    }

    public function test_without_an_override_the_deployed_default_stands(): void
    {
        // An empty config table has to behave exactly as it did before this
        // existed, otherwise the override is a behaviour change in itself.
        config(['freegle.firstreply.scouts.max_per_post' => 2]);

        $message = $this->seedSilentOffer();
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        $this->assertCount(2, $this->scoutsFor((int) $message->id));
    }

    public function test_setting_the_number_to_zero_stops_the_mail(): void
    {
        // Zero is the stop button. scoutPost() used to floor the cap at 1, so
        // without this the one setting you would reach for to halt the mail
        // still mailed somebody on every post.
        DB::table('config')->insert([
            'key' => ScoutService::CONFIG_MAX_PER_POST,
            'value' => '0',
        ]);

        $message = $this->seedSilentOffer();
        $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $stats = $this->service()->run();

        $this->assertSame(0, $stats['mailed']);
        $this->assertCount(0, $this->scoutsFor((int) $message->id));
    }

    public function test_a_frequent_scout_has_their_digest_recorded_as_brought_forward(): void
    {
        // The mail they just got IS their digest, moved earlier, so today's must
        // not also go out.
        $message = $this->seedSilentOffer();
        $replier = $this->frequentReplierOn((int) $message->id, 51.9, 0.8);

        $this->service()->run();

        $lastsent = DB::table('users_digests')
            ->where('userid', $replier->id)->where('mode', 'daily')->value('lastsent');

        $this->assertNotNull($lastsent, 'the digest should now be recorded as sent');
        $this->assertTrue(
            \Carbon\Carbon::parse($lastsent)->greaterThanOrEqualTo($this->londonDayStart()),
            'and recorded as sent TODAY, so today\'s digest cron skips them'
        );
    }

    public function test_a_match_scout_keeps_their_digest(): void
    {
        // Their mail was an extra, justified by their own saved search. Taking
        // the digest away as well would be a straight loss to them.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));
        $this->assertNull(
            DB::table('users_digests')
                ->where('userid', $searcher->id)->where('mode', 'daily')->value('lastsent'),
            'a match-driven scout mail must not consume their digest'
        );
    }

    public function test_a_scouted_reply_is_attributed_so_the_signal_can_be_judged(): void
    {
        // Without this there is no way to tell whether scouting does anything.
        $message = $this->seedSilentOffer();
        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();
        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));

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

    private function londonDayStart(): \Carbon\Carbon
    {
        return \Carbon\Carbon::now('Europe/London')->startOfDay()->setTimezone('UTC');
    }

    public function test_a_brand_new_post_is_scouted_without_waiting_for_the_background_pass(): void
    {
        // Scouting fires as soon as a post is seen, so it regularly arrives before
        // firstreply:maxreach has worked out the eventual reach. Without that
        // nobody is eligible, so the scout path fills it in itself rather than
        // making the post wait a minute for a different cron.
        $message = $this->seedSilentOffer(false);

        $this->assertNull(
            DB::table('rippling_reach')->where('msgid', $message->id)->value('max_cumulative_users'),
            'precondition: the background pass has not run for this post'
        );

        $searcher = $this->memberAt(51.9, 0.8);
        $this->savedSearchFor($searcher);

        $this->service()->run();

        $this->assertArrayHasKey($searcher->id, $this->scoutsFor((int) $message->id));
    }

    /** An open WANTED at (lat,lng) that matches the offer's keywords. */
    private function wantedAt(\App\Models\User $user, float $lat, float $lng): void
    {
        $group = $this->createTestGroup();
        $wanted = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Pine bookcase (TestLocation)',
        ]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, NOW())',
            [$wanted->id, $lng, $lat, $group->id, Message::TYPE_WANTED]
        );

        $this->apiMatchIds[] = (int) $wanted->id;
        $this->refreshApiFake();
    }

    /**
     * A scout is somebody the ripple has NOT reached yet.
     *
     * Tick 1 is the reach the post has now. Someone inside it will be told anyway
     * on the ordinary schedule, so scouting them spends a scout slot, a mail and
     * a per-member cooldown to change nothing. Reaching past the current edge is
     * the entire point.
     */
    public function test_does_not_scout_someone_already_inside_the_current_reach(): void
    {
        $message = $this->seedSilentOffer();

        $inside = $this->memberAt(51.50, -0.10);
        $this->wantedAt($inside, 51.50, -0.10);

        $outside = $this->memberAt(51.90, 0.80);
        $this->wantedAt($outside, 51.90, 0.80);

        $this->service()->run();

        $scouts = $this->scoutsFor((int) $message->id);
        $this->assertArrayNotHasKey($inside->id, $scouts, 'already in reach - the ripple has them');
        $this->assertArrayHasKey($outside->id, $scouts, 'past the edge is the point of scouting');
    }

    /**
     * A scout who replies pulls the reach out to cover them, so the people around
     * them get the same chance instead of waiting on the clock.
     *
     * Recorded as a floor rather than written as a polygon: advancing reach means
     * tick geometry, the origin-group union, bounds and rejection clips, and
     * ExpandService already does all of it.
     */
    public function test_a_scout_who_replies_pulls_the_reach_out_to_them(): void
    {
        $message = $this->seedSilentOffer();

        $scout = $this->memberAt(51.90, 0.80);
        $this->wantedAt($scout, 51.90, 0.80);

        $this->service()->run();
        $this->assertArrayHasKey($scout->id, $this->scoutsFor((int) $message->id));

        DB::table('chat_messages')->insert([
            'chatid' => $this->chatRoomFor((int) $scout->id),
            'userid' => $scout->id,
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
