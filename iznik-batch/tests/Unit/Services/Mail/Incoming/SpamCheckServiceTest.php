<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\Mail\Incoming\SpamCheckService;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Tests for SpamCheckService - all spam detection features.
 *
 * Matches legacy iznik-server Spam.php and MailRouter.php checks.
 */
class SpamCheckServiceTest extends TestCase
{
    use EmailFixtures;

    private SpamCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SpamCheckService;
    }

    // ========================================
    // IP Whitelist Tests
    // ========================================

    public function test_ip_not_whitelisted_returns_false(): void
    {
        $this->assertFalse($this->service->isIPWhitelisted('192.168.1.1'));
    }

    public function test_ip_whitelisted_returns_true(): void
    {
        DB::table('spam_whitelist_ips')->insert([
            'ip' => '10.20.30.40',
            'comment' => 'Test whitelist',
            'date' => now(),
        ]);

        $this->assertTrue($this->service->isIPWhitelisted('10.20.30.40'));
    }

    // ========================================
    // IP Country Blocking Tests
    // ========================================

    public function test_ip_country_not_blocked_returns_null(): void
    {
        // Use a testable subclass to mock GeoIP
        $service = $this->createServiceWithMockedGeoIP('United Kingdom');

        $this->assertNull($service->checkIPCountry('1.2.3.4'));
    }

    public function test_ip_country_blocked_returns_spam_result(): void
    {
        DB::table('spam_countries')->insert([
            'country' => 'Nigeria',
        ]);

        $service = $this->createServiceWithMockedGeoIP('Nigeria');
        $result = $service->checkIPCountry('1.2.3.4');

        $this->assertNotNull($result);
        $this->assertTrue($result[0]);
        $this->assertEquals(SpamCheckService::REASON_COUNTRY_BLOCKED, $result[1]);
        $this->assertStringContainsString('Nigeria', $result[2]);
    }

    public function test_ip_country_unknown_returns_null(): void
    {
        $service = $this->createServiceWithMockedGeoIP(null);
        $this->assertNull($service->checkIPCountry('1.2.3.4'));
    }

    // ========================================
    // IP Reputation - User Threshold Tests
    // ========================================

    public function test_ip_under_user_threshold_returns_null(): void
    {
        $group = $this->createTestGroup();

        for ($i = 0; $i < SpamCheckService::USER_THRESHOLD - 1; $i++) {
            $user = $this->createTestUser();
            DB::table('messages_history')->insert([
                'fromip' => '5.6.7.8',
                'fromuser' => $user->id,
                'fromname' => "User {$i}",
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $this->assertNull($this->service->checkIPUsers('5.6.7.8'));
    }

    public function test_ip_over_user_threshold_returns_spam_result(): void
    {
        $group = $this->createTestGroup();

        for ($i = 0; $i <= SpamCheckService::USER_THRESHOLD; $i++) {
            $user = $this->createTestUser();
            DB::table('messages_history')->insert([
                'fromip' => '9.8.7.6',
                'fromuser' => $user->id,
                'fromname' => "SpamUser {$i}",
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $result = $this->service->checkIPUsers('9.8.7.6');

        $this->assertNotNull($result);
        $this->assertTrue($result[0]);
        $this->assertEquals(SpamCheckService::REASON_IP_USED_FOR_DIFFERENT_USERS, $result[1]);
    }

    // ========================================
    // IP Reputation - Group Threshold Tests
    // ========================================

    public function test_ip_under_group_threshold_returns_null(): void
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < 3; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromip' => '11.22.33.44',
                'fromuser' => $user->id,
                'fromname' => 'Test User',
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $this->assertNull($this->service->checkIPGroups('11.22.33.44'));
    }

    public function test_ip_over_group_threshold_returns_spam_result(): void
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < SpamCheckService::GROUP_THRESHOLD + 1; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromip' => '55.66.77.88',
                'fromuser' => $user->id,
                'fromname' => 'Test User',
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $result = $this->service->checkIPGroups('55.66.77.88');

        $this->assertNotNull($result);
        $this->assertTrue($result[0]);
        $this->assertEquals(SpamCheckService::REASON_IP_USED_FOR_DIFFERENT_GROUPS, $result[1]);
    }

    // ========================================
    // Subject Reuse Detection Tests
    // ========================================

    public function test_subject_under_threshold_returns_null(): void
    {
        $this->assertNull($this->service->checkSubjectReuse('Test item for sale'));
    }

    public function test_subject_over_threshold_returns_spam_result(): void
    {
        for ($i = 0; $i < SpamCheckService::SUBJECT_THRESHOLD + 1; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'prunedsubject' => 'Spam subject line',
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $result = $this->service->checkSubjectReuse('Spam subject line');

        $this->assertNotNull($result);
        $this->assertTrue($result[0]);
        $this->assertEquals(SpamCheckService::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS, $result[1]);
    }

    public function test_subject_over_threshold_but_whitelisted_returns_null(): void
    {
        for ($i = 0; $i < SpamCheckService::SUBJECT_THRESHOLD + 1; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'prunedsubject' => 'Whitelisted subject',
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        DB::table('spam_whitelist_subjects')->insert([
            'subject' => 'Whitelisted subject',
            'comment' => 'Known good subject',
            'date' => now(),
        ]);

        $this->assertNull($this->service->checkSubjectReuse('Whitelisted subject'));
    }

    // ========================================
    // Greeting Spam Tests
    // ========================================

    public function test_greeting_spam_with_subject_and_line1_greeting_plus_link(): void
    {
        $result = $this->service->checkGreetingSpam(
            'Hello there friend',
            "Hello how are you?\nThis is something\nhttp://evil.com/spam"
        );

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_GREETING, $result[1]);
    }

    public function test_greeting_spam_with_line1_and_line3_greeting_plus_link(): void
    {
        $result = $this->service->checkGreetingSpam(
            'Some subject',
            "Hi friend\nsome text\nHey there\nhttp://evil.com/spam"
        );

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_GREETING, $result[1]);
    }

    public function test_no_greeting_spam_without_link(): void
    {
        $this->assertNull($this->service->checkGreetingSpam(
            'Hello',
            "Hello there\nsome text\nHi again"
        ));
    }

    public function test_no_greeting_spam_with_link_but_no_greeting(): void
    {
        $this->assertNull($this->service->checkGreetingSpam(
            'OFFER: Sofa (London)',
            "I have a sofa\nhttp://example.com/photo\nPlease collect"
        ));
    }

    // ========================================
    // Reference to Known Spammer Tests
    // ========================================

    public function test_no_spammer_reference_returns_null(): void
    {
        $this->assertNull($this->service->checkReferToSpammer('Normal message with user@example.com'));
    }

    public function test_spammer_reference_returns_email(): void
    {
        $spammer = $this->createTestUser();
        DB::table('spam_users')->insert([
            'userid' => $spammer->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $spammerEmail = $spammer->emails->first()->email;
        $result = $this->service->checkReferToSpammer("Contact me at {$spammerEmail} for details");

        $this->assertEquals($spammerEmail, $result);
    }

    public function test_no_at_sign_skips_spammer_check(): void
    {
        $this->assertNull($this->service->checkReferToSpammer('No email addresses here'));
    }

    // ========================================
    // Keyword-Based Spam Tests
    // ========================================

    public function test_spam_keyword_detected(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => null,
        ]);

        $result = $this->service->checkSpamKeywords(
            'Please send money via Western Union',
            [SpamCheckService::ACTION_SPAM]
        );

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_KNOWN_KEYWORD, $result[1]);
    }

    public function test_review_keyword_detected(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'earn money',
            'action' => 'Review',
            'type' => 'Literal',
            'exclude' => null,
        ]);

        $result = $this->service->checkSpamKeywords(
            'You can earn money fast',
            [SpamCheckService::ACTION_REVIEW]
        );

        $this->assertNotNull($result);
    }

    public function test_keyword_with_exclude_pattern_not_flagged(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'free',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => 'freegle',
        ]);

        $result = $this->service->checkSpamKeywords(
            'I love freegle free stuff',
            [SpamCheckService::ACTION_SPAM]
        );

        // The exclude pattern 'freegle' matches, so it should NOT flag
        $this->assertNull($result);
    }

    public function test_keyword_not_matching_action_not_flagged(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'suspicious',
            'action' => 'Review',
            'type' => 'Literal',
            'exclude' => null,
        ]);

        $result = $this->service->checkSpamKeywords(
            'This is suspicious',
            [SpamCheckService::ACTION_SPAM]  // Only checking Spam, not Review
        );

        $this->assertNull($result);
    }

    public function test_html_entity_decoding_catches_obfuscated_spam(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'Isis',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => null,
        ]);

        $result = $this->service->checkSpamKeywords(
            'Join &#206;&#537;&#616;&#537;',  // Obfuscated "Isis"
            [SpamCheckService::ACTION_SPAM]
        );

        $this->assertNotNull($result);
    }

    public function test_url_in_job_post_not_flagged(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'click here',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => null,
        ]);

        $result = $this->service->checkSpamKeywords(
            '<https://www.ilovefreegle.org/jobs/12345> click here for job details',
            [SpamCheckService::ACTION_SPAM]
        );

        // The job URL line is stripped, so 'click here' in same line goes too
        $this->assertNull($result);
    }

    // ========================================
    // Our Domain Spoofing in URLs Tests
    // ========================================

    public function test_our_domain_spoofing_in_url_detected(): void
    {
        $result = $this->service->checkSpamKeywords(
            'Visit http://evil.groups.ilovefreegle.org/phishing',
            [SpamCheckService::ACTION_SPAM]
        );

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_USED_OUR_DOMAIN, $result[1]);
    }

    // ========================================
    // Bulk Volunteer Mail Tests
    // ========================================

    public function test_bulk_volunteer_mail_under_threshold_returns_null(): void
    {
        $email = $this->createParsedEmailForSpamTest([
            'envelopeFrom' => 'sender@example.com',
            'subject' => 'Test subject',
        ]);

        $this->assertNull($this->service->checkBulkVolunteerMail($email));
    }

    public function test_bulk_volunteer_mail_sender_over_threshold(): void
    {
        $groupDomain = config('freegle.mail.group_domain');

        for ($i = 0; $i < SpamCheckService::GROUP_THRESHOLD + 1; $i++) {
            DB::table('messages')->insert([
                'envelopefrom' => 'spammer@evil.com',
                'envelopeto' => "group{$i}-volunteers@{$groupDomain}",
                'arrival' => now(),
                'date' => now(),
                'source' => 'Email',
            ]);
        }

        $email = $this->createParsedEmailForSpamTest([
            'envelopeFrom' => 'spammer@evil.com',
            'subject' => 'Buy stuff',
        ]);

        $result = $this->service->checkBulkVolunteerMail($email);

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_BULK_VOLUNTEER_MAIL, $result[1]);
    }

    // ========================================
    // Prune Subject Tests
    // ========================================

    public function test_prune_subject_strips_offer_prefix(): void
    {
        $this->assertEquals('Test Item', $this->service->pruneSubject('OFFER: Test Item (London)'));
    }

    public function test_prune_subject_strips_wanted_prefix(): void
    {
        $this->assertEquals('Test Item', $this->service->pruneSubject('WANTED: Test Item (London)'));
    }

    public function test_prune_subject_strips_taken_prefix(): void
    {
        $this->assertEquals('Test Item', $this->service->pruneSubject('TAKEN: Test Item (London)'));
    }

    public function test_prune_subject_no_prefix_strips_location(): void
    {
        $this->assertEquals('Test Item', $this->service->pruneSubject('Test Item (London)'));
    }

    public function test_prune_subject_plain_text_unchanged(): void
    {
        $this->assertEquals('Just a plain subject', $this->service->pruneSubject('Just a plain subject'));
    }

    // ========================================
    // Review Check Tests
    // ========================================

    public function test_review_detects_script_tag(): void
    {
        $this->assertEquals(
            SpamCheckService::REASON_SCRIPT,
            $this->service->checkReview('<script>alert("xss")</script>')
        );
    }

    public function test_review_detects_money_symbol_dollar(): void
    {
        $this->assertEquals(
            SpamCheckService::REASON_MONEY,
            $this->service->checkReview('Send me $500 please')
        );
    }

    public function test_review_detects_money_symbol_pound(): void
    {
        $this->assertEquals(
            SpamCheckService::REASON_MONEY,
            $this->service->checkReview('Send me £500 please')
        );
    }

    public function test_review_detects_external_email(): void
    {
        $this->assertEquals(
            SpamCheckService::REASON_EMAIL,
            $this->service->checkReview('Contact me at person@gmail.com')
        );
    }

    public function test_review_allows_our_domain_email(): void
    {
        $this->assertNull(
            $this->service->checkReview('Contact us at support@ilovefreegle.org', false)
        );
    }

    public function test_review_empty_message_returns_null(): void
    {
        $this->assertNull($this->service->checkReview(''));
    }

    public function test_review_clean_message_returns_null(): void
    {
        $this->assertNull($this->service->checkReview('I have a nice sofa to give away', false));
    }

    // ========================================
    // Image Spam Tests
    // ========================================

    public function test_image_hash_under_threshold_returns_false(): void
    {
        $this->assertFalse($this->service->checkImageSpam('abc123'));
    }

    public function test_image_hash_over_threshold_returns_true(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        for ($i = 0; $i < SpamCheckService::IMAGE_THRESHOLD + 2; $i++) {
            // Insert chat image first (without chatmsgid to avoid circular FK)
            $imageId = DB::table('chat_images')->insertGetId([
                'hash' => 'spamhash12345678',
                'contenttype' => 'image/jpeg',
            ]);

            // Insert chat message referencing the image
            $msgId = DB::table('chat_messages')->insertGetId([
                'chatid' => $room->id,
                'userid' => $user1->id,
                'imageid' => $imageId,
                'type' => 'Image',
                'date' => now(),
                'platform' => 1,
            ]);

            // Update chat image to reference the message
            DB::table('chat_images')->where('id', $imageId)->update(['chatmsgid' => $msgId]);
        }

        $this->assertTrue($this->service->checkImageSpam('spamhash12345678'));
    }

    // ========================================
    // SpamAssassin Tests
    // ========================================

    public function test_spamassassin_skips_standard_freegle_subject(): void
    {
        [$score, $isSpam] = $this->service->checkSpamAssassin(
            'raw email content',
            'OFFER: Nice Sofa (London)'
        );

        $this->assertNull($score);
        $this->assertFalse($isSpam);
    }

    public function test_spamassassin_checks_non_standard_subject(): void
    {
        // Create service with mocked spamd that returns low score
        $service = $this->createServiceWithMockedSpamd(2.5);

        [$score, $isSpam] = $service->checkSpamAssassin(
            'raw email content',
            'Buy cheap pills now'
        );

        $this->assertEquals(2.5, $score);
        $this->assertFalse($isSpam);
    }

    public function test_spamassassin_flags_high_score_as_spam(): void
    {
        $service = $this->createServiceWithMockedSpamd(12.5);

        [$score, $isSpam] = $service->checkSpamAssassin(
            'raw email content',
            'Buy cheap pills now'
        );

        $this->assertEquals(12.5, $score);
        $this->assertTrue($isSpam);
    }

    // ========================================
    // Full checkMessage Integration Tests
    // ========================================

    public function test_check_message_clean_returns_null(): void
    {
        $email = $this->createParsedEmailForSpamTest([
            'fromName' => 'Normal User',
            'subject' => 'OFFER: Nice table (London)',
            'textBody' => 'I have a table to give away',
        ]);

        $this->assertNull($this->service->checkMessage($email));
    }

    public function test_check_message_detects_our_domain_in_from_name(): void
    {
        $email = $this->createParsedEmailForSpamTest([
            'fromName' => 'admin@groups.ilovefreegle.org',
            'subject' => 'Important',
            'textBody' => 'Urgent action required',
        ]);

        $result = $this->service->checkMessage($email);

        $this->assertNotNull($result);
        $this->assertEquals(SpamCheckService::REASON_USED_OUR_DOMAIN, $result[1]);
    }

    public function test_check_message_skips_ip_checks_for_tn(): void
    {
        // TN messages skip IP checks even with a blocked country
        DB::table('spam_countries')->insert(['country' => 'TestCountry']);

        $service = $this->createServiceWithMockedGeoIP('TestCountry');

        $email = $this->createParsedEmailForSpamTest([
            'senderIp' => '1.2.3.4',
            'isFromTN' => true,
        ]);

        $this->assertNull($service->checkMessage($email));
    }

    public function test_check_message_skips_ip_checks_for_internal_ips(): void
    {
        DB::table('spam_countries')->insert(['country' => 'TestCountry']);

        $service = $this->createServiceWithMockedGeoIP('TestCountry');

        $email = $this->createParsedEmailForSpamTest([
            'senderIp' => '10.0.0.1',
        ]);

        $this->assertNull($service->checkMessage($email));
    }

    public function test_check_message_for_chat_reply_skips_subject_reuse(): void
    {
        // A common post subject like "Washing Machine" legitimately appears on many
        // groups in messages_history. An email reply to a chat notification echoes
        // that subject, so subject-reuse must NOT fire on the reply.
        for ($i = 0; $i < SpamCheckService::SUBJECT_THRESHOLD + 1; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'prunedsubject' => 'Washing Machine',
                'groupid' => $group->id,
                'arrival' => now(),
            ]);
        }

        $email = $this->createParsedEmailForSpamTest([
            'subject' => 'Re: Washing Machine (BD4)',
            'textBody' => 'Okey that\'s fine i can only collect after 7pm',
        ]);

        // Without the chat-reply flag, subject reuse trips.
        $postResult = $this->service->checkMessage($email);
        $this->assertNotNull($postResult);
        $this->assertEquals(SpamCheckService::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS, $postResult[1]);

        // With forChatReply=true, the same email is clean.
        $this->assertNull($this->service->checkMessage($email, forChatReply: true));
    }

    // ========================================
    // Link Whitelist / Review Link Tests
    // ========================================

    public function test_review_link_whitelisted_domain_not_flagged(): void
    {
        DB::table('spam_whitelist_links')->insert([
            'domain' => 'www.freecycle.org',
            'count' => 10,
        ]);

        $this->assertNull(
            $this->service->checkReview('Check out http://www.freecycle.org/stuff', false)
        );
    }

    public function test_review_link_unknown_domain_flagged(): void
    {
        $this->assertEquals(
            SpamCheckService::REASON_LINK,
            $this->service->checkReview('Visit http://dodgy-site.xyz/spam', false)
        );
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Create a ParsedEmail for spam testing with sensible defaults.
     */
    private function createParsedEmailForSpamTest(array $overrides = []): ParsedEmail
    {
        $defaults = [
            'rawMessage' => 'From: test@test.com\r\nSubject: Test\r\n\r\nBody',
            'envelopeFrom' => 'test@test.com',
            'envelopeTo' => 'group@groups.ilovefreegle.org',
            'subject' => 'OFFER: Test (London)',
            'fromAddress' => 'test@test.com',
            'fromName' => 'Test User',
            'toAddresses' => ['group@groups.ilovefreegle.org'],
            'messageId' => '<test-'.uniqid().'@test.com>',
            'date' => null,
            'textBody' => 'Test message body',
            'htmlBody' => null,
            'headers' => [],
            'targetGroupName' => null,
            'isToVolunteers' => false,
            'isToAuto' => false,
            'bounceRecipient' => null,
            'bounceStatus' => null,
            'bounceDiagnostic' => null,
            'chatId' => null,
            'chatUserId' => null,
            'chatMessageId' => null,
            'commandUserId' => null,
            'commandGroupId' => null,
            'senderIp' => null,
            'isFromTN' => false,
        ];

        $merged = array_merge($defaults, $overrides);

        // Handle TN flag via headers
        $headers = $merged['headers'];
        if ($merged['isFromTN']) {
            $headers['x-trash-nothing-secret'] = 'test-secret';
        }

        return new ParsedEmail(
            $merged['rawMessage'],
            $merged['envelopeFrom'],
            $merged['envelopeTo'],
            $merged['subject'],
            $merged['fromAddress'],
            $merged['fromName'],
            $merged['toAddresses'],
            $merged['messageId'],
            $merged['date'],
            $merged['textBody'],
            $merged['htmlBody'],
            $headers,
            $merged['targetGroupName'],
            $merged['isToVolunteers'],
            $merged['isToAuto'],
            $merged['bounceRecipient'],
            $merged['bounceStatus'],
            $merged['bounceDiagnostic'],
            $merged['chatId'],
            $merged['chatUserId'],
            $merged['chatMessageId'],
            $merged['commandUserId'],
            $merged['commandGroupId'],
            $merged['senderIp']
        );
    }

    /**
     * Create a SpamCheckService with mocked GeoIP lookup.
     */
    private function createServiceWithMockedGeoIP(?string $country): SpamCheckService
    {
        return new class($country) extends SpamCheckService
        {
            public function __construct(private ?string $mockCountry) {}

            protected function lookupIPCountry(string $ip): ?string
            {
                return $this->mockCountry;
            }
        };
    }

    /**
     * Create a SpamCheckService with mocked spamd response.
     */
    private function createServiceWithMockedSpamd(float $score): SpamCheckService
    {
        return new class($score) extends SpamCheckService
        {
            public function __construct(private float $mockScore) {}

            protected function querySpamd(string $message, string $host, int $port): ?float
            {
                return $this->mockScore;
            }
        };
    }
}
