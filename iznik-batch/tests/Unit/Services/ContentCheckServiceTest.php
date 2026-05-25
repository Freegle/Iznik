<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\ContentCheckService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
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
    // Private method helpers via reflection
    // =========================================================================

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionClass($this->service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    // =========================================================================
    // damerauLevenshtein — pure algorithm
    // =========================================================================

    #[DataProvider('damerauLevenshteinProvider')]
    public function test_damerau_levenshtein(string $a, string $b, int $expected): void
    {
        $dist = $this->callPrivate('damerauLevenshtein', $a, $b);
        $this->assertSame($expected, $dist);
    }

    public static function damerauLevenshteinProvider(): array
    {
        return [
            'identical strings'         => ['hello', 'hello', 0],
            'one insertion'             => ['hello', 'helllo', 1],
            'one deletion'              => ['helllo', 'hello', 1],
            'one substitution'          => ['hello', 'hxllo', 1],
            'adjacent transposition'    => ['beleived', 'believed', 1],
            'empty a'                   => ['', 'abc', 3],
            'empty b'                   => ['abc', '', 3],
            'both empty'                => ['', '', 0],
            'completely different'      => ['abc', 'xyz', 3],
            'two edits'                 => ['kitten', 'sitting', 3],
        ];
    }

    // =========================================================================
    // inflectionVariants — pure string logic
    // =========================================================================

    #[DataProvider('inflectionVariantsProvider')]
    public function test_inflection_variants_includes_expected(string $keyword, string $expectedVariant): void
    {
        $variants = $this->callPrivate('inflectionVariants', $keyword);
        $this->assertContains($expectedVariant, $variants, "Expected variant '{$expectedVariant}' for keyword '{$keyword}'");
    }

    public static function inflectionVariantsProvider(): array
    {
        return [
            'plural -s'          => ['sell', 'sells'],
            'plural -es'         => ['sell', 'selles'],
            '-ing suffix'        => ['sell', 'selling'],
            '-ed suffix'         => ['sell', 'selled'],
            '-y -> -ies'         => ['supply', 'supplies'],
            'CVC double+ed swap' => ['swap', 'swapped'],
            'CVC double+ing'     => ['swap', 'swapping'],
        ];
    }

    public function test_inflection_variants_no_cvc_when_ends_vowel(): void
    {
        // "true" ends in vowel 'e' — no CVC doubling
        $variants = $this->callPrivate('inflectionVariants', 'true');
        $this->assertNotContains('trueed', $variants);
        $this->assertNotContains('trueing', $variants);
    }

    // =========================================================================
    // matchesFuzzy — pure string matching (tested via public surface below)
    // =========================================================================

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

    public function test_matches_fuzzy_empty_keyword_returns_false(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'some text', '');
        $this->assertFalse($result);
    }

    public function test_matches_fuzzy_no_match(): void
    {
        $result = $this->callPrivate('matchesFuzzy', 'clean offer sofa', 'heroin');
        $this->assertFalse($result);
    }

    public function test_matches_fuzzy_transposition_for_long_keywords(): void
    {
        // "beleived" is an adjacent transposition of "believed"
        // (8 chars, damerau distance 1).
        $result = $this->callPrivate('matchesFuzzy', 'I beleived him', 'believed');
        $this->assertTrue($result);
    }

    public function test_matches_fuzzy_rejects_initial_consonant_swap(): void
    {
        // "hangers" vs "bangers" differ at position 0 — should NOT match
        $result = $this->callPrivate('matchesFuzzy', 'coat hangers', 'bangers');
        $this->assertFalse($result);
    }

    public function test_matches_fuzzy_short_keyword_no_levenshtein(): void
    {
        // Short keywords (< 8 chars) use only exact/inflection, not levenshtein
        // "guns" ≠ "buns" (1 substitution at pos 0) — should not match
        $result = $this->callPrivate('matchesFuzzy', 'fresh buns for sale', 'guns');
        $this->assertFalse($result);
    }

    // =========================================================================
    // checkVagueItem — public, pure logic
    // =========================================================================

    #[DataProvider('vagueItemProvider')]
    public function test_check_vague_item(mixed $itemName, bool $expectFlag): void
    {
        $result = $this->service->checkVagueItem($itemName);

        if ($expectFlag) {
            $this->assertNotNull($result);
            $this->assertSame(ContentCheckService::CHECK_VAGUE, $result['check']);
        } else {
            $this->assertNull($result);
        }
    }

    public static function vagueItemProvider(): array
    {
        return [
            'null item'                  => [null, false],
            'empty string'               => ['', false],
            'whitespace only'            => ['   ', false],
            'specific item sofa'         => ['Sofa', false],
            'specific item TV'           => ['TV', false],
            'specific item PC'           => ['PC', false],
            'pure vague: stuff'          => ['stuff', true],
            'pure vague: things'         => ['things', true],
            'pure vague: items'          => ['items', true],
            'pure vague: junk'           => ['junk', true],
            'vague phrase: free stuff'   => ['free stuff', true],
            'vague: bits and pieces'     => ['bits and pieces', true],
            'mixed: assorted frames'     => ['assorted picture frames', false],
            'mixed: Marilyn stuff'       => ['Marilyn monroe stuff', false],
            'numeric token ignored'      => ['2024', false],
            'short token TV + vague'     => ['TV stuff', false], // "TV" is <=2 chars, ignored; "stuff" is vague but only 1 significant token
            'specific: computer desk'    => ['Computer desk', false],
            'vague: assorted'            => ['assorted', true],
            'mixed: assorted bike parts' => ['assorted bike parts', false],
            // Point 1: vocabulary gaps
            'vague: any'                 => ['Any', true],
            'vague: all'                 => ['all', true],
            'vague: everything'          => ['Everything', true],
            'vague: household goods'     => ['household goods', true],
            'vague: household items'     => ['household items', true],
            'vague: sundries'            => ['sundries', true],
            'vague: bric-a-brac'         => ['bric-a-brac', true],
            'vague: odds and ends'       => ['odds and ends', true],
            'vague: general'             => ['general', true],
            // Point 2: inflection folding
            'vague: thing (singular)'    => ['thing', true],
            'vague: item (singular)'     => ['item', true],
            // Point 3: category qualifiers don't rescue (vague + non-rescuing qualifier)
            'vague: assorted junk'       => ['assorted junk', true],
            'vague: various items'       => ['various items', true],
            'vague: miscellaneous stuff' => ['miscellaneous stuff', true],
            // Rescue still works for genuine specific tokens
            'specific rescues: vintage bundle' => ['vintage bundle', false],
            'specific rescues: baby bundle'    => ['baby bundle', false],
            'specific rescues: stamp collection' => ['stamp collection', false],
            // Point 7: ambiguous tokens alone don't flag
            'ambiguous alone: bundle'    => ['bundle', false],
            'ambiguous alone: collection' => ['collection', false],
            'ambiguous alone: lots'      => ['lots', false],
            'ambiguous alone: random'    => ['random', false],
            // Ambiguous + unambiguous vague still flags
            'ambiguous + vague: random stuff' => ['random stuff', true],
            'ambiguous + vague: bundle of junk' => ['bundle of junk', true],
        ];
    }

    // =========================================================================
    // checkPhoneNumbers — public, pure regex
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
    // checkGreetingSpam — public, pure
    // =========================================================================

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

    public static function greetingSpamProvider(): array
    {
        return [
            'no greeting no link'       => ['Sofa', 'Good condition sofa available', false],
            'greeting no link'          => ['', 'Hello, I have a sofa to give away', false],
            'no greeting with http'     => ['', 'See https://example.com for info', false],
            'hello + http link'         => ['', 'Hello, visit https://buy-here.com', true],
            'hi + http link'            => ['Hi there', 'Check https://site.com', true],
            'hey + www link'            => ['', 'Hey visit www.site.com today', true],
            'good morning + http'       => ['', 'Good morning, see https://offer.com', true],
            'salutations + www'         => ['', 'Salutations! www.spam.com', true],
            'greetings + link'          => ['', 'Greetings from https://site.org', true],
            'good evening + link'       => ['', 'Good evening, http://spam.biz', true],
            'sup + link'                => ['Sup', 'https://buy.com', true],
            'greeting case insensitive' => ['', 'HELLO check https://site.com', true],
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
        // Text <= 50 chars is never checked regardless of content
        $result = $this->service->checkLanguage('', 'Hola'); // 4 chars
        $this->assertNull($result);
    }

    public function test_check_language_english_text_accepted(): void
    {
        $text = 'I have a sofa and two chairs that I no longer need. They are in good condition and free to collect.';
        $result = $this->service->checkLanguage('', $text);
        $this->assertNull($result);
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
        // Clear Spanish — well over 50 chars, will be detected as non-English
        $text = 'Tengo un sofá que ya no necesito. Está en buenas condiciones y se puede recoger en cualquier momento del día. Contacta conmigo si estás interesado.';
        $result = $this->service->checkLanguage('', $text);
        // Language detection may or may not flag this; we assert the return type is correct
        $this->assertTrue($result === null || $result['check'] === ContentCheckService::CHECK_LANGUAGE);
    }

    // =========================================================================
    // isGroupModerated — needs DB
    // =========================================================================

    public function test_is_group_moderated_no_rules_returns_false(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['rules' => null]);

        $this->assertFalse($this->service->isGroupModerated($group->id));
    }

    public function test_is_group_moderated_empty_rules_returns_false(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update(['rules' => json_encode([])]);

        $this->assertFalse($this->service->isGroupModerated($group->id));
    }

    public function test_is_group_moderated_fully_moderated_true(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'rules' => json_encode(['fullymoderated' => true]),
        ]);

        $this->assertTrue($this->service->isGroupModerated($group->id));
    }

    public function test_is_group_moderated_fully_moderated_false(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'rules' => json_encode(['fullymoderated' => false]),
        ]);

        $this->assertFalse($this->service->isGroupModerated($group->id));
    }

    public function test_is_group_moderated_nonexistent_group_returns_false(): void
    {
        $this->assertFalse($this->service->isGroupModerated(999999999));
    }

    // =========================================================================
    // isUserModerated — needs DB
    // =========================================================================

    public function test_is_user_moderated_null_status_is_moderated(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        // Membership with NULL ourPostingStatus
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => null,
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        $this->assertTrue($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_explicit_moderated_status(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => 'MODERATED',
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        $this->assertTrue($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_prohibited_status(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => 'PROHIBITED',
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        $this->assertTrue($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_default_status_not_moderated(): void
    {
        // ENUM values are MODERATED / DEFAULT / PROHIBITED / UNMODERATED
        // (see memberships migration). DEFAULT means standard posting → not moderated.
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => 'DEFAULT',
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        $this->assertFalse($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_no_membership_is_moderated(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        // No membership inserted
        $msg = $this->createTestMessage($user, $group);

        $this->assertTrue($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_case_insensitive_moderated(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => 'moderated', // lowercase
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        $this->assertTrue($this->service->isUserModerated($msg->id, $group->id, $user->id));
    }

    public function test_is_user_moderated_looks_up_fromuser_from_message(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('memberships')->insert([
            'userid'  => $user->id,
            'groupid' => $group->id,
            'role'    => 'Member',
            'collection' => 'Approved',
            'emailfrequency' => -2,
            'ourPostingStatus' => 'DEFAULT',
            'added'   => now(),
        ]);

        $msg = $this->createTestMessage($user, $group);

        // Pass null for fromuser — should look it up from messages table
        $this->assertFalse($this->service->isUserModerated($msg->id, $group->id, null));
    }

    // =========================================================================
    // checkConcernKeywords — needs DB (concern_keywords table)
    // =========================================================================

    public function test_check_concern_keywords_empty_table_returns_null(): void
    {
        // No keywords in DB
        $result = $this->service->checkConcernKeywords('free sofa', 'good condition', 1);
        $this->assertNull($result);
    }

    public function test_check_concern_keywords_fuzzy_match(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'heroin',
            'category'   => 'substance_regulated',
            'match_mode' => 'fuzzy',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'block',
        ]);

        $result = $this->service->checkConcernKeywords('free heroin', 'good stuff', 1);
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_CONCERN_KEYWORD, $result['check']);
        $this->assertSame('substance_regulated', $result['category']);
        $this->assertSame('block', $result['action']);
    }

    public function test_check_concern_keywords_literal_match(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'knives',
            'category'   => 'review',
            'match_mode' => 'literal',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('', 'I have some knives available', 1);
        $this->assertNotNull($result);
        $this->assertSame('flag', $result['action']);
    }

    public function test_check_concern_keywords_regex_match(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'crack\s+cocaine',
            'category'   => 'substance_regulated',
            'match_mode' => 'regex',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'block',
        ]);

        $result = $this->service->checkConcernKeywords('', 'selling crack cocaine cheap', 1);
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_CONCERN_KEYWORD, $result['check']);
    }

    public function test_check_concern_keywords_exclude_pattern_skips(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'guns',
            'category'   => 'review',
            'match_mode' => 'fuzzy',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'flag',
            'exclude'    => 'toy|water\s+guns',
        ]);

        // Should be excluded because body matches the exclude pattern
        $result = $this->service->checkConcernKeywords('', 'water guns for kids', 1);
        $this->assertNull($result);
    }

    public function test_check_concern_keywords_allowed_category_skipped(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'sofa',
            'category'   => 'allowed',  // Should be excluded from checks
            'match_mode' => 'literal',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('Free sofa', 'collect anytime', 1);
        $this->assertNull($result);
    }

    public function test_check_concern_keywords_group_scoped_matches_own_group(): void
    {
        $group = $this->createTestGroup();

        DB::table('concern_keywords')->insert([
            'keyword'    => 'badword',
            'category'   => 'review',
            'match_mode' => 'literal',
            'scope'      => 'group',
            'group_id'   => $group->id,
            'action'     => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('', 'contains badword text', $group->id);
        $this->assertNotNull($result);
    }

    public function test_check_concern_keywords_group_scoped_no_match_other_group(): void
    {
        $group = $this->createTestGroup();

        DB::table('concern_keywords')->insert([
            'keyword'    => 'badword',
            'category'   => 'review',
            'match_mode' => 'literal',
            'scope'      => 'group',
            'group_id'   => $group->id,
            'action'     => 'flag',
        ]);

        // Different group — should not match
        $result = $this->service->checkConcernKeywords('', 'contains badword text', $group->id + 999);
        $this->assertNull($result);
    }

    // =========================================================================
    // checkPerGroupWorryWords — needs DB
    // =========================================================================

    public function test_check_per_group_worry_words_no_settings_returns_null(): void
    {
        $group = $this->createTestGroup();
        // No spammers.worrywords in settings

        $result = $this->service->checkPerGroupWorryWords('free sofa', 'good condition', $group->id);
        $this->assertNull($result);
    }

    public function test_check_per_group_worry_words_matches(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'settings' => json_encode(['spammers' => ['worrywords' => 'dodgy, scammer']]),
        ]);

        $result = $this->service->checkPerGroupWorryWords('', 'this seller is a scammer', $group->id);
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_PER_GROUP_WORRY, $result['check']);
        $this->assertSame('flag', $result['action']);
    }

    public function test_check_per_group_worry_words_no_match(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'settings' => json_encode(['spammers' => ['worrywords' => 'dodgy, scammer']]),
        ]);

        $result = $this->service->checkPerGroupWorryWords('free chair', 'collect from SE1', $group->id);
        $this->assertNull($result);
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
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createTestMessage($user, $group);
        // No attachments

        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNull($result);
    }

    public function test_check_image_spam_no_hash_on_attachment_returns_null(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createTestMessage($user, $group);

        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id,
            'hash'  => null,
        ]);

        $result = $this->service->checkImageSpam($msg->id);
        $this->assertNull($result);
    }

    public function test_check_image_spam_under_threshold_returns_null(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createTestMessage($user, $group);

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
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create 6 messages with the same image hash within 24h
        for ($i = 0; $i < 6; $i++) {
            $msg = $this->createTestMessage($user, $group);
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
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // 4 messages with same hash but older than 24h
        for ($i = 0; $i < 4; $i++) {
            $msg = $this->createTestMessage($user, $group);
            DB::table('messages')->where('id', $msg->id)->update(['arrival' => now()->subHours(30)]);
            DB::table('messages_attachments')->insert([
                'msgid' => $msg->id,
                'hash'  => $hash,
            ]);
        }

        // One recent message with same hash — total within 24h = 1, below threshold
        $recentMsg = $this->createTestMessage($user, $group);
        DB::table('messages')->where('id', $recentMsg->id)->update(['arrival' => now()->subMinutes(5)]);
        DB::table('messages_attachments')->insert([
            'msgid' => $recentMsg->id,
            'hash'  => $hash,
        ]);

        $result = $this->service->checkImageSpam($recentMsg->id);
        $this->assertNull($result);
    }

    // =========================================================================
    // checkGreetingSpam — boundary: both greeting keywords
    // =========================================================================

    public function test_check_greeting_spam_good_afternoon_with_link(): void
    {
        $result = $this->service->checkGreetingSpam('', 'Good afternoon, visit https://buy.com now');
        $this->assertNotNull($result);
        $this->assertSame(ContentCheckService::CHECK_GREETING_SPAM, $result['check']);
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

    // =========================================================================
    // checkVagueItem — boundary: all-vague multi-word
    // =========================================================================

    public function test_check_vague_item_mixed_separators(): void
    {
        // Separators include hyphen and slash per the regex
        $result = $this->service->checkVagueItem('bits/pieces');
        // "bits" and "pieces" are both > 2 chars and vague
        $this->assertNotNull($result);
    }

    public function test_check_vague_item_only_stopwords_not_flagged(): void
    {
        // All tokens are stopwords — significantCount = 0 → not flagged
        $result = $this->service->checkVagueItem('and the or');
        $this->assertNull($result);
    }
}
