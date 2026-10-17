<?php

namespace Tests\Support;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for the tests of the clean-path cron (AutoApproveCleanService): a Pending post from a
 * member with no posting status, content-checked, with the automod chart's approve decision a
 * minute after the check, all old enough to be due. Use it from a Tests\TestCase.
 */
trait MakesApprovablePosts
{
    /**
     * Build a clean, content-checked, NULL-status pending post that is eligible for
     * auto-approval (arrival older than the default 20-minute delay).
     *
     * Eligibility for auto-approval is a messages_automod row (the automod flowchart's
     * verdict, plans/active/automod-flowchart.md) rather than anything AutoApproveCleanService
     * computes itself, so this seeds a passing "approve" verdict by default. Pass
     * `automod => false` to skip seeding (post has no verdict yet), or `automod => [...]` to
     * override fields (e.g. verdict => MessageAutomod::VERDICT_HOLD, mode => shadow, or a
     * stale `created`).
     *
     * @return array{0: User, 1: Group, 2: Message}
     */
    private function makeApprovable(array $opts = []): array
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup($opts['group'] ?? []);
        $this->createMembership($user, $group, array_merge([
            'added' => now()->subDays(2),
            // ourPostingStatus left NULL: the auto-moderated tier.
        ], $opts['membership'] ?? []));

        $message = $this->createTestMessage($user, $group, $opts['message'] ?? []);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(array_merge([
                'collection'              => MessageGroup::COLLECTION_PENDING,
                'arrival'                 => now()->subMinutes(22),
                // The check ran a minute before the chart decided: a decision has to be
                // strictly later than the check it drew its facts from.
                'contentcheck_checked_at' => now()->subMinutes(21),
                'contentcheck_reasons'    => null,
            ], $opts['mg'] ?? []));

        if (($opts['automod'] ?? []) !== false) {
            MessageAutomod::create(array_merge([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'mode' => MessageAutomod::MODE_APPROVE,
                'chart_version' => 'test',
                'verdict' => MessageAutomod::VERDICT_APPROVE,
                'end_node' => 'APPROVE',
                'reason' => null,
                'path' => [],
                // The wait counts from the decision, so a fixture decided "now" would not
                // be due yet.
                'created' => now()->subMinutes(20),
            ], is_array($opts['automod'] ?? null) ? $opts['automod'] : []));
        }

        return [$user, $group, $message];
    }

    private function assertApproved(int $msgid, int $groupid): void
    {
        $mg = DB::table('messages_groups')->where('msgid', $msgid)->where('groupid', $groupid)->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection, 'message should be Approved');
        $this->assertNull($mg->approvedby, 'auto-approval leaves approvedby NULL');
        // text says which automatic path published it: the 48h sweep writes none.
        $this->assertDatabaseHas('logs', [
            'msgid' => $msgid, 'groupid' => $groupid, 'type' => 'Message', 'subtype' => 'Autoapproved', 'text' => 'automod',
        ]);
    }

    private function assertStillPending(int $msgid, int $groupid): void
    {
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $msgid, 'groupid' => $groupid, 'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
        $this->assertDatabaseMissing('logs', [
            'msgid' => $msgid, 'type' => 'Message', 'subtype' => 'Autoapproved',
        ]);
    }
}
