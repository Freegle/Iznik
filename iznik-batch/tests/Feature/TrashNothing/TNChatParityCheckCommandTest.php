<?php

namespace Tests\Feature\TrashNothing;

use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * tn:chat-parity-check, per layer of plans/2026-11-12-tn-api-chat-ingestion.md 5.3, from both
 * email sides.
 */
class TNChatParityCheckCommandTest extends TestCase
{
    use WritesTnChatArchive;

    private const WINDOW = ['--date-min' => '2026-11-17T10:00:00Z', '--date-max' => '2026-11-17T11:00:00Z'];

    private const ARRIVAL = '2026-11-17 10:30:00';

    private User $poster;

    private User $member;

    private ChatRoom $chat;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.trashnothing.api_key' => 'partner-key',
            'freegle.trashnothing.api_base_url' => 'https://tn.example.invalid/fd/api',
        ]);

        $this->poster = $this->createTestUser();
        $this->member = $this->createTestUser(['tnuserid' => random_int(50_000_000, 99_999_999)]);
        $this->chat = $this->createTestChatRoom($this->poster, $this->member);
    }

    protected function tearDown(): void
    {
        $this->removeChatArchive();
        parent::tearDown();
    }

    private function rawReply(string $logId, string $text): string
    {
        return $this->tnChatReply($this->chat->id, $this->member->id, $this->member->tnuserid, $logId, $text);
    }

    /** The email path's work: the chat message, and its raw mail kept beside it. */
    private function emailPathWrote(string $logId, string $text, array $chatMessage = []): int
    {
        $msg = $this->createTestChatMessage($this->chat, $this->member, array_merge(['message' => $text, 'platform' => 0], $chatMessage));
        $msgId = DB::table('messages')->insertGetId([
            'arrival' => self::ARRIVAL,
            'date' => self::ARRIVAL,
            'source' => 'Email',
            'message' => $this->rawReply($logId, $text),
            'fromuser' => $this->member->id,
            'fromaddr' => 'member-g1@user.trashnothing.com',
            'envelopefrom' => 'member-g1@user.trashnothing.com',
            'envelopeto' => "notify-{$this->chat->id}-{$this->member->id}@" . config('freegle.mail.user_domain'),
            'messageid' => $logId . '@m2.trashnothing.com',
            'subject' => 'Re: OFFER: Bookcase (Town)',
        ]);
        DB::table('chat_messages_byemail')->insert(['chatmsgid' => $msg->id, 'msgid' => $msgId]);

        return $msg->id;
    }

    private function feed(array $records): void
    {
        Http::fake(['*/chat-messages*' => Http::response(['chat_messages' => $records], 200)]);
    }

    private function record(string $logId, array $fields = []): array
    {
        return array_merge([
            'id' => $logId,
            'date' => '2026-11-17T10:29:58Z',
            'tn_user_id' => $this->member->tnuserid,
            'kind' => 'chat_reply',
            'fd_chat_id' => $this->chat->id,
            'text' => 'See you at six.',
            'display_name' => 'Member',
        ], $fields);
    }

    private function check(string $side = 'db', array $extra = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('tn:chat-parity-check', self::WINDOW + ['--email-side' => $side] + $extra);
    }

    public function test_matching_paths_pass(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->feed([$this->record($logId)]);

        $this->check()->expectsOutputToContain('[ok]   Layer 1, emails with no feed record: 0')->assertExitCode(0);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/chat-messages') && $r['key'] === 'partner-key');
    }

    public function test_an_email_with_no_record_fails_coverage(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->feed([]);

        $this->check()->expectsOutputToContain('[FAIL] Layer 1, emails with no feed record: 1')->assertExitCode(1);
    }

    public function test_different_text_fails_content_without_printing_it(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->feed([$this->record($logId, ['text' => 'Something else entirely.'])]);

        $this->check()
            ->expectsOutputToContain('"fields":["text"],"email_length":15,"api_length":24,"same_for_first":1')
            ->doesntExpectOutputToContain('Something else entirely')
            ->assertExitCode(1);
    }

    public function test_more_text_in_the_email_is_reported_not_failed(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, "See you at six.\r\nSent from my phone today");
        $this->feed([$this->record($logId)]);

        $this->check()->expectsOutputToContain('[note] The email had more text than the record: 1')->assertExitCode(0);
    }

    public function test_an_arrival_hold_the_email_row_lacks_fails_and_a_processing_hold_does_not(): void
    {
        $held = (string) Str::uuid();
        $this->emailPathWrote($held, 'See you at six.', ['reviewrequired' => 1, 'reportreason' => 'Last']);
        $this->feed([$this->record($held)]);

        // Held only on the email side, for a reason processing writes: processing's own.
        $this->check()->assertExitCode(0);
    }

    public function test_api_hold_without_an_email_hold_fails(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'I can pay £20 for it.');
        $this->feed([$this->record($logId, ['text' => 'I can pay £20 for it.'])]);

        $this->check()
            ->expectsOutputToContain('"fields":["hold"],"email_hold":null,"api_hold":"Money","previous":null')
            ->assertExitCode(1);
    }

    public function test_a_sender_found_by_address_that_does_not_hold_the_tn_id_is_explained(): void
    {
        $logId = (string) Str::uuid();
        // The email path filed it on the poster's side of the chat, an account with no TN id; the
        // TN id is the member's, who is in the same chat.
        $this->emailPathWrote($logId, 'See you at six.', ['userid' => $this->poster->id]);
        $this->feed([$this->record($logId)]);

        $this->check()
            ->expectsOutputToContain('"reason":"tn-id-on-another-account","email_sender":' . $this->poster->id . ',"api_sender":' . $this->member->id)
            ->assertExitCode(0);
    }

    public function test_a_sender_the_tn_id_rule_skips_is_explained_not_failed(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        // TN names an id no account holds: the API path skips it (plan 3.1).
        $this->feed([$this->record($logId, ['tn_user_id' => $this->member->tnuserid + 1])]);

        $this->check()->expectsOutputToContain('[note] Explained by the TN id rule (3.1): 1')->assertExitCode(0);
    }

    public function test_a_record_with_no_email_is_an_extra(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->feed([$this->record($logId), $this->record((string) Str::uuid())]);

        $this->check()->expectsOutputToContain('Records with no email')->assertExitCode(0);
    }

    public function test_a_window_with_no_chat_mail_fails_and_says_what_the_database_keeps(): void
    {
        $this->emailPathWrote((string) Str::uuid(), 'See you at six.');
        // A stray copy years older, as production keeps a few, is not where the kept mail starts.
        $stray = $this->emailPathWrote((string) Str::uuid(), 'Old.');
        DB::table('messages')->where('id', DB::table('chat_messages_byemail')->where('chatmsgid', $stray)->value('msgid'))->update(['arrival' => '2017-11-07 18:21:00']);
        $this->feed([]);

        $this->artisan('tn:chat-parity-check', ['--email-side' => 'db', '--date-min' => '2026-11-01T00:00:00Z', '--date-max' => '2026-11-02T00:00:00Z'])
            ->expectsOutputToContain('No TN chat mail in this window, so nothing was compared. The database keeps chat mail from ' . self::ARRIVAL)
            ->assertExitCode(1);
    }

    public function test_an_unreadable_feed_fails_the_run(): void
    {
        Http::fake(['*/chat-messages*' => Http::response(null, 500)]);

        $this->check()->expectsOutputToContain('could not be read in full')->assertExitCode(1);
    }

    public function test_archive_side_finds_the_mail_and_its_chat_message(): void
    {
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->writeArchive($this->rawReply($logId, 'See you at six.'), 'ToUser');
        // A Freegle member's mail in the same window is not TN's, and is ignored.
        $this->writeArchive(str_replace('member-g1@user.trashnothing.com', 'someone@example.com', $this->rawReply('x', 'Hi')), 'ToUser', 'someone@example.com');
        $this->feed([$this->record($logId)]);

        $this->check('archive', ['--json' => true])
            ->expectsOutputToContain('"failed": false')
            ->assertExitCode(0);
    }

    public function test_archive_side_email_drop_against_an_api_write_fails_outcome(): void
    {
        $logId = (string) Str::uuid();
        $this->writeArchive($this->rawReply($logId, 'See you at six.'), 'Dropped');
        $this->feed([$this->record($logId)]);

        $this->check('archive')->expectsOutputToContain('[FAIL] Layer 3, one path wrote and the other did not: 1')->assertExitCode(1);
    }

    public function test_apply_is_refused_without_the_yesterday_setting(): void
    {
        $this->feed([]);

        $this->check('db', ['--apply' => true])->expectsOutputToContain('FREEGLE_TN_PARITY_ALLOW_WRITES')->assertExitCode(1);
    }

    public function test_apply_writes_the_api_rows_and_compares_them(): void
    {
        config(['freegle.trashnothing.parity_allow_writes' => true]);
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        $this->feed([$this->record($logId)]);

        $this->check('db', ['--apply' => true])
            ->expectsOutputToContain('Applied: 1 written of 1 records')
            ->expectsOutputToContain('[ok]   Layer 4, both wrote but differ: 0')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('chat_messages')->where('chatid', $this->chat->id)->count());
        $this->assertTrue(DB::table('chat_messages_tn')->where('tnmessageid', $logId)->exists());
    }

    public function test_a_feed_built_from_the_email_needs_no_tn_and_agrees_with_the_email_path(): void
    {
        Http::preventStrayRequests();
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');

        $this->check('db', ['--feed' => 'email'])
            ->expectsOutputToContain('Feed: built from the email, 1 records')
            ->expectsOutputToContain('[ok]   Layer 4, both wrote but differ: 0')
            ->assertExitCode(0);
    }

    public function test_a_feed_built_from_the_email_shows_the_tn_id_rule_on_a_shared_account(): void
    {
        Http::preventStrayRequests();
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');
        // The mail names a TN id no account holds, as on an account two members share.
        DB::table('messages')->where('messageid', $logId . '@m2.trashnothing.com')->update([
            'message' => str_replace('X-Trash-Nothing-User-Id: ' . $this->member->tnuserid, 'X-Trash-Nothing-User-Id: 1', $this->rawReply($logId, 'See you at six.')),
        ]);

        $this->check('db', ['--feed' => 'email'])
            ->expectsOutputToContain('[note] Explained by the TN id rule (3.1): 1')
            ->assertExitCode(0);
    }

    public function test_a_feed_built_from_the_email_can_be_applied(): void
    {
        config(['freegle.trashnothing.parity_allow_writes' => true]);
        Http::preventStrayRequests();
        $logId = (string) Str::uuid();
        $this->emailPathWrote($logId, 'See you at six.');

        $this->check('db', ['--feed' => 'email', '--apply' => true])
            ->expectsOutputToContain('Applied: 1 written of 1 records')
            ->assertExitCode(0);
    }

    private function writeArchive(string $raw, string $outcome, string $envelopeFrom = 'member-g1@user.trashnothing.com'): void
    {
        $this->archiveChatMail($raw, $this->notifyAddress($this->chat->id, $this->member->id), $outcome, self::ARRIVAL, $envelopeFrom);
    }
}
