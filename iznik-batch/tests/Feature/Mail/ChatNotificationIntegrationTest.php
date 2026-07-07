<?php

namespace Tests\Feature\Mail;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * Integration tests for ChatNotification that actually send emails to Mailpit.
 *
 * Tests require Mailpit to be running and accessible.
 */
class ChatNotificationIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;
    protected string $testRunId;

    /**
     * Test-environment-only spam symbols and their scores.
     * These are caused by DNS/DKIM not being configured in the test environment.
     */
    protected const TEST_ENV_SYMBOLS = [
        "DMARC_POLICY_REJECT" => 2.0,   // Would pass in production with DKIM
        "HFILTER_HOSTNAME_UNKNOWN" => 2.5, // Hostname resolves in production
        "URL_NO_TLD" => 2.0,             // Test env uses .localhost domains
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRunId = uniqid("chat_", TRUE);

        Config::set("mail.default", "smtp");
        Config::set("mail.mailers.smtp.host", "mailpit");
        Config::set("mail.mailers.smtp.port", 1025);

        Config::set("freegle.spam_check.enabled", true);
        Config::set("freegle.spam_check.spamassassin_host", "spamassassin-app");
        Config::set("freegle.spam_check.spamassassin_port", 783);
        Config::set("freegle.spam_check.rspamd_host", "rspamd");
        Config::set("freegle.spam_check.rspamd_port", 11334);

        $this->mailpit = new MailpitHelper("http://mailpit:8025");
        // Note: Do NOT call deleteAllMessages() here - in parallel test runs,
        // this would delete emails from other tests.
    }

    protected function createUniqueTestUser(string $prefix = "user"): User
    {
        $user = User::create([
            "firstname" => "Test",
            "lastname" => $prefix,
            "fullname" => "Test " . $prefix,
            "added" => now(),
        ]);

        $email = "{$prefix}_{$this->testRunId}@example.com";
        UserEmail::create([
            "userid" => $user->id,
            "email" => $email,
            "preferred" => 1,
            "added" => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Calculate adjusted Rspamd score by removing test-environment-only penalties.
     */
    protected function getAdjustedRspamdScore(?float $score, array $symbols): ?float
    {
        if ($score === null) {
            return null;
        }

        $adjustment = 0.0;
        foreach ($symbols as $symbol) {
            // Extract symbol name (before the score in parens).
            $symbolName = preg_replace("/\\([^)]+\\)/", "", $symbol);
            $symbolName = trim($symbolName);
            
            if (isset(self::TEST_ENV_SYMBOLS[$symbolName])) {
                $adjustment += self::TEST_ENV_SYMBOLS[$symbolName];
            }
        }

        return max(0, $score - $adjustment);
    }

    public function test_chat_notification_delivered_to_mailpit(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped("Mailpit is not available.");
        }

        $sender = $this->createUniqueTestUser("sender");
        $recipient = $this->createUniqueTestUser("recipient");

        $room = ChatRoom::create([
            "chattype" => ChatRoom::TYPE_USER2USER,
            "user1" => $sender->id,
            "user2" => $recipient->id,
            "created" => now(),
        ]);

        $message = ChatMessage::create([
            "chatid" => $room->id,
            "userid" => $sender->id,
            "message" => "Hi there! I am interested in this item.",
            "type" => ChatMessage::TYPE_DEFAULT,
            "date" => now(),
            "reviewrequired" => 0,
            "processingrequired" => 0,
            "processingsuccessful" => 1,
            "mailedtoall" => 0,
            "seenbyall" => 0,
            "reviewrejected" => 0,
            "platform" => 1,
        ]);

        $mail = new ChatNotification(
            $recipient,
            $sender,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        Mail::to($recipient->email_preferred)->send($mail);

        $receivedMessage = $this->mailpit->assertMessageSentTo($recipient->email_preferred);

        $this->assertNotNull($receivedMessage);
        $subject = $this->mailpit->getSubject($receivedMessage);
        $this->assertStringContainsString("sent you a message", $subject);
    }

    public function test_chat_notification_passes_spam_checks(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped("Mailpit is not available.");
        }

        $sender = $this->createUniqueTestUser("sender_spam");
        $recipient = $this->createUniqueTestUser("recipient_spam");

        $room = ChatRoom::create([
            "chattype" => ChatRoom::TYPE_USER2USER,
            "user1" => $sender->id,
            "user2" => $recipient->id,
            "created" => now(),
        ]);

        $message = ChatMessage::create([
            "chatid" => $room->id,
            "userid" => $sender->id,
            "message" => "Hi! I would like to collect this item if possible. When would be a good time?",
            "type" => ChatMessage::TYPE_DEFAULT,
            "date" => now(),
            "reviewrequired" => 0,
            "processingrequired" => 0,
            "processingsuccessful" => 1,
            "mailedtoall" => 0,
            "seenbyall" => 0,
            "reviewrejected" => 0,
            "platform" => 1,
        ]);

        $mail = new ChatNotification(
            $recipient,
            $sender,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        Mail::to($recipient->email_preferred)->send($mail);

        $receivedMessage = $this->mailpit->assertMessageSentTo($recipient->email_preferred);
        $this->assertNotNull($receivedMessage, "Message should have been sent");

        $spamReport = $this->mailpit->getSpamReport($receivedMessage);

        $saScore = $spamReport["spamassassin"]["score"];
        $rspamdScore = $spamReport["rspamd"]["score"];
        $saSymbols = $spamReport["spamassassin"]["symbols"];
        $rspamdSymbols = $spamReport["rspamd"]["symbols"];
        
        // Calculate production-equivalent score (removing test-env penalties).
        $adjustedRspamdScore = $this->getAdjustedRspamdScore($rspamdScore, $rspamdSymbols);

        fwrite(STDERR, "\n");
        fwrite(STDERR, "=== Chat Notification Spam Report ===\n");
        fwrite(STDERR, "SpamAssassin Score: " . ($saScore !== null ? sprintf("%.1f", $saScore) : "N/A") . "\n");
        fwrite(STDERR, "SpamAssassin Symbols: " . (count($saSymbols) > 0 ? implode(", ", $saSymbols) : "none") . "\n");
        fwrite(STDERR, "Rspamd Score: " . ($rspamdScore !== null ? sprintf("%.1f", $rspamdScore) : "N/A") . "\n");
        fwrite(STDERR, "Rspamd (Production Est.): " . ($adjustedRspamdScore !== null ? sprintf("%.1f", $adjustedRspamdScore) : "N/A") . "\n");
        fwrite(STDERR, "Rspamd Symbols: " . (count($rspamdSymbols) > 0 ? implode(", ", $rspamdSymbols) : "none") . "\n");
        fwrite(STDERR, "======================================\n");

        if ($saScore !== null) {
            $this->assertLessThan(
                5.0,
                $saScore,
                "SpamAssassin score {$saScore} exceeds threshold. Symbols: " . implode(", ", $saSymbols)
            );
        }

        // Use adjusted score for Rspamd (accounts for test-env penalties).
        if ($adjustedRspamdScore !== null) {
            $this->assertLessThan(
                5.0,
                $adjustedRspamdScore,
                "Rspamd production-equivalent score {$adjustedRspamdScore} exceeds threshold. " .
                "Raw: {$rspamdScore}. Symbols: " . implode(", ", $rspamdSymbols)
            );
        }

        if ($saScore === null && $rspamdScore === null) {
            fwrite(STDERR, "WARNING: No spam scores available. Check spam check services.\n");
        }
    }

    public function test_user2mod_notification_passes_spam_checks(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped("Mailpit is not available.");
        }

        $user = $this->createUniqueTestUser("mod_user");
        $group = $this->createTestGroup();

        $room = ChatRoom::create([
            "chattype" => ChatRoom::TYPE_USER2MOD,
            "user1" => $user->id,
            "groupid" => $group->id,
            "created" => now(),
        ]);

        $message = ChatMessage::create([
            "chatid" => $room->id,
            "userid" => $user->id,
            "message" => "Hello volunteers, I have a question about posting on this group.",
            "type" => ChatMessage::TYPE_DEFAULT,
            "date" => now(),
            "reviewrequired" => 0,
            "processingrequired" => 0,
            "processingsuccessful" => 1,
            "mailedtoall" => 0,
            "seenbyall" => 0,
            "reviewrejected" => 0,
            "platform" => 1,
        ]);

        $mail = new ChatNotification(
            $user,
            null,
            $room,
            $message,
            ChatRoom::TYPE_USER2MOD
        );

        Mail::to($user->email_preferred)->send($mail);

        $receivedMessage = $this->mailpit->assertMessageSentTo($user->email_preferred);

        $spamReport = $this->mailpit->getSpamReport($receivedMessage);

        $saScore = $spamReport["spamassassin"]["score"];
        $rspamdScore = $spamReport["rspamd"]["score"];
        $saSymbols = $spamReport["spamassassin"]["symbols"];
        $rspamdSymbols = $spamReport["rspamd"]["symbols"];
        
        $adjustedRspamdScore = $this->getAdjustedRspamdScore($rspamdScore, $rspamdSymbols);

        fwrite(STDERR, "\n");
        fwrite(STDERR, "=== User2Mod Notification Spam Report ===\n");
        fwrite(STDERR, "SpamAssassin Score: " . ($saScore !== null ? sprintf("%.1f", $saScore) : "N/A") . "\n");
        fwrite(STDERR, "SpamAssassin Symbols: " . (count($saSymbols) > 0 ? implode(", ", $saSymbols) : "none") . "\n");
        fwrite(STDERR, "Rspamd Score: " . ($rspamdScore !== null ? sprintf("%.1f", $rspamdScore) : "N/A") . "\n");
        fwrite(STDERR, "Rspamd (Production Est.): " . ($adjustedRspamdScore !== null ? sprintf("%.1f", $adjustedRspamdScore) : "N/A") . "\n");
        fwrite(STDERR, "Rspamd Symbols: " . (count($rspamdSymbols) > 0 ? implode(", ", $rspamdSymbols) : "none") . "\n");
        fwrite(STDERR, "==========================================\n");

        if ($saScore !== null) {
            $this->assertLessThan(5.0, $saScore, "SpamAssassin score {$saScore} exceeds threshold");
        }

        if ($adjustedRspamdScore !== null) {
            $this->assertLessThan(5.0, $adjustedRspamdScore, 
                "Rspamd production-equivalent score {$adjustedRspamdScore} exceeds threshold");
        }
    }

    protected function isMailpitAvailable(): bool
    {
        try {
            $ch = curl_init("http://mailpit:8025/api/v1/messages");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return FALSE;
        }
    }
}
