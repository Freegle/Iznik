<?php

namespace App\Console\Commands\Message;

use App\Mail\Matched\MatchedPosts;
use App\Services\EmailSpoolerService;
use App\Services\MatchedPostsService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    protected $description = 'Email members opposite-type posts that match their open offers/wanteds (vector matched-posts mail)';

    public function handle(MatchedPostsService $service, EmailSpoolerService $spooler): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE — no mail sent, no ledger written.');
        }

        return $this->runWithLogging(function () use ($service, $spooler, $dryRun) {
            $now = Carbon::now();
            $notifications = $service->buildNotifications($now);

            $emails = 0;
            $matches = 0;

            foreach ($notifications as $n) {
                if ($this->shouldStop()) {
                    break;
                }

                $user = $n['user'];
                $items = $n['items'];
                $email = $user->email_preferred ?? $user->email ?? null;
                if (! $email) {
                    continue;
                }

                if ($dryRun) {
                    $emails++;
                    $matches += count($items);

                    continue;
                }

                $mail = new MatchedPosts($user, $email, $items);
                $spooler->spool($mail);

                // Record every matched post as mailed to this user (never re-send),
                // and bump the per-user cooldown watermark.
                $rows = array_map(fn ($i) => [
                    'msgid' => (int) $i['message']->id,
                    'userid' => (int) $user->id,
                    'mailed_at' => $now->toDateTimeString(),
                ], $items);
                DB::table('messages_matched_notified')->insertOrIgnore($rows);
                DB::table('users')->where('id', $user->id)->update(['lastrelevantcheck' => $now->toDateTimeString()]);

                $emails++;
                $matches += count($items);
            }

            $prefix = $dryRun ? 'Would ' : '';
            $this->newLine();
            $this->table(
                ['Operation', 'Count'],
                [
                    [$prefix . 'Members emailed', number_format($emails)],
                    [$prefix . 'Matched posts included', number_format($matches)],
                ]
            );

            Log::info('Matched-posts notify completed', [
                'emails' => $emails,
                'matches' => $matches,
                'dry_run' => $dryRun,
            ]);

            return Command::SUCCESS;
        });
    }
}
