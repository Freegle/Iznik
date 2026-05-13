<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes pending moderation work per moderator and manages notification dedup.
 *
 * Mirrors V1 cron/mod_notifs.php:
 * - For each active Freegle group, find moderators active in the last 90 days.
 * - For each mod, count pending work items filtered by their notification threshold (minage hours).
 * - Skip if minage < 0 (notifications disabled).
 * - Only send if the summary has changed since last notification, or it's been > 24h.
 */
class ModNotifService
{
    // Default notification thresholds (hours old items must be before notifying)
    private const DEFAULT_ACTIVE_MOD_THRESHOLD = 4;

    private const DEFAULT_BACKUP_MOD_THRESHOLD = 12;

    // Moderators inactive for more than this many days are skipped
    private const MAX_INACTIVE_DAYS = 90;

    // Re-send the same summary after this many seconds even if unchanged
    private const RESEND_INTERVAL_SECONDS = 24 * 3600;

    /**
     * Build a pending-work notification for every eligible mod on every active group.
     *
     * Returns an array of notification records:
     * [
     *   'user_id'      => int,
     *   'email'        => string,
     *   'name'         => string,
     *   'text_summary' => string,
     *   'html_summary' => string,
     *   'total'        => int,
     *   'subject'      => string,
     * ]
     */
    public function getNotificationsToSend(): array
    {
        $notifications = [];

        // Per-mod accumulator keyed by user ID so a mod on many groups gets one email.
        $modData = [];

        $groups = Group::activeFreegle()->get();

        foreach ($groups as $group) {
            $mods = Membership::where('groupid', $group->id)
                ->whereIn('role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])
                ->get();

            foreach ($mods as $membership) {
                $modId = $membership->userid;
                $isActive = $membership->isActiveMod();
                $modSettings = $this->getModSettings($modId, $membership, $isActive);

                if ($modSettings['minage'] < 0) {
                    continue;
                }

                // Skip mods who have been inactive for too long
                if (!$this->isModRecentlyActive($modId)) {
                    continue;
                }

                $work = $this->getPendingWork($modId, $group->id, $modSettings['minage']);
                $chatReview = $this->getChatReviewCount($modId, $modSettings['minage']);
                $total = array_sum($work) + $chatReview;

                if ($total === 0) {
                    continue;
                }

                if (!isset($modData[$modId])) {
                    $user = User::find($modId);
                    $email = UserEmail::where('userid', $modId)->where('preferred', 1)->first();

                    if (!$user || !$email) {
                        continue;
                    }

                    $modData[$modId] = [
                        'user_id' => $modId,
                        'email' => $email->email,
                        'name' => $user->displayname ?? $user->fullname ?? 'Moderator',
                        'groups' => [],
                        'chat_review' => 0,
                    ];
                }

                // Use assignment, not accumulation. getChatReviewCount is a global query
                // with no group filter, so it returns the same value on every group
                // iteration for the same mod. Using += would multiply it by group count.
                $modData[$modId]['chat_review'] = $chatReview;

                $nonZeroWork = array_filter($work, fn ($v) => $v > 0);
                if (!empty($nonZeroWork)) {
                    $modData[$modId]['groups'][$group->nameshort] = $nonZeroWork;
                }
            }
        }

        $modtoolsUrl = config('freegle.sites.mod', 'https://modtools.org');

        foreach ($modData as $modId => $data) {
            $textSummary = $this->buildTextSummary($data['groups'], $data['chat_review'], $modtoolsUrl);
            $htmlSummary = $this->buildHtmlSummary($data['groups'], $data['chat_review']);

            if (!$this->shouldSend($modId, $textSummary)) {
                continue;
            }

            $total = $data['chat_review'];
            foreach ($data['groups'] as $groupWork) {
                $total += array_sum($groupWork);
            }

            $notifications[] = [
                'user_id' => $modId,
                'email' => $data['email'],
                'name' => $data['name'],
                'text_summary' => $textSummary,
                'html_summary' => $htmlSummary,
                'total' => $total,
                'subject' => "MODERATE: {$total} thing" . ($total === 1 ? '' : 's') . ' to do',
            ];
        }

        return $notifications;
    }

    /**
     * Get notification threshold settings for a moderator.
     */
    public function getModSettings(int $modId, Membership $membership, bool $isActive): array
    {
        $user = User::find($modId);
        $settings = $user ? ($user->settings ?? []) : [];

        $activeMinage = (int) ($settings['modnotifs'] ?? self::DEFAULT_ACTIVE_MOD_THRESHOLD);
        $backupMinage = (int) ($settings['backupmodnotifs'] ?? self::DEFAULT_BACKUP_MOD_THRESHOLD);

        return [
            'minage' => $isActive ? $activeMinage : $backupMinage,
        ];
    }

    /**
     * Check if the moderator has been active (approved a message) in the last 90 days.
     *
     * A value of 0 means they approved something today.
     */
    public function isModRecentlyActive(int $modId): bool
    {
        $row = DB::table('messages_groups')
            ->selectRaw('DATEDIFF(NOW(), MAX(arrival)) AS activeago')
            ->where('approvedby', $modId)
            ->first();

        if (!$row) {
            return false;
        }

        $activeago = $row->activeago;

        // '0' or null means approved today; an integer <= 90 means recently active
        if ($activeago === null) {
            return false;
        }

        if ($activeago == '0' || ($activeago !== null && (int) $activeago <= self::MAX_INACTIVE_DAYS)) {
            return true;
        }

        return false;
    }

    /**
     * Count pending work items for a moderator on a specific group.
     *
     * @param  int       $minage  Hours old items must be before they appear (0 = all items)
     * @return array<string, int>
     */
    public function getPendingWork(int $modId, int $groupId, int $minage): array
    {
        $minageFilter = $minage > 0 ? now()->subHours($minage) : null;
        $now = now();
        $earliest = now()->subDays(31)->startOfDay();

        // Pending messages
        $pendingMessages = DB::table('messages')
            ->join('messages_groups', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.groupid', $groupId)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.heldby')
            ->whereNull('messages.deleted')
            ->when($minageFilter, fn ($q) => $q->where('messages_groups.arrival', '<', $minageFilter))
            ->count();

        // Pending community events
        $pendingEvents = DB::table('communityevents')
            ->join('communityevents_dates', 'communityevents_dates.eventid', '=', 'communityevents.id')
            ->join('communityevents_groups', 'communityevents_groups.eventid', '=', 'communityevents.id')
            ->where('communityevents_groups.groupid', $groupId)
            ->where('communityevents.pending', 1)
            ->where('communityevents.deleted', 0)
            ->where('communityevents_dates.end', '>=', $now)
            ->when($minageFilter, fn ($q) => $q->where('communityevents.added', '<', $minageFilter))
            ->distinct('communityevents.id')
            ->count('communityevents.id');

        // Pending volunteering
        $pendingVolunteering = DB::table('volunteering')
            ->join('volunteering_groups', 'volunteering_groups.volunteeringid', '=', 'volunteering.id')
            ->leftJoin('volunteering_dates', 'volunteering_dates.volunteeringid', '=', 'volunteering.id')
            ->where('volunteering_groups.groupid', $groupId)
            ->where('volunteering.pending', 1)
            ->where('volunteering.deleted', 0)
            ->where('volunteering.expired', 0)
            ->where(fn ($q) => $q->whereNull('volunteering_dates.applyby')->orWhere('volunteering_dates.applyby', '>=', $now))
            ->where(fn ($q) => $q->whereNull('volunteering_dates.end')->orWhere('volunteering_dates.end', '>=', $now))
            ->when($minageFilter, fn ($q) => $q->where('volunteering.added', '<', $minageFilter))
            ->distinct('volunteering.id')
            ->count('volunteering.id');

        // Members to review
        $membersToReview = DB::table('memberships')
            ->where('groupid', $groupId)
            ->whereNotNull('reviewrequestedat')
            ->when($minageFilter, fn ($q) => $q->where('reviewrequestedat', '>=', $minageFilter))
            ->where(fn ($q) => $q->whereNull('reviewedat')
                ->orWhereRaw('DATE(reviewedat) < DATE_SUB(NOW(), INTERVAL 31 DAY)'))
            ->count();

        // Pending admins
        $pendingAdmins = DB::table('admins')
            ->where('groupid', $groupId)
            ->whereNull('complete')
            ->where('pending', 1)
            ->whereNull('heldby')
            ->where('created', '>=', $earliest)
            ->distinct('id')
            ->count('id');

        return [
            'Pending Messages' => $pendingMessages,
            'Pending Community Events' => $pendingEvents,
            'Pending Volunteering Opportunities' => $pendingVolunteering,
            'Members to Review' => $membersToReview,
            'Pending Admins' => $pendingAdmins,
        ];
    }

    /**
     * Count chat messages awaiting review by this moderator.
     */
    public function getChatReviewCount(int $modId, int $minage): int
    {
        $minageFilter = $minage > 0 ? now()->subHours($minage) : null;

        $count = DB::table('chat_messages')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->where('chat_messages.reviewrequired', 1)
            ->whereNull('chat_messages.reviewedby')
            ->when($minageFilter, fn ($q) => $q->where('chat_messages.date', '<', $minageFilter))
            ->count();

        return $count;
    }

    /**
     * Build plain-text summary of pending work.
     */
    public function buildTextSummary(array $groupWork, int $chatReview, string $modtoolsUrl): string
    {
        $text = "There's stuff to do on ModTools:\r\n\r\n";

        if ($chatReview > 0) {
            $text .= "You have {$chatReview} chat message" . ($chatReview > 1 ? 's' : '') . " to review.\r\n\r\n";
        }

        foreach ($groupWork as $groupName => $work) {
            $text .= "\r\n{$groupName}\r\n:";
            foreach ($work as $key => $val) {
                if ($val > 0) {
                    $text .= "{$key}: {$val}\r\n";
                }
            }
        }

        $text .= "\r\nYou can control how often you get these mails or turn them off entirely from https://{$modtoolsUrl}/settings\r\n";

        return $text;
    }

    /**
     * Build HTML summary for MJML template.
     */
    public function buildHtmlSummary(array $groupWork, int $chatReview): string
    {
        $html = '';

        if ($chatReview > 0) {
            $html .= "<p>You have <b>{$chatReview}</b> chat message" . ($chatReview > 1 ? 's' : '') . ' to review.</p>';
        }

        foreach ($groupWork as $groupName => $work) {
            $html .= "<p>{$groupName}</p><ul>";
            foreach ($work as $key => $val) {
                if ($val > 0) {
                    $html .= "<li>{$key}: <b>{$val}</b></li>";
                }
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * Check whether to send a notification for this moderator.
     *
     * Sends if: no previous record, summary has changed, or it's been > 24h since last send.
     */
    public function shouldSend(int $modId, string $textSummary): bool
    {
        $record = DB::table('modnotifs')->where('userid', $modId)->first();

        if (!$record) {
            return true;
        }

        if ($record->data !== $textSummary) {
            return true;
        }

        $age = Carbon::parse($record->timestamp)->diffInSeconds(now());

        return $age > self::RESEND_INTERVAL_SECONDS;
    }

    /**
     * Record that a notification was sent.
     */
    public function recordSent(int $modId, string $textSummary): void
    {
        DB::table('modnotifs')->updateOrInsert(
            ['userid' => $modId],
            ['data' => $textSummary, 'timestamp' => now()]
        );
    }
}
