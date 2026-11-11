<?php

namespace Tests\Unit\Services;

use App\Services\UserDataExportService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDataExportServiceTest extends TestCase
{
    protected UserDataExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserDataExportService();
    }

    // --- Basic processing ---

    public function test_marks_export_as_started_and_completed(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNotNull($export->started);
        $this->assertNotNull($export->completed);
        $this->assertNotNull($export->data);
    }

    public function test_skips_already_completed_exports(): void
    {
        $user = $this->createTestUser();

        DB::table('users_exports')->insert([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
            'started' => now(),
            'completed' => now(),
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(0, $count);
    }

    public function test_returns_count_of_processed_exports(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createTestUserEmail($user1);
        $this->createTestUserEmail($user2);

        DB::table('users_exports')->insert([
            ['userid' => $user1->id, 'tag' => 'tag1', 'requested' => now()],
            ['userid' => $user2->id, 'tag' => 'tag2', 'requested' => now()],
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(2, $count);
    }

    public function test_purges_data_from_old_completed_exports(): void
    {
        $user = $this->createTestUser();

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'old-tag',
            'requested' => now()->subDays(10),
            'started' => now()->subDays(10),
            'completed' => now()->subDays(8),
            'data' => 'old export data',
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNull($export->data);
    }

    public function test_does_not_purge_recent_completed_exports(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'recent-tag',
            'requested' => now()->subDays(3),
            'started' => now()->subDays(3),
            'completed' => now()->subDays(2),
            'data' => 'recent export data',
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNotNull($export->data);
    }

    // --- Export data content ---

    public function test_export_contains_basic_user_info(): void
    {
        $user = $this->createTestUser(['fullname' => 'Test Exporter']);
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('basic', $json);
        $this->assertEquals($user->id, $json['basic']['id']);
        $this->assertEquals('Test Exporter', $json['basic']['fullname']);
    }

    public function test_export_contains_the_answers_given_to_microvolunteer_questions(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $prompt = DB::table('ux_prompts')->insertGetId(['kind' => 'open', 'question' => 'Did anything cause you a problem?', 'body' => 'b', 'options' => '[]',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ux_prompt_responses')->insert([
            ['prompt_id' => $prompt, 'userid' => $user->id, 'answer' => json_encode(['text' => 'The photo upload']), 'comment' => null],
            ['prompt_id' => $prompt, 'userid' => $other->id, 'answer' => json_encode(['text' => 'Not mine']), 'comment' => null],
        ]);
        $exportId = DB::table('users_exports')->insertGetId(['userid' => $user->id, 'tag' => 'test-tag', 'requested' => now()]);

        $this->service->processAll();

        $json = json_decode(gzinflate(DB::table('users_exports')->where('id', $exportId)->value('data')), true);
        $this->assertCount(1, $json['question_answers']);
        $this->assertSame('Did anything cause you a problem?', $json['question_answers'][0]['question']);
        $this->assertSame(['text' => 'The photo upload'], $json['question_answers'][0]['answer']);
    }

    public function test_export_contains_emails(): void
    {
        $user = $this->createTestUser();
        $email = $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('emails', $json);
        $this->assertNotEmpty($json['emails']);
        $this->assertContains($email->email, array_column($json['emails'], 'email'));
    }

    public function test_export_contains_addresses_as_text(): void
    {
        $user = $this->createTestUser();

        $postcode = 'ZP' . random_int(10, 99) . ' ' . random_int(1, 9) . 'ZZ';
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => $postcode,
            'type' => 'Postcode',
            'osm_place' => 0,
            'osm_amenity' => 0,
            'osm_shop' => 0,
        ]);
        $street = 'Export Road ' . uniqid();
        $pafId = DB::table('paf_addresses')->insertGetId([
            'postcodeid' => $postcodeId,
            'udprn' => random_int(900000000, 999999999),
            'buildingnumber' => 66,
            'thoroughfaredescriptorid' => DB::table('paf_thoroughfaredescriptor')->insertGetId(['thoroughfaredescriptor' => $street]),
        ]);
        DB::table('users_addresses')->insert(['userid' => $user->id, 'pafid' => $pafId]);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertCount(1, $json['addresses']);
        $this->assertStringContainsString("66 $street", $json['addresses'][0]['singleline']);
        $this->assertStringContainsString($postcode, $json['addresses'][0]['multiline']);
    }

    public function test_rippled_post_appears_once_in_export_not_per_group(): void
    {
        // A post that rippled into other groups must appear ONCE in the SAR export (via its origin
        // group), not once per group - otherwise the export looks like deliberate cross-posting.
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $originGroup = $this->createTestGroup();
        $rippledGroup = $this->createTestGroup();

        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: a single sofa',
            'textbody' => 'A sofa.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
        ]);
        DB::table('messages_groups')->insert([
            ['msgid' => $message->id, 'groupid' => $originGroup->id, 'collection' => 'Approved', 'arrival' => now(), 'rippled_in' => 0],
            ['msgid' => $message->id, 'groupid' => $rippledGroup->id, 'collection' => 'Approved', 'arrival' => now(), 'rippled_in' => 1],
        ]);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id, 'tag' => 'test-tag', 'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $rows = array_values(array_filter($json['messages'] ?? [], fn ($m) => (int) $m['id'] === (int) $message->id));
        $this->assertCount(1, $rows, 'the rippled post appears exactly once');
        $this->assertEquals($originGroup->id, (int) $rows[0]['groupid'], 'and via its origin group, not the rippled copy');
    }

    public function test_export_contains_memberships(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('memberships', $json);
        $this->assertNotEmpty($json['memberships']);
        $this->assertEquals($group->id, $json['memberships'][0]['groupid']);
    }

    public function test_export_contains_the_questions_freegle_asked_the_member_and_how_they_answered(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $poster = $this->createTestUser();
        $freegle = $this->createTestUser();
        $post = $this->createTestMessage($poster, $this->createTestGroup());
        $room = $this->createTestChatRoom($user, $poster);
        $reply = $this->createTestChatMessage($room, $user, ['type' => 'Interested', 'refmsgid' => $post->id]);
        $question = $this->createTestChatMessage($room, $freegle, ['type' => 'Prompt', 'refmsgid' => $post->id, 'message' => '@Test, before we pass your reply on: Do you still want it?']);
        DB::table('chat_prompts')->insert([
            'chatmsgid' => $question->id, 'msgid' => $post->id, 'msgids' => json_encode([$post->id]), 'userid' => $user->id,
            'heldchatmsgid' => $reply->id, 'kind' => 'confirm_reply', 'options' => json_encode([['value' => 'yes'], ['value' => 'no']]),
            'answer' => 'no', 'answered_via' => 'button', 'answered_at' => '2026-10-20 10:05:00', 'created_at' => '2026-10-20 10:00:00', 'expires_at' => '2026-10-23 10:00:00',
        ]);

        // Another member's question is not in it.
        $other = $this->createTestUser();
        $otherRoom = $this->createTestChatRoom($other, $poster);
        $otherReply = $this->createTestChatMessage($otherRoom, $other, ['type' => 'Interested', 'refmsgid' => $post->id]);
        $otherQuestion = $this->createTestChatMessage($otherRoom, $freegle, ['type' => 'Prompt', 'refmsgid' => $post->id]);
        DB::table('chat_prompts')->insert([
            'chatmsgid' => $otherQuestion->id, 'msgid' => $post->id, 'msgids' => json_encode([$post->id]), 'userid' => $other->id,
            'heldchatmsgid' => $otherReply->id, 'kind' => 'confirm_reply', 'options' => '[]', 'created_at' => now(), 'expires_at' => now()->addDays(3),
        ]);

        $exportId = DB::table('users_exports')->insertGetId(['userid' => $user->id, 'tag' => 'test-tag', 'requested' => now()]);

        $this->service->processAll();

        $json = json_decode(gzinflate(DB::table('users_exports')->where('id', $exportId)->value('data')), true);

        $this->assertArrayHasKey('questions', $json);
        $this->assertCount(1, $json['questions'], 'only the questions asked of this member');
        $this->assertEquals('confirm_reply', $json['questions'][0]['kind']);
        $this->assertEquals($post->id, $json['questions'][0]['msgid']);
        $this->assertEquals('no', $json['questions'][0]['answer']);
        $this->assertEquals('button', $json['questions'][0]['answered_via']);
        $this->assertEquals('2026-10-20 10:05:00', $json['questions'][0]['answered_at']);
    }

    // --- Dry-run mode ---

    public function test_dry_run_does_not_write_export_data(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $count = $this->service->processAll(dryRun: true);

        $this->assertEquals(1, $count);

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNull($export->started);
        $this->assertNull($export->completed);
        $this->assertNull($export->data);
    }

    public function test_dry_run_does_not_purge_old_exports(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $oldExportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'old-tag',
            'requested' => now()->subDays(10),
            'completed' => now()->subDays(10),
            'data' => 'old-data',
        ]);

        $this->service->processAll(dryRun: true);

        $export = DB::table('users_exports')->where('id', $oldExportId)->first();
        $this->assertEquals('old-data', $export->data);
    }
}
