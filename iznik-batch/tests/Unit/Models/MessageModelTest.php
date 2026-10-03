<?php

namespace Tests\Unit\Models;

use App\Models\Message;
use App\Models\MessageOutcome;
use Tests\TestCase;

class MessageModelTest extends TestCase
{
    public function test_message_can_be_created(): void
    {
        $user = $this->createTestUser();

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
        ]);

        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_approved_scope(): void
    {
        $user = $this->createTestUser();

        $approved = $this->createTestMessage($user);

        $pending = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Pending (Location)',
            'textbody' => 'Pending message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'collection' => Message::COLLECTION_PENDING,
        ]);

        $messages = Message::approved()->get();

        $this->assertTrue($messages->contains('id', $approved->id));
        $this->assertFalse($messages->contains('id', $pending->id));
    }

    public function test_not_deleted_scope(): void
    {
        $user = $this->createTestUser();

        $active = $this->createTestMessage($user);

        $deleted = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Deleted (Location)',
            'textbody' => 'Deleted message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'deleted' => now(),
        ]);

        $messages = Message::notDeleted()->get();

        $this->assertTrue($messages->contains('id', $active->id));
        $this->assertFalse($messages->contains('id', $deleted->id));
    }

    public function test_with_location_scope(): void
    {
        $user = $this->createTestUser();

        $withLocation = $this->createTestMessage($user, [
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $withoutLocation = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: No Location',
            'textbody' => 'No location',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => null,
            'lng' => null,
        ]);

        $messages = Message::withLocation()->get();

        $this->assertTrue($messages->contains('id', $withLocation->id));
        $this->assertFalse($messages->contains('id', $withoutLocation->id));
    }

    public function test_offers_scope(): void
    {
        $user = $this->createTestUser();

        $offer = $this->createTestMessage($user, [
            'type' => Message::TYPE_OFFER,
        ]);

        $wanted = $this->createTestMessage($user, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Something (Location)',
        ]);

        $messages = Message::offers()->get();

        $this->assertTrue($messages->contains('id', $offer->id));
        $this->assertFalse($messages->contains('id', $wanted->id));
    }

    public function test_wanted_scope(): void
    {
        $user = $this->createTestUser();

        $wanted = $this->createTestMessage($user, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Something (Location)',
        ]);

        $offer = $this->createTestMessage($user, [
            'type' => Message::TYPE_OFFER,
        ]);

        $messages = Message::wanted()->get();

        $this->assertTrue($messages->contains('id', $wanted->id));
        $this->assertFalse($messages->contains('id', $offer->id));
    }

    public function test_deadline_reached_scope(): void
    {
        $user = $this->createTestUser();

        $pastDeadline = $this->createTestMessage($user, [
            'deadline' => now()->subDays(2),
        ]);

        $futureDeadline = $this->createTestMessage($user, [
            'deadline' => now()->addDays(2),
        ]);

        $noDeadline = $this->createTestMessage($user, [
            'deadline' => null,
        ]);

        $messages = Message::deadlineReached()->get();

        $this->assertTrue($messages->contains('id', $pastDeadline->id));
        $this->assertFalse($messages->contains('id', $futureDeadline->id));
        $this->assertFalse($messages->contains('id', $noDeadline->id));
    }

    public function test_recent_scope(): void
    {
        $user = $this->createTestUser();

        $recent = $this->createTestMessage($user, [
            'arrival' => now()->subDays(5),
        ]);

        $old = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Old (Location)',
            'textbody' => 'Old message',
            'source' => 'Platform',
            'date' => now()->subDays(60),
            'arrival' => now()->subDays(60),
        ]);

        $messages = Message::recent(31)->get();

        $this->assertTrue($messages->contains('id', $recent->id));
        $this->assertFalse($messages->contains('id', $old->id));
    }

    public function test_is_offer(): void
    {
        $user = $this->createTestUser();

        $offer = $this->createTestMessage($user, [
            'type' => Message::TYPE_OFFER,
        ]);

        $wanted = $this->createTestMessage($user, [
            'type' => Message::TYPE_WANTED,
        ]);

        $this->assertTrue($offer->isOffer());
        $this->assertFalse($wanted->isOffer());
    }

    public function test_is_wanted(): void
    {
        $user = $this->createTestUser();

        $wanted = $this->createTestMessage($user, [
            'type' => Message::TYPE_WANTED,
        ]);

        $offer = $this->createTestMessage($user, [
            'type' => Message::TYPE_OFFER,
        ]);

        $this->assertTrue($wanted->isWanted());
        $this->assertFalse($offer->isWanted());
    }

    public function test_has_successful_outcome_with_taken(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user);

        $this->assertFalse($message->hasSuccessfulOutcome());

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $message->refresh();

        $this->assertTrue($message->hasSuccessfulOutcome());
    }

    public function test_has_successful_outcome_with_received(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user, [
            'type' => Message::TYPE_WANTED,
        ]);

        $this->assertFalse($message->hasSuccessfulOutcome());

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_RECEIVED,
            'timestamp' => now(),
        ]);

        $message->refresh();

        $this->assertTrue($message->hasSuccessfulOutcome());
    }

    public function test_has_successful_outcome_with_withdrawn(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user);

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_WITHDRAWN,
            'timestamp' => now(),
        ]);

        $message->refresh();

        $this->assertFalse($message->hasSuccessfulOutcome());
    }

    public function test_from_user_relationship(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user);

        $this->assertEquals($user->id, $message->fromUser->id);
    }

    public function test_outcomes_relationship(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user);

        $this->assertEquals(0, $message->outcomes()->count());

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => Message::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $message->refresh();

        $this->assertEquals(1, $message->outcomes()->count());
    }

    public function test_chat_messages_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $message = $this->createTestMessage($user1);

        $room = \App\Models\ChatRoom::create([
            'chattype' => \App\Models\ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        \App\Models\ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Interested!',
            'type' => \App\Models\ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $message->id,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $message->refresh();

        $this->assertEquals(1, $message->chatMessages()->count());
    }

    public function test_outcome_constants(): void
    {
        $this->assertEquals('Taken', Message::OUTCOME_TAKEN);
        $this->assertEquals('Received', Message::OUTCOME_RECEIVED);
        $this->assertEquals('Withdrawn', Message::OUTCOME_WITHDRAWN);
        $this->assertEquals('Expired', Message::OUTCOME_EXPIRED);
    }

    public function test_type_constants(): void
    {
        $this->assertEquals('Offer', Message::TYPE_OFFER);
        $this->assertEquals('Wanted', Message::TYPE_WANTED);
    }

    public function test_attachments_relationship(): void
    {
        $user = $this->createTestUser();

        $message = $this->createTestMessage($user);

        $this->assertEquals(0, $message->attachments()->count());
    }
}
