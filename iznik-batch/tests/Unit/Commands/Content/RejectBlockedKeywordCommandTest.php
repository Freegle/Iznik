<?php

namespace Tests\Unit\Commands\Content;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Services\BlockedKeywordBackfillService;
use App\Services\ContentCheckService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * content:reject-blocked-keyword - applies a Freegle-wide block keyword to chat
 * messages and posts that were delivered before the keyword existed.
 *
 * Chat: a delivered message that matches becomes reviewrequired = 0,
 * reviewrejected = 1, the row a moderator Reject writes. Posts: the moderator
 * Spam action's shape - messages_spamham row, messages_groups.deleted = 1, then
 * messages.deleted once no live group row remains, and a freebie_alerts_remove
 * task. One row per statement, idempotent.
 */
class RejectBlockedKeywordCommandTest extends TestCase
{
    private string $word;
    private int $keywordId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE IF NOT EXISTS background_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_type VARCHAR(50) NOT NULL,
            data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            INDEX idx_task_type (task_type),
            INDEX idx_pending (processed_at, created_at)
        )');

        // No pause between rows in tests.
        $service = new BlockedKeywordBackfillService(new ContentCheckService());
        $service->pauseMicros = 0;
        $this->app->instance(BlockedKeywordBackfillService::class, $service);

        $this->word = 'testblockword' . uniqid();
        $this->keywordId = DB::table('concern_keywords')->insertGetId([
            'keyword' => $this->word, 'category' => 'scam', 'action' => 'block',
            'match_mode' => 'literal', 'scope' => 'global', 'group_id' => 0,
        ]);
    }

    private function seedChat(): array
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $matching = $this->createTestChatMessage($room, $sender, [
            'message' => "Confirm your payment at {$this->word} now",
            'type' => ChatMessage::TYPE_INTERESTED,
        ]);
        $clean = $this->createTestChatMessage($room, $sender, [
            'message' => 'Is the lamp still available?',
        ]);

        return [$matching, $clean];
    }

    private function seedPosts(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $matching = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Gift voucher (Town)',
            'textbody' => "Claim it at {$this->word} today",
        ]);
        $clean = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Lamp (Town)',
            'textbody' => 'Working table lamp, collection only.',
        ]);

        return [$matching, $clean];
    }

    public function test_dry_run_reports_matches_and_changes_nothing(): void
    {
        [$chatMatch] = $this->seedChat();
        [$postMatch] = $this->seedPosts();

        $this->artisan('content:reject-blocked-keyword', [
            '--since' => now()->subDay()->toDateTimeString(),
            '--keyword' => [(string) $this->keywordId],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Chat messages: 1 matched, would change 1 (dry run)')
            ->expectsOutputToContain('Posts: 1 matched, would change 1 (dry run)')
            ->assertSuccessful();

        $this->assertEquals(0, DB::table('chat_messages')->where('id', $chatMatch->id)->value('reviewrejected'));
        $this->assertNull(DB::table('messages')->where('id', $postMatch->id)->value('deleted'));
    }

    public function test_rejects_matching_chat_and_removes_matching_posts_only(): void
    {
        [$chatMatch, $chatClean] = $this->seedChat();
        [$postMatch, $postClean] = $this->seedPosts();

        $this->artisan('content:reject-blocked-keyword', [
            '--since' => now()->subDay()->toDateTimeString(),
            '--keyword' => [$this->word],
        ])
            ->expectsOutputToContain('Chat messages: 1 matched, changed 1')
            ->expectsOutputToContain('Posts: 1 matched, changed 1')
            ->assertSuccessful();

        $rejected = DB::table('chat_messages')->where('id', $chatMatch->id)->first();
        $this->assertEquals(1, $rejected->reviewrejected);
        $this->assertEquals(0, $rejected->reviewrequired);
        $this->assertEquals(0, DB::table('chat_messages')->where('id', $chatClean->id)->value('reviewrejected'));

        // The moderator Spam shape, all of it.
        $this->assertNotNull(DB::table('messages')->where('id', $postMatch->id)->value('deleted'));
        $this->assertEquals(1, DB::table('messages_groups')->where('msgid', $postMatch->id)->value('deleted'));
        $this->assertEquals('Spam', DB::table('messages_spamham')->where('msgid', $postMatch->id)->value('spamham'));
        $this->assertNotNull(
            DB::table('background_tasks')
                ->where('task_type', 'freebie_alerts_remove')
                ->where('data->msgid', $postMatch->id)
                ->first(),
            'a removed post leaves freebiealerts.app the way a moderator Spam does'
        );

        $this->assertNull(DB::table('messages')->where('id', $postClean->id)->value('deleted'));
        $this->assertEquals(0, DB::table('messages_groups')->where('msgid', $postClean->id)->value('deleted'));
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->seedChat();
        $this->seedPosts();
        $args = [
            '--since' => now()->subDay()->toDateTimeString(),
            '--keyword' => [(string) $this->keywordId],
        ];

        $this->artisan('content:reject-blocked-keyword', $args)->assertSuccessful();
        $this->artisan('content:reject-blocked-keyword', $args)
            ->expectsOutputToContain('Chat messages: 0 matched, changed 0')
            ->expectsOutputToContain('Posts: 0 matched, changed 0')
            ->assertSuccessful();
    }

    public function test_content_before_the_window_is_left_alone(): void
    {
        [$chatMatch] = $this->seedChat();
        DB::table('chat_messages')->where('id', $chatMatch->id)->update(['date' => now()->subDays(3)]);

        $this->artisan('content:reject-blocked-keyword', [
            '--since' => now()->subDay()->toDateTimeString(),
            '--keyword' => [(string) $this->keywordId],
        ])->assertSuccessful();

        $this->assertEquals(0, DB::table('chat_messages')->where('id', $chatMatch->id)->value('reviewrejected'));
    }

    public function test_a_mail_with_no_community_copy_is_not_a_post(): void
    {
        // A member's "Reporting member" mail quotes the scam it is reporting. It sits
        // in messages with no messages_groups row, and it is not a post to remove.
        $reporter = $this->createTestUser();
        $report = Message::create([
            'type' => Message::TYPE_OTHER,
            'fromuser' => $reporter->id,
            'subject' => 'Reporting member "notify-1-2" (#4512)',
            'textbody' => "They sent me this: Claim it at {$this->word} today",
            'source' => 'Email',
            'date' => now(),
            'arrival' => now(),
        ]);

        $this->artisan('content:reject-blocked-keyword', [
            '--since' => now()->subDay()->toDateTimeString(),
            '--keyword' => [(string) $this->keywordId],
        ])
            ->expectsOutputToContain('Posts: 0 matched, changed 0')
            ->assertSuccessful();

        $this->assertNull(DB::table('messages')->where('id', $report->id)->value('deleted'));
        $this->assertNull(DB::table('messages_spamham')->where('msgid', $report->id)->first());
    }

    public function test_unknown_keyword_fails_rather_than_applying_every_keyword(): void
    {
        $this->artisan('content:reject-blocked-keyword', [
            '--keyword' => ['no-such-keyword-' . uniqid()],
        ])->assertFailed();
    }
}
