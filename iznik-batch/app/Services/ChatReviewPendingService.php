<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ChatReviewPendingService
{
    // Email of the system modtools user used to mark auto-rejected messages.
    public const SYSTEM_MOD_EMAIL = 'modtools@modtools.org';

    // Messages stuck in review for more than this many days are auto-rejected.
    public const AUTO_REJECT_DAYS = 7;

    // Messages pending review for more than this many hours trigger a mod notification.
    public const NOTIFY_HOURS = 48;

    /**
     * Auto-reject stale review messages.
     *
     * There is no national mod notification for messages pending review yet —
     * that was previously sent per group, and has no group-free equivalent —
     * so groups_notified is always 0 until one is built.
     *
     * @return array{auto_rejected: int, groups_notified: int}
     */
    public function processReview(bool $dryRun = false): array
    {
        $systemUserId = $this->getSystemUserId();

        $autoRejected = $this->autoRejectStale($systemUserId, $dryRun);

        return [
            'auto_rejected' => $autoRejected,
            'groups_notified' => 0,
        ];
    }

    private function getSystemUserId(): ?int
    {
        $row = DB::table('users_emails')
            ->where('email', self::SYSTEM_MOD_EMAIL)
            ->value('userid');

        return $row ? (int) $row : null;
    }

    private function autoRejectStale(?int $systemUserId, bool $dryRun): int
    {
        $cutoff = now()->subDays(self::AUTO_REJECT_DAYS)->toDateTimeString();

        if ($dryRun) {
            return DB::table('chat_messages')
                ->where('date', '<', $cutoff)
                ->where('reviewrequired', 1)
                ->whereNull('reviewedby')
                ->count();
        }

        return DB::table('chat_messages')
            ->where('date', '<', $cutoff)
            ->where('reviewrequired', 1)
            ->whereNull('reviewedby')
            ->update([
                'reviewedby' => $systemUserId,
                'reviewrejected' => 1,
            ]);
    }
}
