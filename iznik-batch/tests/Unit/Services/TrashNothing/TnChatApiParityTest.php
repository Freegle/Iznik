<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use App\Services\TrashNothing\Sync\ChatMessageSyncer;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One TN chat message through both real paths: the email through IncomingMailService::route(),
 * logged as IncomingMailController does, and its feed record through ChatMessageSyncer writing.
 * The chat message rows and the routed Loki entries must agree, apart from what differs by
 * design. The chat version of TnApiLokiParityTest; see plans/2026-11-12-tn-api-chat-ingestion.md,
 * section 6, Tests.
 */
class TnChatApiParityTest extends TestCase
{
    use CapturesRoutedLokiEntries;

    /** The row fields both paths write and must agree on. */
    private const ROW_FIELDS = ['chatid', 'userid', 'type', 'refmsgid', 'message', 'reviewrequired', 'reportreason', 'platform', 'processingrequired'];

    /** What the API entry carries that the email one does not: TN's ids, the kind, the row. */
    private const API_ONLY_KEYS = ['tn_message_id', 'tn_user_id', 'kind', 'chat_message_id'];

    private LokiService $loki;

    private User $member;

    private string $memberAddress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loki = $this->enableLokiCapture();
        $this->memberAddress = 'parity' . Str::random(8) . '-g1@user.trashnothing.com';
        $this->member = $this->createTestUser([
            'tnuserid' => random_int(50_000_000, 99_999_999),
            'email_preferred' => $this->memberAddress,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLokiCapture();
        parent::tearDown();
    }

    /** Route the email as IncomingMailController::receive() does, logging its entry. */
    private function viaEmail(string $envelopeTo, string $subject, string $text, array $headers = []): void
    {
        $lines = [
            'From: Parity Member <' . $this->memberAddress . '>',
            'To: ' . $envelopeTo,
            'Subject: ' . $subject,
            'Message-ID: <' . Str::uuid() . '@m2.trashnothing.com>',
            'Date: Tue, 17 Nov 2026 10:30:00 +0000',
            'X-Trash-Nothing-User-Id: ' . $this->member->tnuserid,
        ];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n" . $text . "\r\n";

        $parsed = app(MailParserService::class)->parse($raw, $this->memberAddress, $envelopeTo);
        $service = app(IncomingMailService::class);
        $result = $service->route($parsed);

        $this->loki->logIncomingEmail(
            $this->memberAddress,
            $envelopeTo,
            $parsed->fromAddress,
            $parsed->subject ?? '',
            $parsed->messageId ?? '',
            $result->value,
            $service->getLastRoutingContext(),
        );
    }

    private function viaApi(array $record): void
    {
        $syncer = new ChatMessageSyncer(false, false, 'k', 'https://tn.example.invalid/fd/api', $this->loki, write: true);
        $syncer->ingestOne(TnChatMessage::fromArray($record + [
            'id' => (string) Str::uuid(),
            'date' => '2026-11-17T10:30:00Z',
            'tn_user_id' => $this->member->tnuserid,
            'display_name' => 'Parity Member',
        ]));
    }

    /** The two paths' rows in one chat, email first, and their two routed entries. */
    private function assertBothPathsAgree(int $chatId, array $loggedTheSame): void
    {
        $rows = ChatMessage::where('chatid', $chatId)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'Expected one chat message from each path');
        [$email, $api] = [$rows[0], $rows[1]];
        foreach (self::ROW_FIELDS as $field) {
            $this->assertEquals($email->$field, $api->$field, "chat_messages.{$field} diverged between the paths");
        }

        $entries = $this->routedEntries();
        $this->assertCount(2, $entries, 'Expected one routed entry from each path');
        [$e, $a] = $entries;

        $this->assertSame('incoming_mail', $e['labels']['source']);
        $this->assertSame('tn_api', $a['labels']['source']);
        unset($e['labels']['source'], $a['labels']['source']);
        $this->assertSame($e['labels'], $a['labels'], 'Labels diverged');

        $this->assertSame([], array_values(array_diff(array_keys($e['message']), array_keys($a['message']))), 'The API entry lacks keys the email entry has');
        $this->assertSame(self::API_ONLY_KEYS, array_values(array_diff(array_keys($a['message']), array_keys($e['message']))));

        foreach (array_merge(['routing_outcome', 'subject'], $loggedTheSame) as $key) {
            $this->assertSame($e['message'][$key] ?? null, $a['message'][$key] ?? null, "Loki field '{$key}' diverged");
        }

        // By design: no SMTP envelope or From on the API path, and its message_id is TN's id.
        $this->assertSame('', $a['message']['envelope_from']);
        $this->assertSame('', $a['message']['from_address']);
        $this->assertSame($a['message']['tn_message_id'], $a['message']['message_id']);
    }

