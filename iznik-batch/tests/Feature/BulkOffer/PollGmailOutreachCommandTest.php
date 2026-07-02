<?php

namespace Tests\Feature\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Services\Gmail\GmailService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises bulkoffer:poll-outreach against a faked GmailService so reply
 * classification (unsubscribe / bounce / auto-reply / genuine reply) can be
 * verified without contacting Google. The command emits a JSON array of
 * genuine replies to stdout for the FSM brain to act on - auto-acks and
 * bounces must NOT appear in that output, and must not be marked Replied.
 */
class PollGmailOutreachCommandTest extends TestCase
{
    private int $msgid;

    protected function setUp(): void
    {
        parent::setUp();

        $donor = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->msgid = $this->createTestMessage($donor, $group, [
            'subject' => 'OFFER: Office clearance (Hampstead)',
        ])->id;
    }

    /** Insert a Sent outreach row watching the given Gmail thread id. */
    private function sentOutreach(string $threadId, array $overrides = []): int
    {
        $org = $this->createTestUser(['email_preferred' => $this->uniqueEmail('org')]);

        return DB::table('messages_bulk_outreach')->insertGetId(array_merge([
            'msgid' => $this->msgid,
            'userid' => $org->id,
            'orgname' => 'Hampstead Community Centre',
            'area' => 'Hampstead, NW3',
            'tier' => '2',
            'status' => MessagesBulkOutreach::STATUS_SENT,
            'gmail_thread_id' => $threadId,
            'gmail_message_id' => 'sent-'.$threadId,
            'sent_at' => now(),
            'sent_via' => 'email',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** Build a Gmail API message array with the given headers and plain-text body. */
    private function gmailMessage(array $headers, string $body, string $mimeType = 'text/plain'): array
    {
        $headerPairs = [];
        foreach ($headers as $name => $value) {
            $headerPairs[] = ['name' => $name, 'value' => $value];
        }

        return [
            'id' => 'msg-'.substr(md5(uniqid('', true)), 0, 12),
            'payload' => [
                'mimeType' => $mimeType,
                'headers' => $headerPairs,
                'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
            ],
        ];
    }

    /** Our own outbound message in the thread, so latestInbound() has something to skip past. */
    private function outboundMessage(): array
    {
        return $this->gmailMessage([
            'From' => 'Natalie @ Freegle <natalie-wagg@ilovefreegle.org>',
            'To' => 'org@example.com',
            'Subject' => 'OFFER: Office clearance (Hampstead) - would you like some of these?',
        ], 'Hi, we have some office furniture available, would your organisation like any of it?');
    }

    /** Bind a fake GmailService whose listThreads()/getThread() surface the given thread. */
    private function fakeGmail(string $threadId, array $thread): void
    {
        $mock = $this->createMock(GmailService::class);
        $mock->method('listThreads')->willReturn([['id' => $threadId]]);
        $mock->method('getThread')->with($threadId)->willReturn($thread);
        // void return type - stub the method without willReturn(), which
        // PHPUnit rejects (IncompatibleReturnValueException) even for null.
        $mock->method('modifyMessageLabels');
        $this->app->instance(GmailService::class, $mock);
    }

    /** Run the poller for $this->msgid and return the decoded JSON reply array. */
    private function poll(): array
    {
        $code = \Artisan::call('bulkoffer:poll-outreach', ['--msgid' => $this->msgid]);
        $this->assertSame(0, $code, 'poller should exit cleanly');

        $lines = array_values(array_filter(explode("\n", trim(\Artisan::output()))));
        $jsonLine = end($lines) ?: '[]';

        $decoded = json_decode($jsonLine, true);
        $this->assertIsArray($decoded, "expected the last output line to be a JSON array, got: {$jsonLine}");

        return $decoded;
    }

    public function test_auto_submitted_header_marks_auto_ack_and_is_not_emitted(): void
    {
        $threadId = 'thread-autosubmitted';
        $id = $this->sentOutreach($threadId);

        $this->fakeGmail($threadId, [
            'messages' => [
                $this->outboundMessage(),
                $this->gmailMessage([
                    'From' => 'Org Contact <org@example.com>',
                    'To' => 'natalie-wagg@ilovefreegle.org',
                    'Subject' => 'Re: OFFER: Office clearance (Hampstead)',
                    'Auto-Submitted' => 'auto-replied',
                ], 'This mailbox is not monitored, please try another address.'),
            ],
        ]);

        $replies = $this->poll();

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_AUTO_ACK, $row->status);
        $this->assertNull($row->replied_at, 'an auto-ack is not a genuine reply');
        $this->assertEmpty($replies, 'auto-ack must not be emitted to the FSM brain');
    }

    public function test_out_of_office_subject_marks_auto_ack(): void
    {
        $threadId = 'thread-ooo';
        $id = $this->sentOutreach($threadId);

        $this->fakeGmail($threadId, [
            'messages' => [
                $this->outboundMessage(),
                $this->gmailMessage([
                    'From' => 'Org Contact <org@example.com>',
                    'To' => 'natalie-wagg@ilovefreegle.org',
                    'Subject' => 'Out of Office: Re: OFFER: Office clearance',
                ], "I'm currently on annual leave and will respond when I return."),
            ],
        ]);

        $replies = $this->poll();

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_AUTO_ACK, $row->status);
        $this->assertNull($row->replied_at);
        $this->assertEmpty($replies, 'OOO auto-reply must not be emitted to the FSM brain');
    }

    public function test_mailer_daemon_bounce_marks_bounced_and_suppresses(): void
    {
        $threadId = 'thread-bounce';
        $id = $this->sentOutreach($threadId);

        $this->fakeGmail($threadId, [
            'messages' => [
                $this->outboundMessage(),
                $this->gmailMessage([
                    'From' => 'Mail Delivery System <MAILER-DAEMON@ilovefreegle.org>',
                    'To' => 'natalie-wagg@ilovefreegle.org',
                    'Subject' => 'Delivery Status Notification (Failure)',
                ], 'Your message could not be delivered to org@example.com: mailbox unavailable'),
            ],
        ]);

        $replies = $this->poll();

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_BOUNCED, $row->status);
        $this->assertNotNull($row->suppressed_until, 'a bounced address must be suppressed from re-contact');
        $this->assertTrue(\Carbon\Carbon::parse($row->suppressed_until)->isFuture());
        $this->assertNull($row->replied_at);
        $this->assertEmpty($replies, 'a bounce must not be emitted to the FSM brain');
    }

    public function test_genuine_human_reply_is_still_marked_replied_and_emitted(): void
    {
        $threadId = 'thread-genuine';
        $id = $this->sentOutreach($threadId);

        $this->fakeGmail($threadId, [
            'messages' => [
                $this->outboundMessage(),
                $this->gmailMessage([
                    'From' => 'Org Contact <org@example.com>',
                    'To' => 'natalie-wagg@ilovefreegle.org',
                    'Subject' => 'Re: OFFER: Office clearance (Hampstead)',
                ], "Hi Natalie, yes we'd love the desk lamps, when can we collect them?"),
            ],
        ]);

        $replies = $this->poll();

        $row = DB::table('messages_bulk_outreach')->find($id);
        $this->assertSame(MessagesBulkOutreach::STATUS_REPLIED, $row->status);
        $this->assertNotNull($row->replied_at, 'a genuine reply must still stamp replied_at');
        $this->assertCount(1, $replies, 'a genuine reply must still be emitted to the FSM brain');
        $this->assertSame($id, $replies[0]['outreachid']);
        $this->assertSame($threadId, $replies[0]['threadid']);
        $this->assertStringContainsString('desk lamps', $replies[0]['body']);
    }
}
