<?php

namespace Tests\Unit\Services\Automod;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\Automod\AutomodFactsService;
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
            $config = require base_path("config/freegle.php");
            $this->assertSame(20, $config["autoapprove"]["delay_minutes"]);
            $this->assertSame(90, $config["autoapprove"]["danger_log_days"]);
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
        $other = $this->createTestMessage($user, $group, ["subject" => "OFFER: Kettle (Leeds)", "textbody" => "Works fine"]);
        $gone = $this->createTestMessage($user, $group, ["subject" => "OFFER: Lamp (Leeds)"]);
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
}
