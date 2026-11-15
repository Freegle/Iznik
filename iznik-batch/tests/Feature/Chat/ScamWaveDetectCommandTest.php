<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Services\ChatScamWaveService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * chat:scam-wave-detect: finds the same chat text arriving from many senders in a short
 * window, and mails geeks@ a report that shows the text itself.
 */
class ScamWaveDetectCommandTest extends TestCase
{
    private ChatScamWaveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatScamWaveService();
    }

    /**
     * One message with this text from each of $count senders, each in a chat of its own.
     *
     * @return int[] the chat message ids
     */
    private function sendFromManySenders(string $text, int $count, array $userAttributes = [], array $messageAttributes = []): array
    {
        $ids = [];
        $recipient = $this->createTestUser(['added' => now()->subYear()]);

        for ($i = 0; $i < $count; $i++) {
            $sender = $this->createTestUser(array_merge(['added' => now()->subYear()], $userAttributes));
            $room = $this->createTestChatRoom($sender, $recipient);
            $ids[] = $this->createTestChatMessage($room, $sender, array_merge(['message' => $text], $messageAttributes))->id;
        }

        return $ids;
    }

    /** The cluster whose sample text contains $needle, or null. */
    private function clusterContaining(array $result, string $needle): ?array
    {
        foreach ($result['clusters'] as $cluster) {
            foreach ($cluster['samples'] as $sample) {
                if (str_contains($sample['text'], $needle)) {
                    return $cluster;
                }
            }
        }

        return null;
    }

    public function test_the_same_long_text_from_many_senders_is_a_cluster(): void
    {
        $marker = 'wave'.uniqid();
        $text = "Hello, I am interested. Please email me directly at my shop {$marker} for a faster reply.";
        $ids = $this->sendFromManySenders($text, 8);

        $cluster = $this->clusterContaining($this->service->detect(30), $marker);

        $this->assertNotNull($cluster);
        $this->assertSame(8, $cluster['n']);
        $this->assertSame(8, $cluster['senders']);
        $this->assertSame(8, $cluster['rooms']);
        $this->assertSame(0, $cluster['new']);
        $this->assertSame(array_slice($ids, 0, 3), array_column($cluster['samples'], 'id'));
        // The sample is the text as the member wrote it, not the folded signature.
        $this->assertSame($text, $cluster['samples'][0]['text']);
    }

    public function test_short_everyday_replies_from_established_members_are_not_a_cluster(): void
    {
        // The false alarm this guards against: eight unrelated members each said "ok".
        $this->sendFromManySenders('Ok', 5);
        $this->sendFromManySenders('ok ', 5);

        $result = $this->service->detect(30);

        foreach ($result['clusters'] as $cluster) {
            foreach ($cluster['samples'] as $sample) {
                $this->assertNotSame('ok', mb_strtolower(trim($sample['text'])));
            }
        }
        $this->assertGreaterThanOrEqual(10, $result['messages']);
        $this->assertLessThan(ChatScamWaveService::MAX_CLUSTERS, count($result['clusters']), 'the list was not cut short, so an "ok" cluster would have been seen');
    }

    public function test_blank_messages_are_not_a_cluster(): void
    {
        $ids = $this->sendFromManySenders("  \n ", 8, ['added' => now()->subHours(2)]);

        $result = $this->service->detect(30);

        $sampled = [];
        foreach ($result['clusters'] as $cluster) {
            $sampled = array_merge($sampled, array_column($cluster['samples'], 'id'));
        }
        $this->assertEmpty(array_intersect($ids, $sampled));
        $this->assertGreaterThanOrEqual(8, $result['messages']);
    }

    public function test_the_report_replaces_control_and_direction_characters(): void
    {
        $marker = 'ctl'.uniqid();
        $ids = $this->sendFromManySenders("Send your details to\u{202E} the address {$marker}\u{200B} and I will\x1B reply quickly.", 8);

        $result = $this->service->detect(30);
        $result['clusters'] = [$this->clusterContaining($result, $marker)];
        $report = $this->service->report($result);

        $this->assertStringContainsString("  [{$ids[0]}] \"Send your details to the address {$marker} and I will reply quickly.\"", $report);
    }

    public function test_the_command_refuses_a_window_that_is_not_1_to_1440_minutes(): void
    {
        foreach (['abc', '0', '1441'] as $window) {
            $this->artisan("chat:scam-wave-detect --no-mail --window={$window}")
                ->expectsOutputToContain('--window must be a whole number of minutes from 1 to 1440.')
                ->assertFailed();
        }
    }

    public function test_a_short_text_is_still_a_cluster_when_new_accounts_dominate_it(): void
    {
        $marker = 'w'.substr(uniqid(), -8);
        $ids = $this->sendFromManySenders("txt {$marker}", 8, ['added' => now()->subHours(2)]);

        $cluster = $this->clusterContaining($this->service->detect(30), $marker);

        $this->assertNotNull($cluster);
        $this->assertSame(8, $cluster['new']);
        $this->assertContains($ids[0], array_column($cluster['samples'], 'id'));
    }

    public function test_styled_letters_and_spacing_do_not_split_a_cluster(): void
    {
        $marker = 'fold'.uniqid();
        $plain = "Contact me on the other site for this lovely item {$marker}";
        // "Contact" in mathematical bold capitals and small letters.
        $styled = "\u{1D402}\u{1D428}\u{1D427}\u{1D42D}\u{1D41A}\u{1D41C}\u{1D42D}  me on the   other site for this lovely item {$marker}";
        $this->sendFromManySenders($plain, 4);
        $this->sendFromManySenders($styled, 4);

        $cluster = $this->clusterContaining($this->service->detect(30), $marker);

        $this->assertNotNull($cluster);
        $this->assertSame(8, $cluster['n']);
    }

    public function test_too_few_senders_old_messages_and_rejected_messages_do_not_count(): void
    {
        $few = 'few'.uniqid();
        $old = 'old'.uniqid();
        $rejected = 'rej'.uniqid();
        $tail = ' is the marker in a message that is long enough to be clustered.';

        // Eight messages, but only one sender: a member repeating themselves is not a wave.
        $sender = $this->createTestUser(['added' => now()->subYear()]);
        for ($i = 0; $i < 8; $i++) {
            $room = $this->createTestChatRoom($sender, $this->createTestUser());
            $this->createTestChatMessage($room, $sender, ['message' => $few.$tail]);
        }
        $this->sendFromManySenders($old.$tail, 8, [], ['date' => now()->subHours(2)]);
        $this->sendFromManySenders($rejected.$tail, 8, [], ['reviewrejected' => 1]);

        $result = $this->service->detect(30);

        $this->assertNull($this->clusterContaining($result, $few));
        $this->assertNull($this->clusterContaining($result, $old));
        $this->assertNull($this->clusterContaining($result, $rejected));
    }

    public function test_a_domain_named_by_several_messages_is_listed_and_our_own_are_not(): void
    {
        $domain = 'shop'.substr(uniqid(), -8).'.example';
        for ($i = 0; $i < 5; $i++) {
            $this->sendFromManySenders("Message number {$i} says look at www.ilovefreegle.org and {$domain} today", 1);
        }

        $result = $this->service->detect(30);

        $this->assertArrayHasKey($domain, $result['domains']);
        $this->assertSame(5, $result['domains'][$domain]['n']);
        $this->assertSame(5, $result['domains'][$domain]['senders']);
        $this->assertArrayNotHasKey('www.ilovefreegle.org', $result['domains']);
    }

    public function test_the_report_shows_the_message_text_under_a_label(): void
    {
        $marker = 'body'.uniqid();
        $text = "Hi there, is this available?\nPlease reply to my other address {$marker} as I rarely check here.";
        $ids = $this->sendFromManySenders($text, 8);

        $result = $this->service->detect(30);
        $result['clusters'] = [$this->clusterContaining($result, $marker)];
        $report = $this->service->report($result);

        $this->assertStringContainsString('Cluster 1: 8 messages from 8 senders (0 with accounts under 48 hours old) in 8 chats', $report);
        $this->assertStringContainsString('What they said:', $report);
        // Each sample is on one line, quoted, with its message id.
        $this->assertStringContainsString(
            "  [{$ids[0]}] \"Hi there, is this available? Please reply to my other address {$marker} as I rarely check here.\"",
            $report
        );
    }

    public function test_the_report_shortens_a_very_long_message(): void
    {
        $marker = 'long'.uniqid();
        $this->sendFromManySenders("Start {$marker} ".str_repeat('lorem ipsum ', 80), 8);

        $result = $this->service->detect(30);
        $result['clusters'] = [$this->clusterContaining($result, $marker)];
        $report = $this->service->report($result);

        $this->assertStringContainsString('... (', $report);
        $this->assertStringContainsString(' characters in all)', $report);
        $this->assertLessThan(3000, strlen($report));
    }

    public function test_the_command_mails_the_report_when_it_finds_a_cluster(): void
    {
        // Mail::raw() is a no-op under Mail::fake(), so the command's own output is the
        // signal, as in LockdownCommandsTest.
        Mail::fake();
        $marker = 'cmd'.uniqid();
        $this->sendFromManySenders("Please write to me at the address {$marker}, I cannot use this chat.", 8);

        $this->artisan('chat:scam-wave-detect')
            ->expectsOutputToContain($marker)
            ->expectsOutputToContain('Report emailed to')
            ->assertSuccessful();
    }

    public function test_the_command_does_not_mail_when_told_not_to(): void
    {
        Mail::fake();
        $marker = 'dry'.uniqid();
        $this->sendFromManySenders("Please write to me at the address {$marker}, I cannot use this chat.", 8);

        $this->artisan('chat:scam-wave-detect --no-mail')
            ->expectsOutputToContain($marker)
            ->doesntExpectOutputToContain('Report emailed to')
            ->assertSuccessful();
    }

    public function test_the_command_says_so_when_nothing_is_clustered(): void
    {
        Mail::fake();
        // A window too short to hold anything.
        ChatMessage::query()->where('date', '>=', now()->subMinutes(2))->update(['date' => now()->subMinutes(5)]);

        $this->artisan('chat:scam-wave-detect --window=1')
            ->expectsOutputToContain('No clustered messages.')
            ->doesntExpectOutputToContain('Report emailed to')
            ->assertSuccessful();
    }
}
