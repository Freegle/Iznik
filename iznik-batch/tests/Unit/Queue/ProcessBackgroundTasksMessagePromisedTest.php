<?php

namespace Tests\Unit\Queue;

use App\Console\Commands\Queue\ProcessBackgroundTasksCommand;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The message_promised background task: when an Offer is promised, the other members who replied to
 * it are told once, in their own chat with the giver, through the same channel as the Taken notice.
 */
class ProcessBackgroundTasksMessagePromisedTest extends TestCase
{
    private User $giver;
    private Message $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giver = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->offer = $this->createTestMessage($this->giver, $group, ['availablenow' => 1]);
    }

    protected function tearDown(): void
    {
        DB::table('background_tasks')->delete();
        parent::tearDown();
    }

    /**
     * A member who has replied to the offer: a chat with the giver holding an Interested message.
     *
     * @return array{user: User, room: ChatRoom}
     */
    private function replier(): array
    {
        $user = $this->createTestUser();
        $room = $this->createTestChatRoom($this->giver, $user);
        $this->createTestChatMessage($room, $user, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $this->offer->id,
        ]);

        return ['user' => $user, 'room' => $room];
    }

    private function promiseTo(?User $promisee): void
    {
        DB::table('messages_promises')->updateOrInsert(
            ['msgid' => $this->offer->id, 'userid' => $promisee?->id ?? $this->giver->id],
            []
        );
    }

    private function runTask(?User $promisee): void
    {
        DB::table('background_tasks')->delete();
        DB::table('background_tasks')->insert([
            'task_type' => 'message_promised',
            'data' => json_encode([
                'msgid' => $this->offer->id,
                'userid' => $promisee?->id ?? 0,
                'byuser' => $this->giver->id,
            ]),
            'created_at' => now(),
        ]);

        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])->assertSuccessful();
    }

    private function notices(ChatRoom $room): int
    {
        return ChatMessage::where('chatid', $room->id)
            ->where('type', ChatMessage::TYPE_SYSTEM)
            ->where('refmsgid', $this->offer->id)
            ->where('message', ProcessBackgroundTasksCommand::PROMISED_TO_SOMEONE_ELSE_NOTICE)
            ->count();
    }

    public function test_other_repliers_are_told_and_the_promisee_is_not(): void
    {
        $promisee = $this->replier();
        $other1 = $this->replier();
        $other2 = $this->replier();
        $this->promiseTo($promisee['user']);

        $this->runTask($promisee['user']);

        $this->assertSame(1, $this->notices($other1['room']));
        $this->assertSame(1, $this->notices($other2['room']));
        $this->assertSame(0, $this->notices($promisee['room']));
    }

    public function test_notice_is_written_by_the_giver_and_is_visible_to_the_replier(): void
    {
        $promisee = $this->replier();
        $other = $this->replier();
        $this->promiseTo($promisee['user']);

        $this->runTask($promisee['user']);

        $notice = ChatMessage::where('chatid', $other['room']->id)->where('type', ChatMessage::TYPE_SYSTEM)->first();
        $this->assertNotNull($notice);
        $this->assertSame($this->giver->id, (int) $notice->userid);
        $this->assertSame(
            'This has been promised to someone else for now. If it falls through, the giver may come back to you.',
            $notice->message
        );
        $this->assertTrue($notice->isVisible());
    }

    public function test_nobody_is_told_twice_when_the_post_is_promised_again(): void
    {
        $first = $this->replier();
        $second = $this->replier();
        $third = $this->replier();

        $this->promiseTo($first['user']);
        $this->runTask($first['user']);

        // The promise moves to someone else.
        DB::table('messages_promises')->where('msgid', $this->offer->id)->delete();
        $this->promiseTo($second['user']);
        $this->runTask($second['user']);

        // The second and third members were told by the first promise and are not told again.
        $this->assertSame(1, $this->notices($second['room']));
        $this->assertSame(1, $this->notices($third['room']));
        // The first promisee had not been told, and is told once the post is promised to someone else.
        $this->assertSame(1, $this->notices($first['room']));
    }

    public function test_nothing_is_sent_when_the_promise_has_been_cancelled(): void
    {
        $promisee = $this->replier();
        $other = $this->replier();
        // No messages_promises row: the promise was cancelled before the task ran.

        $this->runTask($promisee['user']);

        $this->assertSame(0, $this->notices($other['room']));
    }

    public function test_nothing_is_sent_while_items_remain_unpromised(): void
    {
        DB::table('messages')->where('id', $this->offer->id)->update(['availablenow' => 3]);
        $promisee = $this->replier();
        $other = $this->replier();
        $this->promiseTo($promisee['user']);

        $this->runTask($promisee['user']);

        $this->assertSame(0, $this->notices($other['room']));
    }

    public function test_nothing_is_sent_once_the_post_has_an_outcome(): void
    {
        $promisee = $this->replier();
        $other = $this->replier();
        $this->promiseTo($promisee['user']);
        DB::table('messages_outcomes')->insert([
            'msgid' => $this->offer->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);

        $this->runTask($promisee['user']);

        $this->assertSame(0, $this->notices($other['room']));
    }

    public function test_a_promise_to_nobody_in_particular_tells_every_replier(): void
    {
        $one = $this->replier();
        $two = $this->replier();
        $this->promiseTo(null);

        $this->runTask(null);

        $this->assertSame(1, $this->notices($one['room']));
        $this->assertSame(1, $this->notices($two['room']));
    }

    public function test_a_wanted_post_is_not_announced(): void
    {
        DB::table('messages')->where('id', $this->offer->id)->update(['type' => Message::TYPE_WANTED]);
        $promisee = $this->replier();
        $other = $this->replier();
        $this->promiseTo($promisee['user']);

        $this->runTask($promisee['user']);

        $this->assertSame(0, $this->notices($other['room']));
    }

    public function test_a_chat_without_a_reply_to_this_post_is_not_told(): void
    {
        $promisee = $this->replier();
        $bystander = $this->createTestUser();
        $room = $this->createTestChatRoom($this->giver, $bystander);
        $this->createTestChatMessage($room, $bystander);
        $this->promiseTo($promisee['user']);

        $this->runTask($promisee['user']);

        $this->assertSame(0, $this->notices($room));
    }
}
