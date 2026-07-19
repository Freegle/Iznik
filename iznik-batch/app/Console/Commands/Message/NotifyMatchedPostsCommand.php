<?php

namespace App\Console\Commands\Message;

use App\Mail\Matched\MatchedPosts;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\MatchedPostsService;
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
                            {--dry-run : Build and report notifications without sending or recording anything}';

    protected $description = 'Notify members (in-app + push, and email) of opposite-type posts that match their open offers/wanteds (vector matched-posts)';

    public function handle(MatchedPostsService $service, EmailSpoolerService $spooler, PushNotificationService $push): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE — nothing sent, no ledger written.');
        }

        return $this->runWithLogging(function () use ($service, $spooler, $push, $dryRun) {
            $now = Carbon::now();
            $notifications = $service->buildNotifications($now);

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

                // In-app (bell) notification + device push — the primary channel,
                // delivered to every eligible recipient regardless of email.
                $this->notifyUserOfMatches($user, $items, $now, $push);
                $notified++;

                // Email too, when we have an address.
                if ($email) {
                    $spooler->spool(new MatchedPosts($user, $email, $items));
                    $emails++;
                }

                // Record every matched post as notified to this user (never
                // re-send, across both channels) and bump the per-user cooldown.
                $rows = array_map(fn ($i) => [
                    'msgid' => (int) $i['message']->id,
                    'userid' => (int) $user->id,
                    'mailed_at' => $now->toDateTimeString(),
                ], $items);
                DB::table('messages_matched_notified')->insertOrIgnore($rows);
                DB::table('users')->where('id', $user->id)->update(['lastrelevantcheck' => $now->toDateTimeString()]);

                $matches += count($items);
            }

            $prefix = $dryRun ? 'Would ' : '';
            $this->newLine();
            $this->table(
                ['Operation', 'Count'],
                [
                    [$prefix . 'Members notified (in-app + push)', number_format($notified)],
                    [$prefix . 'Members also emailed', number_format($emails)],
                    [$prefix . 'Matched posts included', number_format($matches)],
                ]
            );

            Log::info('Matched-posts notify completed', [
                'notified' => $notified,
                'emails' => $emails,
                'matches' => $matches,
                'dry_run' => $dryRun,
            ]);

            return Command::SUCCESS;
        });
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
