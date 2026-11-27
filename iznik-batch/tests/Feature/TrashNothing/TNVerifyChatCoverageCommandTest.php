<?php

namespace Tests\Feature\TrashNothing;

use App\Models\ChatMessage;
use App\Models\ChatMessageTn;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\TrashNothing\Ingestion\DeliberateSkips;
use App\Services\TrashNothing\Sync\ChatMessageSyncer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * tn:verify-chat-coverage: after the cutover, every archived TN chat email of a moved kind was
 * written from the feed, decided on purpose, or is a miss (plan 5.4).
 */
class TNVerifyChatCoverageCommandTest extends TestCase
{
    use WritesTnChatArchive;

    private const WINDOW = ['--date-min' => '2026-11-17T10:00:00Z', '--date-max' => '2026-11-17T11:00:00Z'];

    private User $member;

    private ChatRoom $chat;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.trashnothing.ingest_chat_via_api' => true,
            'freegle.trashnothing.api_key' => 'partner-key',
            'freegle.trashnothing.api_base_url' => 'https://tn.example.invalid/fd/api',
        ]);
        $this->member = $this->createTestUser(['tnuserid' => random_int(50_000_000, 99_999_999)]);
        $this->chat = $this->createTestChatRoom($this->createTestUser(), $this->member);
    }

    protected function tearDown(): void
    {
        $this->removeChatArchive();
        parent::tearDown();
    }

    private function emailed(string $logId, string $at = '2026-11-17 10:30:00'): void
    {
        $this->archiveChatMail(
            $this->tnChatReply($this->chat->id, $this->member->id, $this->member->tnuserid, $logId, 'See you then.'),
            $this->notifyAddress($this->chat->id, $this->member->id),
            'SkippedTnApi',
            $at,
        );
    }

    private function tnHas(string $logId, ?string $date = null): array
    {
        return ['chat_message' => [
            'id' => $logId, 'date' => $date ?? now()->subHour()->toIso8601ZuluString(), 'tn_user_id' => $this->member->tnuserid,
            'kind' => 'chat_reply', 'fd_chat_id' => $this->chat->id, 'text' => 'See you then.',
        ]];
    }

    public function test_written_and_decided_messages_are_covered(): void
    {
        $written = (string) Str::uuid();
        $decided = (string) Str::uuid();
        $this->emailed($written);
        $this->emailed($decided);
        ChatMessageTn::create(['chatmsgid' => $this->createTestChatMessage($this->chat, $this->member)->id, 'tnmessageid' => $written]);
        DeliberateSkips::record(ChatMessageSyncer::SKIP_KEY_PREFIX . $decided, 'tn-sender-unknown');
        Http::preventStrayRequests();

        $this->artisan('tn:verify-chat-coverage', self::WINDOW)
            ->expectsOutputToContain('"covered":1,"decided":1')
            ->assertExitCode(0);
    }

    public function test_a_miss_tn_does_not_have_fails_the_run(): void
    {
        $logId = (string) Str::uuid();
        $this->emailed($logId);
        Http::fake(['*/chat-messages/*' => Http::response(null, 404)]);

        $this->artisan('tn:verify-chat-coverage', self::WINDOW)
            ->expectsOutputToContain('"tn":"absent"')
            ->assertExitCode(1);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/chat-messages/' . $logId) && $r['key'] === 'partner-key');
    }

    public function test_a_miss_tn_has_is_reported_and_left_unless_auto_ingest_is_asked_for(): void
    {
        $logId = (string) Str::uuid();
        $this->emailed($logId);
        Http::fake(['*/chat-messages/*' => Http::response($this->tnHas($logId), 200)]);

        $this->artisan('tn:verify-chat-coverage', self::WINDOW)->expectsOutputToContain('"tn":"found"')->assertExitCode(1);
        $this->assertFalse(ChatMessageTn::where('tnmessageid', $logId)->exists());

        $this->artisan('tn:verify-chat-coverage', self::WINDOW + ['--auto-ingest' => true])
            ->expectsOutputToContain('"backfilled":1')
            ->assertExitCode(0);
        $this->assertSame('See you then.', ChatMessage::find(ChatMessageTn::where('tnmessageid', $logId)->value('chatmsgid'))->message);
    }

    public function test_backfill_refuses_a_message_too_old_and_a_run_with_too_many(): void
    {
        $old = (string) Str::uuid();
        $this->emailed($old);
        Http::fake(['*/chat-messages/*' => Http::response($this->tnHas($old, now()->subDays(3)->toIso8601ZuluString()), 200)]);

        $this->artisan('tn:verify-chat-coverage', self::WINDOW + ['--auto-ingest' => true])
            ->expectsOutputToContain('"tn":"too-old"')
            ->assertExitCode(1);

        config(['freegle.trashnothing.verify_chat_coverage.auto_ingest_max' => 0]);
        $this->artisan('tn:verify-chat-coverage', self::WINDOW + ['--auto-ingest' => true])
            ->expectsOutputToContain('more than the 0 it will backfill')
            ->assertExitCode(1);
        $this->assertFalse(ChatMessageTn::where('tnmessageid', $old)->exists());
    }

    public function test_a_kind_not_moved_is_left_to_email(): void
    {
        config(['freegle.trashnothing.ingest_chat_kinds' => 'post_reply']);
        $this->emailed((string) Str::uuid());
        Http::preventStrayRequests();

        $this->artisan('tn:verify-chat-coverage', self::WINDOW)
            ->expectsOutputToContain('"not_moved":1')
            ->assertExitCode(0);
    }

    public function test_with_the_flag_off_it_does_nothing_unless_forced(): void
    {
        config(['freegle.trashnothing.ingest_chat_via_api' => false]);
        $this->emailed((string) Str::uuid());
        Http::fake(['*/chat-messages/*' => Http::response(null, 404)]);

        $this->artisan('tn:verify-chat-coverage', self::WINDOW)->expectsOutputToContain('Use --force')->assertExitCode(0);
        $this->artisan('tn:verify-chat-coverage', self::WINDOW + ['--force' => true])->assertExitCode(1);
    }
}
