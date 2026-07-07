<?php

namespace Tests\Feature\Purge;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Tests\TestCase;

class PurgeCommandsTest extends TestCase
{
    public function test_purge_all_command_runs_successfully(): void
    {
        $this->artisan('purge:all')
            ->assertExitCode(0);
    }

    public function test_purge_all_displays_table(): void
    {
        $this->artisan('purge:all')
            ->expectsOutputToContain('Running all purge operations')
            ->expectsOutputToContain('All purge operations complete')
            ->expectsOutputToContain('Total records purged')
            ->assertExitCode(0);
    }

    public function test_purge_chats_command_runs_successfully(): void
    {
        $this->artisan('purge:chats')
            ->assertExitCode(0);
    }

    public function test_purge_chats_displays_stats(): void
    {
        $this->artisan('purge:chats')
            ->expectsOutputToContain('Purging chat data')
            ->expectsOutputToContain('Purging spam chat messages')
            ->expectsOutputToContain('Purging empty chat rooms')
            ->expectsOutputToContain('Purging orphaned chat images')
            ->expectsOutputToContain('Chat purge complete')
            ->assertExitCode(0);
    }

    public function test_purge_chats_with_custom_spam_days(): void
    {
        $this->artisan('purge:chats', ['--spam-days' => 14])
            ->assertExitCode(0);
    }

    public function test_purge_chats_purges_spam_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
            'latestmessage' => now()->subDays(10),
        ]);

        // Create spam message older than threshold.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Spam message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 1,
            'platform' => 1,
        ]);

        $this->artisan('purge:chats', ['--spam-days' => 7])
            ->assertExitCode(0);
    }

    public function test_purge_messages_command_runs_successfully(): void
    {
        $this->artisan('purge:messages')
            ->assertExitCode(0);
    }

    public function test_purge_messages_displays_stats(): void
    {
        $this->artisan('purge:messages')
            ->expectsOutputToContain('Purging message data')
            ->expectsOutputToContain('Purging messages_history')
            ->expectsOutputToContain('Purging pending messages')
            ->expectsOutputToContain('Purging old drafts')
            ->expectsOutputToContain('Purging non-Freegle messages')
            ->expectsOutputToContain('Purging deleted messages')
            ->expectsOutputToContain('Purging stranded messages')
            ->expectsOutputToContain('Purging HTML body')
            ->expectsOutputToContain('Message purge complete')
            ->assertExitCode(0);
    }

    public function test_purge_messages_with_custom_options(): void
    {
        $this->artisan('purge:messages', [
            '--history-days' => 30,
            '--pending-days' => 60,
            '--draft-days' => 45,
            '--deleted-retention' => 5,
        ])
            ->assertExitCode(0);
    }

    public function test_purge_messages_purges_pending(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create pending message older than threshold.
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Old Pending (Location)',
            'textbody' => 'Old pending message.',
            'source' => 'Platform',
            'date' => now()->subDays(100),
            'arrival' => now()->subDays(100),
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(100),
        ]);

        $this->artisan('purge:messages', ['--pending-days' => 90])
            ->assertExitCode(0);
    }

    public function test_purge_messages_purges_deleted(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create deleted message.
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Deleted Item (Location)',
            'textbody' => 'Deleted message.',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
            'deleted' => now()->subDays(3),
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $this->artisan('purge:messages', ['--deleted-retention' => 2])
            ->assertExitCode(0);
    }
}
