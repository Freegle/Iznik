<?php

namespace Tests\Unit\Services;

use App\Models\MessageGroup;
use ReflectionClass;
use App\Models\Message;
use App\Services\ContentCheckService;
use App\Services\ContentEmbeddingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentCheckServiceTest extends TestCase
{
    protected ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService();
    }

    // =========================================================================
    // checkPhoneNumbers — always applied, no per-community gating (frozen-settings.md:
    // restrictpersonalinfo, personal details are always kept out of posts now)
    // =========================================================================

    #[DataProvider('phoneNumberProvider')]
    public function test_check_phone_numbers(string $subject, string $body, bool $expectFlag): void
    {
        $result = $this->service->checkPhoneNumbers($subject, $body);

        if ($expectFlag) {
            $this->assertNotNull($result);
            $this->assertSame(ContentCheckService::CHECK_PHONE_NUMBER, $result['check']);
            $this->assertSame('flag', $result['action']);
        } else {
            $this->assertNull($result);
        }
    }

    public static function phoneNumberProvider(): array
    {
        return [
            'no number'                        => ['', 'Clean text', false],
            'UK mobile 07xxx'                  => ['', 'Call 07911 123456 for details', true],
            '+44 format'                       => ['', 'Ring +44 7911 123456', true],
            '0044 format'                      => ['', '0044 7911 123456', true],
            'landline 01xxx'                   => ['', '01234 567890 is our number', true],
            'number in subject'                => ['Call 07911 123456', '', true],
            'number with hyphens'              => ['', '07911-123-456', true],
            'too short number'                 => ['', '012 34', false],
            'postal code not a phone'          => ['', 'SW1A 1AA', false],
            'flat number not a phone'          => ['', 'Flat 12', false],
        ];
    }

    // =========================================================================
    // checkMessagingLinks — public, pure string matching
    // =========================================================================

    #[DataProvider('messagingLinkProvider')]
    public function test_check_messaging_links(string $subject, string $body, bool $expectFlag): void
    {
        $result = $this->service->checkMessagingLinks($subject, $body);

        if ($expectFlag) {
            $this->assertNotNull($result);
            $this->assertSame(ContentCheckService::CHECK_MESSAGING_LINK, $result['check']);
        } else {
            $this->assertNull($result);
        }
    }

    public static function messagingLinkProvider(): array
    {
        return [
            'no link'                    => ['Sofa available', 'Good condition', false],
            'whatsapp chat link'         => ['', 'Join us at chat.whatsapp.com/abc123', true],
            'wa.me link'                 => ['', 'Chat: wa.me/447911123456', true],
            'telegram t.me'              => ['', 'https://t.me/mygroup', true],
            'telegram.me'                => ['', 'telegram.me/join/xyz', true],
            'discord.gg'                 => ['', 'Join discord.gg/freegle', true],
            'discord invite'             => ['', 'discord.com/invite/abc', true],
            'signal.group'               => ['', 'https://signal.group/abc', true],
            'normal website'             => ['', 'See https://freegle.in for details', false],
            'link in subject'            => ['Join wa.me/group', '', true],
            'case insensitive'           => ['', 'Chat.WhatsApp.Com/group', true],
        ];
    }

    // =========================================================================
    // checkMoneySymbols — public, pure
    // =========================================================================

    #[DataProvider('moneySymbolProvider')]
    public function test_check_money_symbols(string $subject, string $body, bool $expectFlag): void
    {
        $result = $this->service->checkMoneySymbols($subject, $body);

        if ($expectFlag) {
            $this->assertNotNull($result);
            $this->assertSame(ContentCheckService::CHECK_MONEY, $result['check']);
            $this->assertSame('flag', $result['action']);
        } else {
            $this->assertNull($result);
        }
    }

    public static function moneySymbolProvider(): array
    {
        return [
            'no symbols'             => ['Sofa free', 'Take it', false],
            'pound in subject'       => ['Only £5', 'Great condition', true],
            'pound in body'          => ['Free sofa', 'Worth £200', true],
            'dollar in body'         => ['Free sofa', 'Worth $200', true],
            'both symbols'           => ['£ and $ offer', 'text', true],
            'euro symbol no flag'    => ['', '€100 value', false],
            'hash no flag'           => ['', '#100', false],
        ];
    }

    // =========================================================================
    // checkSpamhaus — injectable DNS, no network calls
    // =========================================================================

    public function test_check_spamhaus_no_urls_returns_null(): void
    {
        $result = $this->service->checkSpamhaus('Free sofa', 'Good condition', fn($d) => []);
        $this->assertNull($result);
    }

    public function test_check_spamhaus_clean_domain_returns_null(): void
    {
        $called = [];
        $dns = function (string $domain) use (&$called): array {
            $called[] = $domain;
            return [];
        };

        $result = $this->service->checkSpamhaus('', 'See https://freegle.in for info', $dns);
        $this->assertNull($result);
        $this->assertNotEmpty($called);
    }

    public function test_check_spamhaus_blocked_domain_returns_flag(): void
    {
        $dns = fn(string $d) => [['ip' => '127.0.0.2']]; // non-empty = blocked

        $result = $this->service->checkSpamhaus('', 'Check https://spam-site.com for offers', $dns);
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_SPAMHAUS_DBL, $result['check']);
        $this->assertSame('flag', $result['action']);
        $this->assertStringContainsString('spam-site.com', $result['detail']);
    }

    public function test_check_spamhaus_only_first_blocked_url_returned(): void
    {
        $calls = [];
        $dns = function (string $domain) use (&$calls): array {
            $calls[] = $domain;
            // Block only the second domain
            return str_contains($domain, 'second') ? [['ip' => '127.0.0.2']] : [];
        };

        $result = $this->service->checkSpamhaus(
            '',
            'First https://first-clean.com then https://second-blocked.com',
            $dns
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('second-blocked.com', $result['detail']);
    }

    public function test_check_spamhaus_strips_www_prefix(): void
    {
        $checked = [];
        $dns = function (string $domain) use (&$checked): array {
            $checked[] = $domain;
            return [];
        };

        $this->service->checkSpamhaus('', 'Visit www.example.com today', $dns);

        // www. should be stripped before DNS lookup
        foreach ($checked as $domain) {
            $this->assertStringNotContainsString('www.', $domain);
        }
    }

    // =========================================================================
    // checkLanguage — pure (uses language detection library)
    // =========================================================================

    public function test_check_language_short_text_skipped(): void
    {
        // Text <= 80 chars is never checked regardless of content
        $result = $this->service->checkLanguage('', 'Hola'); // 4 chars
        $this->assertNull($result);
    }

    public function test_check_language_english_text_accepted(): void
    {
        $text = 'I have a sofa and two chairs that I no longer need. They are in good condition and free to collect.';
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result);
    }

    public function test_check_language_real_world_terse_english_reply_not_flagged(): void
    {
        // Regression: this exact production chat reply (chat_messages 108721900)
        // was shown in ModTools chat review as "It might not be in English". At 60
        // chars it now falls below the 80-char detection gate, so it is no longer
        // checked — it was a false positive (terse English the library misranks as
        // a Latinate conlang). Uses the REAL detector (not a mock).
        $text = "Yes please can collect.Paul\n\nPossible collection times: Asap";
        $this->assertLessThan(80, strlen(trim($text)));
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result, 'Terse English reply must not be flagged as non-English');
    }

    public function test_check_language_long_english_not_flagged_with_restricted_set(): void
    {
        // Longer English (>80 chars) where the FULL library would rank a Latinate
        // conlang (Interlingua/Occitan) top; with the restricted UK language set
        // English ranks top, so it is accepted. Uses the REAL detector to lock in
        // that the restricted set prevents conlang false positives (Discourse #9481).
        $text = 'Hi there, yes I would love these if still available, I can come and collect them this afternoon if that suits you, thank you so much';
        $this->assertGreaterThan(80, strlen($text));
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result, 'Long English must rank English top with the restricted set and not be flagged');
    }

    public function test_check_language_xxx_stripped_before_check(): void
    {
        // "xxx" in text gets stripped; resulting text may still be checkable
        $text = 'I have xxx a sofa and chairs that I no longer need. They are in good condition and free to collect from SE1.';
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result); // English after stripping xxx
    }

    public function test_check_language_flagged_for_non_english(): void
    {
        // Clear Spanish, well over 80 chars — ELD is confident, so it must flag.
        $text = 'Tengo un sofá que ya no necesito. Está en buenas condiciones y se puede recoger en cualquier momento del día. Contacta conmigo si estás interesado.';
        $result = $this->service->checkLanguage('', $text);
        $this->assertNotNull($result, 'Confident Spanish must be flagged');
        $this->assertSame(ContentCheckService::CHECK_LANGUAGE, $result['check']);
    }

    public function test_check_language_terse_list_style_english_not_flagged(): void
    {
        // Regression (Discourse #9919): a plainly-English offer that is all English
        // words, numbers and standard abbreviations. The old trigram library ranked
        // this as a Latinate language and false-flagged it; ELD picks English. Real detector.
        $text = 'Fridge freezer Beko W60 H180 good working order free to collect only from HA8 mon to fri evenings please, first to reply gets it.';
        $this->assertGreaterThan(80, strlen($text));
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result, 'All-English-words offer must not be flagged as foreign (#9919)');
    }

    public function test_check_language_french_offer_flagged(): void
    {
        // A genuinely French offer that the old 0.8 ratio let through as a false
        // negative — ELD detects it confidently. Real detector.
        $text = 'Bonjour, je donne un canapé en bon état, à récupérer rapidement chez moi cette semaine, merci beaucoup et bonne journée à tous.';
        $result = $this->service->checkLanguage('', $text);
        $this->assertNotNull($result, 'Confident French must be flagged');
        $this->assertSame(ContentCheckService::CHECK_LANGUAGE, $result['check']);
    }

    public function test_check_language_text_below_80_chars_skipped(): void
    {
        // The detection gate was raised 50→80: detection is a coin-flip on short
        // text, so a sub-80-char message is skipped before detection even if a
        // detector would flag it. This is how terse English replies (Discourse
        // #9481) stop being false-flagged.
        $alwaysFlags = static fn(string $text) => ['fr' => 0.90, 'en' => 0.10];
        $text = 'Yes please can collect this thanks very much';
        $this->assertLessThanOrEqual(80, strlen($text));
        $result = $this->service->checkLanguage('', $text, $alwaysFlags);
        $this->assertNull($result, 'Sub-80-char text must be skipped before language detection');
    }

    public function test_clearly_non_english_still_flagged_with_v1_threshold(): void
    {
        // A message the detector confidently identifies as French must still be flagged.
        $nonEnglishDetector = static fn(string $text) => ['lang' => 'fr', 'reliable' => true];
        $text = 'Hi, is the sofa still available? I can collect on Saturday morning if that works for you. Thanks.';
        $result = $this->service->checkLanguage('', $text, $nonEnglishDetector);
        $this->assertNotNull($result);
        $this->assertEquals(ContentCheckService::CHECK_LANGUAGE, $result['check']);
    }

    // =========================================================================
    // isUserModerated — needs DB. One national moderation state per user now
    // (self-moderating-community.md), not per membership.
    // =========================================================================

    public function test_is_user_moderated_null_status_is_moderated(): void
    {
        $user = $this->createTestUser(['postingstatus' => null]);
        $msg  = $this->createTestMessage($user);

        $this->assertTrue($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_explicit_moderated_status(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'MODERATED']);
        $msg  = $this->createTestMessage($user);

        $this->assertTrue($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_prohibited_status(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'PROHIBITED']);
        $msg  = $this->createTestMessage($user);

        $this->assertTrue($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_default_status_not_moderated(): void
    {
        // ENUM values are MODERATED / DEFAULT / PROHIBITED / UNMODERATED
        // (users.postingstatus, see 2026_09_20_000001_remove_group_model.php).
        // DEFAULT means standard posting → not moderated.
        $user = $this->createTestUser(['postingstatus' => 'DEFAULT']);
        $msg  = $this->createTestMessage($user);

        $this->assertFalse($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_unmoderated_status_not_moderated(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'UNMODERATED']);
        $msg  = $this->createTestMessage($user);

        $this->assertFalse($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_no_fromuser_is_moderated(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'DEFAULT']);
        $msg  = $this->createTestMessage($user);

        $this->assertTrue($this->service->isUserModerated($msg->id, 0));
    }

    public function test_is_user_moderated_case_insensitive_moderated(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'MODERATED']); // enum has no lowercase form any more
        $msg  = $this->createTestMessage($user);

        $this->assertTrue($this->service->isUserModerated($msg->id, $user->id));
    }

    public function test_is_user_moderated_looks_up_fromuser_from_message(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'DEFAULT']);
        $msg  = $this->createTestMessage($user);

        // Pass null for fromuser — should look it up from messages table
        $this->assertFalse($this->service->isUserModerated($msg->id, null));
    }

    // =========================================================================
    // checkKnownSpammer — needs DB
    // =========================================================================

    public function test_check_known_spammer_no_email_in_text_returns_null(): void
    {
        $result = $this->service->checkKnownSpammer('No email addresses here, just plain text.');
        $this->assertNull($result);
    }

    public function test_check_known_spammer_unknown_email_returns_null(): void
    {
        $result = $this->service->checkKnownSpammer('Contact legit@example.com for the sofa');
        $this->assertNull($result);
    }

    public function test_check_known_spammer_known_spammer_email_returns_flag(): void
    {
        $spammer = $this->createTestUser(['email_preferred' => 'spammer@evil.com']);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        // Insert the email directly — createTestUser already does this, but ensure preferred email matches
        DB::table('users_emails')
            ->where('userid', $spammer->id)
            ->update(['email' => 'spammer@evil.com']);

        $result = $this->service->checkKnownSpammer('Contact spammer@evil.com for this offer');
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_KNOWN_SPAMMER, $result['check']);
        $this->assertStringContainsString('spammer@evil.com', $result['detail']);
    }

    // =========================================================================
    // checkImageSpam — needs DB
    // =========================================================================

    public function test_check_image_spam_no_attachments_returns_null(): void
    {
        $user = $this->createTestUser();
        $msg  = $this->createTestMessage($user);
        // No attachments

        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNull($result);
    }

    public function test_check_image_spam_no_hash_on_attachment_returns_null(): void
    {
        $user = $this->createTestUser();
        $msg  = $this->createTestMessage($user);

        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id,
            'hash'  => null,
        ]);

        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNull($result);
    }

    public function test_check_image_spam_under_threshold_returns_null(): void
    {
        $user = $this->createTestUser();
        $msg  = $this->createTestMessage($user);

        $hash = 'testhash_' . uniqid();
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id,
            'hash'  => $hash,
        ]);

        // Only 1 occurrence (the message itself) — below threshold of 5
        DB::table('messages')->where('id', $msg->id)->update(['arrival' => now()->subHours(1)]);

        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNull($result);
    }

    public function test_check_image_spam_above_threshold_returns_flag(): void
    {
        // messages_attachments.hash is VARCHAR(16); cap to fit so the inserted
        // value round-trips and matches the message string verbatim.
        $hash = substr('spamhash_' . uniqid(), 0, 16);
        $user = $this->createTestUser();

        // Create 6 messages with the same image hash within 24h
        for ($i = 0; $i < 6; $i++) {
            $msg = $this->createTestMessage($user);
            DB::table('messages')->where('id', $msg->id)->update(['arrival' => now()->subHours(1)]);
            DB::table('messages_attachments')->insert([
                'msgid' => $msg->id,
                'hash'  => $hash,
            ]);
        }

        // Check the last message
        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_IMAGE_SPAM, $result['check']);
        $this->assertSame('flag', $result['action']);
        $this->assertStringContainsString($hash, $result['detail']);
    }

    public function test_check_image_spam_old_occurrences_not_counted(): void
    {
        $hash = 'oldhash_' . uniqid();
        $user = $this->createTestUser();

        // 4 messages with same hash but older than 24h
        for ($i = 0; $i < 4; $i++) {
            $msg = $this->createTestMessage($user);
            DB::table('messages')->where('id', $msg->id)->update(['arrival' => now()->subHours(30)]);
            DB::table('messages_attachments')->insert([
                'msgid' => $msg->id,
                'hash'  => $hash,
            ]);
        }

        // One recent message with same hash — total within 24h = 1, below threshold
        $recentMsg = $this->createTestMessage($user);
        DB::table('messages')->where('id', $recentMsg->id)->update(['arrival' => now()->subMinutes(5)]);
        DB::table('messages_attachments')->insert([
            'msgid' => $recentMsg->id,
            'hash'  => $hash,
        ]);

        $result = $this->service->checkImageSpam($recentMsg->id);
        $this->assertNull($result);
    }

    // =========================================================================
    // checkMoneySymbols — boundary: only in combined text
    // =========================================================================

    public function test_check_money_symbols_pound_unicode(): void
    {
        // Unicode pound sign
        $result = $this->service->checkMoneySymbols('', "Worth \xc2\xa3200");
        $this->assertNotNull($result);
    }


    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionClass($this->service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    #[DataProvider('damerauLevenshteinProvider')]
    public function test_damerau_levenshtein(string $a, string $b, int $expected): void
    {
        $dist = $this->callPrivate('damerauLevenshtein', $a, $b);
        $this->assertSame($expected, $dist);
    }

    public function test_inflection_variants_no_cvc_when_ends_vowel(): void
    {
        // "true" ends in vowel 'e' — no CVC doubling
        $variants = $this->callPrivate('inflectionVariants', 'true');
        $this->assertNotContains('trueed', $variants);
        $this->assertNotContains('trueing', $variants);
    }

    public function test_matches_fuzzy_exact_match(): void
    {
        // Tested indirectly via checkVagueItem or via reflection
        $result = $this->callPrivate('matchesFuzzy', 'free stuff', 'stuff');
        $this->assertTrue($result);
    }

    public function test_matches_fuzzy_plural_inflection(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'selling drugs', 'sell');
        $this->assertTrue($result);
    }

    public function test_matches_fuzzy_strips_edge_punctuation(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'free (stuff)', 'stuff');
        $this->assertTrue($result);
    }

    public function test_matches_fuzzy_no_match(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'clean offer sofa', 'heroin');
        $this->assertFalse($result);
    }

    public function test_matches_fuzzy_rejects_initial_consonant_swap(): void
    {
        // "hangers" vs "bangers" differ at position 0 — should NOT match
        $result = $this->callPrivate('matchesFuzzy', 'coat hangers', 'bangers');
        $this->assertFalse($result);
    }

    #[DataProvider('greetingSpamProvider')]
    public function test_check_greeting_spam(string $subject, string $body, bool $expectFlag): void
    {
        $result = $this->service->checkGreetingSpam($subject, $body);

        if ($expectFlag) {
            $this->assertNotNull($result);
            $this->assertSame(ContentCheckService::CHECK_GREETING_SPAM, $result['check']);
            $this->assertSame('flag', $result['action']);
        } else {
            $this->assertNull($result);
        }
    }

    public function test_matches_fuzzy_multiword_phrase_no_match_when_absent(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'free sofa in good condition', 'discounted price');
        $this->assertFalse($result);
    }

    public function test_check_greeting_spam_good_afternoon_with_link(): void
    {
        $result = $this->service->checkGreetingSpam('', 'Good afternoon, visit https://buy.com now');
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_GREETING_SPAM, $result['check']);
    }























}
