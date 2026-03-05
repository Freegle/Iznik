<?php

namespace App\Console\Commands\Mail;

use App\Console\Commands\Mail\SendAdminCommand;
use App\Mail\Admin\AdminMail;
use App\Mail\Chat\ChatNotification;
use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Mail\Message\AutoRepostWarning;
use App\Mail\Message\ChaseUp;
use App\Mail\Message\ChaseUpPromised;
use App\Mail\Message\DeadlineReached;
use App\Mail\Welcome\WelcomeMail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:test
                            {type? : Email type (chat:user2user, chat:user2mod, digest, donations:ask, donations:thank, welcome)}
                            {--user= : User ID to generate email for}
                            {--to= : Find user by this email address (used to build email content)}
                            {--send-to= : Deliver email to this address instead (for testing with real user data)}
                            {--from= : Override From header address (for AMP testing - must be registered with Google)}
                            {--chat= : Chat room ID (for chat types)}
                            {--message-type= : Specific chat message type (Default, Interested, Promised, Reneged, Completed, Image, Address, Nudge, Schedule)}
                            {--all-types : Send test emails for all chat message types}
                            {--amp= : Override AMP email setting (on/off, default uses config)}
                            {--as= : For User2Mod chats: "member" or "mod" perspective (default: member)}
                            {--dry-run : Preview email content without sending}
                            {--list : List available email types}';

    /**
     * The console command description.
     */
    protected $description = 'Send test emails without affecting database state';

    /**
     * Available email types.
     */
    protected array $emailTypes = [
        'admin' => 'Generic admin email (with local volunteers)',
        'admin:marketing' => 'Marketing admin email (Little Free Shop template)',
        'chat:user2user' => 'User-to-user chat notification',
        'chat:user2mod' => 'User-to-moderator chat notification',
        'digest' => 'Unified digest email (posts from all communities)',
        'donations:ask' => 'Donation request email',
        'donations:thank' => 'Donation thank you email',
        'welcome' => 'Welcome email for new users',
        'autorepost-warning' => 'Auto-repost warning (Will Repost: subject)',
        'chaseup' => 'Chase-up email (What happened to: subject)',
        'chaseup-promised' => 'Chase-up promised email (promised variant)',
        'deadline-reached' => 'Deadline reached notification',
    ];

    /**
     * Get the AMP override value from --amp option.
     * Returns true for 'on', false for 'off', null for default.
     */
    protected function getAmpOverride(): ?bool
    {
        $ampOption = $this->option('amp');
        if ($ampOption === null) {
            return null;
        }

        return strtolower($ampOption) === 'on';
    }

    /**
     * Chat message types to test with --all-types.
     */
    protected array $chatMessageTypes = [
        ChatMessage::TYPE_DEFAULT,
        ChatMessage::TYPE_INTERESTED,
        ChatMessage::TYPE_PROMISED,
        ChatMessage::TYPE_RENEGED,
        ChatMessage::TYPE_COMPLETED,
        ChatMessage::TYPE_IMAGE,
        ChatMessage::TYPE_ADDRESS,
        ChatMessage::TYPE_NUDGE,
        ChatMessage::TYPE_REMINDER,
        ChatMessage::TYPE_REPORTEDUSER,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('list')) {
            $this->listEmailTypes();

            return Command::SUCCESS;
        }

        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');
        $allTypes = $this->option('all-types');

        if (! $type) {
            $this->error('Email type is required. Use --list to see available types.');

            return Command::FAILURE;
        }

        if (! isset($this->emailTypes[$type])) {
            $this->error("Unknown email type: {$type}");
            $this->listEmailTypes();

            return Command::FAILURE;
        }

        // Handle --all-types for chat notifications.
        if ($allTypes && str_starts_with($type, 'chat:')) {
            return $this->sendAllChatMessageTypes($type, $dryRun);
        }

        // Wrap everything in a transaction that we'll roll back.
        // This ensures no database state is modified.
        DB::beginTransaction();

        try {
            $mailable = $this->buildMailable($type);

            if (! $mailable) {
                DB::rollBack();

                return Command::FAILURE;
            }

            // Override From header if specified.
            // This sets the From header (visible to recipient), not the envelope from (bounce address).
            // Google AMP validation checks the From header domain against their allowlist.
            $fromOverride = $this->option('from');
            if ($fromOverride) {
                $mailable->from($fromOverride);
                $this->info("From header overridden to: {$fromOverride}");
            }

            if ($dryRun) {
                $this->previewEmail($mailable);
            } else {
                // Use the spooler to send like production does.
                // Call render() first to trigger build() which sets the 'to' address.
                $mailable->render();

                $spooler = app(EmailSpoolerService::class);

                // Use --send-to if provided, otherwise use the mailable's to address.
                $sendToOverride = $this->option('send-to');
                if ($sendToOverride) {
                    // Clear the mailable's existing recipients and set the override.
                    // This ensures the spooler uses our override address, not the original.
                    $mailable->to = [];
                    $mailable->to($sendToOverride);
                    $to = [$sendToOverride];
                    $this->info("Delivering to override address: {$sendToOverride}");
                } else {
                    $to = collect($mailable->to)->pluck('address')->toArray();
                }

                $spoolId = $spooler->spool($mailable, $to, class_basename($mailable));

                // Process the spooled email immediately.
                $stats = $spooler->processSpool(1);

                if ($stats['sent'] > 0) {
                    $this->info("Test email sent successfully! (spool ID: {$spoolId})");
                } else {
                    $this->warn('Email spooled but not sent yet. Check spool directory.');
                }
            }
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            DB::rollBack();

            return Command::FAILURE;
        }

        // Always roll back - we never want to persist any changes.
        DB::rollBack();

        return Command::SUCCESS;
    }

    /**
     * Send test emails for all chat message types.
     */
    protected function sendAllChatMessageTypes(string $type, bool $dryRun): int
    {
        $chatType = match ($type) {
            'chat:user2user' => ChatRoom::TYPE_USER2USER,
            'chat:user2mod' => ChatRoom::TYPE_USER2MOD,
            default => ChatRoom::TYPE_USER2USER,
        };

        $toEmail = $this->option('to');
        if (! $toEmail) {
            $this->error('Please specify --to=email to find chat messages for that user');

            return Command::FAILURE;
        }

        // Find the user by email.
        $recipient = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (! $recipient) {
            $this->error("No user found with email: {$toEmail}");

            return Command::FAILURE;
        }

        $this->info("Found user: {$recipient->displayname} (ID: {$recipient->id})");
        $this->newLine();

        $sentCount = 0;
        $skippedTypes = [];

        foreach ($this->chatMessageTypes as $messageType) {
            $this->info("=== Testing message type: {$messageType} ===");

            DB::beginTransaction();

            try {
                $mailable = $this->buildChatNotificationForType($chatType, $recipient, $messageType);

                if (! $mailable) {
                    $skippedTypes[] = $messageType;
                    $this->warn("Skipped {$messageType} - no messages found");
                    DB::rollBack();
                    $this->newLine();

                    continue;
                }

                // Override From header if specified.
                $fromOverride = $this->option('from');
                if ($fromOverride) {
                    $mailable->from($fromOverride);
                }

                if ($dryRun) {
                    $this->previewEmail($mailable);
                } else {
                    $mailable->render();

                    $spooler = app(EmailSpoolerService::class);

                    // Use --send-to if provided, otherwise use the mailable's to address.
                    $sendToOverride = $this->option('send-to');
                    if ($sendToOverride) {
                        // Clear the mailable's existing recipients and set the override.
                        $mailable->to = [];
                        $mailable->to($sendToOverride);
                        $to = [$sendToOverride];
                    } else {
                        $to = collect($mailable->to)->pluck('address')->toArray();
                    }

                    $spoolId = $spooler->spool($mailable, $to, class_basename($mailable));

                    $stats = $spooler->processSpool(1);

                    if ($stats['sent'] > 0) {
                        $this->info("Sent {$messageType} notification (spool ID: {$spoolId})");
                        $sentCount++;
                    } else {
                        $this->warn('Spooled but not sent yet');
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error for {$messageType}: ".$e->getMessage());
                $skippedTypes[] = $messageType;
            }

            DB::rollBack();
            $this->newLine();

            // Small delay between sends to avoid overwhelming the mail server.
            if (! $dryRun) {
                usleep(500000); // 0.5 second
            }
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Sent: {$sentCount} emails");

        if (! empty($skippedTypes)) {
            $this->warn('Skipped types (no messages found): '.implode(', ', $skippedTypes));
        }

        return Command::SUCCESS;
    }

    /**
     * List available email types.
     */
    protected function listEmailTypes(): void
    {
        $this->info('Available email types:');
        $this->newLine();

        $rows = [];
        foreach ($this->emailTypes as $type => $description) {
            $rows[] = [$type, $description];
        }

        $this->table(['Type', 'Description'], $rows);

        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan mail:test chat:user2user --user=12345 --to=test@example.com');
        $this->line('  php artisan mail:test chat:user2user --to=test@example.com --amp=on');
        $this->line('  php artisan mail:test chat:user2user --to=test@example.com --amp=off');
        $this->line('  php artisan mail:test chat:user2user --to=realuser@example.com --send-to=mytest@example.com');
        $this->line('  php artisan mail:test digest --to=test@example.com');
        $this->line('  php artisan mail:test donations:ask --user=12345 --dry-run');
    }

    /**
     * Build the mailable for the specified type.
     */
    protected function buildMailable(string $type): ?\Illuminate\Mail\Mailable
    {
        return match ($type) {
            'admin' => $this->buildAdmin(FALSE),
            'admin:marketing' => $this->buildAdmin(TRUE),
            'chat:user2user' => $this->buildChatNotification(ChatRoom::TYPE_USER2USER),
            'chat:user2mod' => $this->buildChatNotification(ChatRoom::TYPE_USER2MOD),
            'digest' => $this->buildDigest(),
            'donations:ask' => $this->buildDonationAsk(),
            'donations:thank' => $this->buildDonationThank(),
            'welcome' => $this->buildWelcome(),
            'autorepost-warning' => $this->buildAutoRepostWarning(),
            'chaseup' => $this->buildChaseUp(false),
            'chaseup-promised' => $this->buildChaseUp(true),
            'deadline-reached' => $this->buildDeadlineReached(),
            default => null,
        };
    }

    /**
     * Build an admin email (generic or marketing).
     */
    protected function buildAdmin(bool $marketing): ?AdminMail
    {
        $toEmail = $this->option('to');

        if (!$toEmail) {
            $this->error('Please specify --to=email to find a user');

            return null;
        }

        $user = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (!$user) {
            $this->error("No user found with email: {$toEmail}");

            return null;
        }

        $this->info("Found user: {$user->displayname} (ID: {$user->id})");

        // Find a group the user is on, for realistic volunteer data.
        $membership = DB::table('memberships')->where('userid', $user->id)->first();
        $group = $membership ? Group::find($membership->groupid) : Group::where('type', Group::TYPE_FREEGLE)->first();

        $groupName = $group ? ($group->namefull ?: $group->nameshort) : 'Test Freegle Group';
        $groupShort = $group->nameshort ?? 'TestGroup';
        $modsEmail = "{$groupShort}-volunteers@groups.ilovefreegle.org";

        // Get real local volunteers for the group.
        $volunteers = $group ? SendAdminCommand::getLocalVolunteers($group->id) : [];
        $this->info("Found " . count($volunteers) . " local volunteer(s) for {$groupName}");

        // Build a realistic admin record.
        $admin = [
            'id' => 0,
            'groupid' => $group->id ?? 0,
            'subject' => $marketing
                ? 'Could you help us start a Little Free Shop?'
                : 'Test admin email from ' . $groupName,
            'text' => $marketing
                ? "Dear \$membername,\n\nImagine a place in your neighbourhood where anyone can drop off things they no longer need — and anyone can pick up what they do.\n\nThat's the idea behind the Little Free Shop: a simple, community-run space that makes reuse easy and accessible for everyone.\n\nWe'd love to pilot this in a few areas across the UK, and your donation could help make it happen."
                : "Hello \$membername,\n\nThis is a test admin email for \$groupname.\n\nYou can contact your local volunteers at \$owneremail.\n\nThank you for freegling!",
            'ctatext' => $marketing ? 'Donate now' : 'Visit Freegle',
            'ctalink' => $marketing
                ? 'https://www.ilovefreegle.org/donate'
                : 'https://www.ilovefreegle.org',
            'essential' => FALSE,
            'parentid' => null,
            'template' => $marketing ? 'little-free-shop-2026' : null,
        ];

        // Apply variable substitution like the real send does.
        $admin['text'] = str_replace(
            ['$groupname', '$owneremail', '$membername', '$memberid'],
            [$groupName, $modsEmail, $user->displayname ?? '', (string) $user->id],
            $admin['text']
        );

        return new AdminMail($user, $admin, $groupName, $modsEmail, $groupShort, $volunteers);
    }

    /**
     * Build a chat notification email.
     */
    protected function buildChatNotification(string $chatType): ?ChatNotification
    {
        $toEmail = $this->option('to');
        $chatId = $this->option('chat');
        $messageType = $this->option('message-type');

        // Find recipient by email.
        if (! $toEmail) {
            $this->error('Please specify --to=email to find chat messages for that user');

            return null;
        }

        // Find the user by email.
        $recipient = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (! $recipient) {
            $this->error("No user found with email: {$toEmail}");

            return null;
        }

        $this->info("Found user: {$recipient->displayname} (ID: {$recipient->id})");

        // For User2Mod, check the --as option to determine perspective.
        $perspective = $this->option('as');
        if ($chatType === ChatRoom::TYPE_USER2MOD && $perspective) {
            if (! in_array($perspective, ['member', 'mod'])) {
                $this->error("Invalid --as option: {$perspective}. Use 'member' or 'mod'.");

                return null;
            }
            $this->info("Testing as: {$perspective}");
        }

        // If a specific message type is requested, use that.
        if ($messageType) {
            return $this->buildChatNotificationForType($chatType, $recipient, $messageType);
        }

        // Find a chat room for this user.
        $chatRoomQuery = ChatRoom::where('chattype', $chatType);

        if ($chatType === ChatRoom::TYPE_USER2MOD && $perspective === 'mod') {
            // For mod perspective, find a chat where user is NOT user1 (they're a mod).
            // User2Mod chats have user1 as the member, mods are found via group membership.
            // We need to find a chat where the user is a mod of the group.
            $chatRoomQuery->where('user1', '!=', $recipient->id)
                ->whereHas('group', function ($q) use ($recipient) {
                    $q->whereHas('memberships', function ($mq) use ($recipient) {
                        $mq->where('userid', $recipient->id)
                            ->whereIn('role', ['Moderator', 'Owner']);
                    });
                });
        } else {
            // Default: find chat where user is a participant.
            $chatRoomQuery->where(function ($q) use ($recipient) {
                $q->where('user1', $recipient->id)->orWhere('user2', $recipient->id);
            });
        }

        if ($chatId) {
            $chatRoomQuery->where('id', $chatId);
        }

        $chatRoom = $chatRoomQuery->whereHas('messages', function ($q) use ($recipient) {
            $q->where('userid', '!=', $recipient->id);
        })->orderBy('id', 'desc')->first();

        if (! $chatRoom) {
            // Fall back to any chat of this type that has messages from multiple users
            // (so we can find a message from someone other than user1).
            $this->warn("No {$chatType} chat found for user {$recipient->id}, searching all chats...");

            $chatRoom = ChatRoom::where('chattype', $chatType)
                ->whereHas('messages', function ($q) {
                    $q->select('chatid')
                        ->groupBy('chatid')
                        ->havingRaw('COUNT(DISTINCT userid) > 1');
                })
                ->orderBy('id', 'desc')
                ->first();

            if (! $chatRoom) {
                $this->error("No {$chatType} chat with messages from multiple users found in the system");

                return null;
            }

            // Override recipient to user1 (the member in User2Mod chats).
            $recipientId = $chatRoom->user1;
            $newRecipient = $recipientId ? User::find($recipientId) : null;
            if ($newRecipient) {
                $recipient = $newRecipient;
                $this->info("Using fallback user: {$recipient->displayname} (ID: {$recipient->id})");
            }
        }

        $this->info("Using chat room: {$chatRoom->id}");

        // Get the latest message from someone other than the recipient (this triggers the notification).
        $latestMessage = ChatMessage::where('chatid', $chatRoom->id)
            ->where('userid', '!=', $recipient->id)
            ->orderBy('id', 'desc')
            ->with(['user', 'refMessage'])
            ->first();

        if (! $latestMessage) {
            $this->error('No messages from other users found in this chat');

            return null;
        }

        // Get sender from the latest message author (for User2Mod, user2 is NULL).
        $sender = $latestMessage->user;

        // Get ALL previous messages in the chat (including from recipient).
        // This matches real behavior where the email shows the conversation thread.
        $previousMessages = ChatMessage::where('chatid', $chatRoom->id)
            ->where('id', '<', $latestMessage->id)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse();

        $this->info('Found latest message from '.($sender?->displayname ?? 'unknown').', plus '.$previousMessages->count().' previous messages');

        $mailable = new ChatNotification($recipient, $sender, $chatRoom, $latestMessage, $chatType, $previousMessages);

        // Apply AMP override if specified.
        $ampOverride = $this->getAmpOverride();
        if ($ampOverride !== null) {
            $mailable->setAmpOverride($ampOverride);
            $this->info('AMP email: '.($ampOverride ? 'ON' : 'OFF').' (override)');
        }

        return $mailable;
    }

    /**
     * Build a chat notification for a specific message type.
     */
    protected function buildChatNotificationForType(string $chatType, User $recipient, string $messageType): ?ChatNotification
    {
        $this->info("Looking for {$messageType} messages...");

        // Get recipient's chat room IDs first (fast query).
        $recipientChatIds = ChatRoom::where('chattype', $chatType)
            ->where(function ($q) use ($recipient) {
                $q->where('user1', $recipient->id)->orWhere('user2', $recipient->id);
            })
            ->pluck('id');

        // Find a message of this type in recipient's chats.
        $latestMessage = null;
        $chatRoom = null;

        if ($recipientChatIds->isNotEmpty()) {
            $latestMessage = ChatMessage::whereIn('chatid', $recipientChatIds)
                ->where('userid', '!=', $recipient->id)
                ->where('type', $messageType)
                ->orderBy('id', 'desc')
                ->with(['user', 'refMessage', 'chatRoom'])
                ->first();

            if ($latestMessage) {
                $chatRoom = $latestMessage->chatRoom;
            }
        }

        if (! $latestMessage) {
            // Try to find any recent message of this type (limit search for performance).
            // Only search last 30 days and use indexed columns.
            $since = now()->subDays(30);
            $latestMessage = ChatMessage::select('chat_messages.*')
                ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
                ->where('chat_rooms.chattype', $chatType)
                ->where('chat_messages.type', $messageType)
                ->where('chat_messages.date', '>=', $since)
                ->orderBy('chat_messages.id', 'desc')
                ->limit(1)
                ->with(['user', 'refMessage', 'chatRoom'])
                ->first();

            if ($latestMessage) {
                $chatRoom = $latestMessage->chatRoom;
                // Override recipient to be the other user in this chat.
                $recipientId = ($chatRoom->user1 === $latestMessage->userid) ? $chatRoom->user2 : $chatRoom->user1;
                $newRecipient = User::find($recipientId);
                if ($newRecipient) {
                    $recipient = $newRecipient;
                }
                $this->warn("Using chat from different user to find {$messageType} message");
            } else {
                $this->warn("No {$messageType} messages found in any {$chatType} chat");

                return null;
            }
        }

        $this->info("Using chat room: {$chatRoom->id}");
        $this->info("Found {$messageType} message ID: {$latestMessage->id}");

        // Get sender (the other user).
        $senderId = ($chatRoom->user1 === $recipient->id) ? $chatRoom->user2 : $chatRoom->user1;
        $sender = $senderId ? User::find($senderId) : null;

        // Get some previous messages for context.
        $previousMessages = ChatMessage::where('chatid', $chatRoom->id)
            ->where('id', '<', $latestMessage->id)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse();

        $mailable = new ChatNotification($recipient, $sender, $chatRoom, $latestMessage, $chatType, $previousMessages);

        // Apply AMP override if specified.
        $ampOverride = $this->getAmpOverride();
        if ($ampOverride !== null) {
            $mailable->setAmpOverride($ampOverride);
        }

        return $mailable;
    }

    /**
     * Build a unified digest email.
     */
    protected function buildDigest(): ?UnifiedDigest
    {
        $userId = $this->option('user');
        $toEmail = $this->option('to');

        // Get user.
        if ($toEmail) {
            $user = User::whereHas('emails', function ($q) use ($toEmail) {
                $q->where('email', $toEmail);
            })->first();
        } elseif ($userId) {
            $user = User::find($userId);
        } else {
            $user = User::whereHas('emails')
                ->whereHas('memberships', fn ($q) => $q->where('collection', Membership::COLLECTION_APPROVED))
                ->inRandomOrder()->first();
        }

        if (! $user) {
            $this->error('User not found');

            return null;
        }

        $this->info("Generating unified digest for user: {$user->displayname} (ID: {$user->id})");

        // Get the user's group IDs.
        $groupIds = $user->memberships()
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->pluck('groupid');

        if ($groupIds->isEmpty()) {
            $this->error("User {$user->id} has no approved group memberships");

            return null;
        }

        $this->info('User is a member of ' . $groupIds->count() . ' groups');

        // Get recent messages from those groups.
        $posts = Message::select('messages.*', 'messages_groups.groupid', 'messages_groups.arrival')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->whereIn('messages_groups.groupid', $groupIds)
            ->where('messages_groups.collection', 'Approved')
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages.fromuser', '!=', $user->id)
            ->orderBy('messages_groups.arrival', 'desc')
            ->limit(20)
            ->with(['attachments', 'fromUser', 'groups'])
            ->get();

        if ($posts->isEmpty()) {
            $this->error('No recent messages found in user\'s groups');

            return null;
        }

        $this->info("Found {$posts->count()} recent messages");

        // Deduplicate using the service.
        $service = app(UnifiedDigestService::class);
        $deduplicatedPosts = $service->deduplicatePosts($posts);

        $this->info("After deduplication: {$deduplicatedPosts->count()} unique posts");

        return new UnifiedDigest($user, $deduplicatedPosts, UnifiedDigestService::MODE_DAILY);
    }

    /**
     * Build a donation ask email.
     */
    protected function buildDonationAsk(): ?AskForDonation
    {
        $userId = $this->option('user');

        $user = $this->findUserWithEmail($userId);
        if (! $user) {
            return null;
        }

        $this->info("Generating donation ask for user: {$user->displayname} (ID: {$user->id})");

        // Try to find a recent message from this user to reference.
        $recentMessage = Message::where('fromuser', $user->id)
            ->orderBy('arrival', 'desc')
            ->first();

        $itemSubject = $recentMessage?->subject;

        return new AskForDonation($user, $itemSubject);
    }

    /**
     * Build a donation thank you email.
     */
    protected function buildDonationThank(): ?DonationThankYou
    {
        $userId = $this->option('user');

        $user = $this->findUserWithEmail($userId);
        if (! $user) {
            return null;
        }

        $this->info("Generating donation thank you for user: {$user->displayname} (ID: {$user->id})");

        return new DonationThankYou($user);
    }

    /**
     * Build a welcome email.
     */
    protected function buildWelcome(): ?WelcomeMail
    {
        $userId = $this->option('user');

        $user = $this->findUserWithEmail($userId);
        if (! $user) {
            return null;
        }

        $this->info("Generating welcome email for user: {$user->displayname} (ID: {$user->id})");

        // Check if user has location for nearby offers.
        $location = $user->lastLocation;
        if ($location) {
            $this->info("User location: {$location->name} ({$location->lat}, {$location->lng})");
        } else {
            $this->warn('User has no location set - nearby offers will not be shown');
        }

        // WelcomeMail takes email, optional password, and user ID for nearby offers.
        return new WelcomeMail($user->email_preferred, 'test-password-123', $user->id);
    }

    /**
     * Build an auto-repost warning email.
     */
    protected function buildAutoRepostWarning(): ?AutoRepostWarning
    {
        [$user, $message, $group] = $this->findUserMessageGroup();
        if (!$user) {
            return null;
        }

        $this->info("Generating autorepost warning for: {$message->subject}");

        return new AutoRepostWarning(
            messageId: $message->id,
            messageSubject: $message->subject ?? 'Test item',
            messageType: $message->type ?? Message::TYPE_OFFER,
            userId: $user->id,
            userName: $user->displayname,
            userEmail: $user->email_preferred,
            groupId: $group->id,
        );
    }

    /**
     * Build a chase-up email (normal or promised variant).
     */
    protected function buildChaseUp(bool $promised): ChaseUp|ChaseUpPromised|null
    {
        [$user, $message, $group] = $this->findUserMessageGroup();
        if (!$user) {
            return null;
        }

        $variant = $promised ? 'promised' : 'normal';
        $this->info("Generating chase-up ({$variant}) for: {$message->subject}");

        if ($promised) {
            return new ChaseUpPromised(
                messageId: $message->id,
                messageSubject: $message->subject ?? 'Test item',
                messageType: $message->type ?? Message::TYPE_OFFER,
                userId: $user->id,
                userName: $user->displayname,
                userEmail: $user->email_preferred,
                groupId: $group->id,
            );
        }

        return new ChaseUp(
            messageId: $message->id,
            messageSubject: $message->subject ?? 'Test item',
            messageType: $message->type ?? Message::TYPE_OFFER,
            userId: $user->id,
            userName: $user->displayname,
            userEmail: $user->email_preferred,
            groupId: $group->id,
        );
    }

    /**
     * Build a deadline-reached email.
     */
    protected function buildDeadlineReached(): ?DeadlineReached
    {
        [$user, $message, $group] = $this->findUserMessageGroup();
        if (!$user) {
            return null;
        }

        $this->info("Generating deadline-reached for: {$message->subject}");

        return new DeadlineReached($message, $user);
    }

    /**
     * Find a user, message, and group for message-related test emails.
     */
    protected function findUserMessageGroup(): array
    {
        $toEmail = $this->option('to');

        if (!$toEmail) {
            $this->error('Please specify --to=email to find a user');

            return [null, null, null];
        }

        $user = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (!$user) {
            $this->error("No user found with email: {$toEmail}");

            return [null, null, null];
        }

        $this->info("Found user: {$user->displayname} (ID: {$user->id})");

        // Find a recent message from this user.
        $message = Message::where('fromuser', $user->id)
            ->whereIn('type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->orderBy('arrival', 'desc')
            ->first();

        if (!$message) {
            $this->error("No messages found for user {$user->id}");

            return [null, null, null];
        }

        // Get a group the message is on.
        $group = $message->groups->first();
        if (!$group) {
            $membership = DB::table('memberships')->where('userid', $user->id)->first();
            $group = $membership ? Group::find($membership->groupid) : null;
        }

        if (!$group) {
            $this->error('No group found for message or user');

            return [null, null, null];
        }

        return [$user, $message, $group];
    }

    /**
     * Find a user with a valid external email address.
     * Some users only have internal emails (e.g. @users.ilovefreegle.org) which
     * means email_preferred returns null and mailables can't be constructed.
     */
    protected function findUserWithEmail(?string $userId): ?User
    {
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $this->info("Found user: {$user->displayname} (ID: {$user->id})");
            } else {
                $this->error("User not found: {$userId}");
            }

            return $user;
        }

        // Try up to 10 random users to find one with a valid email_preferred.
        for ($i = 0; $i < 10; $i++) {
            $user = User::whereHas('emails')->inRandomOrder()->first();
            if ($user && $user->email_preferred) {
                $this->info("Found user: {$user->displayname} (ID: {$user->id})");

                return $user;
            }
        }

        $this->error('Could not find a user with a valid external email address');

        return null;
    }

    /**
     * Preview email content without sending.
     */
    protected function previewEmail(\Illuminate\Mail\Mailable $mailable): void
    {
        $this->newLine();
        $this->info('=== EMAIL PREVIEW ===');
        $this->newLine();

        // Get to address.
        $to = collect($mailable->to)->map(fn ($r) => $r['address'] ?? $r)->implode(', ');
        $this->line("To: {$to}");

        // Get subject.
        if (method_exists($mailable, 'envelope')) {
            $envelope = $mailable->envelope();
            $this->line("Subject: {$envelope->subject}");
        } elseif (property_exists($mailable, 'subject')) {
            $this->line("Subject: {$mailable->subject}");
        }

        $this->newLine();
        $this->line('--- Content (HTML) ---');
        $this->newLine();

        try {
            $rendered = $mailable->render();
            // Show a truncated preview.
            if (strlen($rendered) > 2000) {
                $this->line(substr($rendered, 0, 2000)."\n\n... (truncated, ".strlen($rendered).' total characters)');
            } else {
                $this->line($rendered);
            }
        } catch (\Exception $e) {
            $this->error('Could not render email: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('=== END PREVIEW ===');
        $this->newLine();
        $this->comment('Use without --dry-run and with --to=your@email.com to actually send.');
    }
}
