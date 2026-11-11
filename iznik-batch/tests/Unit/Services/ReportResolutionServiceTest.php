<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Microaction;
use App\Models\User;
use App\Services\ReportResolutionService;
use App\Services\TakedownService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportResolutionServiceTest extends TestCase
{
    protected ReportResolutionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReportResolutionService(new TakedownService());
    }

    private function report(User $reporter, int $msgid, ?string $msgcategory = 'ShouldntBeHere'): Microaction
    {
        return Microaction::create([
            'actiontype' => 'CheckMessage',
            'userid' => $reporter->id,
            'msgid' => $msgid,
            'result' => 'Reject',
            'msgcategory' => $msgcategory,
            'comments' => 'Reported via the website',
            'version' => 1,
            'score_negative' => 0,
        ]);
    }

    public function test_two_member_reports_reach_quorum_and_take_the_post_down(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);

        $resolved = $this->service->resolvePending();

        $this->assertContains($message->id, $resolved);
        $message->refresh();
        $this->assertNotNull($message->deleted);
    }

    public function test_a_single_member_report_does_not_reach_quorum(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter = $this->createTestUser();

        $this->report($reporter, $message->id);

        $resolved = $this->service->resolvePending();

        $this->assertNotContains($message->id, $resolved);
        $message->refresh();
        $this->assertNull($message->deleted);
    }

    public function test_a_single_moderator_report_is_quorum_on_its_own(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $moderator = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $this->report($moderator, $message->id);

        $resolved = $this->service->resolvePending();

        $this->assertContains($message->id, $resolved);
        $message->refresh();
        $this->assertNotNull($message->deleted);
    }

    public function test_the_posters_own_report_of_their_own_post_does_not_count(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter = $this->createTestUser();

        $this->report($poster, $message->id);
        $this->report($reporter, $message->id);

        $resolved = $this->service->resolvePending();

        $this->assertNotContains($message->id, $resolved);
        $message->refresh();
        $this->assertNull($message->deleted);
    }

    public function test_resolution_tells_the_poster_and_each_reporter(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);

        $this->service->resolvePending();

        $posterRoom = ChatRoom::where('user1', $poster->id)->where('chattype', ChatRoom::TYPE_USER2MOD)->first();
        $this->assertNotNull($posterRoom);
        $this->assertTrue(ChatMessage::where('chatid', $posterRoom->id)->where('refmsgid', $message->id)->exists());

        foreach ([$reporter1, $reporter2] as $reporter) {
            $room = ChatRoom::where('user1', $reporter->id)->where('chattype', ChatRoom::TYPE_USER2MOD)->first();
            $this->assertNotNull($room, "reporter #{$reporter->id} should have a User2Mod room");
            $this->assertTrue(
                ChatMessage::where('chatid', $room->id)->where('refmsgid', $message->id)->exists(),
                "reporter #{$reporter->id} should have been told the outcome"
            );
        }
    }

    public function test_running_resolution_twice_does_not_double_notify(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);

        $this->service->resolvePending();
        $this->service->resolvePending();

        $posterRoom = ChatRoom::where('user1', $poster->id)->where('chattype', ChatRoom::TYPE_USER2MOD)->first();
        $count = ChatMessage::where('chatid', $posterRoom->id)->where('refmsgid', $message->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_an_already_deleted_message_is_left_alone(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster, ['deleted' => now()]);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);

        $resolved = $this->service->resolvePending();

        $this->assertNotContains($message->id, $resolved);
    }

    public function test_a_restored_post_is_not_taken_down_again_by_the_reports_that_took_it_down(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);
        $this->assertContains($message->id, $this->service->resolvePending());

        // The reports were made before the take-down.
        DB::table('microactions')->where('msgid', $message->id)->update(['timestamp' => now()->subMinutes(10)]);

        (new TakedownService())->restore($message->id);
        $chatsBefore = ChatMessage::where('refmsgid', $message->id)->count();

        $resolved = $this->service->resolvePending();

        $this->assertNotContains($message->id, $resolved);
        $message->refresh();
        $this->assertNull($message->deleted, 'the restored post stays live');
        $this->assertEquals($chatsBefore, ChatMessage::where('refmsgid', $message->id)->count(), 'nobody is told again');
    }

    public function test_fresh_reports_after_a_restore_can_take_the_post_down_again(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $reporter1 = $this->createTestUser();
        $reporter2 = $this->createTestUser();
        $reporter3 = $this->createTestUser();
        $reporter4 = $this->createTestUser();

        $this->report($reporter1, $message->id);
        $this->report($reporter2, $message->id);
        $this->service->resolvePending();
        DB::table('microactions')->where('msgid', $message->id)->update(['timestamp' => now()->subMinutes(10)]);
        (new TakedownService())->restore($message->id);

        foreach ([$reporter3, $reporter4] as $reporter) {
            $this->report($reporter, $message->id)->update(['timestamp' => now()->addMinute()]);
        }

        $this->assertContains($message->id, $this->service->resolvePending());
    }

    public function test_banned_and_linked_accounts_do_not_count_as_reporters(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);
        $banned = $this->createTestUser(['banned' => now()]);
        $linked = $this->createTestUser();
        $honest = $this->createTestUser();
        DB::table('users_related')->insert([
            'user1' => min($poster->id, $linked->id),
            'user2' => max($poster->id, $linked->id),
        ]);

        $this->report($banned, $message->id);
        $this->report($linked, $message->id);
        $this->report($honest, $message->id);

        $this->assertNotContains($message->id, $this->service->resolvePending(), 'one honest report is not quorum');

        $second = $this->createTestUser();
        $this->report($second, $message->id);

        $this->assertContains($message->id, $this->service->resolvePending());
    }

    public function test_the_poster_is_told_a_plain_reason_not_their_own_subject(): void
    {
        $poster = $this->createTestUser();
        $message = $this->createTestMessage($poster);

        $this->report($this->createTestUser(), $message->id);
        $this->report($this->createTestUser(), $message->id);
        $this->service->resolvePending();

        $room = ChatRoom::where('user1', $poster->id)->where('chattype', ChatRoom::TYPE_USER2MOD)->first();
        $text = ChatMessage::where('chatid', $room->id)->where('refmsgid', $message->id)->value('message');
        $this->assertStringContainsString('Other members reported that it should not be on Freegle.', $text);
        $this->assertStringNotContainsString('reported by other freeglers as', $text);
    }
}
