<?php

namespace Tests\Feature\Mail;

use App\Services\EmailSpoolerService;
use App\Services\FreegleApiClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * End-to-end: matches:notify → spool → send → Mailpit receives a well-formed,
 * non-spammy matched-posts email with the reason line, pill, and Reply CTA.
 * Requires Mailpit + the MJML server.
 */
class MatchedPostsIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        $this->mailpit = new MailpitHelper('http://mailpit:8025');
    }

    protected function tearDown(): void
    {
        FreegleApiClient::clearFake();
        parent::tearDown();
    }

    protected function isMailpitAvailable(): bool
    {
        try {
            $ch = curl_init('http://mailpit:8025/api/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function test_matched_posts_email_is_delivered_and_well_formed(): void
    {
        if (! $this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $group = $this->createTestGroup();

        // createTestUser already gives each user a preferred email; use it (adding
        // a second preferred email makes email_preferred ambiguous).
        $recipient = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);
        $recipientEmail = $recipient->email_preferred;
        $offerer = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);

        // Recipient's fresh WANTED (driver: needs spatial + embedding).
        $wanted = $this->createTestMessage($recipient, $group, ['type' => 'Wanted', 'subject' => 'WANTED: Bookcase (York)', 'arrival' => now()]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, groupid, msgtype, successful, promised, arrival, point)
             VALUES (?, ?, ?, 0, 0, ?, ST_GeomFromText(?, 3857))',
            [$wanted->id, $group->id, 'Wanted', now(), sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );
        DB::statement('INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)',
            [$wanted->id, str_repeat("\0", 1024), 'test']);

        // Matching OFFER.
        $offer = $this->createTestMessage($offerer, $group, ['type' => 'Offer', 'subject' => 'OFFER: Bookcase (York)', 'arrival' => now()->subHours(2)]);

        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.85, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        $this->artisan('matches:notify')->assertExitCode(0);
        app(EmailSpoolerService::class)->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        $subject = $this->mailpit->getSubject($message);
        $this->assertStringContainsString('Bookcase', $subject, 'subject names the matched item');

        $this->assertTrue($this->mailpit->bodyContains($message, 'Matches your'), 'reason line present');
        $this->assertTrue($this->mailpit->bodyContains($message, 'OFFER'), 'type pill present');
        $this->assertTrue($this->mailpit->bodyContains($message, 'Reply'), 'reply CTA present');

        $this->mailpit->assertNotSpam($message);
    }
}
