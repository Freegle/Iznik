<?php

namespace App\Services\Lockdown;

use App\Models\ChatMessage;
use App\Models\MessageGroup;
use App\Models\Newsfeed;
use App\Models\SpamUser;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Filters the waiting send queue against what Support removed while email was held
 * (plan 2026-09-27-lockdown-switch.md, section 11.8).
 *
 * Deferring generation (11.7) leaves pending/ holding mail rendered before the mail loops
 * saw the press, some of it about the wave's early messages. Every spooled file that
 * describes member content carries an `about` field (EmailSpoolerService::spool(), from
 * the Mailable's DescribesMemberContent::about()); a file with no `about` field - not a
 * member-content Mailable, or spooled before this existed - is left alone and sent as
 * normal.
 *
 * For a file that does carry one, each id is checked against CURRENT state, not whatever
 * was true when the mail was built: a chat message that was rejected or never delivered,
 * a post not currently Approved anywhere, a ChitChat item deleted or still hidden, or an
 * author now in spam_users. Any one hit removes the whole file - moved to a
 * lockdown-removed directory for the incident report, never straight deletion.
 */
class LockdownFilterSpoolService
{
    public function __construct(
        private readonly EmailSpoolerService $spooler,
        private readonly LockdownService $lockdown,
    ) {
    }

    /**
     * @return array{checked: int, removed: int, errors: int}
     */
    public function filter(): array
    {
        $stats = ['checked' => 0, 'removed' => 0, 'errors' => 0];
        $pendingDir = $this->spooler->getPendingDir();

        foreach (glob($pendingDir . '/*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || !array_key_exists('about', $data) || $data['about'] === null) {
                // No about() - not a member-content Mailable, or an older file spooled
                // before this field existed. Either way, sent as normal (section 11.8).
                continue;
            }

            $stats['checked']++;

            try {
                if ($this->shouldRemove($data['about'])) {
                    $this->remove($path, $data);
                    $stats['removed']++;
                }
            } catch (\Throwable $e) {
                Log::warning('lockdown:filter-spool could not check a waiting file', [
                    'file' => basename($path),
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * @param array{chatmessages?: array, messages?: array, newsfeed?: array, users?: array} $about
     */
    private function shouldRemove(array $about): bool
    {
        foreach ($about['chatmessages'] ?? [] as $id) {
            if ($this->chatMessageIsGone((int) $id)) {
                return true;
            }
        }

        foreach ($about['messages'] ?? [] as $id) {
            if ($this->postIsGone((int) $id)) {
                return true;
            }
        }

        foreach ($about['newsfeed'] ?? [] as $id) {
            if ($this->newsfeedIsGone((int) $id)) {
                return true;
            }
        }

        foreach ($about['users'] ?? [] as $id) {
            if ($this->isSpammer((int) $id)) {
                return true;
            }
        }

        foreach ($about['admins'] ?? [] as $id) {
            if ($this->adminIsWithdrawn((int) $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Withdrawn means sent back to pending (as the lockdown does to moderators' unsent
     * admins, LockdownHoldsService::withdrawAdmins()) or deleted.
     */
    private function adminIsWithdrawn(int $id): bool
    {
        $admin = DB::table('admins')->where('id', $id)->first(['pending']);

        return $admin === null || (int) $admin->pending === 1;
    }

    /**
     * A removed admin mail was recorded as sent to its member. Forget that, so the member
     * gets it if a moderator approves the admin again.
     */
    private function forgetAdminDelivery(array $data): void
    {
        $userId = (int) ($data['headers']['X-Freegle-User-Id'] ?? 0);
        if ($userId === 0) {
            return;
        }

        foreach ($data['about']['admins'] ?? [] as $id) {
            $admin = DB::table('admins')->where('id', (int) $id)->first(['id', 'parentid']);
            $dedupId = $admin ? ((int) $admin->parentid ?: (int) $admin->id) : (int) $id;
            DB::table('admins_users')->where('adminid', $dedupId)->where('userid', $userId)->delete();
        }
    }

    /**
     * Gone means never delivered, or delivered and then rejected - the same line
     * ChatMessage::isVisible() already draws (reviewrejected, reviewrequired,
     * processingsuccessful), reused rather than re-derived here.
     */
    private function chatMessageIsGone(int $id): bool
    {
        $message = ChatMessage::find($id);

        return $message === null || !$message->isVisible();
    }

    /**
     * A direct query on messages_groups - collection Approved AND deleted = 0 - never
     * Message::scopeApproved() (whereHas with no deleted check, so a retracted rippled
     * copy still reads Approved) or a messages_spatial join (approved-only, silently
     * drops a Pending post entirely). See .claude/rules/laravel-batch-traps.md.
     */
    private function postIsGone(int $id): bool
    {
        return !MessageGroup::where('msgid', $id)->approved()->notDeleted()->exists();
    }

    private function newsfeedIsGone(int $id): bool
    {
        $item = Newsfeed::find($id);

        return $item === null || $item->deleted !== null || $item->hidden !== null;
    }

    /**
     * Uses the same spam_users query shape as the rest of the lockdown code, so the checks
     * cannot quietly drift apart.
     */
    private function isSpammer(int $id): bool
    {
        return SpamUser::where('userid', $id)->where('collection', 'Spammer')->exists();
    }

    private function remove(string $path, array $data): void
    {
        $removedDir = $this->spooler->getLockdownRemovedDir();

        if (!is_dir($removedDir)) {
            mkdir($removedDir, 0755, true);
        }

        $destination = $removedDir . '/' . basename($path);

        if (!@rename($path, $destination)) {
            throw new \RuntimeException("could not move {$path} to lockdown-removed");
        }

        $type = $data['email_type'] ?? 'unknown';

        if (!empty($data['about']['admins'])) {
            $this->forgetAdminDelivery($data);
        }

        Log::info('lockdown:filter-spool removed a waiting file', [
            'file' => basename($path),
            'type' => $type,
            'mailable_class' => $data['mailable_class'] ?? null,
        ]);

        $this->lockdown->count('filtered:email:' . $type);
    }
}
