<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\ContentCheckService;
use App\Services\ContentEmbeddingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentCheckTest extends TestCase
{
    private ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService();
        // Mark any unprocessed pending messages so processUnprocessed() only
        // sees rows inserted within this test's transaction.
        DB::table('messages_groups')
            ->where('collection', 'Pending')
            ->whereNull('contentcheck_checked_at')
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

    public function test_concern_keyword_per_group_scope_only_fires_for_matching_group(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        DB::table('concern_keywords')->insert([
            'keyword'    => 'testgroupkw_cc',
            'category'   => 'review',
            'action'     => 'flag',
            'scope'      => 'group',
            'group_id'   => $group1->id,
        ]);

        $matchGroup1 = $this->service->checkConcernKeywords('OFFER: testgroupkw_cc item', '', $group1->id);
        $this->assertNotNull($matchGroup1);

        $noMatchGroup2 = $this->service->checkConcernKeywords('OFFER: testgroupkw_cc item', '', $group2->id);
        $this->assertNull($noMatchGroup2);
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
        ];
    }

    // -------------------------------------------------------------------------
    // checkPhoneNumbers — gated by group restrictpersonalinfo rule
    // -------------------------------------------------------------------------

    public function test_phone_number_flagged_when_group_restricts_personalinfo(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);

        $result = $this->service->checkPhoneNumbers('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNotNull($result, 'Phone number should be flagged when restrictpersonalinfo is set');
        $this->assertEquals('PhoneNumber', $result['check']);
    }

    public function test_phone_number_not_flagged_when_group_has_no_personalinfo_restriction(): void
    {
        // Discourse #9766: groups without restrictpersonalinfo must not have posts held for phone numbers
        $group = $this->createTestGroup();

        $result = $this->service->checkPhoneNumbers('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNull($result, 'Phone number must not be flagged when group has no restrictpersonalinfo rule');
    }

    public function test_phone_number_not_flagged_when_restrict_rule_is_false(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => false]]);

        $result = $this->service->checkPhoneNumbers('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNull($result, 'Phone number must not be flagged when restrictpersonalinfo is false');
    }

    // -------------------------------------------------------------------------
    // checkPII — email addresses, gated by the same restrictpersonalinfo rule
    // -------------------------------------------------------------------------

    public function test_no_personal_info_in_body_returns_null(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Collection only please', $group->id);

        $this->assertNull($result);
    }

    public function test_external_email_in_body_with_restrict_rule_returns_reason(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Email john@example.com for details', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('EmailAddress', $result['check']);
    }

    public function test_freegle_email_not_flagged(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Reply via noreply@ilovefreegle.org', $group->id);

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
        $group = $this->createTestGroup(['rules' => ['fullymoderated' => true]]);
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
    // checkPerGroupWorryWords — per-group worry words from groups.settings
    // -------------------------------------------------------------------------

    public function test_per_group_worry_word_flags_message(): void
    {
        $group = $this->createTestGroup([
            'settings' => ['spammers' => ['worrywords' => 'badtestword_cc,anotherbadword_cc']],
        ]);

        $result = $this->service->checkPerGroupWorryWords('OFFER: badtestword_cc item', 'Some body text', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('PerGroupWorryWord', $result['check']);
        $this->assertStringContainsString('badtestword_cc', $result['detail']);
    }

    public function test_per_group_worry_word_second_word_in_list_matches(): void
    {
        $group = $this->createTestGroup([
            'settings' => ['spammers' => ['worrywords' => 'badtestword_cc,anotherbadword_cc']],
        ]);

        $result = $this->service->checkPerGroupWorryWords('OFFER: Lamp', 'anotherbadword_cc for sale', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('PerGroupWorryWord', $result['check']);
    }

    public function test_per_group_worry_word_not_flagged_for_other_group(): void
    {
        $group1 = $this->createTestGroup([
            'settings' => ['spammers' => ['worrywords' => 'badtestword_cc']],
        ]);
        $group2 = $this->createTestGroup();

        $result = $this->service->checkPerGroupWorryWords('OFFER: badtestword_cc item', 'Some body', $group2->id);

        $this->assertNull($result);
    }

    public function test_per_group_worry_word_no_settings_returns_null(): void
    {
        $group = $this->createTestGroup();

        $result = $this->service->checkPerGroupWorryWords('OFFER: badtestword_cc item', 'Some body', $group->id);

        $this->assertNull($result);
    }

    public function test_per_group_worry_word_fuzzy_matches_typo(): void
    {
        $group = $this->createTestGroup([
            'settings' => ['spammers' => ['worrywords' => 'badtestword_cc']],
        ]);

        // One character off — levenshtein distance 1.
        $result = $this->service->checkPerGroupWorryWords('OFFER: badtestword_cd item', 'Some body', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('PerGroupWorryWord', $result['check']);
    }

    public function test_per_group_worry_word_clean_message_returns_null(): void
    {
        $group = $this->createTestGroup([
            'settings' => ['spammers' => ['worrywords' => 'badtestword_cc']],
        ]);

        $result = $this->service->checkPerGroupWorryWords('OFFER: Nice lamp', 'A lovely lamp. Collection only.', $group->id);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkSubjectRepeat — flag mass-submission spam (V1 parity)
    // -------------------------------------------------------------------------

    public function test_subject_repeat_flags_when_posted_to_30_groups(): void
    {
        $subject = 'OFFER: Spam subject test_sr';

        // Create 30 groups
        $groups = [];
        for ($i = 0; $i < 30; $i++) {
            $groups[] = $this->createTestGroup();
        }

        // Create one message
        $user = $this->createTestUser();
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

        // Add it to 30 groups
        foreach ($groups as $group) {
            DB::table('messages_groups')->insert([
                'msgid'      => $msgid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => now(),
                'deleted'    => 0,
            ]);
        }

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNotNull($result);
        $this->assertEquals('SubjectRepeat', $result['check']);
    }

    public function test_subject_repeat_not_flagged_for_29_groups(): void
    {
        $subject = 'OFFER: Below threshold test_sr';

        // Create 29 groups (below SUBJECT_THRESHOLD of 30)
        $groups = [];
        for ($i = 0; $i < 29; $i++) {
            $groups[] = $this->createTestGroup();
        }

        $user = $this->createTestUser();
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

        foreach ($groups as $group) {
            DB::table('messages_groups')->insert([
                'msgid'      => $msgid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => now(),
                'deleted'    => 0,
            ]);
        }

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNull($result, 'Subject posted to 29 groups should not be flagged (below threshold of 30)');
    }

    public function test_subject_repeat_not_flagged_for_old_messages(): void
    {
        $subject = 'OFFER: Old subject test_sr';

        // Create 30 groups but with messages older than 7 days
        $groups = [];
        for ($i = 0; $i < 30; $i++) {
            $groups[] = $this->createTestGroup();
        }

        $user = $this->createTestUser();
        $oldDate = now()->subDays(8); // 8 days ago

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

        foreach ($groups as $group) {
            DB::table('messages_groups')->insert([
                'msgid'      => $msgid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => $oldDate,
                'deleted'    => 0,
            ]);
        }

        $result = $this->service->checkSubjectRepeat($subject, $msgid);

        $this->assertNull($result, 'Subject older than 7 days should not be flagged');
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
        // Inject deterministic French scores (en/fr = 0.30 — well below the 0.8 V1 threshold).
        // At 0.8: en(0.21) >= 0.8*fr(0.70)=0.56 → false → flagged correctly.
        // Using injection because the real library returns borderline probabilities for
        // mixed-cognate French text, making the live-library assertion threshold-dependent.
        $frenchDetector = static fn(string $text) => ['fr' => 0.70, 'en' => 0.21];
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
    // checkIpAbuse — detect IP abuse (V1 Spam.php USER_THRESHOLD=5, GROUP_THRESHOLD=20 parity)
    // -------------------------------------------------------------------------

    public function test_ip_abuse_flags_when_used_by_6_users(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);

        // Create one message with this IP
        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // Add 5 more messages from different users, same IP
        for ($i = 0; $i < 5; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Another item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        // Now IP has been used by 6 different users — should flag
        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result, 'IP used by 6 users (> 5) should be flagged');
        $this->assertEquals('IpAbuse', $result['check']);
        $this->assertStringContainsString('6', $result['detail']);
    }

    public function test_ip_abuse_not_flagged_for_5_users(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // Add 4 more messages from different users (total 5)
        for ($i = 0; $i < 4; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Another item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNull($result, 'IP used by 5 users should not be flagged (threshold is > 5)');
    }

    public function test_ip_abuse_flags_when_used_for_20_groups(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);
        $user = $this->createTestUser();

        // Create one message with this IP
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // Add the message to 20 different groups
        for ($i = 0; $i < 20; $i++) {
            $group = $this->createTestGroup();
            DB::table('messages_groups')->insert([
                'msgid'      => $msgid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => now(),
                'deleted'    => 0,
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result, 'IP used to post to 20 groups (>= 20) should be flagged');
        $this->assertEquals('IpAbuse', $result['check']);
        $this->assertStringContainsString('20', $result['detail']);
    }

    public function test_ip_abuse_ignores_messages_older_than_31_days(): void
    {
        // V1 queries messages_history which is pruned to 31 days.
        // Messages outside the window must not count toward the abuse threshold.
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);
        $old = now()->subDays(32);

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // Add 5 more users — all with arrival > 31 days ago
        for ($i = 0; $i < 5; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Old item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => $old,
                'date'     => $old,
                'source'   => 'Platform',
            ]);
        }

        // Only 1 user within the window — must NOT flag
        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNull($result, 'IP abuse check should ignore messages older than 31 days');
    }

    public function test_ip_abuse_no_ip_returns_null(): void
    {
        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => NULL,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNull($result, 'Message without IP should not be checked');
    }

    public function test_ip_abuse_user_branch_embeds_user_ids_in_reason(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);
        $createdUserIds = [];

        $user = $this->createTestUser();
        $createdUserIds[] = (int) $user->id;
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $otherUser = $this->createTestUser();
            $createdUserIds[] = (int) $otherUser->id;
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Another item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result);
        $this->assertEquals('IpAbuse', $result['check']);
        $this->assertEquals($ip, $result['ip']);
        $this->assertIsArray($result['users']);
        $this->assertCount(6, $result['users']);
        sort($createdUserIds);
        $returnedSorted = $result['users'];
        sort($returnedSorted);
        $this->assertEquals($createdUserIds, $returnedSorted, 'user IDs in reason must match the senders');
        foreach ($result['users'] as $id) {
            $this->assertIsInt($id, 'user ID must be int, not string');
        }
    }

    public function test_ip_abuse_user_branch_caps_user_ids_at_50(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // 59 more distinct users on the same IP (60 total).
        for ($i = 0; $i < 59; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Another item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result);
        $this->assertCount(50, $result['users'], 'user list must be capped at 50');
        $this->assertStringContainsString('60', $result['detail'], 'detail must still show the true count');
    }

    public function test_ip_abuse_user_branch_orders_users_by_recency(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);

        $oldestUser = $this->createTestUser();
        DB::table('messages')->insert([
            'fromuser' => $oldestUser->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Old',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now()->subDays(10),
            'date'     => now()->subDays(10),
            'source'   => 'Platform',
        ]);

        // Four middle-aged senders.
        for ($i = 0; $i < 4; $i++) {
            $u = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $u->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Middle ' . $i,
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now()->subDays(5),
                'date'     => now()->subDays(5),
                'source'   => 'Platform',
            ]);
        }

        $newestUser = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $newestUser->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: New',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result);
        $this->assertEquals((int) $newestUser->id, $result['users'][0], 'most-recent sender must come first');
        $this->assertEquals((int) $oldestUser->id, end($result['users']), 'oldest sender must come last');
    }

    public function test_ip_abuse_group_branch_embeds_group_ids_in_reason(): void
    {
        $ip = '192.168.' . rand(0, 255) . '.' . rand(0, 255);
        $user = $this->createTestUser();

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Test item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        $createdGroupIds = [];
        for ($i = 0; $i < 20; $i++) {
            $group = $this->createTestGroup();
            $createdGroupIds[] = (int) $group->id;
            DB::table('messages_groups')->insert([
                'msgid'      => $msgid,
                'groupid'    => $group->id,
                'collection' => 'Pending',
                'arrival'    => now(),
                'deleted'    => 0,
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result);
        $this->assertEquals($ip, $result['ip']);
        $this->assertIsArray($result['groups']);
        $this->assertCount(20, $result['groups']);
        sort($createdGroupIds);
        $returnedSorted = $result['groups'];
        sort($returnedSorted);
        $this->assertEquals($createdGroupIds, $returnedSorted);
        foreach ($result['groups'] as $id) {
            $this->assertIsInt($id, 'group ID must be int, not string');
        }
        $this->assertArrayNotHasKey('users', $result, 'group branch must not include a users array');
    }

    // -------------------------------------------------------------------------
    // checkIpAbuse — spam_whitelist_ips exemption (V1 parity: Spam.php lines 105-114)
    // -------------------------------------------------------------------------

    public function test_ip_abuse_whitelisted_exact_ip_not_flagged(): void
    {
        $ip = '162.158.255.1'; // Representative Cloudflare IP in 162.158.0.0/15 range

        DB::table('spam_whitelist_ips')->insertOrIgnore([
            'ip'      => $ip,
            'comment' => 'Cloudflare egress (test)',
            'date'    => now(),
        ]);

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        // Six more users with the same whitelisted IP
        for ($i = 0; $i < 6; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNull($result, 'IP in spam_whitelist_ips should not trigger IP abuse warning (V1 parity)');

        DB::table('spam_whitelist_ips')->where('ip', $ip)->delete();
    }

    public function test_ip_abuse_whitelisted_cidr_not_flagged(): void
    {
        // 162.158.0.0/15 covers 162.158.0.1 through 162.159.255.254 (Cloudflare CDN range)
        $cidr = '162.158.0.0/15';
        $ipInRange = '162.158.100.50';

        DB::table('spam_whitelist_ips')->insertOrIgnore([
            'ip'      => $cidr,
            'comment' => 'Cloudflare CDN 162.158.0.0/15',
            'date'    => now(),
        ]);

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ipInRange,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ipInRange,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNull($result, 'IP within a whitelisted CIDR range should not trigger IP abuse warning');

        DB::table('spam_whitelist_ips')->where('ip', $cidr)->delete();
    }

    public function test_ip_abuse_non_whitelisted_ip_still_flagged(): void
    {
        // Ensure 192.0.2.x is NOT in the whitelist (RFC 5737 documentation range, never allocated)
        $ip = '192.0.2.1';
        DB::table('spam_whitelist_ips')->where('ip', $ip)->delete();

        $user = $this->createTestUser();
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type'     => 'Offer',
            'subject'  => 'OFFER: Item',
            'textbody' => 'Test body',
            'message'  => 'Test body',
            'fromip'   => $ip,
            'arrival'  => now(),
            'date'     => now(),
            'source'   => 'Platform',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $otherUser = $this->createTestUser();
            DB::table('messages')->insert([
                'fromuser' => $otherUser->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Item',
                'textbody' => 'Test body',
                'message'  => 'Test body',
                'fromip'   => $ip,
                'arrival'  => now(),
                'date'     => now(),
                'source'   => 'Platform',
            ]);
        }

        $result = $this->service->checkIpAbuse($msgid);

        $this->assertNotNull($result, 'Non-whitelisted IP used by many users should still be flagged');
        $this->assertEquals('IpAbuse', $result['check']);
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
}
