<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Microaction;
use App\Models\User;
use App\Services\ReportResolutionService;
use App\Services\TakedownService;
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
}
