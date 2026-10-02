<?php

namespace App\Console\Commands\Message;

use App\Mail\Matched\MatchedPosts;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\MatchedPostsService;
use App\Services\MessageSpatialService;
use App\Services\PushNotificationService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emails members the opposite-type posts near them that match their own open
 * Offer/Wanted — the resurrected "Any of these take your fancy?" mail, rebuilt on
 * vector matching (V1: cron/relevant.php). Runs every 10 minutes; the service
 * already applies every dedup/eligibility guard, so this command just renders,
 * spools, and records what was sent so nothing is mailed twice.
 */
class NotifyMatchedPostsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'matches:notify
                            {--dry-run : Build and report notifications without sending or recording anything}
                            {--backfill : One-off: drive on ALL currently-open posts, not just recent arrivals}';

    protected $description = 'Notify members (in-app + push, and email) of opposite-type posts that match their open offers/wanteds (vector matched-posts)';

    public function handle(MatchedPostsService $service, EmailSpoolerService $spooler, PushNotificationService $push): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE — nothing sent, no ledger written.');
        }

        $backfill = (bool) $this->option('backfill');

        return $this->runWithLogging(function () use ($service, $spooler, $push, $dryRun, $backfill) {
            $now = Carbon::now();
            $totals = ['notified' => 0, 'emails' => 0, 'matches' => 0];

            if ($backfill) {
                // Catch up the already-open backlog (the scheduled run only drives
                // on the last fresh_window_minutes of arrivals). Walk the whole
                // messages_spatial open-age window (RECENT_DAYS) in bounded time
                // slices: a single buildNotifications over tens of thousands of
                // drivers overflows the IN(...) queries and idles the DB
                // connection out during its long apiv2 phase ("server has gone
                // away"). Each slice inherits the spatial open-age via freshPosts.
                $slice = (int) config('freegle.matched.backfill_slice_minutes', 360);
                $start = $now->copy()->subDays(MessageSpatialService::RECENT_DAYS);
                $this->warn(sprintf(
                    'BACKFILL: %d-day open-age window in %d-minute slices.',
                    MessageSpatialService::RECENT_DAYS,
                    $slice
                ));

                for ($since = $start; $since->lt($now); $since = $since->copy()->addMinutes($slice)) {
                    if ($this->shouldStop()) {
                        break;
                    }
                    $until = $since->copy()->addMinutes($slice);
                    if ($until->gt($now)) {
                        $until = $now->copy();
                    }
                    $batch = $service->buildNotifications($now, $since, $until);
                    $c = $this->dispatchNotifications($batch, $now, $dryRun, $spooler, $push);
                    $totals['notified'] += $c['notified'];
                    $totals['emails'] += $c['emails'];
                    $totals['matches'] += $c['matches'];
                }
            } else {
                $totals = $this->dispatchNotifications(
                    $service->buildNotifications($now),
                    $now,
                    $dryRun,
                    $spooler,
                    $push
                );
            }

            $prefix = $dryRun ? 'Would ' : '';
            $this->newLine();
            $this->table(
                ['Operation', 'Count'],
                [
                    [$prefix . 'Members notified (in-app + push)', number_format($totals['notified'])],
                    [$prefix . 'Members also emailed', number_format($totals['emails'])],
                    [$prefix . 'Matched posts included', number_format($totals['matches'])],
                ]
            );

            Log::info('Matched-posts notify completed', [
                'notified' => $totals['notified'],
                'emails' => $totals['emails'],
                'matches' => $totals['matches'],
                'dry_run' => $dryRun,
                'backfill' => $backfill,
            ]);

            return Command::SUCCESS;
        });
    }

    /**
     * Deliver one batch of notifications (in-app + push + email) and record the
     * ledger/cooldown. Returns per-batch counts so the backfill can accumulate
     * across slices. In dry-run it only counts.
     *
     * @param  array<int, array{user: User, items: array}>  $notifications
     * @return array{notified:int, emails:int, matches:int}
     */
    private function dispatchNotifications(array $notifications, Carbon $now, bool $dryRun, EmailSpoolerService $spooler, PushNotificationService $push): array
    {
        $notified = 0;
        $emails = 0;
        $matches = 0;

        foreach ($notifications as $n) {
            if ($this->shouldStop()) {
                break;
            }

            $user = $n['user'];
            $items = $n['items'];
            $email = $user->email_preferred ?? $user->email ?? null;

            if ($dryRun) {
                $notified++;
                $emails += $email ? 1 : 0;
                $matches += count($items);

                continue;
            }

            // CLAIM FIRST. "Never re-send" is only true if the record of having
            // sent is written BEFORE the send, not after: a push and an email
            // both leave our control the moment they are issued, so if the
            // recording write then fails the next run sees the same matches as
            // un-notified and issues them again. insertOrIgnore makes the claim
            // atomic - the unique key on (msgid, userid) means a concurrent run
            // cannot claim the same match - and a zero return means every item
            // was already claimed, so there is nothing left to tell this user
            // about.
            //
            // A failed claim now costs one missed notification instead of an
            // unbounded repeat; the next genuinely new match brings the user back.
            $rows = array_map(fn ($i) => [
                'msgid' => (int) $i['message']->id,
                'userid' => (int) $user->id,
                'mailed_at' => $now->toDateTimeString(),
            ], $items);

            if (! DB::table('messages_matched_notified')->insertOrIgnore($rows)) {
                continue;
            }

            // In-app (bell) notification + device push — the primary channel,
            // delivered to every eligible recipient regardless of email.
            $this->notifyUserOfMatches($user, $items, $now, $push);
            $notified++;

            // Email too, when we have an address.
            if ($email) {
                $spooler->spool(new MatchedPosts($user, $email, $items));
                $emails++;
            }

            // Bump the per-user cooldown.
            DB::table('users')->where('id', $user->id)->update(['lastrelevantcheck' => $now->toDateTimeString()]);

            $matches += count($items);
        }

        return ['notified' => $notified, 'emails' => $emails, 'matches' => $matches];
    }

    /**
     * Create the in-app (bell) notification row and fire a device push. The push
     * is a no-op when the user has no registered Freegle-app device. One row per
     * run (the per-user cooldown already bounds frequency), pointing at the top
     * match; the subject mirrors the email.
     *
     * @param  array<int, array{message: \App\Models\Message, reason: \App\Models\Message, score: float}>  $items
     */
    private function notifyUserOfMatches(User $user, array $items, Carbon $now, PushNotificationService $push): void
    {
        $top = $items[0]['message'];
        $count = count($items);

        if ($count === 1) {
            $verb = $top->type === 'Offer' ? 'Someone is offering' : 'Someone wants';
            $title = $verb . ': ' . MatchedPostsService::itemName($top);
            $text = null;
        } else {
            $title = 'Freegle matches for you';
            $text = $count . " posts near you match what you've offered or asked for";
        }

        DB::table('users_notifications')->insert([
            'touser' => (int) $user->id,
            'fromuser' => null,
            'type' => 'MatchedPost',
            'url' => '/message/' . $top->id,
            'title' => Str::limit($title, 79, ''),
            'text' => $text,
            'timestamp' => $now->toDateTimeString(),
            'seen' => 0,
            'mailed' => 0,
        ]);

        $push->notifyUser((int) $user->id);
    }
}
