<?php

namespace App\Services\Mail\Incoming;

use App\Mail\Fbl\FblNotification;
use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail as MailFacade;

/**
 * Service for routing incoming email messages.
 *
 * This is the main entry point for processing incoming emails.
 * It determines the appropriate handler based on the envelope address
 * and message content.
 *
 * Routing order:
 * 1. System addresses (digestoff, readreceipt, subscribe, etc.)
 * 2. Spam detection
 * 3. Destination-based routing (group, user, volunteers)
 */
class IncomingMailService
{
    // Maximum age for chat replies (84 days = 12 weeks)
    private const STALE_CHAT_DAYS = 84;

    // Maximum age for message replies (42 days = 6 weeks)
    private const EXPIRED_MESSAGE_DAYS = 42;

    // No constructor dependencies - parsing is done by the command before routing

    private SpamCheckService $spamCheck;

    private StripQuotedService $stripQuoted;

    private BounceService $bounceService;

    /**
     * Context from the last routing decision (group name, user id, etc.).
     * Set during route() and read by controllers for logging.
     */
    private array $lastRoutingContext = [];

    public function __construct(
        ?SpamCheckService $spamCheck = null,
        ?StripQuotedService $stripQuoted = null,
        ?BounceService $bounceService = null
    ) {
        $this->spamCheck = $spamCheck ?? app(SpamCheckService::class);
        $this->stripQuoted = $stripQuoted ?? new StripQuotedService;
        $this->bounceService = $bounceService ?? new BounceService;
    }

    /**
     * Helper to set routing reason and return DROPPED result.
     */
    private function dropped(string $reason, array $extraContext = []): RoutingResult
    {
        $this->lastRoutingContext = array_merge(['routing_reason' => $reason], $extraContext);

        return RoutingResult::DROPPED;
    }

    /**
     * Get context from the last routing decision.
     */
    public function getLastRoutingContext(): array
    {
        return $this->lastRoutingContext;
    }

    /**
     * Route a parsed email to the appropriate handler.
     *
     * @param  ParsedEmail  $email  The parsed email to route
     * @return RoutingResult The routing outcome
     */
    public function route(ParsedEmail $email): RoutingResult
    {
        $this->lastRoutingContext = [];

        Log::debug('Routing incoming email', [
            'envelope_from' => $email->envelopeFrom,
            'envelope_to' => $email->envelopeTo,
            'subject' => $email->subject,
        ]);

        // Phase 1: Check for system addresses
        $systemResult = $this->routeSystemAddress($email);
        if ($systemResult !== null) {
            return $systemResult;
        }

        // Phase 2: Check for chat/message replies BEFORE global bounce/auto-reply filters.
        // We route notify- and replyto- addresses first, with each handler applying its
        // own nuanced bounce/auto-reply logic internally. The global filters must not
        // intercept these or we'll drop legitimate chat replies from TN autoreplies,
        // Nextdoor bounces, etc.
        if ($email->isChatNotificationReply()) {
            return $this->handleChatNotificationReply($email);
        }

        $replyToResult = $this->handleReplyToAddress($email);
        if ($replyToResult !== null) {
            return $replyToResult;
        }

        // Phase 3: Check for bounces
        if ($email->isBounce()) {
            return $this->handleBounce($email);
        }

        // Phase 3b: Human reply to bounce return-path address (issue #40).
        // Non-DSN emails to bounce-{userid}-{timestamp}@ addresses are human replies
        // that replied to Return-Path instead of Reply-To. Send helpful auto-reply.
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';
        if (str_starts_with($localPart, 'bounce-')) {
            return $this->handleHumanReplyToBounceAddress($email);
        }

        // Phase 3c: Reply to digest email (sent from noreply@).
        // Send helpful auto-response explaining how to reply to specific posts.
        if ($email->isDigestReply()) {
            return $this->handleDigestReply($email);
        }

        // Check for known dropped senders (Twitter, etc.)
        if ($this->shouldDropSender($email)) {
            return $this->dropped('Known dropped sender (Twitter, etc.)');
        }

        // Check for auto-replies (OOO, vacation, etc.)
        if ($email->isAutoReply()) {
            Log::debug('Dropping auto-reply message');

            return $this->dropped('Auto-reply (OOO, vacation, etc.)');
        }

        // Check for self-sent messages
        if ($this->isSelfSent($email)) {
            Log::debug('Dropping self-sent message');

            return $this->dropped('Self-sent message');
        }

        // Check if sender is a known spammer - drop unconditionally, matching legacy behavior.
        // Spammers cannot email anyone, including volunteers.
        if ($this->isKnownSpammer($email)) {
            Log::debug('Dropping message from known spammer');

            return $this->dropped('Known spammer');
        }

        // Phase 4: Check for volunteer/auto addresses
        if ($email->isToVolunteers || $email->isToAuto) {
            return $this->handleVolunteersMessage($email);
        }

        // Phase 5: Check for group posts
        if ($email->targetGroupName !== null) {
            return $this->handleGroupPost($email);
        }

        // Phase 6: Direct user mail
        return $this->handleDirectMail($email);
    }

    /**
     * Route system addresses (digestoff, readreceipt, subscribe, etc.)
     */
    private function routeSystemAddress(ParsedEmail $email): ?RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // FBL (Feedback Loop) reports
        if ($localPart === 'fbl') {
            return $this->handleFbl($email);
        }

        // Read receipt
        if (str_starts_with($localPart, 'readreceipt-')) {
            return $this->handleReadReceipt($email);
        }

        // Tryst/handover response
        if (str_starts_with($localPart, 'handover-')) {
            return $this->handleTrystResponse($email);
        }

        // Digest off
        if ($email->isDigestOffCommand()) {
            return $this->handleDigestOff($email);
        }

        // Events off
        if (str_starts_with($localPart, 'eventsoff-')) {
            return $this->handleEventsOff($email);
        }

        // Newsletters off
        if (str_starts_with($localPart, 'newslettersoff-')) {
            return $this->handleNewslettersOff($email);
        }

        // Relevant off
        if (str_starts_with($localPart, 'relevantoff-')) {
            return $this->handleRelevantOff($email);
        }

        // Volunteering off
        if (str_starts_with($localPart, 'volunteeringoff-')) {
            return $this->handleVolunteeringOff($email);
        }

        // Notification mails off
        if (str_starts_with($localPart, 'notificationmailsoff-')) {
            return $this->handleNotificationMailsOff($email);
        }

        // One-click unsubscribe
        if (str_starts_with($localPart, 'unsubscribe-')) {
            return $this->handleOneClickUnsubscribe($email);
        }

        // Subscribe command
        if ($email->isSubscribeCommand()) {
            return $this->handleSubscribe($email);
        }

        // Unsubscribe command
        if ($email->isUnsubscribeCommand()) {
            return $this->handleUnsubscribe($email);
        }

