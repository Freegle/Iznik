<?php

namespace App\Services;

use App\Mail\Noticeboard\NoticeboardThankMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NoticeboardService
{
    /**
     * Send thank-you emails to users who added noticeboards that haven't been thanked yet.
     *
     * Mirrors V1 cron/noticeboards.php:
     * - One email per user (not per noticeboard) — multiple noticeboards from the same user
     *   count as one thank-you.
     * - After thanking, marks all noticeboards for that user as thanked.
     *
     * @return int Number of users thanked.
     */
    public function thankUsers(bool $dryRun = false): int
    {
        $noticeboards = DB::table('noticeboards')
            ->whereNull('thanked')
            ->whereNotNull('addedby')
            ->where('active', 1)
            ->whereNotNull('name')
            ->select('id', 'addedby')
            ->get();

        $thanked = [];
        $count = 0;

        foreach ($noticeboards as $board) {
            $userId = $board->addedby;

            if (isset($thanked[$userId])) {
                continue;
            }

            $thanked[$userId] = true;

            $user = DB::table('users')
                ->where('id', $userId)
                ->whereNull('deleted')
                ->select('id', 'fullname', 'firstname', 'lastname')
                ->first();

            if (!$user) {
                continue;
            }

            // V1 parity: pick the best external email; skip if user has none.
            $email = \App\Models\User::find($userId)?->email_preferred;

            if (!$email) {
                continue;
            }

            $name = $user->fullname
                ?: trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''))
                ?: 'Freegle User';

            if ($dryRun) {
                Log::info('[DRY RUN] Would thank noticeboard user', [
                    'user_id' => $userId,
                    'email' => $email,
                ]);
                $count++;
                continue;
            }

            try {
                Mail::send(new NoticeboardThankMail(
                    recipientEmail: $email,
                    recipientName: $name,
                ));
            } catch (\Throwable $e) {
                Log::error('NoticeboardService: failed to send thank email', [
                    'user_id' => $userId,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            DB::table('noticeboards')
                ->where('addedby', $userId)
                ->update(['thanked' => now()]);

            Log::info('NoticeboardService: thanked user', ['user_id' => $userId]);
            $count++;
        }

        return $count;
    }
}
