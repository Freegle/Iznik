<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_process_expired_command_runs_successfully(): void
    {
        $this->artisan('messages:process-expired')
            ->assertExitCode(0);
    }

    public function test_process_expired_command_displays_stats(): void
    {
        $this->artisan('messages:process-expired')
            ->expectsOutputToContain('Processing expired messages')
            ->expectsOutputToContain('Deadline expired:')
            ->assertExitCode(0);
    }

    public function test_process_expired_with_spatial_option(): void
    {
        $this->artisan('messages:process-expired', ['--spatial' => true])
            ->expectsOutputToContain('Processing spatial index expiry')
            ->assertExitCode(0);
    }

    public function test_process_expired_with_deadline_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Expired Item (Location)',
            'textbody' => 'Test message.',
            'source' => 'Platform',
            'date' => now()->subDays(40),
            'arrival' => now()->subDays(40),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(40),
        ]);

        // Set message deadline in the past.
        $message->update(['deadline' => now()->subDays(5)]);

        $this->artisan('messages:process-expired')
            ->assertExitCode(0);
    }

    public function test_process_expired_skips_messages_with_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Has Outcome (Location)',
            'textbody' => 'Test message with outcome.',
            'source' => 'Platform',
            'date' => now()->subDays(40),
            'arrival' => now()->subDays(40),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'deadline' => now()->subDays(5),
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(40),
        ]);

        // Create outcome for message.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $this->artisan('messages:process-expired')
            ->assertExitCode(0);
    }
}
