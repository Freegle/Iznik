<?php

namespace Tests\Unit\Models;

use App\Models\Message;
use App\Models\MessageOutcome;
use Tests\TestCase;

class MessageOutcomeModelTest extends TestCase
{
    public function test_outcome_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $outcome = MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $this->assertDatabaseHas('messages_outcomes', ['id' => $outcome->id]);
    }

    public function test_message_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $outcome = MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $this->assertEquals($message->id, $outcome->message->id);
    }

    public function test_successful_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message1 = $this->createTestMessage($user, $group);
        $message2 = $this->createTestMessage($user, $group);
        $message3 = $this->createTestMessage($user, $group);

        $taken = MessageOutcome::create([
            'msgid' => $message1->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $received = MessageOutcome::create([
            'msgid' => $message2->id,
            'outcome' => MessageOutcome::OUTCOME_RECEIVED,
            'timestamp' => now(),
        ]);

        $withdrawn = MessageOutcome::create([
            'msgid' => $message3->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'timestamp' => now(),
        ]);

        $successful = MessageOutcome::successful()->get();

        $this->assertTrue($successful->contains('id', $taken->id));
        $this->assertTrue($successful->contains('id', $received->id));
        $this->assertFalse($successful->contains('id', $withdrawn->id));
    }

    public function test_expired_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message1 = $this->createTestMessage($user, $group);
        $message2 = $this->createTestMessage($user, $group);

        $expired = MessageOutcome::create([
            'msgid' => $message1->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
            'timestamp' => now(),
        ]);

        $taken = MessageOutcome::create([
            'msgid' => $message2->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $results = MessageOutcome::expired()->get();

        $this->assertTrue($results->contains('id', $expired->id));
        $this->assertFalse($results->contains('id', $taken->id));
    }

    public function test_repost_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message1 = $this->createTestMessage($user, $group);
        $message2 = $this->createTestMessage($user, $group);

        $repost = MessageOutcome::create([
            'msgid' => $message1->id,
            'outcome' => MessageOutcome::OUTCOME_REPOST,
            'timestamp' => now(),
        ]);

        $taken = MessageOutcome::create([
            'msgid' => $message2->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $results = MessageOutcome::repost()->get();

        $this->assertTrue($results->contains('id', $repost->id));
        $this->assertFalse($results->contains('id', $taken->id));
    }

    public function test_withdrawn_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message1 = $this->createTestMessage($user, $group);
        $message2 = $this->createTestMessage($user, $group);

        $withdrawn = MessageOutcome::create([
            'msgid' => $message1->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'timestamp' => now(),
        ]);

        $taken = MessageOutcome::create([
            'msgid' => $message2->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $results = MessageOutcome::withdrawn()->get();

        $this->assertTrue($results->contains('id', $withdrawn->id));
        $this->assertFalse($results->contains('id', $taken->id));
    }

    public function test_is_successful(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message1 = $this->createTestMessage($user, $group);
        $message2 = $this->createTestMessage($user, $group);
        $message3 = $this->createTestMessage($user, $group);

        $taken = MessageOutcome::create([
            'msgid' => $message1->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $received = MessageOutcome::create([
            'msgid' => $message2->id,
            'outcome' => MessageOutcome::OUTCOME_RECEIVED,
            'timestamp' => now(),
        ]);

        $withdrawn = MessageOutcome::create([
            'msgid' => $message3->id,
            'outcome' => MessageOutcome::OUTCOME_WITHDRAWN,
            'timestamp' => now(),
        ]);

        $this->assertTrue($taken->isSuccessful());
        $this->assertTrue($received->isSuccessful());
        $this->assertFalse($withdrawn->isSuccessful());
    }

    public function test_outcome_constants(): void
    {
        $this->assertEquals('Taken', MessageOutcome::OUTCOME_TAKEN);
        $this->assertEquals('Received', MessageOutcome::OUTCOME_RECEIVED);
        $this->assertEquals('Withdrawn', MessageOutcome::OUTCOME_WITHDRAWN);
        $this->assertEquals('Repost', MessageOutcome::OUTCOME_REPOST);
        $this->assertEquals('Expired', MessageOutcome::OUTCOME_EXPIRED);
        $this->assertEquals('Partial', MessageOutcome::OUTCOME_PARTIAL);
    }
}
