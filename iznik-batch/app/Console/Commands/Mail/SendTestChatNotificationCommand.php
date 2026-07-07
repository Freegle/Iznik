<?php

namespace App\Console\Commands\Mail;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestChatNotificationCommand extends Command
{
    protected $signature = 'mail:test-chat-notification
        {--chat-id= : Specific chat room ID to use}
        {--to= : Email address to send to (defaults to first message recipient)}
        {--with-previous : Include previous messages for context}
        {--own-message : Simulate notification for your OWN sent message (copy to self)}';

    protected $description = 'Send a test chat notification email for development';

    public function handle(): int
    {
        $chatId = $this->option('chat-id');

        if ($chatId) {
            $chatRoom = ChatRoom::find($chatId);
            if (! $chatRoom) {
                $this->error("Chat room {$chatId} not found.");

                return 1;
            }
        } else {
            // Find a chat room with recent messages.
            $chatRoom = ChatRoom::whereHas('messages', function ($q) {
                $q->where('date', '>=', now()->subDays(30));
            })
                ->where('chattype', ChatRoom::TYPE_USER2USER)
                ->first();

            if (! $chatRoom) {
                $this->error('No chat rooms with recent messages found.');

                return 1;
            }
        }

        $this->info("Using chat room ID: {$chatRoom->id}");

        // Get the users in this chat.
        $user1 = User::find($chatRoom->user1);
        $user2 = User::find($chatRoom->user2);

        if (! $user1 || ! $user2) {
            $this->error('Could not find users for this chat room.');

            return 1;
        }

        // Get the most recent message (production sends one message at a time).
        $latestMessage = ChatMessage::where('chatid', $chatRoom->id)
            ->where('date', '>=', now()->subDays(90))
            ->orderBy('id', 'desc')
            ->with(['user', 'refMessage'])
            ->first();

        if (! $latestMessage) {
            $this->error('No messages found in this chat room.');

            return 1;
        }

        $this->info("Sending notification for message ID: {$latestMessage->id}");

        // Get previous messages if requested (messages before the current one).
        $previousMessages = collect();
        if ($this->option('with-previous')) {
            $previousMessages = ChatMessage::where('chatid', $chatRoom->id)
                ->where('id', '<', $latestMessage->id)
                ->where('date', '>=', now()->subDays(90))
                ->orderBy('id', 'desc')
                ->limit(3)
                ->with(['user', 'refMessage'])
                ->get()
                ->reverse()
                ->values();

            $this->info("Found {$previousMessages->count()} previous message(s) for context.");
        }

        // Determine sender and recipient.
        $sender = $latestMessage->userid === $user1->id ? $user1 : $user2;

        // For --own-message, simulate the notification a user gets for their own sent message.
        // In this case, the recipient IS the sender (they get a copy of their own message).
        if ($this->option('own-message')) {
            $recipient = $sender;
            $this->info('Simulating OWN MESSAGE notification (copy to self).');
        } else {
            // Normal case: recipient is the OTHER user in the chat.
            $recipient = $latestMessage->userid === $user1->id ? $user2 : $user1;
        }

        $toEmail = $this->option('to') ?? $recipient->email_preferred;

        if (! $toEmail) {
            $this->error('Recipient has no email address. Use --to to specify one.');

            return 1;
        }

        // Use display names or generate meaningful ones for clarity.
        $senderDisplayName = $sender->displayname ?: 'User'.$sender->id;
        $recipientDisplayName = $recipient->displayname ?: 'User'.$recipient->id;

        $this->info("Sending test email to: {$toEmail}");
        $this->info("Message sender: {$senderDisplayName}");
        $this->info("Email recipient: {$recipientDisplayName}");
        if ($this->option('own-message')) {
            $this->info("Note: This is a 'copy of your own message' notification.");
        }

        // Create and send the email.
        $mail = new ChatNotification(
            $recipient,
            $sender,
            $chatRoom,
            $latestMessage,
            $chatRoom->chattype,
            $previousMessages
        );

        // Override the recipient email.
        app(\App\Services\EmailSpoolerService::class)->spool($mail, $toEmail);

        $this->info('Test email sent successfully!');
        $this->info('Check Mailpit at http://mailpit.localhost to view it.');

        return 0;
    }
}