    public function test_a_reply_in_a_chat(): void
    {
        $poster = $this->createTestUser();
        $chat = $this->createTestChatRoom($poster, $this->member);
        $to = "notify-{$chat->id}-{$this->member->id}@" . config('freegle.mail.user_domain');

        $this->viaEmail($to, 'Re: OFFER: Bookcase (Town)', 'See you then.');
        $this->viaApi(['kind' => 'chat_reply', 'fd_chat_id' => $chat->id, 'subject' => 'Re: OFFER: Bookcase (Town)', 'text' => 'See you then.']);

        $this->assertBothPathsAgree($chat->id, ['user_id', 'chat_id', 'envelope_to']);
    }

    public function test_a_first_reply_to_a_post(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $post = $this->createTestMessage($poster, $group);
        $to = 'poster-' . $poster->id . '@' . config('freegle.mail.user_domain');
        $text = "Is this still available?\n\nPossible collection times:\nWeekday evenings";

        $this->viaEmail($to, 'Re: ' . $post->subject, $text, ['X-FD-MsgId' => (string) $post->id]);
        $chat = ChatRoom::where('chattype', ChatRoom::TYPE_USER2USER)->where(fn ($q) => $q->where('user1', $this->member->id)->orWhere('user2', $this->member->id))->firstOrFail();
        $this->viaApi(['kind' => 'post_reply', 'fd_msgid' => $post->id, 'fd_recipient_user_id' => $poster->id, 'subject' => 'Re: ' . $post->subject, 'text' => $text]);

        // envelope_to differs by design: the API path cannot know the poster's address.
        $this->assertBothPathsAgree($chat->id, ['user_id', 'to_user_id', 'chat_id']);
    }

    public function test_a_message_to_the_volunteers(): void
    {
        $group = $this->createTestGroup();
        $to = $group->nameshort . '-volunteers@' . config('freegle.mail.group_domain');

        $this->viaEmail($to, 'A question for the volunteers', 'How do I change my community?');
        $chat = ChatRoom::where('chattype', ChatRoom::TYPE_USER2MOD)->where('user1', $this->member->id)->where('groupid', $group->id)->firstOrFail();
        $this->viaApi(['kind' => 'volunteers', 'fd_group_id' => $group->id, 'subject' => 'A question for the volunteers', 'text' => 'How do I change my community?']);

        $this->assertBothPathsAgree($chat->id, ['group_id', 'group_name', 'user_id', 'chat_id', 'envelope_to']);
    }

    public function test_a_reply_to_a_missing_chat_is_dropped_alike(): void
    {
        $to = "notify-999999999-{$this->member->id}@" . config('freegle.mail.user_domain');

        $this->viaEmail($to, 'Re: OFFER: Bookcase (Town)', 'See you then.');
        $this->viaApi(['kind' => 'chat_reply', 'fd_chat_id' => 999999999, 'text' => 'See you then.']);

        [$e, $a] = $this->routedEntries();
        $this->assertSame('Dropped', $e['labels']['subtype']);
        $this->assertSame($e['labels']['subtype'], $a['labels']['subtype']);
        $this->assertSame('Reply to non-existent chat', $e['message']['routing_reason']);
        $this->assertSame($e['message']['routing_reason'], $a['message']['routing_reason']);
    }
}
