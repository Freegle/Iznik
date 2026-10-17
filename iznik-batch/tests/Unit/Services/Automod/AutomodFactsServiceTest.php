<?php

namespace Tests\Unit\Services\Automod;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\Automod\AutomodFactsService;
use App\Support\EnvSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the member_veto and group_disallows facts that AutomodFactsService::facts()
 * computes (app/Services/Automod/AutomodFactsService.php). These were originally written
 * against AutoApproveCleanService's own veto/group checks before that logic moved here as
 * part of the automod flowchart (plans/active/automod-flowchart.md); they now call the
 * public facts() method directly rather than going through a post-processing pipeline.
 */
class AutomodFactsServiceTest extends TestCase
{
    private AutomodFactsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutomodFactsService();
    }

    /**
     * Create a user, group and a Pending offer message from that user on that group,
     * mirroring AutoApproveCleanServiceTest::makeApprovable()'s shape but without seeding
     * any contentcheck/automod state, since facts() does not read messages_groups.
     *
     * @return array{0: User, 1: Group, 2: Message}
     */
    private function makeFactsSubject(array $opts = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup($opts['group'] ?? []);
        $this->createMembership($user, $group, $opts['membership'] ?? []);
        $message = $this->createTestMessage($user, $group, $opts['message'] ?? []);

        return [$user, $group, $message];
    }

    public function test_group_moderated_setting_disallows(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject([
            'group' => ['settings' => ['moderated' => 1]],
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['group_disallows']);
        $this->assertSame('Group moderates all posts', $facts['group_disallows_detail']);
    }

    public function test_group_closed_disallows(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject([
            'group' => ['settings' => ['closed' => true]],
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['group_disallows']);
        $this->assertSame('Group is closed', $facts['group_disallows_detail']);
    }

    public function test_veto_microvolunteering_reject(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $reviewer = $this->createTestUser();
        DB::table('microactions')->insert([
            'userid'         => $reviewer->id,
            'msgid'          => $message->id,
            'actiontype'     => 'CheckMessage',
            'result'         => 'Reject',
            'score_negative' => 1,
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['member_veto']);
        $this->assertTrue($facts['microvol_reject']);
        $this->assertStringContainsString('microvolunteer', $facts['microvol_reject_detail']);
    }

    public function test_does_not_veto_microvolunteering_approve(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $reviewer = $this->createTestUser();
        DB::table('microactions')->insert([
            'userid'         => $reviewer->id,
            'msgid'          => $message->id,
            'actiontype'     => 'CheckMessage',
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts['member_veto']);
        $this->assertNull($facts['member_veto_detail']);
    }

    public function test_veto_user_note(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('users_comments')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'user1'   => 'Keep an eye on this member.',
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['member_veto']);
        $this->assertTrue($facts['mod_note']);
        $this->assertStringContainsString('Note left', $facts['mod_note_detail']);
    }

    public function test_veto_recent_negative_mod_log(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(1),
            'type'      => 'User',
            'subtype'   => 'Mailed',
            'user'      => $user->id,
            'byuser'    => $mod->id,
            'groupid'   => $group->id,
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['member_veto']);
        $this->assertTrue($facts['recent_action']);
        $this->assertStringContainsString('User Mailed', $facts['recent_action_detail']);
    }

    public function test_does_not_veto_old_negative_log(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subDays(120), // outside the 90-day danger window
            'type'      => 'User',
            'subtype'   => 'Mailed',
            'user'      => $user->id,
            'byuser'    => $mod->id,
            'groupid'   => $group->id,
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts['member_veto']);
        $this->assertNull($facts['member_veto_detail']);
    }

    public function test_veto_known_spammer(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('spam_users')->insert([
            'userid'     => $user->id,
            'collection' => 'Spammer',
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['member_veto']);
        $this->assertTrue($facts['spammer']);
        $this->assertStringContainsString('spammer', $facts['spammer_detail']);
    }

    public function test_veto_membership_review_pending(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->update(['reviewrequestedat' => now()->subHour(), 'reviewedat' => null]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['member_veto']);
        $this->assertTrue($facts['membership_review']);
        $this->assertStringContainsString('Review requested', $facts['membership_review_detail']);
    }

    public function test_normalise_subject_ignores_prefix_place_and_case(): void
    {
        $this->assertSame("brown sofa", AutomodFactsService::normaliseSubject("OFFER: Brown  Sofa (Leeds LS6)"));
        $this->assertSame("ladder", AutomodFactsService::normaliseSubject("wanted:ladder"));
    }

    public function test_duplicate_when_the_same_post_is_already_open(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(["message" => ["subject" => "OFFER: Brown sofa (Leeds)"]]);
        $this->createTestMessage($user, $group, ["subject" => "OFFER: brown sofa (Headingley)", "arrival" => now()->subDays(2)]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts["duplicate"]);
        $this->assertStringContainsString("open post", $facts["duplicate_detail"]);
    }

    public function test_duplicate_when_reposted_sooner_than_the_community_allows(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject([
            "group" => ["settings" => ["reposts" => ["offer" => 5, "wanted" => 7]]],
            "message" => ["subject" => "OFFER: Brown sofa (Leeds)"],
        ]);
        $earlier = $this->createTestMessage($user, $group, ["subject" => "OFFER: Brown sofa (Leeds)", "arrival" => now()->subDays(2)]);
        DB::table("messages_outcomes")->insert(["msgid" => $earlier->id, "outcome" => "Withdrawn", "timestamp" => now()->subDay()]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts["duplicate"]);
        $this->assertStringContainsString("repost after 5 days", $facts["duplicate_detail"]);
    }

    public function test_not_duplicate_after_the_repost_interval_or_for_a_different_item(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(["message" => ["subject" => "OFFER: Brown sofa (Leeds)"]]);
        $old = $this->createTestMessage($user, $group, ["subject" => "OFFER: Brown sofa (Leeds)", "arrival" => now()->subDays(10)]);
        DB::table("messages")->where("id", $old->id)->update(["arrival" => now()->subDays(10)]);
        DB::table("messages_outcomes")->insert(["msgid" => $old->id, "outcome" => "Taken", "timestamp" => now()->subDays(9)]);
        $this->createTestMessage($user, $group, ["subject" => "OFFER: Kettle (Leeds)"]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts["duplicate"]);
    }

    public function test_blank_env_values_fall_back_to_the_defaults(): void
    {
        // docker-compose passes unset variables through as empty strings.
        $names = ["FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "FREEGLE_AUTOAPPROVE_DANGER_LOG_DAYS"];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("$name=");
            $_ENV[$name] = "";
            $_SERVER[$name] = "";
        }

        try {
            // The config holds the raw value ('' here); EnvSetting turns blank into the default.
            $config = require base_path("config/freegle.php");
            $this->assertSame(20, EnvSetting::positiveInt($config["autoapprove"]["delay_minutes"], 20));
            $this->assertSame(90, EnvSetting::positiveInt($config["autoapprove"]["danger_log_days"], 90));
            $this->assertSame(60, EnvSetting::positiveInt($config["automod"]["timeout"], 60));
            $this->assertFalse(EnvSetting::flag($config["autoapprove"]["enabled"]));
        } finally {
            foreach ($saved as $name => [$env, $e, $srv]) {
                $env === false ? putenv($name) : putenv("$name=$env");
                if ($e === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $e; }
                if ($srv === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $srv; }
            }
        }
    }

    public function test_member_moderated_only_for_an_explicit_posting_status(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table("memberships")->where("userid", $user->id)->where("groupid", $group->id)->update(["ourPostingStatus" => null]);
        $this->assertFalse($this->service->facts($message->id, $group->id)["member_moderated"], "blank status is the auto-moderated tier");

        DB::table("memberships")->where("userid", $user->id)->where("groupid", $group->id)->update(["ourPostingStatus" => "MODERATED"]);
        $this->assertTrue($this->service->facts($message->id, $group->id)["member_moderated"]);
    }

    public function test_other_open_posts_are_listed_for_the_duplicate_question(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(["message" => ["subject" => "OFFER: Brown sofa (Leeds)"]]);
        $other = $this->createTestMessage($user, $group, ["subject" => "OFFER: Kettle (Leeds)", "textbody" => "Works fine", "arrival" => now()->subMinutes(5)]);
        $gone = $this->createTestMessage($user, $group, ["subject" => "OFFER: Lamp (Leeds)", "arrival" => now()->subMinutes(5)]);
        DB::table("messages_outcomes")->insert(["msgid" => $gone->id, "outcome" => "Taken", "timestamp" => now()]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts["has_other_posts"]);
        $this->assertCount(1, $facts["other_posts"]);
        $this->assertStringContainsString("Kettle", $facts["other_posts"][0]);
        $this->assertStringContainsString("Works fine", $facts["other_posts"][0]);
    }

    public function test_posting_status_detail_names_the_status(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table("memberships")->where("userid", $user->id)->where("groupid", $group->id)->update(["ourPostingStatus" => "MODERATED"]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts["member_moderated"]);
        $this->assertSame("Moderated", $facts["member_moderated_detail"]);
    }

    public function test_spam_findings_split_into_links_and_patterns(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table("messages_groups")->where("msgid", $message->id)->where("groupid", $group->id)->update([
            "contentcheck_reasons" => json_encode([
                ["check" => "Url", "category" => null, "action" => "flag", "detail" => "Link to example.com"],
                ["check" => "BulkMail", "category" => null, "action" => "flag", "detail" => "Sent to 40 addresses"],
            ]),
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts["spam_links"]);
        $this->assertSame("Link to example.com", $facts["spam_links_detail"]);
        $this->assertTrue($facts["spam_pattern"]);
        $this->assertSame("Sent to 40 addresses", $facts["spam_pattern_detail"]);
    }

    /** Of two similar open posts, the later one is the duplicate; the earlier is not held for it. */
    public function test_the_earlier_of_two_similar_open_posts_is_not_the_duplicate(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(["message" => ["subject" => "OFFER: Brown sofa (Leeds)", "arrival" => now()->subMinutes(2)]]);
        $this->createTestMessage($user, $group, ["subject" => "OFFER: Brown sofa (Leeds)", "arrival" => now()->subMinute()]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts["duplicate"], "the later post is the duplicate, not this one");
        $this->assertFalse($facts["has_other_posts"]);
    }

    // --- round 2 ---------------------------------------------------------------------------

    /**
     * The ripple engine writes Message/Deleted (a retracted copy) and User/Mailed 'RippleIntro'
     * rows with no byuser. They are lifecycle, not a moderator acting, and used to veto every
     * member whose post rippled for 90 days.
     */
    public function test_a_system_log_with_no_actor_is_not_recent_action(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('logs')->insert([
            ['timestamp' => now()->subDays(2), 'type' => 'Message', 'subtype' => 'Deleted', 'user' => $user->id, 'byuser' => null, 'groupid' => $group->id, 'text' => 'Rippling: removed on leave'],
            ['timestamp' => now()->subDays(2), 'type' => 'User', 'subtype' => 'Mailed', 'user' => $user->id, 'byuser' => null, 'groupid' => null, 'text' => 'RippleIntro'],
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts['recent_action']);
        $this->assertFalse($facts['member_veto']);
    }

    public function test_a_member_acting_on_their_own_post_is_not_recent_action(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Message', 'subtype' => 'Deleted',
            'user' => $user->id, 'byuser' => $user->id, 'groupid' => $group->id,
        ]);

        $this->assertFalse($this->service->facts($message->id, $group->id)['recent_action']);
    }

    public function test_a_moderator_rejection_is_still_recent_action(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        DB::table('logs')->insert([
            'timestamp' => now()->subDay(), 'type' => 'Message', 'subtype' => 'Rejected',
            'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
        ]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertTrue($facts['recent_action']);
        $this->assertStringContainsString('Message Rejected', $facts['recent_action_detail']);
    }

    /** An earlier post that expired is finished, not open: the member may offer the item again. */
    public function test_an_expired_earlier_post_is_not_an_open_duplicate(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(['message' => ['subject' => 'OFFER: Brown sofa (Leeds)']]);
        $earlier = $this->createTestMessage($user, $group, ['subject' => 'OFFER: Brown sofa (Leeds)', 'arrival' => now()->subDays(20)]);
        DB::table('messages')->where('id', $earlier->id)->update(['arrival' => now()->subDays(20)]);
        DB::table('messages_outcomes')->insert(['msgid' => $earlier->id, 'outcome' => 'Expired', 'timestamp' => now()->subDays(5)]);

        $facts = $this->service->facts($message->id, $group->id);

        $this->assertFalse($facts['duplicate'], 'an expired post is not still open');
        $this->assertFalse($facts['has_other_posts'], 'and it is not offered to the model as an open post either');
    }

    // --- the signals that can arrive while a post waits, read again at publish ----------------

    public function test_live_veto_is_null_for_a_clean_member_on_an_open_community(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();

        $this->assertNull($this->service->liveVeto($message->id, $group->id));
    }

    public function test_live_veto_names_the_signal_and_its_detail(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('spam_users')->insert(['userid' => $user->id, 'collection' => 'Spammer']);

        $veto = $this->service->liveVeto($message->id, $group->id);

        $this->assertSame('spammer', $veto['signal']);
        $this->assertSame('Listed as a spammer', $veto['detail']);
    }

    public function test_live_veto_includes_the_community_switches(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject(['group' => ['settings' => ['moderated' => 1]]]);

        $veto = $this->service->liveVeto($message->id, $group->id);

        $this->assertSame('group_disallows', $veto['signal']);
        $this->assertSame('Group moderates all posts', $veto['detail']);
    }

    // --- backtesting: the facts as they stood when a moderator decided -------------------------

    /**
     * automod:evaluate asks "what would the chart have said?" about a post a moderator already
     * decided. Read now, the facts include the moderator's own decision (their Rejected log is
     * a recent moderator action on the member), which holds every rejected post before a single
     * text question and inflates the agreement. As of the decision, the post's own logs and
     * anything after it are left out.
     */
    public function test_as_of_the_decision_ignores_the_posts_own_logs_and_anything_after_it(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        $decidedAt = now()->subHours(2);
        DB::table('logs')->insert([
            'timestamp' => $decidedAt->copy()->addMinute(), 'type' => 'Message', 'subtype' => 'Rejected',
            'msgid' => $message->id, 'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
        ]);
        DB::table('users_comments')->insert(['userid' => $user->id, 'groupid' => $group->id, 'user1' => 'after', 'date' => $decidedAt->copy()->addHour()]);
        DB::table('microactions')->insert([
            'userid' => $this->createTestUser()->id, 'msgid' => $message->id, 'actiontype' => 'CheckMessage',
            'result' => 'Reject', 'score_negative' => 1, 'timestamp' => $decidedAt->copy()->addMinutes(5),
        ]);

        $now = $this->service->facts($message->id, $group->id);
        $then = $this->service->facts($message->id, $group->id, $decidedAt);

        $this->assertTrue($now['recent_action'] && $now['mod_note'] && $now['microvol_reject'], 'read now, it all counts');
        $this->assertFalse($then['recent_action'], 'the moderator\'s own rejection of this post is the answer, not a fact about the member');
        $this->assertFalse($then['mod_note'], 'a note written after the decision');
        $this->assertFalse($then['microvol_reject'], 'a microvolunteer\'s reject after the decision');
    }

    public function test_as_of_the_decision_still_counts_what_was_true_before_it(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        $decidedAt = now()->subHours(2);
        $other = $this->createTestMessage($user, $group);
        DB::table('logs')->insert([
            'timestamp' => $decidedAt->copy()->subDays(10), 'type' => 'Message', 'subtype' => 'Rejected',
            'msgid' => $other->id, 'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
        ]);
        DB::table('users_comments')->insert(['userid' => $user->id, 'groupid' => $group->id, 'user1' => 'before', 'date' => $decidedAt->copy()->subDay()]);

        $facts = $this->service->facts($message->id, $group->id, $decidedAt);

        $this->assertTrue($facts['recent_action'], 'an earlier rejection of another post still counts');
        $this->assertTrue($facts['mod_note']);
    }

    public function test_the_ninety_day_window_is_measured_back_from_the_decision(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        $mod = $this->createTestUser();
        $decidedAt = now()->subDays(200);
        DB::table('logs')->insert([
            'timestamp' => $decidedAt->copy()->subDays(30), 'type' => 'Message', 'subtype' => 'Rejected',
            'msgid' => null, 'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
        ]);

        $this->assertFalse($this->service->facts($message->id, $group->id)['recent_action'], 'read now, that is 230 days ago');
        $this->assertTrue($this->service->facts($message->id, $group->id, $decidedAt)['recent_action'], 'as of the decision it was 30 days before');
    }

    /**
     * The facts the chart reads (automod/chart.json, version 6; the service names them from the
     * chart with factNames(), and automod/test/chart.test.js pins the same list). The service
     * holds a post whose request leaves one out, or sends anything but true or false, as
     * unavailable, so a fact that was sometimes null or missing here would hold every such post
     * until a moderator got to it. text_truncated is not here: the service works that out itself.
     */
    private const CHART_FACTS = [
        'concern_keyword', 'duplicate', 'has_other_posts', 'is_offer', 'is_wanted', 'member_moderated',
        'membership_review', 'microvol_reject', 'mod_note', 'no_location', 'not_english', 'outside_uk',
        'personal_info', 'recent_action', 'spam_links', 'spam_pattern', 'spammer',
    ];

    public function test_every_fact_the_chart_reads_is_a_boolean_for_an_ordinary_post(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();

        $facts = $this->service->facts($message->id, $group->id);

        foreach (self::CHART_FACTS as $name) {
            $this->assertArrayHasKey($name, $facts, $name);
            $this->assertIsBool($facts[$name], $name);
        }
    }

    public function test_every_fact_the_chart_reads_is_a_boolean_when_there_is_nothing_to_read(): void
    {
        // A message row that does not exist, a community that does not exist: the facts are
        // still answers, never nulls or gaps.
        $facts = $this->service->facts(999999999, 999999999);

        foreach (self::CHART_FACTS as $name) {
            $this->assertArrayHasKey($name, $facts, $name);
            $this->assertIsBool($facts[$name], $name);
        }
    }

    public function test_every_fact_the_chart_reads_is_a_boolean_for_a_post_with_no_sender(): void
    {
        [$user, $group, $message] = $this->makeFactsSubject();
        DB::table('messages')->where('id', $message->id)->update(['fromuser' => null]);

        $facts = $this->service->facts($message->id, $group->id);

        foreach (self::CHART_FACTS as $name) {
            $this->assertArrayHasKey($name, $facts, $name);
            $this->assertIsBool($facts[$name], $name);
        }
    }
}
