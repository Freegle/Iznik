<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\TrashNothing\Ingestion\ClosedPostOutcome;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A TN post closed on TN before Freegle had it gets the same outcome a member's own
 * Taken/Received/Withdrawn would give it: handleOutcome in iznik-server-go/message/message.go.
 */
class ClosedPostOutcomeTest extends TestCase
{
    private function closedPost(string $type, string $collection): Message
    {
        $user    = $this->createTestUser();
        $group   = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group, ['type' => $type]);
        MessageGroup::where('msgid', $message->id)->update(['collection' => $collection]);

        return $message;
    }

    private function tasks(int $msgId, string $type): int
    {
        return BackgroundTask::where('task_type', $type)->get()
            ->filter(fn ($t) => (int) ($t->data['msgid'] ?? 0) === $msgId)->count();
    }

    public function test_a_satisfied_offer_is_taken_and_stays_pending(): void
    {
        $message = $this->closedPost(Message::TYPE_OFFER, MessageGroup::COLLECTION_PENDING);

        $this->assertSame('taken', (new ClosedPostOutcome())->apply($message->id, 'satisfied'));

        $this->assertSame('Taken', DB::table('messages_outcomes')->where('msgid', $message->id)->value('outcome'));
        $this->assertSame(MessageGroup::COLLECTION_PENDING, MessageGroup::where('msgid', $message->id)->value('collection'),
            'a post pending on its only community keeps its row');
        $this->assertSame(0, (int) MessageGroup::where('msgid', $message->id)->value('deleted'));
        $this->assertSame(1, $this->tasks($message->id, BackgroundTask::TASK_MESSAGE_OUTCOME));
        $this->assertSame(1, $this->tasks($message->id, BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE));
    }

    public function test_a_satisfied_wanted_is_received(): void
    {
        $message = $this->closedPost(Message::TYPE_WANTED, MessageGroup::COLLECTION_APPROVED);

        $this->assertSame('received', (new ClosedPostOutcome())->apply($message->id, 'satisfied'));
        $this->assertSame('Received', DB::table('messages_outcomes')->where('msgid', $message->id)->value('outcome'));
    }

    public function test_withdrawn_while_pending_deletes_the_post_and_gives_no_outcome(): void
    {
        $message = $this->closedPost(Message::TYPE_OFFER, MessageGroup::COLLECTION_PENDING);
        $groupId = MessageGroup::where('msgid', $message->id)->value('groupid');

        $this->assertSame('withdrawn-deleted', (new ClosedPostOutcome())->apply($message->id, 'withdrawn'));

        $row = DB::table('messages')->where('id', $message->id)->first(['deleted', 'messageid']);
        $this->assertNotNull($row->deleted);
        $this->assertNull($row->messageid);
        $this->assertSame(1, (int) MessageGroup::where('msgid', $message->id)->value('deleted'));
        $this->assertFalse(DB::table('messages_outcomes')->where('msgid', $message->id)->exists());
        $this->assertTrue(DB::table('logs')->where('msgid', $message->id)->where('groupid', $groupId)
            ->where('subtype', 'Deleted')->where('text', 'Withdrawn')->exists());
        $this->assertSame(1, $this->tasks($message->id, BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE));
        $this->assertSame(0, $this->tasks($message->id, BackgroundTask::TASK_MESSAGE_OUTCOME));
    }

    public function test_withdrawn_once_approved_is_recorded_as_withdrawn(): void
    {
        $message = $this->closedPost(Message::TYPE_OFFER, MessageGroup::COLLECTION_APPROVED);

        $this->assertSame('withdrawn', (new ClosedPostOutcome())->apply($message->id, 'withdrawn'));

        $this->assertSame('Withdrawn', DB::table('messages_outcomes')->where('msgid', $message->id)->value('outcome'));
        $this->assertNull(DB::table('messages')->where('id', $message->id)->value('deleted'));
    }

    public function test_an_outcome_already_recorded_is_left_alone(): void
    {
        $message = $this->closedPost(Message::TYPE_OFFER, MessageGroup::COLLECTION_APPROVED);
        DB::table('messages_outcomes')->insert(['msgid' => $message->id, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->assertSame('skipped', (new ClosedPostOutcome())->apply($message->id, 'withdrawn'));
        $this->assertSame(1, DB::table('messages_outcomes')->where('msgid', $message->id)->count());
    }

    public function test_an_outcome_with_no_freegle_equivalent_is_skipped(): void
    {
        $message = $this->closedPost(Message::TYPE_OFFER, MessageGroup::COLLECTION_APPROVED);

        $this->assertSame('skipped', (new ClosedPostOutcome())->apply($message->id, 'promised'));
        $this->assertFalse(DB::table('messages_outcomes')->where('msgid', $message->id)->exists());
    }
}