        return null;
    }

    /**
     * Check if sender should be dropped (Twitter, etc.)
     */
    private function shouldDropSender(ParsedEmail $email): bool
    {
        $fromAddress = strtolower($email->fromAddress ?? '');

        // Drop Twitter notifications
        if ($fromAddress === 'info@twitter.com') {
            return true;
        }

        return false;
    }

    /**
     * Check if this is a self-sent message (envelope-from == envelope-to).
     *
     * Sending to yourself isn't a valid path, and is used by spammers.
     * This compares envelope addresses, not header addresses.
     */
    private function isSelfSent(ParsedEmail $email): bool
    {
        $envelopeFrom = strtolower($email->envelopeFrom);
        $envelopeTo = strtolower($email->envelopeTo);

        return $envelopeFrom === $envelopeTo && ! empty($envelopeFrom);
    }

    /**
     * Check if sender is a known spammer.
     */
    private function isKnownSpammer(ParsedEmail $email): bool
    {
        $fromAddress = $email->fromAddress;
        if (empty($fromAddress)) {
            return false;
        }

        // Find user by email
        $userEmail = UserEmail::where('email', $fromAddress)->first();
        if ($userEmail === null) {
            return false;
        }

        // Check spam_users table
        return DB::table('spam_users')
            ->where('userid', $userEmail->userid)
            ->where('collection', 'Spammer')
            ->exists();
    }

    /**
     * Handle FBL (Feedback Loop) reports.
     *
     * FBL reports come from email providers when a user marks an email as spam.
     * We extract the original recipient email address and turn off their emails.
     */
    private function handleFbl(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing FBL report');

        $rawMessage = $email->rawMessage;

        // Extract original recipient from various FBL headers
        $recipientEmail = null;
        if (preg_match('/Original-Rcpt-To:\s*(.+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        } elseif (preg_match('/X-Original-To:\s*([^;]+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        } elseif (preg_match('/X-HmXmrOriginalRecipient:\s*(.+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        }

        if ($recipientEmail) {
            Log::info('FBL report for email', ['email' => $recipientEmail]);

            // Find the user
            $userEmail = UserEmail::where('email', $recipientEmail)->first();
            if ($userEmail) {
                $user = User::find($userEmail->userid);
                if ($user) {
                    // Comprehensive email shutoff matching legacy setSimpleMail(SIMPLE_MAIL_NONE).
                    // Turn off ALL email types, not just digests.

                    // Turn off digests, events, volunteering on all memberships
                    DB::table('memberships')
                        ->where('userid', $user->id)
                        ->update([
                            'emailfrequency' => 0,
                            'eventsallowed' => 0,
                            'volunteeringallowed' => 0,
                        ]);

                    // Turn off relevant and newsletters on users table
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'relevantallowed' => 0,
                            'newslettersallowed' => 0,
                        ]);

                    // Turn off notification emails and engagement in user settings
                    $settings = json_decode($user->settings ?? '{}', TRUE) ?: [];
                    if (! isset($settings['notifications'])) {
                        $settings['notifications'] = [];
                    }
                    $settings['notifications']['email'] = FALSE;
                    $settings['notifications']['emailmine'] = FALSE;
                    $settings['notificationmails'] = FALSE;
                    $settings['engagement'] = FALSE;

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['settings' => json_encode($settings)]);

                    Log::info('Turned off all emails due to FBL', [
                        'user_id' => $user->id,
                        'email' => $recipientEmail,
                    ]);

                    // Send FBL notification email to the user.
                    // Matches legacy User::FBL() - tells user their emails
                    // have been turned off and provides settings/unsubscribe links.
                    $this->sendFblNotificationEmail($user, $recipientEmail);
                }
            }
        } else {
            Log::warning('FBL report could not extract recipient email');
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle read receipts.
     *
     * Updates the chat roster to indicate the user has seen messages.
     * Format: readreceipt-{chatid}-{userid}-{msgid}@users.ilovefreegle.org
     */
    private function handleReadReceipt(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse readreceipt-{chatid}-{userid}-{msgid}
        if (! preg_match('/^readreceipt-(\d+)-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid read receipt address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped('Invalid read receipt address format');
        }

        $chatId = (int) $matches[1];
        $userId = (int) $matches[2];
        $msgId = (int) $matches[3];

        Log::info('Processing read receipt', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $msgId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if chat exists (follow redirects from merged duplicate rooms)
        $chat = ChatRoom::find($chatId);
        if ($chat === null) {
            $redirect = DB::table('chat_room_redirects')->where('old_id', $chatId)->first();
            if ($redirect) {
                $chatId = $redirect->new_id;
                $chat = ChatRoom::find($chatId);
            }
        }
        if ($chat === null) {
            Log::warning('Read receipt for non-existent chat', [
                'chat_id' => $chatId,
            ]);

            return $this->dropped('Read receipt for non-existent chat');
        }

        // Check if user can see the chat
        if (! $this->isUserInChat($userId, $chat)) {
            Log::warning('Read receipt from user not in chat', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            return $this->dropped('Read receipt from user not in chat');
        }

        // Update the chat roster to mark messages as seen
        DB::table('chat_roster')
            ->updateOrInsert(
                ['chatid' => $chatId, 'userid' => $userId],
                ['lastmsgseen' => $msgId, 'date' => now()]
            );

        // For user-to-user chats, mark message as seen by all
        if ($chat->chattype === 'User2User') {
            DB::table('chat_messages')
                ->where('id', $msgId)
                ->where('chatid', $chatId)
                ->update(['seenbyall' => 1]);
        }

        Log::info('Processed read receipt', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $msgId,
        ]);

        return RoutingResult::RECEIPT;
    }

    /**
     * Handle tryst/handover calendar responses.
     *
     * Parses VCALENDAR attachments or subject line to determine accept/decline status.
     * Format: handover-{trystid}-{userid}@users.ilovefreegle.org
     */
    private function handleTrystResponse(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse handover-{trystid}-{userid}
        if (! preg_match('/^handover-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid handover address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid handover address format");
        }

        $trystId = (int) $matches[1];
        $userId = (int) $matches[2];

        Log::info('Processing tryst response', [
            'tryst_id' => $trystId,
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if tryst exists
        $tryst = DB::table('trysts')->find($trystId);
        if ($tryst === null) {
            Log::warning('Tryst response for non-existent tryst', [
                'tryst_id' => $trystId,
            ]);

            return $this->dropped("Tryst response for non-existent tryst");
        }

        // Determine response from email content
        $response = $this->parseTrystResponse($email);

        // Update the tryst response in the correct column based on which user is responding
        $updateColumn = null;
        if ($tryst->user1 == $userId) {
            $updateColumn = 'user1response';
        } elseif ($tryst->user2 == $userId) {
            $updateColumn = 'user2response';
        }

        if ($updateColumn !== null) {
            $oldValue = $tryst->$updateColumn;
            DB::table('trysts')
                ->where('id', $trystId)
                ->update([$updateColumn => $response]);

            Log::info('Updated tryst response', [
                'tryst_id' => $trystId,
                'user_id' => $userId,
                'column' => $updateColumn,
                'old_value' => $oldValue,
                'new_value' => $response,
            ]);
        } else {
            Log::warning('Tryst response from user not in tryst', [
                'tryst_id' => $trystId,
                'user_id' => $userId,
                'user1' => $tryst->user1,
                'user2' => $tryst->user2,
            ]);
        }

        Log::info('Processed tryst response', [
            'tryst_id' => $trystId,
            'user_id' => $userId,
            'response' => $response,
        ]);

        return RoutingResult::TRYST;
    }

    /**
     * Parse tryst response from email content.
     *
     * Checks VCALENDAR content in body or subject line for accepted/declined.
     */
    private function parseTrystResponse(ParsedEmail $email): string
    {
        // Check body for VCALENDAR status
        $body = strtolower($email->textBody ?? '');
        if (str_contains($body, 'status:confirmed') || str_contains($body, 'status:tentative')) {
            return 'Accepted';
        }
        if (str_contains($body, 'status:cancelled')) {
            return 'Declined';
        }

        // Check subject line as fallback
        $subject = strtolower($email->subject ?? '');
        if (str_contains($subject, 'accepted')) {
            return 'Accepted';
        }
        if (str_contains($subject, 'declined')) {
            return 'Declined';
        }

        // Default to Other
        return 'Other';
    }

    /**
     * Handle digest off command.
     *
     * Updates the user's membership to turn off email digests (emailfrequency = 0).
     * Format: digestoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleDigestOff(ParsedEmail $email): RoutingResult
    {
        $userId = $email->commandUserId;
        $groupId = $email->commandGroupId;

        Log::info('Processing digest off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        if (! $userId || ! $groupId) {
            Log::warning('Invalid digest off command - missing user or group ID');

            return $this->dropped("Digest off command missing user or group ID");
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return $this->dropped("User not an approved member for digest off");
        }

        // Set email frequency to 0 (NEVER)
        $oldFrequency = $membership->emailfrequency;
        $membership->emailfrequency = 0;
        $membership->save();

        // Log to logs table (matches legacy Digest::off())
        $group = Group::find($groupId);
        $groupName = $group->namefull ?? $group->nameshort ?? "Group #$groupId";

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'User',
            'subtype' => 'MailOff',
            'user' => $userId,
            'groupid' => $groupId,
        ]);

        // Send confirmation email (matches legacy Digest::off())
        $user = User::find($userId);
        if ($user) {
            $preferredEmail = $this->getPreferredEmail($userId);
            if ($preferredEmail) {
                try {
                    $userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
                    $noreplyAddr = 'noreply@' . $userDomain;
                    $siteName = config('freegle.site_name', 'Freegle');

                    MailFacade::raw(
                        "We've turned your emails off on $groupName.",
                        function ($message) use ($preferredEmail, $user, $noreplyAddr, $siteName, $userId, $userDomain) {
                            $message->to($preferredEmail, $user->fullname ?? $user->firstname ?? '')
                                ->from($noreplyAddr, 'Do Not Reply')
                                ->returnPath("bounce-$userId-" . time() . "@$userDomain")
                                ->subject('Email Change Confirmation');
                        }
                    );

                    Log::info('Sent digest off confirmation email', [
                        'user_id' => $userId,
                        'email' => $preferredEmail,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send digest off confirmation', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Turned off digest for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
            'membership_id' => $membership->id,
            'old_emailfrequency' => $oldFrequency,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle events off command.
     *
     * Updates the user's membership to turn off event emails (eventsallowed = 0).
     * Format: eventsoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleEventsOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse eventsoff-{userid}-{groupid}
        if (! preg_match('/^eventsoff-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid events off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid events off address format");
        }

        $userId = (int) $matches[1];
        $groupId = (int) $matches[2];

        Log::info('Processing events off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return $this->dropped("User not an approved member for events off");
        }

        // Set eventsallowed to 0
        $oldEventsAllowed = $membership->eventsallowed;
        $membership->eventsallowed = 0;
        $membership->save();

        Log::info('Turned off events for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
            'membership_id' => $membership->id,
            'old_eventsallowed' => $oldEventsAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle newsletters off command.
     *
     * Updates the user's settings to turn off newsletters (newslettersallowed = 0).
     * Format: newslettersoff-{userid}@users.ilovefreegle.org
     */
    private function handleNewslettersOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse newslettersoff-{userid}
        if (! preg_match('/^newslettersoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid newsletters off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid newsletters off address format");
        }

        $userId = (int) $matches[1];

        Log::info('Processing newsletters off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for newsletters off', [
                'user_id' => $userId,
            ]);

            return $this->dropped("User not found for newsletters off");
        }

        // Set newslettersallowed to 0
        $oldNewslettersAllowed = $user->newslettersallowed;
        $user->newslettersallowed = 0;
        $user->save();

        Log::info('Turned off newsletters for user', [
            'user_id' => $userId,
            'old_newslettersallowed' => $oldNewslettersAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle relevant off command.
     *
     * Updates the user's settings to turn off "interested in" emails (relevantallowed = 0).
     * Format: relevantoff-{userid}@users.ilovefreegle.org
     */
    private function handleRelevantOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse relevantoff-{userid}
        if (! preg_match('/^relevantoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid relevant off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid relevant off address format");
        }

        $userId = (int) $matches[1];

        Log::info('Processing relevant off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for relevant off', [
                'user_id' => $userId,
            ]);

            return $this->dropped("User not found for relevant off");
        }

        // Set relevantallowed to 0
        $oldRelevantAllowed = $user->relevantallowed;
        $user->relevantallowed = 0;
        $user->save();

        Log::info('Turned off relevant for user', [
            'user_id' => $userId,
            'old_relevantallowed' => $oldRelevantAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle volunteering off command.
     *
     * Updates the user's membership to turn off volunteering emails (volunteeringallowed = 0).
     * Format: volunteeringoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleVolunteeringOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse volunteeringoff-{userid}-{groupid}
        if (! preg_match('/^volunteeringoff-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid volunteering off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid volunteering off address format");
        }

        $userId = (int) $matches[1];
        $groupId = (int) $matches[2];

        Log::info('Processing volunteering off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return $this->dropped("User not an approved member for volunteering off");
        }

        // Set volunteeringallowed to 0
        $oldVolunteeringAllowed = $membership->volunteeringallowed;
        $membership->volunteeringallowed = 0;
        $membership->save();

        Log::info('Turned off volunteering for user', [
            'user_id' => $userId,
            'membership_id' => $membership->id,
            'old_volunteeringallowed' => $oldVolunteeringAllowed,
            'group_id' => $groupId,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle notification mails off command.
     *
     * Updates the user's settings JSON to turn off notification mails.
     * Format: notificationmailsoff-{userid}@users.ilovefreegle.org
     */
    private function handleNotificationMailsOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse notificationmailsoff-{userid}
        if (! preg_match('/^notificationmailsoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid notification mails off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid notification mails off address format");
        }

        $userId = (int) $matches[1];

        Log::info('Processing notification mails off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for notification mails off', [
                'user_id' => $userId,
            ]);

            return $this->dropped("User not found for notification mails off");
        }

        // Get current settings JSON
        $settings = json_decode($user->settings ?? '{}', true) ?: [];

        // Only update if not already off
        if (($settings['notificationmails'] ?? true) === true) {
            $settings['notificationmails'] = false;
            $user->settings = json_encode($settings);
            $user->save();

            Log::info('Turned off notification mails for user', [
                'user_id' => $userId,
                'old_notificationmails' => true,
            ]);
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle one-click unsubscribe (RFC 8058).
     *
     * Puts the user into "limbo" (soft delete) which allows them to recover their account.
     * Format: unsubscribe-{userid}-{key}-{type}@users.ilovefreegle.org
     */
    private function handleOneClickUnsubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse unsubscribe-{userid}-{key}-{type}
        if (! preg_match('/^unsubscribe-(\d+)-([^-]+)-(.+)$/', $localPart, $matches)) {
            Log::warning('Invalid one-click unsubscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid one-click unsubscribe address format");
        }

        $userId = (int) $matches[1];
        $key = $matches[2];
        $type = $matches[3];

        Log::info('Processing one-click unsubscribe', [
            'user_id' => $userId,
            'type' => $type,
        ]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for one-click unsubscribe', [
                'user_id' => $userId,
            ]);

            return $this->dropped("User not found for one-click unsubscribe");
        }

        // Prevent accidental unsubscription by moderators
        if ($this->isUserModerator($userId)) {
            Log::info('Ignoring one-click unsubscribe for moderator', [
                'user_id' => $userId,
            ]);

            return $this->dropped("Ignoring one-click unsubscribe for moderator");
        }

        // Validate the key to prevent spoof unsubscribes
        $storedKey = $this->getUserKey($userId);
        if (! $storedKey || strcasecmp($storedKey, $key) !== 0) {
            Log::warning('Invalid key for one-click unsubscribe', [
                'user_id' => $userId,
            ]);

            return $this->dropped("Invalid key for one-click unsubscribe");
        }

        // Log old value for reversibility.
        $oldDeleted = DB::table('users')->where('id', $userId)->value('deleted');

        // Put user into limbo (soft delete)
        DB::table('users')
            ->where('id', $userId)
            ->update(['deleted' => now()]);

        Log::info('Put user into limbo via one-click unsubscribe', [
            'user_id' => $userId,
            'type' => $type,
            'old_deleted' => $oldDeleted,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Check if user is a moderator on any group.
     */
    private function isUserModerator(int $userId): bool
    {
        return DB::table('memberships')
            ->where('userid', $userId)
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    /**
     * Get the user's stored key for one-click unsubscribe validation.
     */
    private function getUserKey(int $userId): ?string
    {
        $login = DB::table('users_logins')
            ->where('userid', $userId)
            ->where('type', 'Link')
            ->first();

        return $login?->credentials;
    }

    /**
     * Handle subscribe command.
     *
     * Adds the user to the group. If the user doesn't exist, creates them.
     * Format: {groupname}-subscribe@groups.ilovefreegle.org
     */
    private function handleSubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse {groupname}-subscribe
        if (! preg_match('/^(.+)-subscribe$/', $localPart, $matches)) {
            Log::warning('Invalid subscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid subscribe address format");
        }

        $groupName = $matches[1];

        Log::info('Processing subscribe command', [
            'group' => $groupName,
        ]);

        // Find the group
        $group = Group::where('nameshort', $groupName)->first();
        if ($group === null) {
            Log::warning('Subscribe to unknown group', [
                'group' => $groupName,
            ]);

            return $this->dropped("Subscribe to unknown group");
        }

        // Find or create the user
        $envFrom = $email->envelopeFrom;
        $userEmail = UserEmail::where('email', $envFrom)->first();

        if ($userEmail === null) {
            // Create a new user
            $user = User::create([
                'fullname' => $email->fromName,
                'systemrole' => 'User',
                'added' => now(),
                'lastaccess' => now(),
            ]);

            // Add their email
            UserEmail::create([
                'userid' => $user->id,
                'email' => $envFrom,
                'preferred' => 1,
                'added' => now(),
            ]);

            Log::info('Created new user for subscribe', [
                'user_id' => $user->id,
                'email' => $envFrom,
                'created_new' => true,
            ]);
        } else {
            $user = User::find($userEmail->userid);
            if ($user === null) {
                Log::warning('User email exists but user not found', [
                    'email' => $envFrom,
                ]);

                return $this->dropped("User email exists but user not found for subscribe");
            }

            // Update last access
            $user->lastaccess = now();
            $user->save();
        }

        // Check if already a member
        $existingMembership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        if ($existingMembership !== null) {
            Log::info('User is already a member', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::TO_SYSTEM;
        }

        // Add membership
        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => 'Member',
            'collection' => 'Approved',
            'added' => now(),
            'emailfrequency' => 24, // Daily digest by default
        ]);

        Log::info('Added user to group', [
            'user_id' => $user->id,
            'group_id' => $group->id,
            'group_name' => $groupName,
            'membership_id' => $membership->id,
            'created_new' => true,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle unsubscribe command.
     *
     * Removes the user from the group. Moderators/owners are protected from
     * accidental unsubscription.
     * Format: {groupname}-unsubscribe@groups.ilovefreegle.org
     */
    private function handleUnsubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse {groupname}-unsubscribe
        if (! preg_match('/^(.+)-unsubscribe$/', $localPart, $matches)) {
            Log::warning('Invalid unsubscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid unsubscribe address format");
        }

        $groupName = $matches[1];

        Log::info('Processing unsubscribe command', [
            'group' => $groupName,
        ]);

        // Find the group
        $group = Group::where('nameshort', $groupName)->first();
        if ($group === null) {
            Log::warning('Unsubscribe from unknown group', [
                'group' => $groupName,
            ]);

            return $this->dropped("Unsubscribe from unknown group");
        }

        // Find the user by envelope from
        $envFrom = $email->envelopeFrom;
        $userEmail = UserEmail::where('email', $envFrom)->first();

        if ($userEmail === null) {
            Log::warning('Unsubscribe from unknown user', [
                'email' => $envFrom,
            ]);

            return $this->dropped("Unsubscribe from unknown user");
        }

        $user = User::find($userEmail->userid);
        if ($user === null) {
            Log::warning('User email exists but user not found', [
                'email' => $envFrom,
            ]);

            return $this->dropped("User email exists but user not found for unsubscribe");
        }

        // Update last access
        $user->lastaccess = now();
        $user->save();

        // Check if user is a mod or owner of this group - protect them
        $membership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        if ($membership === null) {
            Log::info('User is not a member of group', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return $this->dropped("User not a member of group for unsubscribe");
        }

        if (in_array($membership->role, ['Moderator', 'Owner'])) {
            Log::info('Ignoring unsubscribe for moderator/owner', [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'role' => $membership->role,
            ]);

            return $this->dropped("Ignoring unsubscribe for moderator or owner");
        }

        // Log full membership for reversibility before deletion.
        Log::info('Removing membership (saving state for rollback)', [
            'membership_id' => $membership->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'role' => $membership->role,
            'collection' => $membership->collection,
            'emailfrequency' => $membership->emailfrequency,
            'eventsallowed' => $membership->eventsallowed,
            'volunteeringallowed' => $membership->volunteeringallowed,
            'ourPostingStatus' => $membership->ourPostingStatus,
        ]);

        // Remove membership
        $membership->delete();

        Log::info('Removed user from group', [
            'user_id' => $user->id,
            'group_id' => $group->id,
            'group_name' => $groupName,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle bounce messages.
     *
     * Processes DSN bounce messages by:
     * 1. Parsing the DSN to extract diagnostic code and recipient
     * 2. Classifying as permanent, temporary, or ignored
     * 3. Recording in bounces_emails table
     * 4. Checking if user should be suspended (inline, not via cron)
     *
     * @see BounceService for full implementation details
     */
    private function handleBounce(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing bounce', [
            'recipient' => $email->bounceRecipient,
            'status' => $email->bounceStatus,
            'envelope_to' => $email->envelopeTo,
        ]);

        $result = $this->bounceService->processBounce($email);

        if (! $result['success']) {
            $error = $result['error'] ?? 'unknown';
            Log::warning('Bounce processing failed', [
                'error' => $error,
                'envelope_to' => $email->envelopeTo,
            ]);

            // For unparseable DSNs, only return ERROR if we expected valid DSN data.
            // If the parser already determined there's no bounceRecipient, this might
            // be a human reply that was incorrectly flagged as a bounce. Return TO_SYSTEM
            // to allow the routing to continue to other handlers.
            if ($error === 'unparseable') {
                // Auto-replies (OOO, vacation) sometimes arrive with MAILER-DAEMON
                // envelope-from, causing them to be misclassified as bounces. They
                // have no DSN content so parseDsn() returns null. Treat as TO_SYSTEM.
                if ($email->isAutoReply()) {
                    Log::debug('Auto-reply misclassified as bounce - treating as system message', [
                        'subject' => $email->subject,
                        'from' => $email->fromAddress,
                    ]);

                    return RoutingResult::TO_SYSTEM;
                }

                if ($email->bounceRecipient !== null) {
                    // We had recipient info but couldn't parse - this is a real error
                    $this->lastRoutingContext = [
                        'routing_reason' => 'Bounce parse failed',
                    ];

                    return RoutingResult::ERROR;
                }
                // No recipient info - probably not a real DSN, return TO_SYSTEM quietly
                Log::debug('Bounce without recipient info - treating as non-bounce');
            }
        }

        // Update routing context
        if (isset($result['user_id'])) {
            $this->lastRoutingContext = [
                'user_id' => $result['user_id'],
                'routing_reason' => 'Bounce processed'.($result['suspended'] ?? false ? ' (user suspended)' : ''),
            ];
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle human replies to bounce return-path addresses (issue #40).
     *
     * Some email clients reply to Return-Path instead of Reply-To. When this
     * happens, the reply goes to bounce-{userid}-{timestamp}@users... which is
     * only meant for DSN processing. We send a helpful auto-reply explaining
     * how to reach Freegle properly.
     */
    private function handleHumanReplyToBounceAddress(ParsedEmail $email): RoutingResult
    {
        $senderAddress = strtolower($email->fromAddress ?? $email->envelopeFrom ?? '');

        Log::info('Human reply to bounce address', [
            'from' => $senderAddress,
            'envelope_to' => $email->envelopeTo,
            'subject' => $email->subject,
        ]);

        // Loop prevention: never auto-reply to these senders
        $suppressPatterns = ['mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'bounce-'];
        foreach ($suppressPatterns as $pattern) {
            if (str_contains($senderAddress, $pattern)) {
                Log::debug('Suppressing auto-reply to system address', ['from' => $senderAddress]);

                return RoutingResult::TO_SYSTEM;
            }
        }

        // Loop prevention: never auto-reply to auto-submitted messages
        if ($email->isAutoReply()) {
            Log::debug('Suppressing auto-reply to auto-submitted message');

            return RoutingResult::TO_SYSTEM;
        }

        // Rate limit: max 1 auto-reply per 24h per sender
        $cacheKey = 'bounce_autoreply:'.md5($senderAddress);
        if (Cache::has($cacheKey)) {
            Log::debug('Rate limiting auto-reply', ['from' => $senderAddress]);

            return RoutingResult::TO_SYSTEM;
        }

        // Send auto-reply
        try {
            MailFacade::to($senderAddress)->send(new \App\Mail\BounceAddressAutoReply($senderAddress));
            Cache::put($cacheKey, true, now()->addHours(24));
            Log::info('Sent bounce address auto-reply', ['to' => $senderAddress]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send bounce address auto-reply', [
                'to' => $senderAddress,
                'error' => $e->getMessage(),
            ]);
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle reply to a digest email.
     *
     * Digest emails are sent from noreply@ and contain multiple posts.
     * When someone replies, we send a friendly auto-response explaining
     * how to reply to specific posts via the buttons/links in the email.
     */
    private function handleDigestReply(ParsedEmail $email): RoutingResult
    {
        $senderAddress = strtolower($email->fromAddress ?? $email->envelopeFrom ?? '');

        Log::info('Reply to digest email', [
            'from' => $senderAddress,
            'envelope_to' => $email->envelopeTo,
            'subject' => $email->subject,
        ]);

        // Loop prevention: never auto-reply to system addresses
        $suppressPatterns = ['mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'bounce-'];
        foreach ($suppressPatterns as $pattern) {
            if (str_contains($senderAddress, $pattern)) {
                Log::debug('Suppressing digest reply auto-response to system address', ['from' => $senderAddress]);

                return RoutingResult::TO_SYSTEM;
            }
        }

        // Loop prevention: never auto-reply to auto-submitted messages
        if ($email->isAutoReply()) {
            Log::debug('Suppressing digest reply auto-response to auto-submitted message');

            return RoutingResult::TO_SYSTEM;
        }

        // Rate limit: max 1 auto-reply per 24h per sender
        $cacheKey = 'digest_reply_autoreply:' . md5($senderAddress);
        if (Cache::has($cacheKey)) {
            Log::debug('Rate limiting digest reply auto-response', ['from' => $senderAddress]);

            return RoutingResult::TO_SYSTEM;
        }

        // Look up the sender to get their name and user ID
        $senderName = $email->fromName;
        $senderUserId = null;

        $userEmail = \App\Models\UserEmail::where('email', $senderAddress)->first();
        if ($userEmail) {
            $senderUserId = $userEmail->userid;
        }

        // Send auto-response
        try {
            MailFacade::send(new \App\Mail\Digest\DigestReplyNotice(
                $senderAddress,
                $senderName,
                $senderUserId
            ));
            Cache::put($cacheKey, true, now()->addHours(24));
            Log::info('Sent digest reply auto-response', ['to' => $senderAddress]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send digest reply auto-response', [
                'to' => $senderAddress,
                'error' => $e->getMessage(),
            ]);
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle reply-to addresses (replyto-{msgid}-{fromid}).
     */
    private function handleReplyToAddress(ParsedEmail $email): ?RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        if (! str_starts_with($localPart, 'replyto-')) {
            return null;
        }

        // Check for bounces FIRST - bounces to replyto addresses happen when the
        // original What's New notification email bounces. Process as bounce, not chat.
        if ($email->isBounce()) {
            Log::info('Bounce detected at replyto address, routing to bounce handler', [
                'envelope_to' => $email->envelopeTo,
                'bounce_recipient' => $email->bounceRecipient,
            ]);

            return $this->handleBounce($email);
        }

        // Parse replyto-{msgid}-{fromid}
        $parts = explode('-', $localPart);
        if (count($parts) < 3) {
            Log::warning('Invalid replyto address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return $this->dropped("Invalid replyto address format");
        }

        $messageId = (int) $parts[1];

        // Check if message exists and is not expired
        $message = Message::find($messageId);
        if ($message === null) {
            Log::warning('Reply to non-existent message', [
                'message_id' => $messageId,
            ]);

            return $this->dropped("Reply to non-existent message");
        }

        // Check if message is expired (>42 days old)
        $arrival = $message->arrival ?? $message->date;
        if ($arrival && $arrival->diffInDays(now()) > self::EXPIRED_MESSAGE_DAYS) {
            Log::info('Dropping reply to expired message', [
                'message_id' => $messageId,
                'age_days' => $arrival->diffInDays(now()),
            ]);

            return $this->dropped("Reply to expired message");
        }

        // Check if message is on a closed group
        $messageGroups = DB::table('messages_groups')
            ->where('msgid', $messageId)
            ->pluck('groupid');

        $closed = FALSE;
        foreach ($messageGroups as $groupId) {
            $group = Group::find($groupId);
            if ($group !== null) {
                $settings = is_array($group->settings) ? $group->settings : (json_decode($group->settings ?? '{}', TRUE) ?: []);
                if (! empty($settings['closed'])) {
                    $closed = TRUE;
                    break;
                }
            }
        }

        if ($closed) {
            Log::info('Reply to message on closed group', [
                'message_id' => $messageId,
                'group_id' => $groupId,
            ]);

            // #21: Send notification email to sender about closed group (matches legacy)
            $this->sendClosedGroupEmail($email->fromAddress);

            return RoutingResult::TO_SYSTEM;
        }

        // Find the sender user
        $fromUser = $this->findUserByEmail($email->fromAddress);
        if ($fromUser === null) {
            Log::warning('Reply from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return $this->dropped("Reply from unknown user");
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $fromUser->id)
            ->update(['lastaccess' => now()]);

        // #6: Add unrecognised sender email to user profile (email forwarding scenario)
        $this->addEmailToUser($fromUser->id, $email->envelopeFrom);

        // Get the message owner
        $messageOwner = $message->fromuser;
        if ($messageOwner === null) {
            Log::warning('Message has no owner', [
                'message_id' => $messageId,
            ]);

            return $this->dropped("Message has no owner");
        }

        // Get or create User2User chat between the sender and message owner
        $chat = $this->getOrCreateUserChat($fromUser->id, $messageOwner);
        if ($chat === null) {
            Log::warning('Could not create chat for reply', [
                'from_user' => $fromUser->id,
                'to_user' => $messageOwner,
            ]);

            return $this->dropped("Could not create chat for reply");
        }

        // Create the chat message as TYPE_INTERESTED with refmsgid.
        // Reply-to addresses are first replies to posts, so use TYPE_INTERESTED
        // (not TYPE_DEFAULT which is for ongoing chat notification replies).
        $this->createChatMessageFromEmail(
            $chat,
            $fromUser->id,
            $email,
            refMsgId: $messageId,
            type: ChatMessage::TYPE_INTERESTED
        );

        // #7: Check if message has outcome (TAKEN/RECEIVED) - don't email if so
        $hasOutcome = DB::table('messages_outcomes')
            ->where('msgid', $messageId)
            ->exists();

        if ($hasOutcome) {
            // Don't pester the poster with more emails for completed items.
            // They can still see replies on the site.
            Log::info('Message has outcome - suppressing email notification via mailedLastForUser', [
                'message_id' => $messageId,
            ]);

            // Ensure roster entry exists (may not yet for a newly created chat)
            DB::table('chat_roster')->insertOrIgnore([
                'chatid' => $chat->id,
                'userid' => $messageOwner,
            ]);

            DB::table('chat_roster')
                ->where('chatid', $chat->id)
                ->where('userid', $messageOwner)
                ->update([
                    'lastemailed' => now(),
                    'lastmsgemailed' => DB::raw("(SELECT MAX(id) FROM chat_messages WHERE chatid = {$chat->id})"),
                ]);
        }

        // Track email reply in email_tracking for AMP comparison stats.
        $this->trackEmailReply($chat->id, $fromUser->id);

        $this->lastRoutingContext = [
            'user_id' => $fromUser->id,
            'to_user_id' => $messageOwner,
            'chat_id' => $chat->id,
            'message_id' => $messageId,
        ];

        Log::info('Created chat message from reply-to email', [
            'message_id' => $messageId,
            'chat_id' => $chat->id,
            'from_user' => $fromUser->id,
            'has_outcome' => $hasOutcome,
        ]);

        return RoutingResult::TO_USER;
    }

    /**
     * Handle chat notification replies (notify-{chatid}-{userid}[-{msgid}]).
     */
    private function handleChatNotificationReply(ParsedEmail $email): RoutingResult
    {
        $chatId = $email->chatId;
        $userId = $email->chatUserId;

        Log::info('Processing chat notification reply', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $email->chatMessageId,
        ]);

        // Drop misdirected read receipts (MDNs sent to chat notification addresses)
        if ($this->isReadReceipt($email)) {
            Log::debug('Dropping misdirected read receipt in chat reply');

            return $this->dropped("Misdirected read receipt in chat reply");
        }

        // Check for bounces FIRST - bounces to notify addresses happen when the original
        // chat notification email bounces. These should be processed as bounces, not
        // as chat messages.
        if ($email->isBounce()) {
            Log::info('Bounce detected at notify address, routing to bounce handler', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'bounce_recipient' => $email->bounceRecipient,
            ]);

            return $this->handleBounce($email);
        }

        // Validate chat exists (follow redirects from merged duplicate rooms)
        $chat = ChatRoom::find($chatId);
        if ($chat === null) {
            $redirect = DB::table('chat_room_redirects')->where('old_id', $chatId)->first();
            if ($redirect) {
                Log::info('Following chat redirect', [
                    'old_chat_id' => $chatId,
                    'new_chat_id' => $redirect->new_id,
                ]);
                $chat = ChatRoom::find($redirect->new_id);
            }
        }
        if ($chat === null) {
            Log::warning('Reply to non-existent chat', [
                'chat_id' => $chatId,
            ]);

            return $this->dropped("Reply to non-existent chat");
        }

        // Check if chat is stale and sender email is unfamiliar
        if ($this->isStaleChatWithUnfamiliarSender($chat, $email)) {
            Log::info('Dropping reply to stale chat from unfamiliar sender', [
                'chat_id' => $chatId,
                'age_days' => $chat->latestmessage?->diffInDays(now()),
            ]);

            return $this->dropped("Reply to stale chat from unfamiliar sender");
        }

        // Validate user is part of chat
        if (! $this->isUserInChat($userId, $chat)) {
            Log::warning('User not part of chat', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            return $this->dropped("User not part of chat");
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // #6: Add unrecognised sender email to user profile (email forwarding scenario)
        $this->addEmailToUser($userId, $email->envelopeFrom);

        // Create chat message
        $this->createChatMessageFromEmail($chat, $userId, $email);

        // Track email reply in email_tracking so AMP vs email reply stats are accurate.
        // Find the most recent chat notification sent to this user for this chat.
        $this->trackEmailReply($chatId, $userId);

        $this->lastRoutingContext = [
            'user_id' => $userId,
            'chat_id' => $chatId,
        ];

        return RoutingResult::TO_USER;
    }

    /**
     * Track an email reply against the most recent email_tracking record for this chat/user.
     */
    private function trackEmailReply(int $chatId, int $userId): void
    {
        try {
            DB::table('email_tracking')
                ->where('email_type', 'ChatNotification')
                ->where('userid', $userId)
                ->where('replied_at', null)
                ->whereRaw("JSON_EXTRACT(metadata, '$.chat_id') = ?", [$chatId])
                ->orderByDesc('sent_at')
                ->limit(1)
                ->update([
                    'replied_at' => now(),
                    'replied_via' => 'email',
                ]);
        } catch (\Throwable $e) {
            // Don't let tracking failures break mail processing.
            Log::warning('Failed to track email reply', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if chat is stale with unfamiliar sender.
     */
    private function isStaleChatWithUnfamiliarSender(ChatRoom $chat, ParsedEmail $email): bool
    {
        // Check if chat is stale (>84 days old)
        $latestMessage = $chat->latestmessage;
        if ($latestMessage === null || $latestMessage->diffInDays(now()) <= self::STALE_CHAT_DAYS) {
            return false;
        }

        // Check if sender email is known
        $fromAddress = $email->fromAddress;
        if (empty($fromAddress)) {
            return true;
        }

        // Check if email belongs to a user in the chat
        $emailUser = UserEmail::where('email', $fromAddress)->first();
        if ($emailUser === null) {
            return true;
        }

        // Check if email user is part of the chat
        return ! $this->isUserInChat($emailUser->userid, $chat);
    }

    /**
     * Check if user is part of a chat.
     */
    private function isUserInChat(int $userId, ChatRoom $chat): bool
    {
        if ($chat->chattype === 'User2User') {
            return $chat->user1 === $userId || $chat->user2 === $userId;
        }

        // For group chats, check roster
        return DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $userId)
            ->exists();
    }

    /**
     * Create a chat message from an incoming email.
     *
     * - Runs spam checks; if spam found, sets reviewrequired=1 (not rejected)
     * - Runs review-level content checks (scripts, money, links, etc.)
     * - Stores raw email in messages table for moderator viewing
     * - Links chat message to stored email via chat_messages_byemail
     * - Checks image attachments for repeated hash spam
     */
    private function createChatMessageFromEmail(
        ChatRoom $chat,
        int $userId,
        ParsedEmail $email,
        bool $forceReview = false,
        ?string $forceReviewReason = null,
        ?int $refMsgId = null,
        string $type = ChatMessage::TYPE_DEFAULT,
        ?float $spamScore = null,
        ?string $prependSubject = null,
        bool $skipStripQuoted = false
    ): void {
        // Get body text, converting HTML to plain text if no text part exists.
        // This handles email clients like Apple Mail that may send HTML-only emails.
        $body = $email->textBody;
        if ($body === null && $email->htmlBody !== null) {
            $html2text = new \Html2Text\Html2Text($email->htmlBody);
            $body = $html2text->getText();
        }
        $body = $body ?? '';

        // #20: Prepend subject to body for unpaired direct messages
        if ($prependSubject !== null) {
            $body = $prependSubject . "\r\n\r\n" . $body;
        }

        // Strip quoted reply text and signatures before storing.
        // For volunteer messages, the quoted text (conversation transcript, reported post)
        // is the useful content - don't strip it. Matches legacy iznik-server behavior:
        // "Don't strip quoted as it might be useful."
        if (! $skipStripQuoted) {
            $body = $this->stripQuoted->strip($body);
        }

        // Determine if this chat message needs review.
        // If spam is detected for a chat-destined email, we don't reject it - instead
        // we flag reviewrequired=1 so moderators can review it.
        $reviewRequired = $forceReview;
        $reportReason = $forceReviewReason;

        // Check for spam-level issues (unless already flagged by caller)
        if (! $reviewRequired) {
            $spamResult = $this->spamCheck->checkMessage($email);
            if ($spamResult !== null) {
                [, $reason, $detail] = $spamResult;
                $reviewRequired = true;
                $reportReason = $reason;
                Log::info('Chat message flagged for review (spam detected)', [
                    'reason' => $reason,
                    'detail' => $detail,
                ]);
            }
        }

        // Check for review-level issues (scripts, money, links, language, etc.)
        if (! $reviewRequired && strlen($body) > 0) {
            $reviewReason = $this->spamCheck->checkReview($body, true);
            if ($reviewReason !== null) {
                $reviewRequired = true;
                $reportReason = $reviewReason;
                Log::info('Chat message flagged for review', [
                    'reason' => $reviewReason,
                ]);
            }
        }

        // If the last message in this chat is held for review, hold this one too.
        // This includes messages by any user. For example, suppose we add a Mod Note
        // to a user2user chat - we don't want to send out that chat until the messages
        // that triggered it have been reviewed.
        if (! $reviewRequired) {
            $lastHeld = DB::table('chat_messages')
                ->where('chatid', $chat->id)
                ->orderByDesc('id')
                ->limit(1)
                ->value('reviewrequired');

            if ($lastHeld) {
                $reviewRequired = true;
                $reportReason = 'Last';
                Log::info('Chat message held for review (previous message in chat is held)', [
                    'chat_id' => $chat->id,
                ]);
            }
        }

        // Map reportreason to valid enum values for the chat_messages table.
        // The DB column is enum('Spam','Other','Last','Force','Fully','TooMany','User','UnknownMessage','SameImage','DodgyImage').
        // Our spam check reasons are more detailed, so map them to the enum.
        $dbReportReason = $this->mapReportReason($reportReason);

        $chatMsgData = [
            'chatid' => $chat->id,
            'userid' => $userId,
            'message' => $body,
            'type' => $type,
            'refmsgid' => $refMsgId,
            'date' => now(),
            'platform' => 0, // Email source
            'reviewrequired' => $reviewRequired ? 1 : 0,
            'reportreason' => $dbReportReason,
            'processingrequired' => 1, // Background chat_process.php cron handles visibility, roster, push notifications
            'replyreceived' => 0,
        ];

        // #22: Store SpamAssassin score on chat message if provided
        if ($spamScore !== null) {
            $chatMsgData['spamscore'] = $spamScore;
        }

        $chatMsg = ChatMessage::create($chatMsgData);

        // Store the raw incoming email in the messages table so moderators can
        // view the original SMTP message when reviewing chat messages.
        $this->storeChatEmailMessage($chatMsg, $email, $userId);

        // Extract image attachments and create TYPE_IMAGE chat messages for each.
        $this->addPhotosToChat($chat->id, $userId, $email);

        Log::info('Created chat message from email', [
            'chat_id' => $chat->id,
            'chat_message_id' => $chatMsg->id,
            'user_id' => $userId,
            'review_required' => $reviewRequired,
        ]);
    }

    /**
     * Store the raw incoming email in the messages table and link it to the chat message
     * via chat_messages_byemail. This allows moderators to view the original SMTP email
     * when reviewing chat messages in ModTools.
     */
    private function storeChatEmailMessage(ChatMessage $chatMsg, ParsedEmail $email, int $userId): void
    {
        try {
            $rawMessage = $email->rawMessage;

            // Truncate very large messages to avoid bloating the database.
            if (strlen($rawMessage) > 100000) {
                $rawMessage = substr($rawMessage, 0, 100000);
            }

            // Ensure we have a message ID for the unique key constraint.
            $messageId = $email->messageId ?? (microtime(TRUE).'@'.config('freegle.mail.user_domain', 'users.ilovefreegle.org'));

            $msgId = DB::table('messages')->insertGetId([
                'date' => now(),
                'source' => Message::SOURCE_EMAIL,
                'sourceheader' => $this->determineSourceHeader($email),
                'message' => $rawMessage,
                'fromuser' => $userId,
                'envelopefrom' => $email->envelopeFrom,
                'envelopeto' => $email->envelopeTo,
                'fromname' => $email->fromName,
                'fromaddr' => $email->fromAddress,
                'fromip' => $email->senderIp,
                'subject' => $email->subject,
                'messageid' => $messageId,
                'textbody' => $email->textBody,
            ]);

            if ($msgId) {
                DB::table('chat_messages_byemail')->insert([
                    'chatmsgid' => $chatMsg->id,
                    'msgid' => $msgId,
                ]);
            }
        } catch (\Exception $e) {
            // Non-fatal - don't fail chat message creation if email storage fails.
            Log::warning('Failed to store chat email message', [
                'chat_message_id' => $chatMsg->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract image attachments from the email and create TYPE_IMAGE chat messages for each.
     *
     * Each image is uploaded to TUS and gets its own chat message with a chat_images record.
     * Images with hashes matching the Freegle logo are suppressed.
     * Images that have been used too many times recently are flagged for review.
     */
    private function addPhotosToChat(int $chatRoomId, int $userId, ParsedEmail $email): int
    {
        // Suppressed image hashes (e.g. Freegle logo)
        $suppressedHashes = ['61e4d4a2e4bb8a5d', '61e4d4a2e4bb8a59'];

        try {
            // Re-parse the raw message to extract MIME attachment parts.
            $message = \ZBateson\MailMimeParser\Message::from($email->rawMessage, FALSE);
            $attachments = $message->getAllAttachmentParts();

            if (empty($attachments)) {
                return 0;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to parse email for attachments', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $tusService = app(\App\Services\TusService::class);
        $count = 0;

        foreach ($attachments as $part) {
            try {
                $contentType = $part->getContentType() ?? '';

                // Only process image attachments
                if (! str_starts_with($contentType, 'image/')) {
                    continue;
                }

                $imageData = $part->getContent();
                if (empty($imageData)) {
                    continue;
                }

                $hash = $this->computeImageHash($imageData);

                // Skip suppressed images (e.g. Freegle logo)
                if ($hash && in_array($hash, $suppressedHashes)) {
                    continue;
                }

                // Create a TYPE_IMAGE chat message
                $imageMsg = ChatMessage::create([
                    'chatid' => $chatRoomId,
                    'userid' => $userId,
                    'type' => ChatMessage::TYPE_IMAGE,
                    'date' => now(),
                    'platform' => 0,
                    'processingrequired' => 1, // Background chat_process.php cron handles visibility, roster, push notifications
                    'replyreceived' => 0,
                ]);

                if (! $imageMsg || ! $imageMsg->id) {
                    continue;
                }

                // Upload to TUS
                $tusUrl = $tusService->upload($imageData, $contentType);

                if (! $tusUrl) {
                    Log::warning('Failed to upload email image to tusd');
                    // Delete the orphaned chat message
                    $imageMsg->delete();

                    continue;
                }

                $externalUid = \App\Services\TusService::urlToExternalUid($tusUrl);

                // Create chat_images record
                $chatImage = ChatImage::create([
                    'chatmsgid' => $imageMsg->id,
                    'contenttype' => $contentType,
                    'externaluid' => $externalUid,
                    'hash' => $hash,
                ]);

                // Link chat message to image
                $imageMsg->update(['imageid' => $chatImage->id]);

                // Check for repeated hash spam
                if ($hash) {
                    $recentUsage = DB::table('chat_images')
                        ->join('chat_messages', 'chat_images.id', '=', 'chat_messages.imageid')
                        ->where('chat_images.hash', $hash)
                        ->where('chat_messages.date', '>=', now()->subHours(24))
                        ->count();

                    if ($recentUsage > 20) {
                        $imageMsg->update([
                            'reviewrequired' => 1,
                            'reportreason' => 'SameImage',
                        ]);
                    }
                }

                $count++;

                Log::debug('Created chat image from email attachment', [
                    'chat_room_id' => $chatRoomId,
                    'chat_message_id' => $imageMsg->id,
                    'content_type' => $contentType,
                    'hash' => $hash,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to process email image attachment', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Map a spam check reason to a valid chat_messages.reportreason enum value.
     *
     * The DB column is enum('Spam','Other','Last','Force','Fully','TooMany','User','UnknownMessage','SameImage','DodgyImage').
     * SpamCheckService returns detailed reason strings. Map them to the enum.
     */
    private function mapReportReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        // These are already valid enum values
        $validEnumValues = ['Spam', 'Other', 'Last', 'Force', 'Fully', 'TooMany', 'User', 'UnknownMessage', 'SameImage', 'DodgyImage'];
        if (in_array($reason, $validEnumValues, true)) {
            return $reason;
        }

        // Map detailed reasons to generic 'Spam' enum value
        return 'Spam';
    }

    /**
     * Handle messages to group volunteers.
     *
     * Processing flow:
     * 1. Run SpamAssassin check first
     * 2. Run checkMessage() for our own spam checks
     * 3. Filter auto-replies (for -auto@ addresses, not -volunteers@)
     * 4. Create User2Mod chat message
     * Note: Known spammers are dropped before reaching this method.
     */
    private function handleVolunteersMessage(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing volunteers message', [
            'group' => $email->targetGroupName,
            'is_volunteers' => $email->isToVolunteers,
            'is_auto' => $email->isToAuto,
        ]);

        // Find the group
        $group = $this->findGroup($email->targetGroupName);
        if ($group === null) {
            Log::warning('Volunteers message to unknown group', [
                'group' => $email->targetGroupName,
            ]);

            return $this->dropped("Volunteers message to unknown group");
        }

        // Find sender user
        $user = $this->findUserByEmail($email->fromAddress);
        if ($user === null) {
            Log::warning('Volunteers message from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return $this->dropped("Volunteers message from unknown user");
        }

        // Drop messages from deleted users - their account no longer exists
        if ($user->deleted !== null) {
            Log::warning('Volunteers message from deleted user', [
                'from' => $email->fromAddress,
                'user_id' => $user->id,
                'deleted' => $user->deleted,
            ]);

            return $this->dropped("Volunteers message from deleted user");
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $user->id)
            ->update(['lastaccess' => now()]);

        // Filter auto-replies for -auto@ addresses (only -volunteers@ allows auto-replies through)
        if (! $email->isToVolunteers && $email->isAutoReply()) {
            Log::debug('Dropping auto-reply to auto address');

            return $this->dropped("Auto-reply to auto address dropped");
        }

        // Spam checks for volunteers messages: flag for review, never reject.
        // Users may be reporting spam to volunteers, so we don't want to block
        // the message. Instead, flag it so volunteers can see it was detected.
        $spamDetected = false;
        $spamReason = null;

        // SpamAssassin check
        [$spamScore, $isSpamAssassin] = $this->spamCheck->checkSpamAssassin(
            $email->rawMessage,
            $email->subject ?? ''
        );

        if ($isSpamAssassin) {
            $spamDetected = true;
            $spamReason = 'SpamAssassin score '.$spamScore;
            Log::info('Volunteers message flagged by SpamAssassin (for review)', [
                'score' => $spamScore,
            ]);
        }

        // Our own spam checks
        if (! $spamDetected) {
            $spamResult = $this->spamCheck->checkMessage($email);
            if ($spamResult !== null) {
                [, $reason, $detail] = $spamResult;
                $spamDetected = true;
                $spamReason = $reason;
                Log::info('Volunteers message flagged as spam (for review)', [
                    'reason' => $reason,
                    'detail' => $detail,
                ]);
            }
        }

        // Note: Known spammer check is not needed here because spammers are
        // dropped unconditionally before routing (matching legacy behavior).

        // Get or create User2Mod chat between user and group moderators
        $chat = $this->getOrCreateUser2ModChat($user->id, $group->id);
        if ($chat === null) {
            Log::warning('Could not create User2Mod chat', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return $this->dropped("Could not create User2Mod chat");
        }

        // TN "Reporting member/post" emails include a conversation transcript that
        // is valuable context for moderators. Don't strip quoted text for these -
        // the transcript contains From:/To:/Subject:/Date: lines that the strip
        // logic would eat. Matches legacy iznik-server behavior which says
        // "Don't strip quoted as it might be useful" for volunteer messages,
        // but we still want to strip for other volunteer messages like digest replies.
        $isTnReport = str_starts_with($email->subject ?? '', 'Reporting ');

        // Create the chat message, flagging for review if spam was detected
        $this->createChatMessageFromEmail($chat, $user->id, $email, $spamDetected, $spamReason, skipStripQuoted: $isTnReport);

        $this->lastRoutingContext = [
            'group_id' => $group->id,
            'group_name' => $group->nameshort ?? $group->namefull ?? '',
            'user_id' => $user->id,
            'chat_id' => $chat->id,
        ];

        Log::info('Created volunteers message', [
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'spam_flagged' => $spamDetected,
        ]);

        return RoutingResult::TO_VOLUNTEERS;
    }

    /**
     * Handle group posts.
     */
    private function handleGroupPost(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing group post', [
            'group' => $email->targetGroupName,
            'subject' => $email->subject,
        ]);

        // Find the group
        $group = $this->findGroup($email->targetGroupName);
        if ($group === null) {
            Log::warning('Post to unknown group', [
                'group' => $email->targetGroupName,
            ]);

            return $this->dropped("Post to unknown group");
        }

        // Find sender user
        $user = $this->findUserByEmail($email->fromAddress);
        if ($user === null) {
            Log::info('Post from unknown user - dropping', [
                'from' => $email->fromAddress,
            ]);

            return $this->dropped("Post from unknown user");
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $user->id)
            ->update(['lastaccess' => now()]);

        // Check membership
        $membership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::info('Post from non-member - dropping', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return $this->dropped("Post from non-member");
        }

        // Check for TAKEN/RECEIVED subjects - swallow silently (mods don't need to review completion markers)
        if ($this->isTakenOrReceivedSubject($email->subject)) {
            Log::info('TAKEN/RECEIVED post swallowed', [
                'subject' => $email->subject,
            ]);

            return RoutingResult::TO_SYSTEM;
        }

        // Check if Trash Nothing post with valid secret (skip spam check)
        $skipSpamCheck = $this->shouldSkipSpamCheck($email);

        // Set context early - we know the group and user at this point
        $this->lastRoutingContext = [
            'group_id' => $group->id,
            'group_name' => $group->nameshort ?? $group->namefull ?? '',
            'user_id' => $user->id,
        ];

        // Check for spam if not TN
        if (! $skipSpamCheck) {
            [$isSpam, $spamType, $spamReason] = $this->checkForSpam($email);
            if ($isSpam) {
                // Create the message with spam info and route to pending for moderator review
                $messageId = $this->createGroupPostMessage($email, $user, $group, $spamType, $spamReason);

                if ($messageId !== null) {
                    $this->lastRoutingContext['message_id'] = $messageId;
                    $this->lastRoutingContext['spam_type'] = $spamType;
                    $this->lastRoutingContext['spam_reason'] = $spamReason;

                    // #23: Log spam classification to logs table (matches legacy MailRouter)
                    DB::table('logs')->insert([
                        'timestamp' => now(),
                        'type' => 'Message',
                        'subtype' => 'ClassifiedSpam',
                        'msgid' => $messageId,
                        'text' => $spamReason,
                        'groupid' => $group->id,
                    ]);

                    // #12: Record posting in messages_postings even for spam
                    DB::table('messages_postings')->insert([
                        'msgid' => $messageId,
                        'groupid' => $group->id,
                        'repost' => 0,
                        'autorepost' => 0,
                        'date' => now(),
                    ]);

                    // #15: Notify group moderators of new pending spam
                    $this->notifyGroupMods($group->id);

                    Log::info('Spam message created for moderator review', [
                        'message_id' => $messageId,
                        'spam_type' => $spamType,
                        'spam_reason' => $spamReason,
                    ]);
                }

                return RoutingResult::INCOMING_SPAM;
            }
        }

        // Check posting status (column is camelCase: ourPostingStatus)
        // NULL defaults to MODERATED (goes to PENDING). Only explicit 'DEFAULT' or
        // 'UNMODERATED' posting status means approved.
        $postingStatus = $membership->ourPostingStatus;

        // #9: Check Big Switch (overridemoderation) - forces ALL posts through moderation
        $overrideModeration = $group->overridemoderation ?? 'None';
        if ($overrideModeration === 'ModerateAll') {
            $postingStatus = 'MODERATED';
            Log::info('Big Switch active - forcing post to moderated', [
                'group_id' => $group->id,
            ]);
        }

        // #10: Mod posts forced to PENDING - mods posting by email go to pending
        // so other mods can review (matches legacy behaviour)
        if ($user->isModeratorOf($group->id)) {
            $postingStatus = 'MODERATED';
            Log::info('Moderator post - forcing to pending', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);
        }

        // #11: Check group 'moderated' setting - forces all posts to pending
        $groupSettings = is_array($group->settings) ? $group->settings : (json_decode($group->settings ?? '{}', TRUE) ?: []);
        if (! empty($groupSettings['moderated'])) {
            $postingStatus = 'MODERATED';
            Log::info('Group is moderated - forcing post to pending', [
                'group_id' => $group->id,
            ]);
        }

        // Determine routing result first
        $routingResult = RoutingResult::PENDING;  // Default
        $pendingReason = null;

        // Check if user is unmapped (no location)
        if ($user->lastlocation === null) {
            $pendingReason = 'unmapped user';
            Log::info('Post from unmapped user - pending', [
                'user_id' => $user->id,
            ]);
        }
        // Check for worry words
        elseif ($this->containsWorryWords($email)) {
            $pendingReason = 'worry words';
            Log::info('Post contains worry words - pending', [
                'subject' => $email->subject,
            ]);
        }
        // Route based on posting status
        // API rejects PROHIBITED with "Not allowed to post on this group" (message.php:625)
        // so email should match: drop the post.
        else {
            $routingResult = match ($postingStatus) {
                'DEFAULT', 'UNMODERATED' => RoutingResult::APPROVED,
                'PROHIBITED' => RoutingResult::DROPPED,
                default => RoutingResult::PENDING,  // NULL, MODERATED, or any other value
            };
        }

        // For DROPPED messages, don't create a record
        if ($routingResult === RoutingResult::DROPPED) {
            return RoutingResult::DROPPED;
        }

        // Create the message record for APPROVED and PENDING posts
        $messageId = $this->createGroupPostMessage($email, $user, $group);

        if ($messageId !== null) {
            $this->lastRoutingContext['message_id'] = $messageId;

            // #12: Record posting in messages_postings (for repost logic)
            DB::table('messages_postings')->insert([
                'msgid' => $messageId,
                'groupid' => $group->id,
                'repost' => 0,
                'autorepost' => 0,
                'date' => now(),
            ]);

            // Update the collection based on routing result
            if ($routingResult === RoutingResult::APPROVED) {
                // Message is approved - update collection to Approved
                MessageGroup::where('msgid', $messageId)
                    ->update([
                        'collection' => MessageGroup::COLLECTION_APPROVED,
                        'approvedat' => now(),
                    ]);

                // #14: Add to spatial index so message appears in search results
                $this->addToSpatialIndex($messageId, $group->id);

                Log::info('Message approved and posted to group', [
                    'message_id' => $messageId,
                    'group_id' => $group->id,
                ]);
            } else {
                // Message is pending - collection is already Incoming, update to Pending
                MessageGroup::where('msgid', $messageId)
                    ->update(['collection' => MessageGroup::COLLECTION_PENDING]);

                // #15: Notify group moderators of new pending work
                $this->notifyGroupMods($group->id);

                Log::info('Message pending moderator review', [
                    'message_id' => $messageId,
                    'group_id' => $group->id,
                    'reason' => $pendingReason ?? 'posting status',
                ]);
            }
        }

        return $routingResult;
    }

    /**
     * Create a message record for a group post.
     *
     * This stores the message in the database with appropriate collection status.
     * For spam messages, sets spamtype/spamreason and collection=Pending for moderator review.
     *
     * @param  ParsedEmail  $email  The parsed email
     * @param  User  $user  The sender user
     * @param  Group  $group  The target group
     * @param  string|null  $spamType  Spam type if this is a spam message
     * @param  string|null  $spamReason  Spam reason if this is a spam message
     * @return int|null  The created message ID, or null on failure
     */
    private function createGroupPostMessage(
        ParsedEmail $email,
        User $user,
        Group $group,
        ?string $spamType = null,
        ?string $spamReason = null
    ): ?int {
        $message = null;
        try {
            // Determine message type from subject using keyword matching
            $type = Message::determineType($email->subject);

            // Generate a unique message ID if not present
            $messageId = $email->messageId ?? (microtime(true) . '@' . config('freegle.mail.user_domain', 'users.ilovefreegle.org'));
            // Append group ID to make message ID unique per group
            $messageId = $messageId . '-' . $group->id;

            // Determine lat/lng - prefer TN coordinates header, then subject location, then user location
            $lat = null;
            $lng = null;
            $locationId = null;

            // 1. Try TN coordinates header
            $tnCoords = $email->getTrashNothingCoordinates();
            if ($tnCoords) {
                $parts = explode(',', $tnCoords);
                if (count($parts) >= 2) {
                    $lat = (float) trim($parts[0]);
                    $lng = (float) trim($parts[1]);
                }
            }

            // 2. Try to extract location from subject (e.g., "OFFER: Sofa (Edinburgh)")
            if ($lat === null || $lng === null) {
                $subjectLocation = $this->extractLocationFromSubject($email->subject, $group->id);
                if ($subjectLocation) {
                    $lat = $subjectLocation['lat'];
                    $lng = $subjectLocation['lng'];
                    $locationId = $subjectLocation['id'];
                }
            }

            // 3. Fall back to user's location if still no coordinates
            if ($lat === null || $lng === null) {
                [$lat, $lng] = $user->getLatLng();
                // If user has lastlocation, use that as locationid
                if ($user->lastlocation) {
                    $locationId = $user->lastlocation;
                }
            }

            // Find the closest postcode location to get locationid (if not already set)
            if ($lat !== null && $lng !== null && $locationId === null) {
                $locationId = $this->findClosestPostcodeId($lat, $lng);
            }

            // Update user's lastlocation if we found a location
            if ($locationId && $user->id) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['lastlocation' => $locationId]);
            }

            // Scrape TN image URLs before processing textbody
            $tnImageUrls = $this->scrapeTnImageUrls($email->textBody);

            // Strip TN pic links from textbody
            $cleanedTextBody = $this->stripTnPicLinks($email->textBody);

            // Create the message record
            $message = Message::create([
                'date' => now(),
                'source' => Message::SOURCE_EMAIL ?? 'Email',
                'sourceheader' => $this->determineSourceHeader($email),
                'message' => $email->rawMessage,
                'fromuser' => $user->id,
                'envelopefrom' => $email->envelopeFrom,
                'envelopeto' => $email->envelopeTo,
                'fromname' => $email->fromName,
                'fromaddr' => $email->fromAddress,
                'replyto' => $email->getHeader('Reply-To'),
                'fromip' => $email->senderIp,
                'subject' => $email->subject,
                'suggestedsubject' => $email->subject, // TODO: implement subject suggestion
                'messageid' => $messageId,
                'tnpostid' => $email->getTrashNothingPostId(),
                'textbody' => $cleanedTextBody,
                'type' => $type,
                'lat' => $lat,
                'lng' => $lng,
                'locationid' => $locationId,
                'spamtype' => $spamType,
                'spamreason' => $spamReason,
            ]);

            if (! $message || ! $message->id) {
                Log::error('Failed to create message record');

                return null;
            }

            // Create the messages_groups entry
            // Spam messages go to Pending for moderator review
            $collection = $spamType !== null
                ? MessageGroup::COLLECTION_PENDING
                : MessageGroup::COLLECTION_INCOMING;

            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $group->id,
                'msgtype' => $type,
                'collection' => $collection,
                'arrival' => now(),
            ]);

            // Add to message history for spam checking
            DB::table('messages_history')->insert([
                'groupid' => $group->id,
                'source' => Message::SOURCE_EMAIL ?? 'Email',
                'fromuser' => $user->id,
                'envelopefrom' => $email->envelopeFrom,
                'envelopeto' => $email->envelopeTo,
                'fromname' => $email->fromName,
                'fromaddr' => $email->fromAddress,
                'fromip' => $email->senderIp,
                'subject' => $email->subject,
                'prunedsubject' => $this->pruneSubject($email->subject),
                'messageid' => $messageId,
                'msgid' => $message->id,
            ]);

            // Note: messages_spatial is added when message is APPROVED, not at creation.
            // The spatial index is populated during the approval step.

            // Process TN images: download, upload to tusd, create attachments
            if (! empty($tnImageUrls)) {
                $this->createTnImageAttachments($message->id, $tnImageUrls);
            }

            return $message->id;

        } catch (\Exception $e) {
            // Check for duplicate message ID (can happen if message is resent)
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::info('Duplicate message ID, likely resent message', [
                    'message_id' => $email->messageId,
                ]);

                return null;
            }

            Log::error('Failed to create group post message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If the message row was created before the failure, record the failure
            // so retrycount/retrylastfailure are updated (V1 parity: Message::recordFailure).
            if ($message !== null && $message->id) {
                $this->recordFailure($message->id, $e->getMessage());
            }

            return null;
        }
    }

    /**
     * Prune subject for history comparison (remove location, normalize).
     */
    private function pruneSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        // Remove location in parentheses at end
        $pruned = preg_replace('/\s*\([^)]+\)\s*$/', '', $subject);
        // Remove type prefix
        $pruned = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $pruned);

        return trim($pruned);
    }

    /**
     * Check if spam check should be skipped (e.g., for TN with valid secret).
     */
    private function shouldSkipSpamCheck(ParsedEmail $email): bool
    {
        if (! $email->isFromTrashNothing()) {
            return false;
        }

        $secret = $email->getTrashNothingSecret();
        if ($secret === null) {
            return false;
        }

        // Validate secret against config
        $configSecret = config('freegle.mail.trashnothing_secret');
        if (empty($configSecret)) {
            // If no secret configured, accept any TN email with a secret header
            return true;
        }

        return $secret === $configSecret;
    }

    /**
     * Check if email is spam using the SpamCheckService.
     *
     * Runs all spam detection checks: keywords, IP checks, subject reuse, greeting spam,
     * Spamhaus DBL, known spammer references, and more. Also runs SpamAssassin if available.
     *
     * @return array{bool, ?string, ?string} [isSpam, spamType, spamReason]
     */
    private function checkForSpam(ParsedEmail $email): array
    {
        $result = $this->spamCheck->checkMessage($email);

        if ($result !== null) {
            [, $reason, $detail] = $result;
            Log::info('Spam detected', [
                'reason' => $reason,
                'detail' => $detail,
            ]);

            return [true, $reason, $detail];
        }

        // Also run SpamAssassin if available
        // Note: checkForSpam() is only called when shouldSkipSpamCheck() is false,
        // so SpamAssassin always runs here.
        [$score, $isSpam] = $this->spamCheck->checkSpamAssassin(
            $email->rawMessage,
            $email->subject ?? ''
        );

        if ($isSpam) {
            $reason = SpamCheckService::REASON_SPAMASSASSIN;
            $detail = "SpamAssassin flagged this as possible spam; score $score (high is bad)";
            Log::info('SpamAssassin flagged as spam', [
                'score' => $score,
            ]);

            return [true, $reason, $detail];
        }

        return [false, null, null];
    }

    /**
     * Simple wrapper for checkForSpam() - returns boolean only.
     */
    private function isSpam(ParsedEmail $email): bool
    {
        [$isSpam] = $this->checkForSpam($email);

        return $isSpam;
    }

    /**
     * Check if email contains worry words.
     *
     * Worry words are stored in the 'worrywords' database table with types:
     * - Regulated: UK regulated substances
     * - Reportable: UK reportable substances
     * - Medicine: Medicines/supplements
     * - Review: Just needs looking at
     * - Allowed: Exclusions (removed from text before checking)
     */
    private function containsWorryWords(ParsedEmail $email): bool
    {
        $subject = $email->subject ?? '';
        $body = $email->textBody ?? '';

        // Get worry words from database
        $worryWords = DB::table('worrywords')->get();

        // Check for pound sign (£) as a special case
        if (str_contains($subject, '£') || str_contains($body, '£')) {
            Log::debug('Worry word found: £');

            return true;
        }

        // First, remove any ALLOWED type words from the text
        foreach ($worryWords as $worryWord) {
            if ($worryWord->type === 'Allowed') {
                $pattern = '/\b'.preg_quote($worryWord->keyword, '/').'\b/i';
                $subject = preg_replace($pattern, '', $subject);
                $body = preg_replace($pattern, '', $body);
            }
        }

        // Check for phrases (words containing spaces) with literal matching
        foreach ($worryWords as $worryWord) {
            if ($worryWord->type !== 'Allowed' && str_contains($worryWord->keyword, ' ')) {
                if (stripos($subject, $worryWord->keyword) !== false ||
                    stripos($body, $worryWord->keyword) !== false) {
                    Log::debug('Worry word phrase found', [
                        'keyword' => $worryWord->keyword,
                        'type' => $worryWord->type,
                    ]);

                    return true;
                }
            }
        }

        // Check individual words with exact matching (threshold = 1, so exact match only)
        $subjectWords = preg_split('/\b/', $subject);
        $bodyWords = preg_split('/\b/', $body);
        $allWords = array_merge($subjectWords, $bodyWords);

        foreach ($allWords as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }

            foreach ($worryWords as $worryWord) {
                if ($worryWord->type !== 'Allowed' && ! empty($worryWord->keyword)) {
                    // Check length ratio (0.75 to 1.25)
                    $ratio = strlen($word) / strlen($worryWord->keyword);
                    if ($ratio >= 0.75 && $ratio <= 1.25) {
                        // Exact match only (levenshtein distance < 1)
                        if (levenshtein(strtolower($worryWord->keyword), strtolower($word)) < 1) {
                            Log::debug('Worry word found', [
                                'word' => $word,
                                'keyword' => $worryWord->keyword,
                                'type' => $worryWord->type,
                            ]);

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Handle direct mail to users.
     *
     * Direct mail is sent to {something}@users.ilovefreegle.org where {something}
     * is a user's email address that can be looked up. This handles replies to
     * What's New emails and direct user-to-user communication.
     */
    private function handleDirectMail(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing direct mail', [
            'from' => $email->fromAddress,
            'to' => $email->envelopeTo,
        ]);

        // Find the recipient user by looking up the envelope-to address.
        // Uses findUserByEmail which handles Freegle-formatted addresses
        // (*-UID@users.ilovefreegle.org) and canonical email matching.
        $recipientUser = $this->findUserByEmail($email->envelopeTo);

        if ($recipientUser === null) {
            Log::info('Direct mail to unknown user address', [
                'to' => $email->envelopeTo,
            ]);

            return $this->dropped("Direct mail to unknown user address");
        }

        // Find the sender user
        $senderUser = $this->findUserByEmail($email->fromAddress);
        if ($senderUser === null) {
            Log::info('Direct mail from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return $this->dropped("Direct mail from unknown user");
        }

        // Don't create a chat between the same user
        if ($senderUser->id === $recipientUser->id) {
            Log::info('Direct mail to self - dropping', [
                'user_id' => $senderUser->id,
            ]);

            return $this->dropped("Direct mail to self");
        }

        // #6: Add unrecognised sender email to user profile (email forwarding scenario)
        $this->addEmailToUser($senderUser->id, $email->envelopeFrom);

        // Get or create chat between sender and recipient
        $chat = $this->getOrCreateUserChat($senderUser->id, $recipientUser->id);
        if ($chat === null) {
            Log::warning('Could not create chat for direct mail', [
                'from_user' => $senderUser->id,
                'to_user' => $recipientUser->id,
            ]);

            return $this->dropped("Could not create chat for direct mail");
        }

        // Try to find the original message this email is replying to.
        // 1. Check x-fd-msgid header (TN provides this for initial replies)
        // 2. Fall back to subject matching against recipient's recent posts
        $refMsgId = $this->findRefMessage($email, $recipientUser->id);

        // #22: Get SpamAssassin score for chat message
        $spamScore = $this->getSpamAssassinScore($email);

        // #20: Prepend subject to body when no refmsgid found.
        // When the email isn't paired to a specific post, the subject provides
        // important context that would otherwise be lost in the chat message.
        $prependSubject = ($refMsgId === null && ! empty($email->subject)) ? $email->subject : null;

        // Use TYPE_INTERESTED only when we found the post being replied to.
        // Without a refmsgid, use TYPE_DEFAULT since we can't link to a specific post.
        $this->createChatMessageFromEmail(
            $chat,
            $senderUser->id,
            $email,
            refMsgId: $refMsgId,
            type: $refMsgId !== null ? ChatMessage::TYPE_INTERESTED : ChatMessage::TYPE_DEFAULT,
            spamScore: $spamScore,
            prependSubject: $prependSubject
        );

        // Track email reply in email_tracking for AMP comparison stats.
        $this->trackEmailReply($chat->id, $senderUser->id);

        $this->lastRoutingContext = [
            'user_id' => $senderUser->id,
            'to_user_id' => $recipientUser->id,
            'chat_id' => $chat->id,
        ];

        Log::info('Created chat message from direct mail', [
            'chat_id' => $chat->id,
            'from_user' => $senderUser->id,
            'to_user' => $recipientUser->id,
            'refmsgid' => $refMsgId,
            'x_fd_msgid' => $email->getHeader('x-fd-msgid'),
        ]);

        return RoutingResult::TO_USER;
    }

    /**
     * Find the original message that an email is replying to.
     *
     * 1. Check x-fd-msgid header (TN provides this for initial replies to posts)
     * 2. Fall back to subject matching against the recipient user's recent messages
     */
    private function findRefMessage(ParsedEmail $email, int $recipientUserId): ?int
    {
        // TN puts a useful header in for the initial reply to a post.
        $fdMsgId = $email->getHeader('x-fd-msgid');
        if ($fdMsgId) {
            $msgId = (int) $fdMsgId;
            $exists = DB::table('messages')->where('id', $msgId)->exists();
            if ($exists) {
                Log::info('Found refmsgid from x-fd-msgid header', ['refmsgid' => $msgId]);

                return $msgId;
            }
        }

        // Fall back to subject matching against the recipient's recent posts
        // using Levenshtein distance for fuzzy matching.
        $subject = $email->subject ?? '';
        if (empty($subject)) {
            return null;
        }

        // Canonicalise the subject (strip Re:, punctuation, whitespace)
        $thisSubj = $this->canonicaliseSubject($subject);
        $thisSubj = preg_replace('/^re:/i', '', $thisSubj);
        $thisSubj = preg_replace('/[\-,.\s]/', '', $thisSubj);

        if (empty($thisSubj)) {
            return null;
        }

        // Get the recipient's recent messages (last 90 days)
        $messages = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('groups', 'groups.id', '=', 'messages_groups.groupid')
            ->where('messages.fromuser', $recipientUserId)
            ->whereIn('groups.type', ['Freegle', 'Reuse'])
            ->where('messages.arrival', '>', now()->subDays(90))
            ->select('messages.id', 'messages.subject')
            ->limit(1000)
            ->get();

        $bestMatch = null;
        $bestDist = PHP_INT_MAX;

        foreach ($messages as $msg) {
            $msgSubj = $this->canonicaliseSubject($msg->subject ?? '');
            $msgSubj = preg_replace('/[\-,.\s]/', '', $msgSubj);

            if (empty($msgSubj)) {
                continue;
            }

            $dist = levenshtein(strtolower($thisSubj), strtolower($msgSubj));
            $threshold = strlen($thisSubj) * 3 / 4;

            if ($dist <= $bestDist && $dist <= $threshold) {
                $bestDist = $dist;
                $bestMatch = $msg->id;
            }
        }

        if ($bestMatch !== null) {
            Log::info('Found refmsgid from subject matching', [
                'refmsgid' => $bestMatch,
                'distance' => $bestDist,
            ]);
        }

        return $bestMatch;
    }

    /**
     * Canonicalise a subject for matching - removes group tags like [GroupName] and duplicate spaces.
     */
    private function canonicaliseSubject(string $subject): string
    {
        // Remove group tag [GroupName]
        $subject = preg_replace('/^\[.*?\](.*)/', '$1', $subject);

        // Remove duplicate spaces
        $subject = preg_replace('/\s+/', ' ', $subject);

        return trim($subject);
    }

    /**
     * Find a group by name.
     */
    private function findGroup(?string $name): ?Group
    {
        if (empty($name)) {
            return null;
        }

        return Group::where('nameshort', $name)->first();
    }

    /**
     * Find a user by email address.
     *
     * Handles Freegle-formatted addresses (*-UID@users.ilovefreegle.org) by
     * extracting the UID directly. Also uses canonical email matching for
     * TN variant addresses and canonical email matching.
     */
    private function findUserByEmail(?string $email): ?User
    {
        if (empty($email)) {
            return null;
        }

        // Check for Freegle-formatted address with embedded UID.
        // Fall through to email lookup if the UID no longer exists (merged users
        // retain their old proxy email in users_emails pointing to the new user).
        $userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        if (preg_match('/.*\-(\d+)@'.preg_quote($userDomain, '/').'$/', $email, $matches)) {
            $user = User::find((int) $matches[1]);
            if ($user !== null) {
                return $user;
            }
        }

        // Try direct email lookup first
        $userEmail = UserEmail::where('email', $email)->first();
        if ($userEmail !== null) {
            return User::find($userEmail->userid);
        }

        // Try canonical email lookup (handles TN variants, gmail dots, etc.)
        $canon = $this->canonicalizeEmail($email);
        if ($canon !== $email) {
            $userEmail = UserEmail::where('canon', $canon)->first();
            if ($userEmail !== null) {
                return User::find($userEmail->userid);
            }
        }

        return null;
    }

    /**
     * Canonicalize an email address for matching.
     *
     * Handles: TN group suffixes, googlemail->gmail, plus addressing, gmail dots.
     */
    private function canonicalizeEmail(string $email): string
    {
        // Googlemail → Gmail
        $email = str_replace('@googlemail.', '@gmail.', $email);
        $email = str_replace('@googlemail.co.uk', '@gmail.co.uk', $email);

        // Strip TN group suffix: user-gNNNN@user.trashnothing.com → user@user.trashnothing.com
        if (preg_match('/(.*)\-(.*)(@user\.trashnothing\.com)/', $email, $matches)) {
            $email = $matches[1].$matches[3];
        }

        // Remove plus addressing (except Facebook proxy and leading +)
        if (
            str_starts_with($email, '+') === false &&
            preg_match('/(.*)\+(.*)(@.*)/', $email, $matches) &&
            strpos($email, '@proxymail.facebook.com') === false
        ) {
            $email = $matches[1].$matches[3];
        }

        // Remove dots in Gmail LHS
        $p = strpos($email, '@');
        if ($p !== false) {
            $lhs = substr($email, 0, $p);
            $rhs = substr($email, $p);

            if (stripos($rhs, '@gmail') !== false || stripos($rhs, '@googlemail') !== false) {
                $lhs = str_replace('.', '', $lhs);
            }

            // Remove dots from RHS for canonical comparison
            $email = $lhs.str_replace('.', '', $rhs);
        }

        return $email;
    }

    /**
     * Get or create a User2User chat between two users.
     */
    private function getOrCreateUserChat(int $userId1, int $userId2): ?ChatRoom
    {
        // Check if chat already exists in EITHER user order.
        // Old rooms from PHP createConversation() were not normalized, so user1/user2
        // can be in either order. We must check both to avoid creating duplicates.
        $chat = ChatRoom::where('chattype', 'User2User')
            ->where(function ($q) use ($userId1, $userId2) {
                $q->where(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId1)->where('user2', $userId2);
                })->orWhere(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId2)->where('user2', $userId1);
                });
            })
            ->first();

        if ($chat !== null) {
            return $chat;
        }

        // Normalize order (smaller ID first) for new rooms
        if ($userId1 > $userId2) {
            [$userId1, $userId2] = [$userId2, $userId1];
        }

        // Create new chat
        $chat = ChatRoom::create([
            'chattype' => 'User2User',
            'user1' => $userId1,
            'user2' => $userId2,
        ]);

        // Create roster entries for both users so the notification system can
        // track seen/emailed state. The legacy iznik-server code does this in
        // ChatRoom::createConversation() via updateRoster() for both users.
        // Without roster entries, users never receive email notifications
        // about new messages in this chat.
        DB::table('chat_roster')->insertOrIgnore([
            'chatid' => $chat->id,
            'userid' => $userId1,
        ]);

        DB::table('chat_roster')->insertOrIgnore([
            'chatid' => $chat->id,
            'userid' => $userId2,
        ]);

        Log::info('Created new User2User chat', [
            'chat_id' => $chat->id,
            'user1' => $userId1,
            'user2' => $userId2,
            'created_new' => true,
        ]);

        return $chat;
    }

    /**
     * Check if subject indicates a TAKEN or RECEIVED post.
     *
     * These are completion markers in the format: "TAKEN: item (location)" or "RECEIVED: item (location)".
     * They are swallowed silently as TO_SYSTEM since mods don't need to review them.
     */
    private function isTakenOrReceivedSubject(?string $subject): bool
    {
        if ($subject === null) {
            return false;
        }

        return (bool) preg_match('/^\s*(TAKEN|RECEIVED)\s*:/i', $subject);
    }

    /**
     * Check if email is a read receipt (MDN).
     *
     * Read receipts sent to chat notification addresses are misdirected and should be dropped.
     * We check for MDN content-type and disposition-notification headers.
     */
    private function isReadReceipt(ParsedEmail $email): bool
    {
        // Check Content-Type for disposition-notification
        $contentType = $email->getHeader('content-type') ?? '';
        if (str_contains(strtolower($contentType), 'disposition-notification')) {
            return true;
        }

        // Check for MDN-specific content disposition header
        $contentDisposition = $email->getHeader('content-disposition') ?? '';
        if (str_contains(strtolower($contentDisposition), 'notification')) {
            return true;
        }

        return false;
    }

    /**
     * Get or create a User2Mod chat for a user with a group's moderators.
     */
    private function getOrCreateUser2ModChat(int $userId, int $groupId): ?ChatRoom
    {
        return ChatRoom::getOrCreateUser2Mod($userId, $groupId);
    }

    /**
     * Determine the source header for a message based on email headers.
     *
     * Identifies the source of incoming emails (TrashNothing, Yahoo, Platform, etc.)
     * based on email headers.
     *
     * The priority order is:
     * 1. X-Freegle-Source header (explicit source)
     * 2. X-trash-nothing-Source header (TrashNothing posts, prefixed with "TN-")
     * 3. X-Mailer = "Yahoo Groups Message Poster" -> "Yahoo-Web"
     * 4. X-Mailer contains "Freegle Message Maker" -> "MessageMaker"
     * 5. From our domain -> "Platform"
     * 6. Default -> "Yahoo-Email" (historical name for emails from Freegle members)
     */
    private function determineSourceHeader(ParsedEmail $email): string
    {
        // 1. Try X-Freegle-Source header first
        $source = $email->getHeader('X-Freegle-Source');
        if ($source && $source !== 'Unknown') {
            return $source;
        }

        // 2. Try X-trash-nothing-Source and prepend TN-
        $tnSource = $email->getHeader('X-trash-nothing-Source');
        if ($tnSource) {
            return 'TN-'.$tnSource;
        }

        // 3. Check X-Mailer for Yahoo Groups
        $mailer = $email->getHeader('X-Mailer');
        if ($mailer === 'Yahoo Groups Message Poster') {
            return 'Yahoo-Web';
        }

        // 4. Check X-Mailer for Freegle Message Maker
        if ($mailer && str_contains($mailer, 'Freegle Message Maker')) {
            return 'MessageMaker';
        }

        // 5. Default based on whether from our domain
        // "Yahoo-Email" is the historical name used for any posts received via
        // email from Freegle members (not actually Yahoo-specific anymore)
        if (User::isInternalEmail($email->fromAddress)) {
            return 'Platform';
        }

        return 'Yahoo-Email';
    }

    /**
     * Find the closest postcode location ID for given coordinates.
     *
     * Uses spatial index to efficiently find the nearest postcode.
     * Uses an expanding spatial search to find the nearest postcode.
     *
     * @param  float  $lat  Latitude
     * @param  float  $lng  Longitude
     * @return int|null The location ID of the closest postcode, or null if not found
     */
    private function findClosestPostcodeId(float $lat, float $lng): ?int
    {
        $srid = config('freegle.srid', 3857);

        // Start with a small search radius and expand if needed
        $scan = 0.00001953125;

        while ($scan <= 0.2) {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

            $sql = "SELECT locations.id,
                           ST_distance(locations_spatial.geometry, ST_GeomFromText('POINT($lng $lat)', $srid)) AS dist
                    FROM locations_spatial
                    INNER JOIN locations ON locations.id = locations_spatial.locationid
                    WHERE MBRContains(ST_Envelope(ST_GeomFromText('$poly', $srid)), locations_spatial.geometry)
                      AND locations.type = 'Postcode'
                      AND LOCATE(' ', locations.name) > 0
                    ORDER BY dist ASC,
                             CASE WHEN ST_Dimension(locations_spatial.geometry) < 2 THEN 0
                                  ELSE ST_AREA(locations_spatial.geometry) END ASC
                    LIMIT 1";

            $result = DB::selectOne($sql);

            if ($result) {
                return (int) $result->id;
            }

            $scan *= 2;
        }

        return null;
    }

    /**
     * Extract location from subject line.
     *
     * Parses subjects like "OFFER: Sofa (Edinburgh)" to extract "Edinburgh"
     * then looks it up in the locations for this group.
     * Parses subjects like "OFFER: Sofa (Edinburgh)" to extract the location name.
     *
     * @param  string  $subject  The email subject
     * @param  int  $groupId  The group ID to search locations for
     * @return array|null Array with id, lat, lng or null if not found
     */
    private function extractLocationFromSubject(string $subject, int $groupId): ?array
    {
        // Parse the subject: "TYPE: item (location)"
        [$type, $item, $locationName] = $this->parseSubject($subject);

        if (! $locationName) {
            return null;
        }

        // Look up the location for this group
        // First try exact match on name
        $location = DB::table('locations')
            ->where('name', $locationName)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first(['id', 'lat', 'lng']);

        if ($location) {
            return [
                'id' => (int) $location->id,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
            ];
        }

        // Try case-insensitive search
        $location = DB::table('locations')
            ->whereRaw('LOWER(name) = ?', [strtolower($locationName)])
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first(['id', 'lat', 'lng']);

        if ($location) {
            return [
                'id' => (int) $location->id,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
            ];
        }

        return null;
    }

    /**
     * Parse subject to extract type, item, and location.
     *
     * Subject format: "TYPE: item (location)"
     * Location may contain brackets so we count backwards.
     * Handles nested parentheses by counting backwards from the end.
     *
     * @param  string  $subj  The subject line
     * @return array [type, item, location] - any may be null
     */
    private function parseSubject(string $subj): array
    {
        $type = null;
        $item = null;
        $location = null;

        $p = strpos($subj, ':');

        if ($p !== false) {
            $startp = $p;
            $rest = trim(substr($subj, $p + 1));
            $p = strlen($rest) - 1;

            if (substr($rest, -1) == ')') {
                $count = 0;

                do {
                    $curr = substr($rest, $p, 1);

                    if ($curr == '(') {
                        $count--;
                    } elseif ($curr == ')') {
                        $count++;
                    }

                    $p--;
                } while ($count > 0 && $p > 0);

                if ($count == 0) {
                    $type = trim(substr($subj, 0, $startp));
                    $location = trim(substr($rest, $p + 2, strlen($rest) - $p - 3));
                    $item = trim(substr($rest, 0, $p));
                }
            }
        }

        return [$type, $item, $location];
    }

    /**
     * Scrape TN (Trash Nothing) image URLs from text body.
     *
     * TN sends image links in the format https://trashnothing.com/pics/...
     * which link to pages containing the actual high-res image URLs.
     *
     * @param  string|null  $textBody  Message text body
     * @return array Array of image URLs to download
     */
    public function scrapeTnImageUrls(?string $textBody): array
    {
        if (! $textBody) {
            return [];
        }

        $imageUrls = [];

        // Find TN pic page URLs
        if (preg_match_all('/(https:\/\/trashnothing\.com\/pics\/.*)$/m', $textBody, $matches)) {
            $pageUrls = [];
            foreach ($matches[1] as $url) {
                $pageUrls[] = trim($url);
            }
            $pageUrls = array_unique($pageUrls);

            foreach ($pageUrls as $pageUrl) {
                // Fetch the TN pics page to extract actual image URLs
                $extractedUrls = $this->extractTnImageUrlsFromPage($pageUrl);
                $imageUrls = array_merge($imageUrls, $extractedUrls);
            }
        }

        return array_unique($imageUrls);
    }

    /**
     * Extract image URLs from a TN pics page.
     *
     * TN page structure has changed over time:
     * - Old: img inside anchor tag (parent href has high-res)
     * - New: anchor with high-res href separate from img tag
     *
     * We prioritize finding anchor hrefs with TN image URLs as they
     * typically have higher resolution than img src attributes.
     *
     * @param  string  $pageUrl  TN pics page URL
     * @return array Array of image URLs
     */
    private function extractTnImageUrlsFromPage(string $pageUrl): array
    {
        $imageUrls = [];

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(120)->get($pageUrl);

            if (! $response->successful()) {
                Log::warning('Failed to fetch TN pics page', [
                    'url' => $pageUrl,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $html = $response->body();
            $doc = new \DOMDocument;
            @$doc->loadHTML($html);

            // Strategy 1: Look for anchor tags with TN image URLs (high-res)
            $anchors = $doc->getElementsByTagName('a');
            foreach ($anchors as $anchor) {
                $href = $anchor->getAttribute('href');
                if ($this->isTnImageUrl($href)) {
                    $imageUrls[] = $href;
                }
            }

            // Strategy 2: Fall back to img src if no anchors found
            if (empty($imageUrls)) {
                $imgs = $doc->getElementsByTagName('img');
                foreach ($imgs as $img) {
                    $src = $img->getAttribute('src');
                    if ($this->isTnImageUrl($src)) {
                        $imageUrls[] = $src;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Exception fetching TN pics page', [
                'url' => $pageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return array_unique($imageUrls);
    }

    /**
     * Check if a URL is a TN image URL.
     */
    private function isTnImageUrl(?string $url): bool
    {
        if (! $url || strpos($url, 'https://') !== 0) {
            return false;
        }

        return strpos($url, 'trashnothing.com/img/') !== false ||
               strpos($url, 'img.trashnothing.com') !== false ||
               strpos($url, '/tn-photos/') !== false ||
               strpos($url, 'photos.trashnothing.com') !== false;
    }

    /**
     * Strip TN pic links from text body.
     *
     * Removes the "Check out the pictures..." text and TN pic URLs.
     *
     * @param  string|null  $textBody  Message text body
     * @return string|null Cleaned text body
     */
    public function stripTnPicLinks(?string $textBody): ?string
    {
        if (! $textBody) {
            return $textBody;
        }

        // Remove "Check out the pictures..." block with TN URLs
        return preg_replace(
            '/Check out the pictures[\s\S]*?https:\/\/trashnothing[\s\S]*?pics\/[a-zA-Z0-9]*/',
            '',
            $textBody
        );
    }

    /**
     * Download and upload TN images to tusd, creating attachment records.
     *
     * @param  int  $messageId  The message ID to attach images to
     * @param  array  $imageUrls  Array of image URLs to download
     * @return int Number of attachments created
     */
    public function createTnImageAttachments(int $messageId, array $imageUrls): int
    {
        $tusService = app(\App\Services\TusService::class);
        $created = 0;
        $isFirst = true;

        foreach ($imageUrls as $url) {
            try {
                // Download the image
                $response = \Illuminate\Support\Facades\Http::timeout(120)->get($url);

                if (! $response->successful()) {
                    Log::warning('Failed to download TN image', [
                        'url' => $url,
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $imageData = $response->body();
                $contentType = $response->header('Content-Type') ?? 'image/jpeg';

                // Compute perceptual hash for deduplication
                $hash = $this->computeImageHash($imageData);

                // Check if we already have this image (by hash)
                $existing = \App\Models\MessageAttachment::where('msgid', $messageId)
                    ->where('hash', $hash)
                    ->first();

                if ($existing) {
                    Log::debug('Skipping duplicate TN image', [
                        'message_id' => $messageId,
                        'hash' => $hash,
                    ]);

                    continue;
                }

                // Upload to tusd
                $tusUrl = $tusService->upload($imageData, $contentType);

                if (! $tusUrl) {
                    Log::warning('Failed to upload TN image to tusd', [
                        'url' => $url,
                    ]);

                    continue;
                }

                $externalUid = \App\Services\TusService::urlToExternalUid($tusUrl);

                // Create attachment record
                \App\Models\MessageAttachment::create([
                    'msgid' => $messageId,
                    'externaluid' => $externalUid,
                    'hash' => $hash,
                    'primary' => $isFirst,
                ]);

                $created++;
                $isFirst = false;

                Log::debug('Created TN image attachment', [
                    'message_id' => $messageId,
                    'url' => $url,
                    'externaluid' => $externalUid,
                ]);
            } catch (\Exception $e) {
                Log::error('Exception processing TN image', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * Add an email address to a user's profile if not already present.
     *
     * This handles email forwarding scenarios where a user replies from an
     * address we don't know about. Legacy: User::addEmail($email, 0, false)
     */
    private function addEmailToUser(int $userId, ?string $email): void
    {
        if (empty($email)) {
            return;
        }

        $email = trim($email);

        // Don't add system addresses
        $groupDomain = config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
        $userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');

        if (stripos($email, '-owner@yahoogroups.co') !== FALSE ||
            stripos($email, '-volunteers@' . $groupDomain) !== FALSE ||
            stripos($email, '-auto@' . $groupDomain) !== FALSE ||
            stripos($email, 'replyto-') !== FALSE ||
            stripos($email, 'notify-') !== FALSE) {
            return;
        }

        // Check if this email is already known
        $existing = UserEmail::where('email', $email)->first();
        if ($existing !== null) {
            return;
        }

        try {
            DB::table('users_emails')->insert([
                'userid' => $userId,
                'email' => $email,
                'preferred' => 0,
                'canon' => $this->canonicalizeEmail($email),
            ]);

            Log::info('Added forwarding email to user', [
                'user_id' => $userId,
                'email' => $email,
            ]);
        } catch (\Exception $e) {
            // Duplicate key or other constraint - not fatal
            Log::debug('Could not add email to user', [
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send FBL notification email to the user.
     *
     * Sends branded MJML email telling the user their emails have been
     * turned off because they marked a Freegle email as spam. Provides
     * links to settings page and unsubscribe.
     */
    private function sendFblNotificationEmail(User $user, string $recipientEmail): void
    {
        try {
            MailFacade::send(new FblNotification($user, $recipientEmail));

            Log::info('Sent FBL notification email', [
                'user_id' => $user->id,
                'to' => $recipientEmail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send FBL notification email', [
                'user_id' => $user->id,
                'to' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification email about closed group to the sender.
     *
     * Matches legacy MailRouter behaviour for replies to messages on closed groups.
     */
    private function sendClosedGroupEmail(?string $toAddress): void
    {
        if (empty($toAddress)) {
            return;
        }

        try {
            $userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
            $noreplyAddr = 'noreply@' . $userDomain;

            MailFacade::raw(
                "This Freegle community is currently closed.\r\n\r\nThis is an automated message - please do not reply.",
                function ($message) use ($toAddress, $noreplyAddr) {
                    $message->to($toAddress)
                        ->from($noreplyAddr)
                        ->subject('This community is currently closed');
                }
            );

            Log::info('Sent closed group notification', ['to' => $toAddress]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send closed group notification', [
                'to' => $toAddress,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a message to the spatial index for search results.
     *
     * Called when a message is approved. Matches legacy Message::addToSpatialIndex().
     */
    private function addToSpatialIndex(int $messageId, int $groupId): void
    {
        $message = Message::find($messageId);
        if (! $message || (! $message->lat && ! $message->lng)) {
            return;
        }

        $srid = config('freegle.srid', 3857);

        // Get arrival from messages_groups
        $mg = DB::table('messages_groups')
            ->where('msgid', $messageId)
            ->where('groupid', $groupId)
            ->first();

        $arrival = $mg->arrival ?? now();
        $msgType = $message->type;

        try {
            $sql = "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                    VALUES (?, ST_GeomFromText('POINT({$message->lng} {$message->lat})', ?), ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    point = ST_GeomFromText('POINT({$message->lng} {$message->lat})', ?),
                    groupid = ?, msgtype = ?, arrival = ?";

            DB::statement($sql, [
                $messageId,
                $srid,
                $groupId,
                $msgType,
                $arrival,
                $srid,
                $groupId,
                $msgType,
                $arrival,
            ]);

            Log::debug('Added message to spatial index', [
                'message_id' => $messageId,
                'group_id' => $groupId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to add to spatial index', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify group moderators of new pending work via push notification.
     *
     * Uses PushNotificationService to send FCM notifications to moderators
     * who have registered for ModTools push notifications.
     */
    private function notifyGroupMods(int $groupId): void
    {
        try {
            $pushService = app(\App\Services\PushNotificationService::class);
            $count = $pushService->notifyGroupMods($groupId);
            Log::info('Notified group mods of pending work', [
                'group_id' => $groupId,
                'notifications_sent' => $count,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal - don't fail mail processing if push notification fails
            Log::warning('Failed to notify group mods', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get SpamAssassin score for an email.
     *
     * Returns the score as a float, or null if SpamAssassin is not available.
     */
    private function getSpamAssassinScore(ParsedEmail $email): ?float
    {
        try {
            [$score, ] = $this->spamCheck->checkSpamAssassin(
                $email->rawMessage,
                $email->subject ?? ''
            );

            return $score;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the preferred email address for a user.
     */
    private function getPreferredEmail(int $userId): ?string
    {
        $email = UserEmail::where('userid', $userId)
            ->where('preferred', 1)
            ->first();

        if ($email) {
            return $email->email;
        }

        // Fall back to any email
        $email = UserEmail::where('userid', $userId)->first();
        return $email?->email;
    }

    /**
     * Compute perceptual hash for an image.
     *
     * Uses ImageHash library to generate a 16-character hex hash
     * that can be used to detect duplicate/similar images.
     *
     * @param  string  $imageData  Binary image data
     * @return string|null 16-character hex hash, or null if failed
     */
    private function computeImageHash(string $imageData): ?string
    {
        try {
            $img = @\imagecreatefromstring($imageData);
            if (! $img) {
                // Fall back to md5 if GD can't parse the image
                return substr(md5($imageData), 0, 16);
            }

            // Use Jenssegers\ImageHash if available for perceptual hashing
            if (class_exists(\Jenssegers\ImageHash\ImageHash::class)) {
                $hasher = new \Jenssegers\ImageHash\ImageHash;
                $hash = $hasher->hash($img)->toHex();
                \imagedestroy($img);

                return substr($hash, 0, 16);
            }

            \imagedestroy($img);

            // Fallback: use md5 of image data
            return substr(md5($imageData), 0, 16);
        } catch (\Exception $e) {
            Log::warning('Failed to compute image hash', [
                'error' => $e->getMessage(),
            ]);

            return substr(md5($imageData), 0, 16);
        }
    }

    /**
     * Record a processing failure against a message.
     *
     * V1 parity: Message::recordFailure() in iznik-server/include/message/Message.php.
     * Increments retrycount and sets retrylastfailure so the message can be retried later.
     * Also logs the failure to the logs table (type=Message, subtype=Failure).
     */
    public function recordFailure(int $messageId, string $reason): void
    {
        DB::table('messages')
            ->where('id', $messageId)
            ->update([
                'retrycount' => DB::raw('retrycount + 1'),
                'retrylastfailure' => now(),
            ]);

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Failure',
            'msgid' => $messageId,
            'text' => $reason,
        ]);

        Log::warning('Recorded message failure', [
            'message_id' => $messageId,
            'reason' => $reason,
        ]);
    }
}
