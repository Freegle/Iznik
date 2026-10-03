<?php

namespace App\Services;

use App\Mail\Stories\AskMail;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StoriesAskService
{
    // Minimum outcome count before we ask for a story
    public const ASK_OUTCOME_THRESHOLD = 3;

    // Minimum offer count before we ask for a story
    public const ASK_OFFER_THRESHOLD = 5;

    // V1 origin date — don't consider messages before this
    public const EARLIEST_DATE = '2016-09-06';

    /**
     * Ask eligible users to share their Freegle story.
     *
     * @return array{asked: int, considered: int}
     */
    public function askForStories(bool $dryRun = false): array
    {
        $earliest = max(
            strtotime(self::EARLIEST_DATE),
            strtotime('midnight 90 days ago')
        );
        $earliestDate = date('Y-m-d', $earliest);

        // Find users who have posted messages since $earliest and haven't been asked yet
        $candidates = DB::table('messages')
            ->leftJoin('users_stories_requested', 'users_stories_requested.userid', '=', 'messages.fromuser')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->whereNotNull('messages.fromuser')
            ->whereNull('users_stories_requested.date')
            ->whereNull('users.deleted')
            ->where('messages.arrival', '>=', $earliestDate)
            ->distinct()
            ->select('messages.fromuser');

        $considered = 0;
        $asked = 0;

        // Stream distinct posters in keyset-paginated chunks (by fromuser) rather than
        // pluck()-ing the entire 90-day poster set into memory at once.
        foreach ($candidates->lazyById(1000, 'messages.fromuser', 'fromuser') as $candidate) {
            $userId = $candidate->fromuser;
            $considered++;

            $outcomeCount = (int) DB::table('messages_by')
                ->where('userid', $userId)
                ->count();

            $offerCount = (int) DB::table('messages')
                ->where('fromuser', $userId)
                ->where('type', Message::TYPE_OFFER)
                ->count();

            if ($outcomeCount <= self::ASK_OUTCOME_THRESHOLD && $offerCount <= self::ASK_OFFER_THRESHOLD) {
                continue;
            }

            // Record that we've considered this user — prevents repeated consideration
            // even if we don't end up sending (e.g. member has opted out of stories)
            if (! $dryRun) {
                DB::table('users_stories_requested')->insertOrIgnore([
                    'userid' => $userId,
                    'date' => now(),
                ]);
            }

            // Only ask a member who hasn't opted out of stories - the same
            // "Newsletters & stories" preference Stories Newsletter and
            // Community News honour.
            $storiesEnabled = (bool) DB::table('users')
                ->where('id', $userId)
                ->value('newslettersallowed');

            if (! $storiesEnabled) {
                continue;
            }

            $asked++;

            if (! $dryRun) {
                // V1 parity: skip our own per-user-alias domains so the mail can't loop back as chat.
                $userModel = \App\Models\User::find($userId);
                $email = $userModel?->email_preferred;

                $user = DB::table('users')->where('id', $userId)->first();
                $name = $user?->fullname
                    ?? trim(($user?->firstname ?? '').' '.($user?->lastname ?? ''))
                    ?: 'Freegle User';

                if ($email) {
                    app(\App\Services\EmailSpoolerService::class)->spool(new AskMail(
                        recipientName: $name,
                        recipientEmail: $email,
                        storiesUrl: config('freegle.sites.user').'/stories',
                        unsubscribeUrl: config('freegle.sites.user').'/unsubscribe',
                    ));
                }
            }
        }

        return ['asked' => $asked, 'considered' => $considered];
    }
}
