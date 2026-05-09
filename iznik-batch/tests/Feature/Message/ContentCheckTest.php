<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\ContentCheckService;
use App\Services\Mail\Incoming\SpamCheckService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentCheckTest extends TestCase
{
    private ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService(new SpamCheckService());
        // Mark any pre-existing unprocessed pending messages so processUnprocessed() only
        // sees rows inserted within this test's transaction.
        DB::table('messages_groups')
            ->where('collection', 'Pending')
            ->whereNull('contentcheck_checked_at')
            ->update(['contentcheck_checked_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // checkWorryWords
    // -------------------------------------------------------------------------

    public function test_worry_word_match_returns_reason(): void
    {
        DB::table('worrywords')->insert(['keyword' => 'testworryword_cc', 'type' => 'Review']);

        $result = $this->service->checkWorryWords('OFFER: testworryword_cc chair', 'A nice chair', null);

        $this->assertNotNull($result);
        $this->assertEquals('WorryWord', $result['check']);
        $this->assertStringContainsString('testworryword_cc', $result['detail']);
    }

    public function test_clean_subject_and_body_returns_null_for_worry_words(): void
    {
        $result = $this->service->checkWorryWords('OFFER: Nice sofa', 'A lovely sofa in great condition', null);

        $this->assertNull($result);
    }

    public function test_blank_worry_word_does_not_match_all_messages(): void
    {
        DB::table('worrywords')->insert(['keyword' => '', 'type' => 'Review']);

        $result = $this->service->checkWorryWords('OFFER: Nice sofa', 'A lovely sofa', null);

        $this->assertNull($result);
    }

    public function test_worry_word_match_is_case_insensitive(): void
    {
        DB::table('worrywords')->insert(['keyword' => 'testworryword_cc2', 'type' => 'Review']);

        $result = $this->service->checkWorryWords('OFFER: TESTWORRYWORD_CC2 item', '', null);

        $this->assertNotNull($result);
    }

    // -------------------------------------------------------------------------
    // checkConcernKeywords
    // -------------------------------------------------------------------------

    public function test_concern_keyword_match_returns_reason(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword'  => 'testconcernkw_cc',
            'category' => 'scam',
            'action'   => 'flag',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testconcernkw_cc item', 'Some text');

        $this->assertNotNull($result);
        $this->assertEquals('ConcernKeyword', $result['check']);
        $this->assertStringContainsString('testconcernkw_cc', $result['detail']);
    }

    public function test_clean_text_returns_null_for_concern_keywords(): void
    {
        $result = $this->service->checkConcernKeywords('OFFER: Nice lamp', 'A lovely lamp');

        $this->assertNull($result);
    }

    public function test_blank_concern_keyword_does_not_match_all_messages(): void
    {
        DB::table('concern_keywords')->insert(['keyword' => '', 'category' => 'test', 'action' => 'flag']);

        $result = $this->service->checkConcernKeywords('OFFER: Nice lamp', 'A lovely lamp');

        $this->assertNull($result);
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

    public function test_vague_item_too_short_returns_reason(): void
    {
        $result = $this->service->checkVagueItem('ab');

        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_specific_item_name_returns_null(): void
    {
        $result = $this->service->checkVagueItem('Oak dining table with four chairs');

        $this->assertNull($result);
    }

    public function test_null_item_name_returns_null(): void
    {
        $result = $this->service->checkVagueItem(null);

        $this->assertNull($result);
    }

    public function test_vague_keyword_as_prefix_returns_reason(): void
    {
        // "stuff " at start of name is vague.
        $result = $this->service->checkVagueItem('stuff from attic');

        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_vague_keyword_embedded_in_word_is_allowed(): void
    {
        // "stuffed" is not vague — it contains "stuff" as substring but is not a keyword match.
        $result = $this->service->checkVagueItem('stuffed bear toy');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkPII — phone numbers
    // -------------------------------------------------------------------------

    public function test_phone_number_in_body_with_restrict_rule_returns_reason(): void
    {
        $group = $this->createTestGroup(['rules' => ['restrictpersonalinfo' => true]]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('PhoneNumber', $result['check']);
    }

    public function test_phone_number_without_restrict_rule_returns_null(): void
    {
        $group = $this->createTestGroup();

        $result = $this->service->checkPII('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNull($result);
    }

    public function test_no_phone_in_body_returns_null(): void
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
        DB::table('worrywords')->insert(['keyword' => 'worrycheck_cc', 'type' => 'Review']);

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
        DB::table('items')->insertOrIgnore(['name' => 'cc_testvague_stuff']);
        $itemId = DB::table('items')->where('name', 'cc_testvague_stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        // Override item name lookup by directly using a known vague item.
        // Re-create with vague item name so checkVagueItem fires.
        DB::table('messages_items')->where('msgid', $msgid)->delete();
        DB::table('items')->insertOrIgnore(['name' => 'stuff']);
        $stuffId = DB::table('items')->where('name', 'stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $stuffId]);

        $reasons = $this->service->checkMessage($msgid, $group->id);

        $checkNames = array_column($reasons, 'check');
        $this->assertContains('Vague', $checkNames);
        $this->assertContains('PhoneNumber', $checkNames);
        $this->assertContains('WorryWord', $checkNames);
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
}
