<?php

namespace Tests\Feature\Message;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\ContentCheckService;
use App\Services\ContentEmbeddingService;
use App\Services\Judgement\FakeJudge;
use App\Services\Judgement\Judge;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentCheckTest extends TestCase
{
    private ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Every test in this file that touches the judge must use the fake (ai-judgement.md:
        // "Every test that touches the judge uses the fake; nothing in the suite calls the
        // network"). Bind a default here so ANY ContentCheckService built in this file -
        // including the bare `new ContentCheckService(...)` construction sites below that
        // don't pass judge: explicitly - resolves a safe, all-clean FakeJudge instead of the
        // real, network-calling ClaudeJudge that AppServiceProvider binds by default. A
        // bare FakeJudge answers every question "no" at high confidence, so it never trips
        // any of this file's existing deterministic-check assertions. Tests that need
        // specific judge behaviour still construct their own `new FakeJudge()` and pass it
        // via `new ContentCheckService(judge: $fake)`, which takes precedence over this
        // container binding.
        $this->app->singleton(Judge::class, fn () => new FakeJudge());
        $this->service = new ContentCheckService();
        // Mark any unprocessed messages so processUnprocessed() only sees rows
        // inserted within this test. Covers both Pending candidates and the
        // recently-arrived Approved candidates the service now also checks.
        // contentcheck_checked_at lives on messages directly (one state per post -
        // self-moderating-community.md); messages_groups no longer exists.
        DB::table('messages')
            ->whereNull('contentcheck_checked_at')
            ->update(['contentcheck_checked_at' => now()]);
        // Same for rows edited since their check (editedat > checked stamp): re-stamp
        // so they stop being candidates too.
        DB::table('messages')
            ->whereColumn('editedat', '>', 'contentcheck_checked_at')
            ->update(['contentcheck_checked_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // checkConcernKeywords — unified keyword check (replaces worrywords + spam_keywords)
    // -------------------------------------------------------------------------

    public function test_concern_keyword_match_returns_reason(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'  => 'testconcernkw_cc',
            'category' => 'scam',
            'action'   => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testconcernkw_cc item', 'Some text', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('ConcernKeyword', $result['check']);
        $this->assertEquals('scam', $result['category']);
        $this->assertStringContainsString('testconcernkw_cc', $result['detail']);
    }

    public function test_concern_keyword_returns_category_in_result(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'  => 'testmedicine_cc',
            'category' => 'substance_medicine',
            'action'   => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testmedicine_cc tablets', '', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('substance_medicine', $result['category']);
    }

    public function test_clean_text_returns_null_for_concern_keywords(): void
    {
        $group = $this->createTestGroup();

        $result = $this->service->checkConcernKeywords('OFFER: Nice lamp', 'A lovely lamp', $group->id);

        $this->assertNull($result);
    }

    public function test_blank_concern_keyword_does_not_match_all_messages(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert(['keyword' => '', 'category' => 'review', 'action' => 'flag']);

        $result = $this->service->checkConcernKeywords('OFFER: Nice lamp', 'A lovely lamp', $group->id);

        $this->assertNull($result);
    }

    public function test_concern_keyword_literal_match_uses_word_boundary(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'knife',
            'category'   => 'substance_reportable',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        // 'penknife' should NOT match the word-boundary literal check for 'knife'.
        $noMatch = $this->service->checkConcernKeywords('OFFER: penknife', '', $group->id);
        $this->assertNull($noMatch);

        // 'knife' as a standalone word should match.
        $match = $this->service->checkConcernKeywords('OFFER: knife', '', $group->id);
        $this->assertNotNull($match);
    }

    public function test_concern_keyword_regex_match(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'diazep[ai]m',
            'category'   => 'substance_medicine',
            'action'     => 'flag',
            'match_mode' => 'regex',
        ]);

        $match = $this->service->checkConcernKeywords('OFFER: diazepam tablets', '', $group->id);
        $this->assertNotNull($match);
    }

    public function test_concern_keyword_exclude_pattern_suppresses_match(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'gun',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'literal',
            'exclude'    => 'water gun|toy gun',
        ]);

        $noMatch = $this->service->checkConcernKeywords('OFFER: water gun', '', $group->id);
        $this->assertNull($noMatch);

        $match = $this->service->checkConcernKeywords('OFFER: gun (real)', '', $group->id);
        $this->assertNotNull($match);
    }

    /**
     * 'allowed'-category entries are a whitelist: text matching them must be
     * removed before the flagging keywords are scanned, exactly as V1's worry
     * words and the Go display path do. Discourse 9944: 'Cashes Green' (a
     * Stroud place name) was whitelisted, but posts and chats mentioning it
     * kept being flagged because the fuzzy keyword 'cash' inflects to
     * 'cashes' and the whitelist entry was never applied.
     */
    public function test_allowed_keyword_whitelists_phrase_from_fuzzy_match(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'cash',
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'    => 'Cashes Green',
            'category'   => 'allowed',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        // Diz's live example from Discourse 9944 post 9.
        $result = $this->service->checkConcernKeywords('OFFER: Basin & Tap (Cashes Green GL6)', 'GL6 6EY', $group->id);

        $this->assertNull($result);
    }

    public function test_fuzzy_match_still_fires_outside_allowed_phrase(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'cash',
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'    => 'Cashes Green',
            'category'   => 'allowed',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        // 'cashes' NOT followed by 'Green' is outside the whitelisted phrase
        // and must still flag.
        $result = $this->service->checkConcernKeywords('OFFER: lamp', 'cashes accepted', $group->id);

        $this->assertNotNull($result);
        $this->assertStringContainsString('cash', $result['detail']);
    }

    public function test_chat_message_respects_allowed_keyword(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'    => 'cash',
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'    => 'Cashes Green',
            'category'   => 'allowed',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        // Neville's live example from Discourse 9944 post 10: a chat message
        // giving an address in Cashes Green, repeatedly held in Chat review.
        $result = $this->service->checkChatMessage("It's 29 elm rd cashes green gl5 4nu, I'm in all morning");

        $this->assertNull($result);
    }

    public function test_offer_with_no_location_is_not_auto_promoted(): void
    {
        // An Offer/Wanted we couldn't locate (NULL lat) must NOT be auto-promoted -
        // it would go live undiscoverable. It stays Pending for a moderator to add a
        // postcode via the "add a postcode" prompt (Discourse #9865). An otherwise
        // identical located post from the same unmoderated poster is auto-promoted.
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $noLocId = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Dining Table (unlocatable)',
            'textbody' => 'A dining table. Collection only.',
            'message'  => 'A dining table. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
            'lat'      => null,
            'lng'      => null,
        ]);
        $locId = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Bookshelf (SW1A)',
            'textbody' => 'A bookshelf. Collection only.',
            'message'  => 'A bookshelf. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
            'lat'      => 51.50,
            'lng'      => -0.13,
        ]);
        foreach ([$noLocId, $locId] as $mid) {
            DB::table('messages_groups')->insert([
                'msgid'      => $mid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => now(),
                'deleted'    => 0,
            ]);
        }

        $this->service->processUnprocessed();

        $noLocColl = DB::table('messages_groups')->where('msgid', $noLocId)->value('collection');
        $locColl   = DB::table('messages_groups')->where('msgid', $locId)->value('collection');

        $this->assertSame('Pending', $noLocColl, 'no-location Offer is kept Pending for a mod');
        $this->assertSame('Approved', $locColl, 'located Offer is auto-promoted as normal');
    }

    public function test_allowed_category_keywords_are_not_flagged(): void
    {
        // 'allowed' is a category (whitelist) in concern_keywords, not an action.
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'  => 'testallowed_cc',
            'category' => 'allowed',
            'action'   => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testallowed_cc item', '', $group->id);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkConcernKeywords — fuzzy match_mode (levenshtein, V1 parity)
    // -------------------------------------------------------------------------

    public function test_fuzzy_match_catches_plural(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'testfuzzy_cc',
            'category'   => 'substance_medicine',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        $exact = $this->service->checkConcernKeywords('OFFER: testfuzzy_cc tablets', '', $group->id);
        $this->assertNotNull($exact, 'exact keyword must match');

        // plural adds one character → levenshtein distance 1 ≤ 1
        $plural = $this->service->checkConcernKeywords('OFFER: testfuzzy_ccs for sale', '', $group->id);
        $this->assertNotNull($plural, 'plural form must match via fuzzy');
    }

    public function test_fuzzy_match_catches_single_char_typo(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'testfuzzy2_cc',
            'category'   => 'substance_medicine',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        // one substitution → levenshtein distance 1 ≤ 1
        $typo = $this->service->checkConcernKeywords('OFFER: testfuzzy2_cd items', '', $group->id);
        $this->assertNotNull($typo, 'single-char typo must match via fuzzy');
    }

    public function test_fuzzy_match_catches_transposition(): void
    {
        // Damerau-Levenshtein: adjacent-char transpositions count as distance 1.
        // 8-char keyword keeps it in the fuzzy-typo branch (< 8 chars only get
        // exact + inflections to avoid false positives like formic/formica).
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'nicotine',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        $transposed = $this->service->checkConcernKeywords('OFFER: nictoine for sale', '', $group->id);
        $this->assertNotNull($transposed, 'adjacent-char transposition must match via Damerau-Levenshtein');
    }

    public function test_fuzzy_match_rejects_six_and_seven_char_neighbours(): void
    {
        // Regression: 'formica' (laminate brand) wrongly matched 'formic' acid
        // via levenshtein-1, blocking a benign WANTED post about furniture
        // restoration. Now keywords < 8 chars only accept exact + inflections.
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insertOrIgnore([
            ['keyword' => 'formic',  'category' => 'substance_reportable', 'action' => 'block', 'match_mode' => 'fuzzy'],
            ['keyword' => 'rocket',  'category' => 'review',               'action' => 'flag',  'match_mode' => 'fuzzy'],
            ['keyword' => 'selling', 'category' => 'review',               'action' => 'flag',  'match_mode' => 'fuzzy'],
            ['keyword' => 'bangers', 'category' => 'review',               'action' => 'flag',  'match_mode' => 'fuzzy'],
        ]);

        $cases = [
            'formica laminate'   => 'WANTED: Imperial 1/2 dowelling for a Formica topped gate-leg table',
            'socket (vs rocket)' => 'OFFER: Spare socket set for car repairs',
            'telling (vs selling)' => 'OFFER: A book about telling stories to kids',
            'hangers (vs bangers)' => 'OFFER: Wooden coat hangers',
        ];

        foreach ($cases as $label => $subject) {
            $result = $this->service->checkConcernKeywords($subject, '', $group->id);
            $this->assertNull($result, "'{$label}' must not match a 6/7-char fuzzy concern keyword");
        }
    }

    public function test_fuzzy_match_rejects_substring_of_much_longer_word(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'testhash_cc',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        // length ratio (testhash_ccextended / testhash_cc) = 22/11 = 2.0 > 1.25 → no match
        $noMatch = $this->service->checkConcernKeywords('OFFER: testhash_ccextended', '', $group->id);
        $this->assertNull($noMatch, 'compound word much longer than keyword must not match');
    }

    public function test_fuzzy_match_rejects_completely_different_word(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'testfuzzy3_cc',
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        $noMatch = $this->service->checkConcernKeywords('OFFER: completely different text', '', $group->id);
        $this->assertNull($noMatch, 'unrelated word must not match');
    }

    /**
     * @dataProvider shortFuzzyFalsePositiveProvider
     */
    public function test_fuzzy_match_rejects_short_keyword_neighbours(string $keyword, string $body): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => $keyword,
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: item', $body, $group->id);
        $this->assertNull($result, "short keyword '{$keyword}' must not match unrelated 1-edit neighbour in body: {$body}");
    }

    public static function shortFuzzyFalsePositiveProvider(): array
    {
        return [
            'poof vs roof'   => ['poof', 'waterproof pvc roof with poles'],
            'poof vs proof'  => ['poof', 'rainproof marquee'],
            'lend vs led'    => ['lend', 'hp 22" ips backlit led monitor'],
            'cash vs case'   => ['cash', 'sewing machine in a case'],
            'pay vs pat'     => ['pay',  'has not been pat tested'],
            'swap vs snap'   => ['swap', 'one snap-on cover'],
            'sell vs sill'   => ['sell', 'window sill needs repainting'],
            'dollar vs lone' => ['$',    'reposting due to no collection - general wear'],
            'dollar vs a'    => ['$',    'as seen, fair chance of working'],
        ];
    }

    /**
     * @dataProvider shortFuzzyInflectionProvider
     */
    public function test_fuzzy_match_still_catches_inflections_for_short_keywords(string $keyword, string $body): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => $keyword,
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'fuzzy',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: item', $body, $group->id);
        $this->assertNotNull($result, "short keyword '{$keyword}' must match inflection in body: {$body}");
    }

    public static function shortFuzzyInflectionProvider(): array
    {
        return [
            'lend plural'       => ['lend', 'who lends tools around here'],
            'cash plural'       => ['cash', 'only cashes accepted'],
            'pay -ing'          => ['pay',  'I am paying for shipping'],
            'swap exact'        => ['swap', 'happy to swap items'],
            'swap -ed'          => ['swap', 'have already swapped these'],
            'punctuation strip' => ['cash', 'only (cash), please'],
        ];
    }

    // -------------------------------------------------------------------------
    // checkVagueItem
    // -------------------------------------------------------------------------

    public function test_vague_item_name_returns_reason(): void
    {
        $result = $this->service->checkVagueItem('stuff');

        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_vague_item_name_case_insensitive(): void
    {
        $result = $this->service->checkVagueItem('STUFF');

        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_short_item_name_passes(): void
    {
        // Short acronym item names like "TV", "PC", "ab" have no significant tokens and pass.
        $this->assertNull($this->service->checkVagueItem('TV'));
        $this->assertNull($this->service->checkVagueItem('PC'));
        $this->assertNull($this->service->checkVagueItem('ab'));
    }

    public function test_specific_item_name_returns_null(): void
    {
        $result = $this->service->checkVagueItem('Oak dining table with four chairs');

        $this->assertNull($result);
    }

    public function test_null_or_empty_item_name_returns_null(): void
    {
        $this->assertNull($this->service->checkVagueItem(null));
        $this->assertNull($this->service->checkVagueItem(''));
        $this->assertNull($this->service->checkVagueItem('   '));
    }

    /**
     * @dataProvider vagueItemFalsePositiveProvider
     */
    public function test_specific_noun_rescues_a_vague_modifier(string $itemName): void
    {
        $this->assertNull(
            $this->service->checkVagueItem($itemName),
            "name '{$itemName}' has a specific noun and must not flag as vague",
        );
    }

    public static function vagueItemFalsePositiveProvider(): array
    {
        return [
            'assorted + specific'        => ['Assorted picture frames'],
            'various + specific'         => ['Various small kitchen items'],
            'bundle + specific'          => ['Girls clothes bundle'],
            'collection + specific'      => ['Pending collection -Camping chair'],
            'mid + specific'             => ['Marilyn monroe stuff'],
            'trailing-comma + specific'  => ['Mugs, various'],
            'embedded "stuff"'           => ['stuffed bear toy'],
            // "yes"/"ok" etc as one word among real nouns must NOT flag.
            'yes + specific noun'        => ['Yes to Life recovery book'],
            'ok describing condition'    => ['Sofa in ok condition'],
        ];
    }

    /**
     * @dataProvider vagueItemTruePositiveProvider
     */
    public function test_genuinely_vague_names_are_flagged(string $itemName): void
    {
        $result = $this->service->checkVagueItem($itemName);
        $this->assertNotNull($result, "name '{$itemName}' must flag as vague");
        $this->assertEquals('Vague', $result['check']);
    }

    public static function vagueItemTruePositiveProvider(): array
    {
        return [
            'single vague word'          => ['stuff'],
            'two vague words'            => ['various items'],
            'vague phrase'               => ['bits and pieces'],
            'free stuff phrase'          => ['free stuff'],
            'numbers + only vague'       => ['4 assorted things'],
            // Content-free responses/fillers left in the item box.
            'affirmation yes'            => ['Yes'],
            'affirmation no'             => ['No'],
            'filler please'              => ['Please'],
            'filler thanks'              => ['Thanks'],
        ];
    }

    // -------------------------------------------------------------------------
    // checkPhoneNumbers — national, unconditional (restrictpersonalinfo froze to
    // "restrict", see ContentCheckService::checkPhoneNumbers() comment)
    // -------------------------------------------------------------------------

    public function test_phone_number_flagged_when_restricted_nationally(): void
    {
        $result = $this->service->checkPhoneNumbers('OFFER: Sofa', 'Call me on 07700 900123');

        $this->assertNotNull($result, 'Phone number should be flagged when restrictpersonalinfo is set');
        $this->assertEquals('PhoneNumber', $result['check']);
    }

    // -------------------------------------------------------------------------
    // checkPII — email addresses, national and unconditional (same freeze)
    // -------------------------------------------------------------------------

    public function test_no_personal_info_in_body_returns_null(): void
    {
        $result = $this->service->checkPII('OFFER: Sofa', 'Collection only please');

        $this->assertNull($result);
    }

    public function test_external_email_in_body_returns_reason(): void
    {
        $result = $this->service->checkPII('OFFER: Sofa', 'Email john@example.com for details');

        $this->assertNotNull($result);
        $this->assertEquals('EmailAddress', $result['check']);
    }

    public function test_freegle_email_not_flagged(): void
    {
        $result = $this->service->checkPII('OFFER: Sofa', 'Reply via noreply@ilovefreegle.org');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkMessagingLinks
    // -------------------------------------------------------------------------

    public function test_whatsapp_invite_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Join our group: https://chat.whatsapp.com/abc123');

        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
        $this->assertStringContainsString('chat.whatsapp.com', $result['detail']);
    }

    public function test_telegram_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Contact me at https://t.me/mygroup');

        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_discord_invite_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Join https://discord.gg/xyz');

        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_signal_group_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'https://signal.group/abc');

        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_wa_me_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Message me: https://wa.me/447700900123');

        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_clean_body_returns_null_for_messaging_links(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Collection from SW1A 1AA please');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkMessage (integration: all checks together)
    // -------------------------------------------------------------------------

    public function test_check_message_returns_all_failures(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);
        $user  = $this->createTestUser();
        DB::table('concern_keywords')->insert([
            'keyword'  => 'worrycheck_cc',
            'category' => 'review',
            'action'   => 'flag',
        ]);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: stuff (Location) - worrycheck_cc',
            'textbody' => 'Call on 07700 900456',
            'message'  => 'Call on 07700 900456',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'stuff']);
        $stuffId = DB::table('items')->where('name', 'stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $stuffId]);

        $reasons = $this->service->checkMessage($msgid, $group->id);

        $checkNames = array_column($reasons, 'check');
        $this->assertContains('Vague', $checkNames);
        $this->assertContains('PhoneNumber', $checkNames);
        $this->assertContains('ConcernKeyword', $checkNames);
    }

    public function test_check_message_returns_empty_for_clean_message(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Oak dining table (SW1A)',
            'textbody' => 'A solid oak dining table in great condition. Collection only.',
            'message'  => 'A solid oak dining table in great condition. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Oak dining table']);
        $itemId = DB::table('items')->where('name', 'Oak dining table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $reasons = $this->service->checkMessage($msgid, $group->id);

        $this->assertEmpty($reasons);
    }

    // -------------------------------------------------------------------------
    // Reason keyword field + de-duplication across concern keywords and the
    // legacy per-group worry words (which overlap: a per-group worry word that
    // has also been migrated into concern_keywords would otherwise be flagged
    // twice — once as ConcernKeyword and once as PerGroupWorryWord).
    // -------------------------------------------------------------------------

    public function test_concern_keyword_reason_includes_matched_keyword(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'  => 'testkwfield_cc',
            'category' => 'review',
            'action'   => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testkwfield_cc item', '', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('testkwfield_cc', $result['keyword']);
    }

    // -------------------------------------------------------------------------
    // processUnprocessed — promotion and notification logic
    // -------------------------------------------------------------------------

    public function test_clean_unmoderated_message_is_promoted_to_approved(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message'  => 'Beautiful table. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
            'lat'      => 51.50,
            'lng'      => -0.13,
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
            // contentcheck_checked_at NULL — unprocessed
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['approved']);

        $collection = DB::table('messages_groups')->where('msgid', $msgid)->value('collection');
        $this->assertEquals('Approved', $collection);

        $checkedAt = DB::table('messages_groups')->where('msgid', $msgid)->value('contentcheck_checked_at');
        $this->assertNotNull($checkedAt);
    }

    /**
     * Editing a message that has already been checked stamps messages.editedat rather
     * than clearing the check stamp, because the stamp is what keeps a Pending post
     * visible to moderators (Discourse 10001). A row whose editedat is newer than its
     * check must be picked up here, and re-stamping on completion must resolve the
     * comparison - otherwise every edit would be re-checked forever.
     */
    public function test_edited_message_marked_for_recheck_is_checked_again(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message'  => 'Beautiful table. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
            'lat'      => 51.50,
            'lng'      => -0.13,
            'editedat' => now(),
            'editedby' => $user->id,
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        // Checked once, flagged, then edited: stamp intact, editedat newer - exactly
        // what PATCH /message leaves behind.
        DB::table('messages_groups')->insert([
            'msgid'                   => $msgid,
            'groupid'                 => $group->id,
            'collection'              => 'Pending',
            'arrival'                 => now(),
            'deleted'                 => 0,
            'contentcheck_checked_at' => now()->subMinutes(5),
            'contentcheck_reasons'    => json_encode(['stale reason from before the edit']),
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['approved'], 'A row edited since its check must be re-checked');

        $row = DB::table('messages_groups')->where('msgid', $msgid)->first();
        $this->assertEquals('Approved', $row->collection);
        $editedat = DB::table('messages')->where('id', $msgid)->value('editedat');
        $this->assertFalse(
            $editedat > $row->contentcheck_checked_at,
            'Re-stamping the check must resolve the editedat comparison, or every edit re-checks forever'
        );
        $this->assertNull($row->contentcheck_reasons, 'The stale pre-edit reason must be replaced');
    }

    public function test_promoted_message_with_location_is_added_to_spatial_index(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message'  => 'Beautiful table. Collection only.',
            'lat'      => 51.5,
            'lng'      => -0.12,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        // Pending → must not be in the spatial index (it backs the public browse).
        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $msgid)->count());

        $stats = $this->service->processUnprocessed();
        $this->assertEquals(1, $stats['approved']);
        $this->assertEquals('Approved', DB::table('messages_groups')->where('msgid', $msgid)->value('collection'));

        // Approved → now in the spatial index.
        $this->assertEquals(1, DB::table('messages_spatial')->where('msgid', $msgid)->count());

        DB::table('messages_spatial')->where('msgid', $msgid)->delete();
    }

    public function test_kept_pending_message_is_not_added_to_spatial_index(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        // NULL ourPostingStatus = MODERATED → message is kept Pending even when clean.
        $this->createMembership($user, $group);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message'  => 'Beautiful table. Collection only.',
            'lat'      => 51.5,
            'lng'      => -0.12,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $stats = $this->service->processUnprocessed();
        $this->assertEquals(1, $stats['kept_pending']);
        $this->assertEquals('Pending', DB::table('messages_groups')->where('msgid', $msgid)->value('collection'));

        // Still Pending → must not be in the spatial index.
        $this->assertEquals(0, DB::table('messages_spatial')->where('msgid', $msgid)->count());
    }

    public function test_moderated_user_message_stays_pending_with_checked_at_set(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        // NULL ourPostingStatus = MODERATED (default for new users).
        $this->createMembership($user, $group);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Solid oak table (SW1A)',
            'textbody' => 'Beautiful table. Collection only.',
            'message'  => 'Beautiful table. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['kept_pending']);

        $collection = DB::table('messages_groups')->where('msgid', $msgid)->value('collection');
        $this->assertEquals('Pending', $collection);

        $checkedAt = DB::table('messages_groups')->where('msgid', $msgid)->value('contentcheck_checked_at');
        $this->assertNotNull($checkedAt, 'contentcheck_checked_at must be set even when kept pending');
    }

    public function test_message_with_check_failure_stays_pending_with_reasons(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: stuff (SW1A)',
            'textbody' => 'Call 07700 900999',
            'message'  => 'Call 07700 900999',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'stuff']);
        $itemId = DB::table('items')->where('name', 'stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['kept_pending']);

        $row = DB::table('messages_groups')->where('msgid', $msgid)->first();
        $this->assertEquals('Pending', $row->collection);
        $this->assertNotNull($row->contentcheck_reasons);

        $reasons    = json_decode($row->contentcheck_reasons, true);
        $checkNames = array_column($reasons, 'check');
        $this->assertContains('Vague', $checkNames);
        $this->assertContains('PhoneNumber', $checkNames);
    }

    public function test_already_processed_messages_are_skipped(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: table (SW1A)',
            'textbody' => 'Nice table',
            'message'  => 'Nice table',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        // Already processed — contentcheck_checked_at IS SET.
        DB::table('messages_groups')->insert([
            'msgid'                    => $msgid,
            'groupid'                  => $group->id,
            'collection'               => 'Pending',
            'arrival'                  => now(),
            'deleted'                  => 0,
            'contentcheck_checked_at'  => now(),
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['approved']);
        $this->assertEquals(0, $stats['kept_pending']);
    }

    public function test_fully_moderated_group_keeps_message_pending(): void
    {
        $group = $this->createTestGroup(['settings' => ['moderated' => 1]]);
        $user  = $this->createTestUser();
        // User is non-moderated — but group is fully moderated.
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Oak chair (SW1A)',
            'textbody' => 'Beautiful chair. Collection only.',
            'message'  => 'Beautiful chair. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'   => $msgid,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['approved']);
        $this->assertEquals(1, $stats['kept_pending']);
        $collection = DB::table('messages_groups')->where('msgid', $msgid)->value('collection');
        $this->assertEquals('Pending', $collection);
    }

    public function test_freebiealerts_task_queued_for_approved_offer(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Bookshelf (SW1A)',
            'textbody' => 'A solid bookshelf. Collection only.',
            'message'  => 'A solid bookshelf. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
            'lat'      => 51.50,
            'lng'      => -0.13,
        ]);
        DB::table('messages_groups')->insert([
            'msgid'   => $msgid,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['errors'], 'processUnprocessed had errors');
        $this->assertEquals(1, $stats['approved'], 'Message was not approved (approved='.$stats['approved'].', kept_pending='.$stats['kept_pending'].')');

        $after = DB::table('background_tasks')
            ->where('task_type', 'freebie_alerts_add')
            ->whereRaw("JSON_EXTRACT(data, '$.msgid') = ?", [$msgid])
            ->count();

        $this->assertGreaterThanOrEqual(1, $after);
    }

    public function test_push_notify_task_queued_for_kept_pending(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        // Moderated user — will be kept pending.
        $this->createMembership($user, $group);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Chair (SW1A)',
            'textbody' => 'Nice chair.',
            'message'  => 'Nice chair.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'   => $msgid,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['errors'], 'processUnprocessed had errors');
        $this->assertEquals(1, $stats['kept_pending'], 'Message was not kept pending (kept_pending='.$stats['kept_pending'].', approved='.$stats['approved'].')');

        $taskCount = DB::table('background_tasks')
            ->where('task_type', 'push_notify_group_mods')
            ->whereRaw("JSON_EXTRACT(data, '$.group_id') = ?", [$group->id])
            ->whereNull('processed_at')
            ->count();

        $this->assertGreaterThanOrEqual(1, $taskCount);
    }

    // -------------------------------------------------------------------------
    // auditExisting — read-only disagreement scan
    // -------------------------------------------------------------------------

    public function test_audit_approved_message_that_would_be_flagged_returns_should_flag(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        // Approved message that contains a phone number (would be flagged by PII check).
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Sofa (SW1A)',
            'textbody' => 'Call 07700 900111 to collect.',
            'message'  => 'Call 07700 900111 to collect.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'                   => $msgid,
            'groupid'                 => $group->id,
            'collection'              => 'Approved',
            'arrival'                 => now(),
            'deleted'                 => 0,
            'contentcheck_checked_at' => now(),
        ]);

        $disagreements = $this->service->auditExisting($group->id);

        $this->assertNotEmpty($disagreements);
        $types = array_column($disagreements, 'type');
        $this->assertContains('should_flag', $types);

        $flagged = array_filter($disagreements, fn ($d) => $d['msgid'] === (int) $msgid);
        $this->assertNotEmpty($flagged, 'Our specific message should appear in disagreements');

        $entry = array_values($flagged)[0];
        $this->assertEquals('should_flag', $entry['type']);
        $this->assertNotEmpty($entry['reasons']);
    }

    public function test_audit_pending_message_that_would_be_approved_returns_should_approve(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        // Clean pending message from a non-moderated user — audit should flag it as should_approve.
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Wooden bookshelf (SW1A)',
            'textbody' => 'Beautiful solid oak bookshelf. Collection only.',
            'message'  => 'Beautiful solid oak bookshelf. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Wooden bookshelf']);
        $itemId = DB::table('items')->where('name', 'Wooden bookshelf')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $disagreements = $this->service->auditExisting($group->id);

        $approvals = array_filter($disagreements, fn ($d) => $d['msgid'] === (int) $msgid);
        $this->assertNotEmpty($approvals, 'Clean pending message from unmoderated user should appear as should_approve');

        $entry = array_values($approvals)[0];
        $this->assertEquals('should_approve', $entry['type']);
        $this->assertEmpty($entry['reasons']);
    }

    public function test_audit_pending_moderated_user_not_returned_as_should_approve(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        // NULL ourPostingStatus = MODERATED — message should NOT appear as should_approve.
        $this->createMembership($user, $group);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Lamp (SW1A)',
            'textbody' => 'A nice lamp. Collection only.',
            'message'  => 'A nice lamp. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Lamp']);
        $itemId = DB::table('items')->where('name', 'Lamp')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $disagreements = $this->service->auditExisting($group->id);

        $forThisMsg = array_filter($disagreements, fn ($d) => $d['msgid'] === (int) $msgid);
        $this->assertEmpty($forThisMsg, 'Moderated user pending message should not appear in audit results');
    }

    public function test_audit_returns_empty_when_no_disagreements(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        // Approved message with contentcheck already done and no failures — no disagreement.
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Oak table (SW1A)',
            'textbody' => 'A lovely oak table. Collection only.',
            'message'  => 'A lovely oak table. Collection only.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Oak table']);
        $itemId = DB::table('items')->where('name', 'Oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        DB::table('messages_groups')->insert([
            'msgid'                   => $msgid,
            'groupid'                 => $group->id,
            'collection'              => 'Approved',
            'arrival'                 => now(),
            'deleted'                 => 0,
            'contentcheck_checked_at' => now(),
        ]);

        $disagreements = $this->service->auditExisting($group->id);

        $forThisMsg = array_filter($disagreements, fn ($d) => $d['msgid'] === (int) $msgid);
        $this->assertEmpty($forThisMsg);
    }

    // -------------------------------------------------------------------------
    // Artisan command
    // -------------------------------------------------------------------------

    public function test_contentcheck_command_runs_successfully(): void
    {
        $this->artisan('messages:contentcheck')
            ->assertExitCode(0);
    }

    public function test_contentcheck_command_dry_run_makes_no_changes(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Lamp (SW1A)',
            'textbody' => 'A nice lamp.',
            'message'  => 'A nice lamp.',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'   => $msgid,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $this->artisan('messages:contentcheck', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        // No change — still Pending, contentcheck_checked_at still NULL.
        $row = DB::table('messages_groups')->where('msgid', $msgid)->first();
        $this->assertEquals('Pending', $row->collection);
        $this->assertNull($row->contentcheck_checked_at);
    }

    // -------------------------------------------------------------------------
    // checkVagueItem — mid-word position
    // -------------------------------------------------------------------------

    public function test_vague_word_alongside_specific_tokens_is_not_flagged(): void
    {
        // "old stuff in shed" includes a vague word ('stuff') but also specific
        // tokens ('old', 'shed'), so the rule should not flag it.
        $this->assertNull($this->service->checkVagueItem('old stuff in shed'));
    }

    // -------------------------------------------------------------------------
    // processUnprocessed — action = 'block' moves message to Spam
    // -------------------------------------------------------------------------

    public function test_block_action_keyword_moves_message_to_spam(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        DB::table('concern_keywords')->insert([
            'keyword'  => 'testblock_cc',
            'category' => 'substance_regulated',
            'action'   => 'block',
            'match_mode' => 'literal',
        ]);

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: testblock_cc item (SW1A)',
            'textbody' => 'Something that should be blocked',
            'message'  => 'Something that should be blocked',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);
        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => now(),
            'deleted'    => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['blocked'] ?? 0, 'block-action keyword must move message to Spam');
        $collection = DB::table('messages_groups')->where('msgid', $msgid)->value('collection');
        $this->assertEquals('Spam', $collection, 'message with block-action keyword must be in Spam collection');
    }

    // -------------------------------------------------------------------------
    // processUnprocessed — arrival must NOT be reset on approval
    // -------------------------------------------------------------------------

    public function test_arrival_not_reset_when_message_is_approved(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $originalArrival = now()->subHour();

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Good chair (SW1A)',
            'textbody' => 'A clean chair. Collection only.',
            'message'  => 'A clean chair. Collection only.',
            'arrival'  => $originalArrival,
            'date'     => $originalArrival,
            'source'   => 'Platform',
            'lat'      => 51.50,
            'lng'      => -0.13,
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Good chair']);
        $itemId = DB::table('items')->where('name', 'Good chair')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);
        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $group->id,
            'collection' => 'Pending',
            'arrival'    => $originalArrival,
            'deleted'    => 0,
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(1, $stats['approved'], 'message must be approved');

        $row = DB::table('messages_groups')->where('msgid', $msgid)->first();
        $approvedArrival = \Carbon\Carbon::parse($row->arrival);
        // arrival must be preserved — within 5 seconds of the original, not near now()
        $this->assertEqualsWithDelta(
            $originalArrival->timestamp,
            $approvedArrival->timestamp,
            5,
            'arrival must not be reset to now() when promoting to Approved'
        );
    }

    // -------------------------------------------------------------------------
    // checkSubjectRepeat — flag mass-submission spam (V1 parity)
    // -------------------------------------------------------------------------

    public function test_subject_repeat_flags_when_posted_to_30_groups(): void
    {
        // Renamed in spirit (no groups any more - self-moderating-community.md):
        // "posted to 30 groups" is now "the same subject used for 30 distinct
        // national posts", which checkSubjectRepeat() counts directly on messages.
        $subject = 'OFFER: Spam subject test_sr';
        $user = $this->createTestUser();

        // 30 distinct prior posts with the same subject (mass-submission signal).
        for ($i = 0; $i < 30; $i++) {
            DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'type'     => 'Offer',
                'subject'  => $subject,
                'textbody' => 'Same spam content',
                'message'  => 'Same spam content',
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        // The message under test, posted with the same subject.
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => $subject,
            'textbody' => 'Same spam content',
            'message'  => 'Same spam content',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNotNull($result);
        $this->assertEquals('SubjectRepeat', $result['check']);
    }

    public function test_subject_repeat_not_flagged_for_29_groups(): void
    {
        $subject = 'OFFER: Below threshold test_sr';
        $user = $this->createTestUser();

        // 29 prior posts (below SUBJECT_THRESHOLD of 30).
        for ($i = 0; $i < 29; $i++) {
            DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'type'     => 'Offer',
                'subject'  => $subject,
                'textbody' => 'Content',
                'message'  => 'Content',
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => $subject,
            'textbody' => 'Content',
            'message'  => 'Content',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNull($result, 'Subject posted 29 times should not be flagged (below threshold of 30)');
    }

    public function test_subject_repeat_not_flagged_for_old_messages(): void
    {
        $subject = 'OFFER: Old subject test_sr';
        $user = $this->createTestUser();
        $oldDate = now()->subDays(8); // 8 days ago, outside SUBJECT_REPEAT_WINDOW

        // 30 prior posts, but all older than the window.
        for ($i = 0; $i < 30; $i++) {
            DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'type'     => 'Offer',
                'subject'  => $subject,
                'textbody' => 'Old content',
                'message'  => 'Old content',
                'arrival'  => $oldDate,
                'date'     => $oldDate,
                'source'   => 'Platform',
            ]);
        }

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => $subject,
            'textbody' => 'Old content',
            'message'  => 'Old content',
            'arrival'  => $oldDate,
            'date'     => $oldDate,
            'source'   => 'Platform',
        ]);

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNull($result, 'Subject older than 7 days should not be flagged');
    }

    public function test_subject_repeat_not_flagged_for_short_item_name_test_post(): void
    {
        // Regression (Discourse 9788/28): "Offer: Test" is 11 chars and was NOT skipped
        // by the < 10 guard, so common test-post subjects accumulated across many posts
        // over time and falsely flagged legitimate mod/tester posts.
        // Root cause: the old code checked strlen(full subject) instead of strlen(item name).
        // "Offer: Test" = 11 chars passes the guard; "Test" = 4 chars does not.
        // Fix: checkSubjectRepeat now accepts $itemName and guards on item name length.
        $subject = 'Offer: Test';

        // Simulate 30 prior "Offer: Test" posts (as accumulates naturally when mods
        // routinely post Test messages to verify things are working).
        $priorUser = $this->createTestUser();
        for ($i = 0; $i < 30; $i++) {
            DB::table('messages')->insertGetId([
                'fromuser' => $priorUser->id,
                'type'     => 'Offer',
                'subject'  => $subject,
                'textbody' => 'Test',
                'message'  => 'Test',
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        // A new mod posts "Offer: Test".
        $newUser = $this->createTestUser();
        $newMsgId = DB::table('messages')->insertGetId([
            'fromuser' => $newUser->id,
            'type'     => 'Offer',
            'subject'  => $subject,
            'textbody' => 'Test',
            'message'  => 'Test',
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // BUG (old code path): calling WITHOUT $itemName uses the full subject
        // "Offer: Test" (11 chars >= 10), so the guard does not fire. With 30+
        // prior posts in the window, the repeat check triggers and flags the message.
        // This proves the DB state is correct and the bug is real.
        $buggyPathResult = $this->service->checkSubjectRepeat($subject, $newMsgId);
        $this->assertNotNull($buggyPathResult, 'Without itemName, "Offer: Test" (11 chars) passes the length guard and 30+ prior posts causes a false flag — this confirms the bug exists');
        $this->assertEquals('SubjectRepeat', $buggyPathResult['check']);

        // FIX (new code path): calling WITH $itemName='Test' uses the item name
        // length (4 chars < 10), so the guard fires immediately and returns null.
        // A real mod "Test" post must not be blocked even with 30+ prior posts.
        $fixedPathResult = $this->service->checkSubjectRepeat($subject, $newMsgId, 'Test');
        $this->assertNull($fixedPathResult, 'With itemName="Test" (4 chars < 10), the length guard fires before the count query — no false flag');
    }

    // -------------------------------------------------------------------------
    // checkKnownSpammer — flag messages containing spammer email (V1 parity)
    // -------------------------------------------------------------------------

    public function test_known_spammer_email_flags_message(): void
    {
        // Create a spammer user and add their spam email
        $spammer = $this->createTestUser();
        $spammerEmail = 'known.spammer' . uniqid() . '@spam.com';
        DB::table('users_emails')->insert([
            'userid' => $spammer->id,
            'email'  => $spammerEmail,
        ]);

        // Mark as known spammer
        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
        ]);

        // Message body containing the spammer's email
        $textbody = "Contact me at $spammerEmail for more info";

        $result = $this->service->checkKnownSpammer($textbody);

        $this->assertNotNull($result);
        $this->assertEquals('KnownSpammer', $result['check']);
        $this->assertStringContainsString($spammerEmail, $result['detail']);
    }

    public function test_known_spammer_multiple_emails_flags_on_first_match(): void
    {
        // Create a spammer user and add their spam email
        $spammer = $this->createTestUser();
        $spammerEmail = 'known.spammer.' . uniqid() . '@spam.com';
        DB::table('users_emails')->insert([
            'userid' => $spammer->id,
            'email'  => $spammerEmail,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
        ]);

        // Message with both legitimate and spammer emails
        $textbody = "Email john@example.com or $spammerEmail for details";

        $result = $this->service->checkKnownSpammer($textbody);

        $this->assertNotNull($result);
        $this->assertEquals('KnownSpammer', $result['check']);
    }

    public function test_legitimate_email_not_flagged(): void
    {
        // Message with legitimate email (not in spam_users table)
        $textbody = "Contact john@example.com for more info";

        $result = $this->service->checkKnownSpammer($textbody);

        $this->assertNull($result, 'Legitimate email should not be flagged');
    }

    public function test_no_email_returns_null(): void
    {
        $textbody = "Collection only, no contact info";

        $result = $this->service->checkKnownSpammer($textbody);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkUrls — flag untrusted URLs (V1 parity)
    // -------------------------------------------------------------------------

    public function test_http_url_in_body_returns_reason(): void
    {
        $result = $this->service->checkUrls('OFFER: Sofa', 'See photos at http://example.com/sofa.jpg');

        $this->assertNotNull($result);
        $this->assertEquals('Url', $result['check']);
    }

    public function test_https_url_in_body_returns_reason(): void
    {
        $result = $this->service->checkUrls('OFFER: Sofa', 'More info at https://www.example.com/listing');

        $this->assertNotNull($result);
        $this->assertEquals('Url', $result['check']);
    }

    public function test_www_url_without_scheme_returns_reason(): void
    {
        $result = $this->service->checkUrls('OFFER: Sofa', 'Visit www.example.com for details');

        $this->assertNotNull($result);
        $this->assertEquals('Url', $result['check']);
    }

    public function test_no_url_in_body_returns_null(): void
    {
        $result = $this->service->checkUrls('OFFER: Sofa', 'Collection from SW1A 1AA please, cash only');

        $this->assertNull($result);
    }

    public function test_whitelisted_url_returns_null(): void
    {
        // Insert a trusted domain with count >= 3 into spam_whitelist_links.
        DB::table('spam_whitelist_links')->insertOrIgnore([
            'domain' => 'trusteddomain-cc.org',
            'count'  => 5,
        ]);

        $result = $this->service->checkUrls('OFFER: Sofa', 'See https://trusteddomain-cc.org/listing for details');

        $this->assertNull($result, 'Whitelisted domain with count >= 3 should not be flagged');

        DB::table('spam_whitelist_links')->where('domain', 'trusteddomain-cc.org')->delete();
    }

    public function test_low_count_whitelisted_url_still_flagged(): void
    {
        DB::table('spam_whitelist_links')->insertOrIgnore([
            'domain' => 'lowcount-cc.org',
            'count'  => 2,
        ]);

        $result = $this->service->checkUrls('OFFER: Sofa', 'See https://lowcount-cc.org/listing');

        $this->assertNotNull($result, 'Domain with whitelist count < 3 should still be flagged');

        DB::table('spam_whitelist_links')->where('domain', 'lowcount-cc.org')->delete();
    }

    // -------------------------------------------------------------------------
    // checkMoneySymbols — flag £, $ (V1 parity)
    // -------------------------------------------------------------------------

    public function test_pound_symbol_in_body_returns_reason(): void
    {
        $result = $this->service->checkMoneySymbols('OFFER: Sofa', 'Worth £200 but free to good home');

        $this->assertNotNull($result);
        $this->assertEquals('Money', $result['check']);
    }

    public function test_dollar_symbol_in_body_returns_reason(): void
    {
        $result = $this->service->checkMoneySymbols('OFFER: Sofa', 'Cost $50 new, giving away free');

        $this->assertNotNull($result);
        $this->assertEquals('Money', $result['check']);
    }

    public function test_pound_in_subject_returns_reason(): void
    {
        $result = $this->service->checkMoneySymbols('OFFER: Sofa worth £100', 'Collection only');

        $this->assertNotNull($result);
        $this->assertEquals('Money', $result['check']);
    }

    public function test_no_money_symbol_returns_null(): void
    {
        $result = $this->service->checkMoneySymbols('OFFER: Sofa', 'Collection from SW1A 1AA please');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkLanguage — flag non-English/Welsh (V1 parity)
    // -------------------------------------------------------------------------

    public function test_english_message_returns_null(): void
    {
        $text = 'This is a lovely solid oak dining table in great condition. Collection only from SW1A. Please bring help to carry.';

        $result = $this->service->checkLanguage('OFFER: Oak dining table', $text);

        $this->assertNull($result);
    }

    public function test_french_message_returns_reason(): void
    {
        // Inject a deterministic confident-French detector result. Using injection
        // because the real ELD library's reliability on borderline mixed-cognate
        // French text makes a live-library assertion threshold-dependent.
        $frenchDetector = static fn(string $text) => ['lang' => 'fr', 'reliable' => true];
        $text = 'Bonjour, je donne une belle table en chêne massif en très bon état. Venez la chercher dans le quartier.';

        $result = $this->service->checkLanguage('OFFER: Table', $text, $frenchDetector);

        $this->assertNotNull($result);
        $this->assertEquals('Language', $result['check']);
    }

    public function test_short_message_skips_language_check(): void
    {
        // Under 50 chars — V1 skips language check for short strings.
        $result = $this->service->checkLanguage('OFFER: Lamp', 'ok thanks');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkBulkVolunteerMail — detect bulk mailing to volunteer addresses (V1 Spam.php parity)
    // -------------------------------------------------------------------------

    public function test_bulk_volunteer_mail_flags_when_sender_mailed_20_addresses_in_24h(): void
    {
        $sender = 'bulk-sender-' . uniqid() . '@example.com';
        $subject = 'Important notice for volunteers';

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser'    => $user->id,
            'type'        => 'Admin',
            'subject'     => $subject,
            'textbody'    => 'Test body',
            'message'     => 'Test body',
            'envelopefrom' => $sender,
            'envelopeto'  => 'group1-volunteers@ilovefreegle.org',
            'arrival'     => now(),
            'date'        => now(),
            'source'      => 'Email',
        ]);

        // Create 19 more messages from same sender to different group volunteer addresses
        for ($i = 1; $i < 20; $i++) {
            DB::table('messages')->insert([
                'fromuser'     => $user->id,
                'type'         => 'Admin',
                'subject'      => $subject,
                'textbody'     => 'Test body',
                'message'      => 'Test body',
                'envelopefrom' => $sender,
                'envelopeto'   => "group{$i}-volunteers@ilovefreegle.org",
                'arrival'      => now(),
                'date'         => now(),
                'source'       => 'Email',
            ]);
        }

        $result = $this->service->checkBulkVolunteerMail($subject, $msgid);

        $this->assertNotNull($result, 'Sender mailing 20 group volunteer addresses in 24h should be flagged');
        $this->assertEquals('BulkMail', $result['check']);
        $this->assertStringContainsString($sender, $result['detail']);
    }

    public function test_bulk_volunteer_mail_flags_when_subject_sent_to_20_addresses_in_24h(): void
    {
        $subject = 'Bulk spam subject ' . uniqid();
        $sender1 = 'sender1-' . uniqid() . '@example.com';
        $sender2 = 'sender2-' . uniqid() . '@example.com';

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser'     => $user->id,
            'type'         => 'Admin',
            'subject'      => $subject,
            'textbody'     => 'Test body',
            'message'      => 'Test body',
            'envelopefrom' => $sender1,
            'envelopeto'   => 'group1-volunteers@ilovefreegle.org',
            'arrival'      => now(),
            'date'         => now(),
            'source'       => 'Email',
        ]);

        // Create 10 messages from sender1 with same subject
        for ($i = 1; $i < 10; $i++) {
            DB::table('messages')->insert([
                'fromuser'     => $user->id,
                'type'         => 'Admin',
                'subject'      => $subject,
                'textbody'     => 'Test body',
                'message'      => 'Test body',
                'envelopefrom' => $sender1,
                'envelopeto'   => "group{$i}-volunteers@ilovefreegle.org",
                'arrival'      => now(),
                'date'         => now(),
                'source'       => 'Email',
            ]);
        }

        // Create 10 messages from sender2 with same subject (different sender, same subject = spam)
        for ($i = 10; $i < 20; $i++) {
            DB::table('messages')->insert([
                'fromuser'     => $user->id,
                'type'         => 'Admin',
                'subject'      => $subject,
                'textbody'     => 'Test body',
                'message'      => 'Test body',
                'envelopefrom' => $sender2,
                'envelopeto'   => "group{$i}-volunteers@ilovefreegle.org",
                'arrival'      => now(),
                'date'         => now(),
                'source'       => 'Email',
            ]);
        }

        $result = $this->service->checkBulkVolunteerMail($subject, $msgid);

        $this->assertNotNull($result, 'Subject sent to 20 group volunteer addresses in 24h should be flagged');
        $this->assertEquals('BulkMail', $result['check']);
        $this->assertStringContainsString($subject, $result['detail']);
    }

    public function test_bulk_volunteer_mail_not_flagged_for_19_addresses(): void
    {
        $sender = 'sender-' . uniqid() . '@example.com';
        $subject = 'Notice';

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser'     => $user->id,
            'type'         => 'Admin',
            'subject'      => $subject,
            'textbody'     => 'Test body',
            'message'      => 'Test body',
            'envelopefrom' => $sender,
            'envelopeto'   => 'group1-volunteers@ilovefreegle.org',
            'arrival'      => now(),
            'date'         => now(),
            'source'       => 'Email',
        ]);

        // Create 18 more messages (total 19, below threshold of 20)
        for ($i = 1; $i < 19; $i++) {
            DB::table('messages')->insert([
                'fromuser'     => $user->id,
                'type'         => 'Admin',
                'subject'      => $subject,
                'textbody'     => 'Test body',
                'message'      => 'Test body',
                'envelopefrom' => $sender,
                'envelopeto'   => "group{$i}-volunteers@ilovefreegle.org",
                'arrival'      => now(),
                'date'         => now(),
                'source'       => 'Email',
            ]);
        }

        $result = $this->service->checkBulkVolunteerMail($subject, $msgid);

        $this->assertNull($result, 'Sender mailing 19 volunteer addresses (< 20) should not be flagged');
    }

    public function test_bulk_volunteer_mail_no_envelope_returns_null(): void
    {
        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser'     => $user->id,
            'type'         => 'Offer',
            'subject'      => 'OFFER: Item',
            'textbody'     => 'Test body',
            'message'      => 'Test body',
            'envelopeto'   => NULL,
            'arrival'      => now(),
            'date'         => now(),
            'source'       => 'Platform',
        ]);

        $result = $this->service->checkBulkVolunteerMail('Test', $msgid);

        $this->assertNull($result, 'Message without envelopeto should not be checked');
    }

    public function test_bulk_volunteer_mail_not_volunteer_address_returns_null(): void
    {
        $sender = 'sender@example.com';
        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser'     => $user->id,
            'type'         => 'Offer',
            'subject'      => 'OFFER: Item',
            'textbody'     => 'Test body',
            'message'      => 'Test body',
            'envelopefrom' => $sender,
            'envelopeto'   => 'regular-user@example.com',  // Not a volunteer address
            'arrival'      => now(),
            'date'         => now(),
            'source'       => 'Platform',
        ]);

        $result = $this->service->checkBulkVolunteerMail('Test', $msgid);

        $this->assertNull($result, 'Non-volunteer address should not be checked');
    }

    // -------------------------------------------------------------------------
    // checkGreetingSpam — greeting + link pattern (V1 Spam.php parity)
    // -------------------------------------------------------------------------

    public function test_greeting_with_http_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Hello! Check this deal', 'Visit http://example.com for more info');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_greeting_in_subject_with_link_in_body_flags(): void
    {
        $result = $this->service->checkGreetingSpam('Hi there', 'More details at https://www.example.com/offer');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_hey_greeting_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Hey!', 'Check http://spam.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_good_morning_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Good morning everyone', 'Visit our site http://deals.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_sup_greeting_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Sup guys', 'Check out https://example.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_greetings_greeting_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Greetings', 'Visit http://spam.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_good_afternoon_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Good afternoon friends', 'www.example.com has deals');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_good_evening_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Good evening', 'Check http://example.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_hello_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Hello', 'http://example.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_salutations_with_link_flags_message(): void
    {
        $result = $this->service->checkGreetingSpam('Salutations', 'Visit https://example.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    public function test_greeting_without_link_returns_null(): void
    {
        $result = $this->service->checkGreetingSpam('Hello friend', 'Collection from SW1A 1AA please');

        $this->assertNull($result);
    }

    public function test_no_greeting_with_link_returns_null(): void
    {
        $result = $this->service->checkGreetingSpam('OFFER: Sofa', 'Visit http://example.com');

        $this->assertNull($result);
    }

    public function test_greeting_case_insensitive(): void
    {
        $result = $this->service->checkGreetingSpam('HELLO', 'http://spam.com');

        $this->assertNotNull($result);
        $this->assertEquals('GreetingSpam', $result['check']);
    }

    // -------------------------------------------------------------------------
    // checkImageSpam — duplicate image hash in 24 hours (V1 parity)
    // -------------------------------------------------------------------------

    public function test_image_spam_detects_hash_used_6_times_in_24h(): void
    {
        $subject = 'OFFER: Item';
        $msgid = DB::table('messages')->insertGetId([
            'subject'  => $subject,
            'textbody' => 'Some content',
            'message'  => 'Some content',
            'arrival'  => now(),
            'date'     => now(),
        ]);

        // Attach same hash to the message being checked
        $testHash = 'hash_' . uniqid();
        DB::table('messages_attachments')->insert([
            'msgid' => $msgid,
            'hash'  => $testHash,
        ]);

        // Insert 5 more messages with the same hash (6 total in 24h → flagged)
        for ($i = 0; $i < 5; $i++) {
            $otherMsgid = DB::table('messages')->insertGetId([
                'subject'  => "OFFER: Item $i",
                'textbody' => 'Content',
                'message'  => 'Content',
                'arrival'  => now()->subHours($i + 1),
                'date'     => now()->subHours($i + 1),
            ]);
            DB::table('messages_attachments')->insert([
                'msgid' => $otherMsgid,
                'hash'  => $testHash,
            ]);
        }

        $result = $this->service->checkImageSpam($msgid);

        $this->assertNotNull($result);
        $this->assertEquals('ImageSpam', $result['check']);
    }

    public function test_image_spam_detects_hash_used_5_times_not_flagged(): void
    {
        $msgid = DB::table('messages')->insertGetId([
            'subject'  => 'OFFER: Item',
            'textbody' => 'Content',
            'message'  => 'Content',
            'arrival'  => now(),
            'date'     => now(),
        ]);

        // Insert 5 messages (threshold is > 5, so 5 is OK)
        $testHash = 'hash_' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            $otherMsgid = DB::table('messages')->insertGetId([
                'subject'  => "OFFER: Item $i",
                'textbody' => 'Content',
                'message'  => 'Content',
                'arrival'  => now()->subHours($i),
                'date'     => now()->subHours($i),
            ]);
            DB::table('messages_attachments')->insert([
                'msgid' => $otherMsgid,
                'hash'  => $testHash,
            ]);
        }

        $result = $this->service->checkImageSpam($msgid);

        $this->assertNull($result, 'Image used 5 times should not be flagged (threshold is > 5)');
    }

    public function test_image_spam_ignores_old_images(): void
    {
        $msgid = DB::table('messages')->insertGetId([
            'subject'  => 'OFFER: Item',
            'textbody' => 'Content',
            'message'  => 'Content',
            'arrival'  => now(),
            'date'     => now(),
        ]);

        // Insert 6 messages but older than 24 hours
        $testHash = 'hash_' . uniqid();
        for ($i = 0; $i < 6; $i++) {
            $otherMsgid = DB::table('messages')->insertGetId([
                'subject'  => "OFFER: Item $i",
                'textbody' => 'Content',
                'message'  => 'Content',
                'arrival'  => now()->subHours(25 + $i),
                'date'     => now()->subHours(25 + $i),
            ]);
            DB::table('messages_attachments')->insert([
                'msgid' => $otherMsgid,
                'hash'  => $testHash,
            ]);
        }

        $result = $this->service->checkImageSpam($msgid);

        $this->assertNull($result, 'Images older than 24 hours should not be counted');
    }

    public function test_image_spam_no_attachments_returns_null(): void
    {
        $msgid = DB::table('messages')->insertGetId([
            'subject'  => 'OFFER: Item',
            'textbody' => 'Content',
            'message'  => 'Content',
            'arrival'  => now(),
            'date'     => now(),
        ]);

        $result = $this->service->checkImageSpam($msgid);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkSpamhaus — Spamhaus DBL lookup (V1 Spam.php parity)
    // -------------------------------------------------------------------------

    public function test_spamhaus_blocked_domain_flags_message(): void
    {
        $result = $this->service->checkSpamhaus(
            'OFFER: Item',
            'Visit http://spam.example.com for deals',
            // Provide a mock DNS lookup for testing
            fn ($domain) => ['spam.example.com.zen.spamhaus.org' => ['type' => 'A', 'ip' => '127.0.0.2']]
        );

        $this->assertNotNull($result);
        $this->assertEquals('SpamhausDBL', $result['check']);
    }

    public function test_spamhaus_allowed_domain_returns_null(): void
    {
        $result = $this->service->checkSpamhaus(
            'OFFER: Item',
            'Visit http://google.com for info',
            fn ($domain) => [] // No DNS response = not blocked
        );

        $this->assertNull($result);
    }

    public function test_spamhaus_no_urls_returns_null(): void
    {
        $result = $this->service->checkSpamhaus(
            'OFFER: Item',
            'Collection from SW1A 1AA please'
        );

        $this->assertNull($result);
    }

    public function test_spamhaus_multiple_urls_flags_on_first_blocked(): void
    {
        $result = $this->service->checkSpamhaus(
            'OFFER: Item',
            'Check http://good.com and http://bad.com',
            function ($domain) {
                if (str_contains($domain, 'bad')) {
                    return ['bad.com.zen.spamhaus.org' => ['type' => 'A', 'ip' => '127.0.0.2']];
                }
                return [];
            }
        );

        $this->assertNotNull($result);
        $this->assertEquals('SpamhausDBL', $result['check']);
    }

    // -------------------------------------------------------------------------
    // Contextual embedding suppression — ContentEmbeddingService integration
    // -------------------------------------------------------------------------

    public function test_contextual_innocent_verdict_suppresses_keyword_flag(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'gun',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        $embedding = $this->createMock(ContentEmbeddingService::class);
        $embedding->method('isInnocentContext')->willReturn(true);

        $service = new ContentCheckService($embedding);
        $result  = $service->checkConcernKeywords('OFFER: hot glue gun for crafts', '', $group->id);

        $this->assertNull($result, 'Embedding service said innocent — should not flag');
    }

    public function test_contextual_concerning_verdict_still_flags(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'gun',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        $embedding = $this->createMock(ContentEmbeddingService::class);
        $embedding->method('isInnocentContext')->willReturn(false);

        $service = new ContentCheckService($embedding);
        $result  = $service->checkConcernKeywords('OFFER: gun for sale', '', $group->id);

        $this->assertNotNull($result, 'Embedding service said concerning — should flag');
        $this->assertEquals('substance_regulated', $result['category']);
    }

    public function test_no_embedding_service_still_flags(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'gun',
            'category'   => 'substance_regulated',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        // No embedding service — conservative default is to flag everything.
        $service = new ContentCheckService(null);
        $result  = $service->checkConcernKeywords('OFFER: glue gun for crafts', '', $group->id);

        $this->assertNotNull($result, 'No embedding service — should flag conservatively');
    }

    public function test_contextual_innocent_verdict_suppresses_substance_medicine_flag(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'codeine',
            'category'   => 'substance_medicine',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        $embedding = $this->createMock(ContentEmbeddingService::class);
        $embedding->method('isInnocentContext')->willReturn(true);

        $service = new ContentCheckService($embedding);
        $result  = $service->checkConcernKeywords('OFFER: medicine cabinet with old codeine labels', '', $group->id);

        $this->assertNull($result, 'Embedding service said innocent — should not flag substance_medicine');
    }

    public function test_contextual_innocent_verdict_suppresses_scam_flag(): void
    {
        $group = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'bank transfer',
            'category'   => 'scam',
            'action'     => 'flag',
            'match_mode' => 'literal',
        ]);

        $embedding = $this->createMock(ContentEmbeddingService::class);
        $embedding->method('isInnocentContext')->willReturn(true);

        $service = new ContentCheckService($embedding);
        $result  = $service->checkConcernKeywords('OFFER: warning — I was asked for a bank transfer, report this scam', '', $group->id);

        $this->assertNull($result, 'Embedding service said innocent — should not flag scam warning');
    }

    // -------------------------------------------------------------------------
    // Held messages must be left alone (Discourse 9816 / mod-veto), and new
    // approved-on-arrival posts must be content-checked too.
    // -------------------------------------------------------------------------

    public function test_held_pending_message_is_not_promoted(): void
    {
        // A mod has pulled a post back to Pending and is holding it for review
        // (heldby set). The content-check job must not fight the mod by promoting
        // it straight back to Approved.
        //
        // It IS still checked, though. This test used to assert the post was "not
        // processed at all", which meant contentcheck_checked_at stayed NULL for as long
        // as the hold lasted - so the moderator holding it never got the reasons it was
        // flagged, and surfaces gated on "has been checked" silently dropped it from
        // their counts (Discourse 9481/635). Checking is not acting: only promoting or
        // blocking would fight the mod, and those remain off.
        $user  = $this->createTestUser();
        $modId = $this->createTestUser()->id;

        $message = $this->createTestMessage($user, [
            'subject'    => 'OFFER: Solid oak table (SW1A)',
            'textbody'   => 'Beautiful table. Collection only.',
            'collection' => Message::COLLECTION_PENDING,
            'heldby'     => $modId,
        ]);
        $msgid = $message->id;

        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals('Pending', DB::table('messages')->where('id', $msgid)->value('collection'),
            'A held message must not be auto-promoted');
        $this->assertNotNull(DB::table('messages')->where('id', $msgid)->value('contentcheck_checked_at'),
            'A held message is still checked - the moderator holding it needs the result');
        $this->assertEquals($modId, DB::table('messages')->where('id', $msgid)->value('heldby'),
            'The hold itself must be left alone');
    }

    public function test_new_approved_message_is_content_checked(): void
    {
        // Unmoderated members post straight to Approved, bypassing the Pending
        // queue. Those new posts must still be content-checked (just recorded
        // when clean — never demoted).
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user, [
            'subject'    => 'OFFER: Solid oak table (SW1A)',
            'textbody'   => 'Beautiful table. Collection only.',
            'collection' => Message::COLLECTION_APPROVED,
        ]);
        $msgid = $message->id;

        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals('Approved', DB::table('messages')->where('id', $msgid)->value('collection'),
            'A clean approved post must stay Approved (never auto-demoted)');
        $this->assertNotNull(DB::table('messages')->where('id', $msgid)->value('contentcheck_checked_at'),
            'A new approved post must be content-checked');
    }

    public function test_new_approved_message_with_reason_notifies_mods_and_stays_live(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user, [
            'subject'    => 'OFFER: stuff (SW1A)',
            'textbody'   => 'Some stuff.',
            'collection' => Message::COLLECTION_APPROVED,
        ]);
        $msgid = $message->id;

        // Vague item name -> a content-check reason.
        DB::table('items')->insertOrIgnore(['name' => 'stuff']);
        $itemId = DB::table('items')->where('name', 'stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $this->service->processUnprocessed();

        $this->assertEquals('Approved', DB::table('messages')->where('id', $msgid)->value('collection'),
            'A flagged approved post stays live (mods are notified, not auto-removed)');
        $this->assertNotNull(DB::table('messages')->where('id', $msgid)->value('contentcheck_reasons'),
            'Reasons must be stored for a flagged approved post');
        $this->assertTrue(
            DB::table('background_tasks')
                ->where('task_type', \App\Models\BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS)
                ->whereRaw("JSON_EXTRACT(data, '$.msgid') = ?", [$msgid])
                ->exists(),
            'Mods must be notified about a flagged approved post - the payload now carries msgid, not group_id (no groups left)'
        );
    }

    public function test_old_approved_message_is_not_content_checked(): void
    {
        // Bound: only NEW approved posts are checked. An older approved post
        // (outside the recent-arrival window) must never be rescanned, so the
        // historical backlog is untouched.
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user, [
            'subject'    => 'OFFER: Solid oak table (SW1A)',
            'textbody'   => 'Beautiful table. Collection only.',
            'collection' => Message::COLLECTION_APPROVED,
            'arrival'    => now()->subHours(72),
            'date'       => now()->subHours(72),
        ]);
        $msgid = $message->id;

        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $this->service->processUnprocessed();

        $this->assertNull(DB::table('messages')->where('id', $msgid)->value('contentcheck_checked_at'),
            'An old approved post (outside the recent-arrival window) must not be rescanned');
    }

    public function test_freebiealerts_task_not_queued_for_bulk_offer(): void
    {
        // A clearance (bulk-offer) Offer must not receive a freebie_alerts_add task even
        // when auto-approved — the concierge manages those posts directly.
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user, [
            'subject'    => 'OFFER: Office Clearance (EC1A)',
            'textbody'   => 'Full office clearance — desks, chairs, monitors.',
            'collection' => Message::COLLECTION_PENDING,
            'lat'        => 51.50,
            'lng'        => -0.13,
        ]);
        $msgid = $message->id;

        // Mark as a clearance post.
        DB::table('messages_bulk_items')->insert([
            'msgid'     => $msgid,
            'position'  => 0,
            'name'      => 'Office desk',
            'quantity'  => 4,
            'condition' => 'Good',
        ]);

        $stats = $this->service->processUnprocessed();

        $this->assertEquals(0, $stats['errors'], 'processUnprocessed had errors');
        $this->assertEquals(1, $stats['approved'], 'Clearance message should still be approved');

        $count = DB::table('background_tasks')
            ->where('task_type', 'freebie_alerts_add')
            ->whereRaw("JSON_EXTRACT(data, '$.msgid') = ?", [$msgid])
            ->count();

        $this->assertEquals(0, $count, 'freebie_alerts_add must not be queued for a clearance/bulk-offer post');
    }

    /**
     * A post a moderator has HELD must still be content-checked - but never re-promoted.
     *
     * Holding used to exclude a post from the check entirely ("never fight a mod"), which
     * meant contentcheck_checked_at stayed NULL for as long as the hold lasted. Two things
     * followed: the moderator who held it never got the content reasons that would tell them
     * why it needed a look, and the ModTools badge - which only counts checked rows - showed
     * fewer held posts than were sitting in their list (Discourse 9481/635, seen live with a
     * post held for two days and still unchecked).
     *
     * Checking is not the same as acting. The check records what it found; only promotion
     * and blocking would fight the moderator, and those stay off for held rows.
     */
    public function test_held_post_is_content_checked_but_never_promoted(): void
    {
        $poster = $this->createTestUser();
        $holder = $this->createTestUser();

        DB::table('concern_keywords')->insert([
            'keyword'  => 'testheldkw_cc',
            'category' => 'review',
            'action'   => 'flag',
            'scope'    => 'global',
        ]);

        $message = $this->createTestMessage($poster, [
            'subject'                 => 'OFFER: testheldkw_cc item (TestLocation)',
            'collection'              => Message::COLLECTION_PENDING,
            'heldby'                  => $holder->id,
            'contentcheck_checked_at' => null,
        ]);

        $this->service->processUnprocessed();

        $row = DB::table('messages')->where('id', $message->id)->first();

        $this->assertNotNull($row->contentcheck_checked_at, 'a held post must still be content-checked');
        $this->assertNotNull($row->contentcheck_reasons, 'the moderator holding it should get the reasons');
        $this->assertStringContainsString('testheldkw_cc', $row->contentcheck_reasons);
        $this->assertEquals(Message::COLLECTION_PENDING, $row->collection,
            'checking must not promote a post out from under the moderator holding it');
        $this->assertEquals($holder->id, $row->heldby, 'the hold itself must be left alone');
    }

    /**
     * The other half: a HELD post with nothing wrong must still be recorded as checked, and
     * still must not be auto-approved - that is the case the old skip was protecting.
     */
    public function test_clean_held_post_is_checked_but_not_auto_approved(): void
    {
        $poster = $this->createTestUser();
        $holder = $this->createTestUser();

        $message = $this->createTestMessage($poster, [
            'subject'                 => 'OFFER: Perfectly ordinary chair (TestLocation)',
            'collection'              => Message::COLLECTION_PENDING,
            'heldby'                  => $holder->id,
            'contentcheck_checked_at' => null,
        ]);

        $this->service->processUnprocessed();

        $row = DB::table('messages')->where('id', $message->id)->first();

        $this->assertNotNull($row->contentcheck_checked_at, 'a clean held post is still checked');
        $this->assertEquals(Message::COLLECTION_PENDING, $row->collection,
            'a clean held post must NOT be auto-approved while a moderator holds it');
    }

    /**
     * Discourse #9987: a post held because of a moderation SETTING rather than
     * its content used to reach the mod queue with contentcheck_reasons NULL,
     * so the moderator had nothing telling them why it needed approving.
     *
     * There is no group setting left (self-moderating-community.md); the only thing
     * that can keep a clean post pending now is the poster's own users.postingstatus
     * or a missing location, both checked directly on the messages row.
     */
    private function pendingCleanPost(User $user, array $messageOverrides = []): int
    {
        $msgid = DB::table('messages')->insertGetId(array_merge([
            'fromuser'   => $user->id,
            'type'       => 'Offer',
            'subject'    => 'OFFER: Solid oak table (SW1A)',
            'textbody'   => 'Beautiful table. Collection only.',
            'message'    => 'Beautiful table. Collection only.',
            'lat'        => 51.5,
            'lng'        => -0.12,
            'arrival'    => now(),
            'date'       => now(),
            'source'     => 'Platform',
            'collection' => 'Pending',
        ], $messageOverrides));

        DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
        $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        return $msgid;
    }

    private function reasonsFor(int $msgid): array
    {
        $json = DB::table('messages')->where('id', $msgid)->value('contentcheck_reasons');

        return $json ? json_decode($json, true) : [];
    }

    public function test_moderated_member_hold_records_why(): void
    {
        // NULL users.postingstatus = MODERATED (isUserModerated()'s default), so a
        // plain createTestUser() is enough - no membership/group setting left to set.
        $user = $this->createTestUser();

        $msgid = $this->pendingCleanPost($user);

        $stats = $this->service->processUnprocessed();
        $this->assertEquals(1, $stats['kept_pending']);

        $checks = array_column($this->reasonsFor($msgid), 'check');
        $this->assertContains(ContentCheckService::CHECK_MEMBER_MODERATED, $checks,
            'a post held because the member is moderated must say so');
    }

    public function test_missing_location_hold_records_why(): void
    {
        // Not moderated, so the only thing keeping this pending is the location.
        $user = $this->createTestUser(['postingstatus' => 'UNMODERATED']);

        $msgid = $this->pendingCleanPost($user, ['lat' => null, 'lng' => null]);

        $this->service->processUnprocessed();

        $checks = array_column($this->reasonsFor($msgid), 'check');
        $this->assertContains(ContentCheckService::CHECK_NO_LOCATION, $checks,
            'a post held because we could not locate it must say so');
    }

    public function test_clean_unmoderated_post_is_approved_with_no_reasons(): void
    {
        $user = $this->createTestUser(['postingstatus' => 'UNMODERATED']);

        $msgid = $this->pendingCleanPost($user);

        $this->service->processUnprocessed();

        $row = DB::table('messages')->where('id', $msgid)->first();

        $this->assertEquals(Message::COLLECTION_APPROVED, $row->collection,
            'nothing is holding this post, so it should go live');
        $this->assertNull($row->contentcheck_reasons,
            'an approved post carries no hold reasons');
    }
}
