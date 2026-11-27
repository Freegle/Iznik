<?php

namespace Tests\Feature\TrashNothing;

use App\Models\ChatRoom;
use App\Models\User;
use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * tn:chat-gate-check: the gate's TN chat predicate over real mail takes TN members' chat and
 * nothing else (plan 5.6).
 */
class TNChatGateCheckCommandTest extends TestCase
{
    use WritesTnChatArchive;

    private const WINDOW = ['--date-min' => '2026-11-17T00:00:00Z', '--date-max' => '2026-11-18T00:00:00Z'];

    private const ARRIVAL = '2026-11-17 10:30:00';

    private User $member;

    private ChatRoom $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->createTestUser(['tnuserid' => random_int(50_000_000, 99_999_999)]);
        $this->chat = $this->createTestChatRoom($this->createTestUser(), $this->member);
    }

    protected function tearDown(): void
    {
        $this->removeChatArchive();
        parent::tearDown();
    }

    /** A raw mail kept in messages, linked to a chat message when $chat; a post's has a $type. */
    private function stored(string $raw, string $envelopeTo, bool $chat, ?string $type = null): int
    {
        $id = DB::table('messages')->insertGetId([
            'arrival' => self::ARRIVAL, 'date' => self::ARRIVAL, 'source' => 'Email', 'message' => $raw,
            'fromuser' => $this->member->id, 'fromaddr' => 'member-g1@user.trashnothing.com',
            'envelopefrom' => 'member-g1@user.trashnothing.com', 'envelopeto' => $envelopeTo,
            'messageid' => Str::uuid() . '@m2.trashnothing.com', 'subject' => 'x', 'type' => $type,
        ]);
        if ($chat) {
            DB::table('chat_messages_byemail')->insert([
                'chatmsgid' => $this->createTestChatMessage($this->chat, $this->member)->id, 'msgid' => $id,
            ]);
        }

        return $id;
    }

    private function reply(string $text = 'See you then.'): string
    {
        return $this->tnChatReply($this->chat->id, $this->member->id, $this->member->tnuserid, (string) Str::uuid(), $text);
    }

    public function test_tn_chat_is_matched_and_a_tn_group_post_is_not(): void
    {
        $this->stored($this->reply(), $this->notifyAddress($this->chat->id, $this->member->id), true);
        $group = $this->createTestGroup();
        $to = $group->nameshort . '@' . config('freegle.mail.group_domain');
        $post = "From: Member <member-g1@user.trashnothing.com>\r\nTo: {$to}\r\nSubject: OFFER: Chair (Town)\r\n"
            . "Message-ID: <p1@trashnothing.com>\r\nX-Trash-Nothing-Post-Id: 123\r\n\r\nA chair.\r\n";
        $this->stored($post, $to, false);

        $this->artisan('tn:chat-gate-check', self::WINDOW)
            ->expectsOutputToContain('[ok]   No mail matched that the email path did not make a chat message of')
            ->assertExitCode(0);
    }

    public function test_a_match_the_email_path_made_no_chat_of_fails(): void
    {
        // Stored as a post, which a chat mail never is.
        $id = $this->stored($this->reply(), $this->notifyAddress($this->chat->id, $this->member->id), false, 'Offer');

        $this->artisan('tn:chat-gate-check', self::WINDOW)
            ->expectsOutputToContain('"message_id":' . $id . ',"kind":"chat_reply"')
            ->assertExitCode(1);
    }

    public function test_a_chat_mail_whose_chat_message_has_gone_since_is_matched(): void
    {
        // Its chat message was deleted since (the duplicate purge), taking the link with it.
        $this->stored($this->reply(), $this->notifyAddress($this->chat->id, $this->member->id), false);

        $this->artisan('tn:chat-gate-check', self::WINDOW)
            ->expectsOutputToContain('matched_since_deleted')
            ->expectsOutputToContain('[ok]   No mail matched that the email path did not make a chat message of')
            ->assertExitCode(0);
    }

    public function test_chat_the_gate_leaves_to_email_is_noted_with_why(): void
    {
        $raw = str_replace('Subject: Re: OFFER: Bookcase (Town)', 'Subject: Out of Office', $this->reply());
        $id = $this->stored($raw, $this->notifyAddress($this->chat->id, $this->member->id), true);

        $this->artisan('tn:chat-gate-check', self::WINDOW)
            ->expectsOutputToContain('{"message_id":' . $id . ',"why":"auto-reply pattern"}')
            ->assertExitCode(0);
    }

    public function test_a_window_with_no_chat_mail_fails_and_says_what_the_database_keeps(): void
    {
        $this->stored($this->reply(), $this->notifyAddress($this->chat->id, $this->member->id), true);

        $this->artisan('tn:chat-gate-check', ['--date-min' => '2026-11-01T00:00:00Z', '--date-max' => '2026-11-02T00:00:00Z'])
            ->expectsOutputToContain('The database keeps chat mail from ' . self::ARRIVAL)
            ->assertExitCode(1);
    }

    public function test_archive_side_counts_a_dropped_match_apart(): void
    {
        $this->archiveChatMail($this->reply(), $this->notifyAddress($this->chat->id, $this->member->id), 'Dropped', self::ARRIVAL);
        $dir = $this->chatArchiveDir;
        $this->app->bind(IncomingArchiveReader::class, fn () => new IncomingArchiveReader(app(MailParserService::class), $dir));

        $this->artisan('tn:chat-gate-check', self::WINDOW + ['--source' => 'archive'])
            ->expectsOutputToContain('matched_dropped')
            ->assertExitCode(0);
    }
}
