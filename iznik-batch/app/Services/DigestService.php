<?php

namespace App\Services;

use App\Mail\Digest\MultipleDigest;
use App\Mail\Digest\SingleDigest;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DigestService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'Digest';

    /**
     * Send digests for a group at a specific frequency.
     */
    public function sendDigestForGroup(Group $group, int $frequency, bool $dryRun = false): array
    {
        $stats = [
            'members_processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        // Check if this email type is enabled.
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('Digest emails disabled via FREEGLE_MAIL_ENABLED_TYPES');

            return $stats;
        }

        // Check if group is closed.
        if ($group->isClosed()) {
            Log::info("Skipping closed group: {$group->nameshort}");
            return $stats;
        }

        // Get the digest record for this group/frequency.
        $digest = $this->getOrCreateDigest($group, $frequency);

        // Get new messages since last digest.
        $messages = $this->getNewMessages($group, $digest);

        if ($messages->isEmpty()) {
            Log::debug("No new messages for {$group->nameshort} at frequency {$frequency}");
            return $stats;
        }

        // Get members who want this frequency.
        $members = $this->getMembersForFrequency($group, $frequency);

        foreach ($members as $membership) {
            try {
                $user = $membership->user;

                if (!$user || !$user->email_preferred) {
                    continue;
                }

                // Filter out messages posted by this user - they don't need to see their own posts.
                $userMessages = $messages->filter(fn($msg) => $msg->fromuser !== $user->id);

                if ($userMessages->isEmpty()) {
                    continue;
                }

                if (!$dryRun) {
                    $this->sendDigestToUser($user, $group, $userMessages, $frequency);
                }
                $stats['emails_sent']++;
            } catch (\Exception $e) {
                Log::error("Failed to send digest to user {$membership->userid}: " . $e->getMessage());
                $stats['errors']++;
            }

            $stats['members_processed']++;
        }

        // Update digest record with latest message.
        if (!$dryRun) {
            $this->updateDigestRecord($digest, $messages);
        }

        return $stats;
    }

    /**
     * Get or create a digest record for a group/frequency.
     */
    protected function getOrCreateDigest(Group $group, int $frequency): GroupDigest
    {
        return GroupDigest::firstOrCreate(
            [
                'groupid' => $group->id,
                'frequency' => $frequency,
            ],
            [
                'msgid' => NULL,
                'msgdate' => NULL,
            ]
        );
    }

    /**
     * Get new messages since the last digest was sent.
     */
    protected function getNewMessages(Group $group, GroupDigest $digest): Collection
    {
        $query = Message::select('messages.*')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $group->id)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->orderBy('messages_groups.arrival', 'asc');

        // Only get messages after the last digest.
        if ($digest->msgdate) {
            $query->where('messages_groups.arrival', '>', $digest->msgdate);
        } else {
            // First digest - only get messages from the last hour.
            $query->where('messages_groups.arrival', '>=', now()->subHour());
        }

        return $query->with(['attachments', 'fromUser'])->get();
    }

    /**
     * Get members who want digests at this frequency.
     */
    protected function getMembersForFrequency(Group $group, int $frequency): Collection
    {
        return Membership::where('groupid', $group->id)
            ->where('collection', Membership::COLLECTION_APPROVED)
            ->where('emailfrequency', $frequency)
            ->with('user')
            ->get();
    }

    /**
     * Send a digest email to a specific user.
     */
    protected function sendDigestToUser(User $user, Group $group, Collection $messages, int $frequency): void
    {
        if ($messages->count() === 1) {
            // Single message - send individual email.
            app(\App\Services\EmailSpoolerService::class)->spool(new SingleDigest($user, $group, $messages->first(), $frequency));
        } else {
            // Multiple messages - send digest.
            app(\App\Services\EmailSpoolerService::class)->spool(new MultipleDigest($user, $group, $messages, $frequency));
        }
    }

    /**
     * Update the digest record with the latest message sent.
     */
    protected function updateDigestRecord(GroupDigest $digest, Collection $messages): void
    {
        $lastMessage = $messages->last();

        if ($lastMessage) {
            $digest->update([
                'msgid' => $lastMessage->id,
                'msgdate' => $lastMessage->arrival,
                'ended' => now(),
            ]);
        }
    }

    /**
     * Get all active Freegle groups.
     */
    public function getActiveGroups(): Collection
    {
        return Group::activeFreegle()->get();
    }

    /**
     * Get valid digest frequencies.
     */
    public static function getValidFrequencies(): array
    {
        return [
            -1,  // Immediate
            1,   // Hourly
            2,   // Every 2 hours
            4,   // Every 4 hours
            8,   // Every 8 hours
            24,  // Daily
        ];
    }
}
