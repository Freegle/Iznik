<?php

namespace Tests\Feature\Message;

use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * automod:evaluate backtests the chart on posts a moderator already decided. The export carries
 * each post's facts; they must be the facts as they stood when the moderator decided, or the
 * moderator's own decision is in them (see AutomodFactsServiceTest).
 */
class AutomodEvaluateCommandTest extends TestCase
{
    public function test_the_export_carries_the_facts_as_of_the_moderators_decision(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        $mod = $this->createTestUser();
        $decidedAt = now()->subHours(3);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_REJECTED,
            'rejectedat' => $decidedAt,
            'arrival' => now()->subHours(4),
            'contentcheck_checked_at' => now()->subHours(4),
        ]);
        // The moderator's own rejection of this post, written at the decision.
        DB::table('logs')->insert([
            'timestamp' => $decidedAt, 'type' => 'Message', 'subtype' => 'Rejected',
            'msgid' => $message->id, 'user' => $user->id, 'byuser' => $mod->id, 'groupid' => $group->id,
        ]);
        $file = tempnam(sys_get_temp_dir(), 'evaluate');

        try {
            $this->artisan('automod:evaluate', ['--export' => $file, '--days' => 30, '--group' => $group->id])->assertExitCode(0);

            $rows = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", file_get_contents($file))));
            $this->assertCount(1, $rows);
            $this->assertSame($message->id, $rows[0]['id']);
            $this->assertSame('hold', $rows[0]['outcome']);
            $this->assertFalse($rows[0]['facts']['recent_action'], 'the rejection being predicted is not a fact about the member');
        } finally {
            @unlink($file);
        }
    }

    /** A decided post on a fresh community, for the sampling tests. */
    private function decidedPost($user, $group): object
    {
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_REJECTED,
            'rejectedat' => now()->subHours(3),
            'arrival' => now()->subHours(4),
            'contentcheck_checked_at' => now()->subHours(4),
        ]);

        return $message;
    }

    public function test_the_export_leaves_out_deleted_members_and_deleted_posts_and_is_private(): void
    {
        $group = $this->createTestGroup();
        $kept = $this->createTestUser();
        $deletedMember = $this->createTestUser();
        $deletedPostMember = $this->createTestUser();
        foreach ([$kept, $deletedMember, $deletedPostMember] as $user) {
            $this->createMembership($user, $group);
        }
        $keptPost = $this->decidedPost($kept, $group);
        $this->decidedPost($deletedMember, $group);
        $deletedPost = $this->decidedPost($deletedPostMember, $group);
        DB::table('users')->where('id', $deletedMember->id)->update(['deleted' => now()]);
        DB::table('messages')->where('id', $deletedPost->id)->update(['deleted' => now()]);
        // A path that does not exist yet, so the mode is the one the command creates it with.
        $file = tempnam(sys_get_temp_dir(), 'evaluate');
        unlink($file);

        try {
            $this->artisan('automod:evaluate', ['--export' => $file, '--days' => 30, '--group' => $group->id])->assertExitCode(0);

            $rows = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", file_get_contents($file))));
            $this->assertSame([$keptPost->id], array_column($rows, 'id'), 'a deleted member and a deleted post are not exported');
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4), 'the export is readable by its owner only');
        } finally {
            @unlink($file);
        }
    }
}
