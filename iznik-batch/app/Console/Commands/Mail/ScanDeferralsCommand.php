<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\Deferrals\DeferralCatchUpService;
use App\Services\Mail\Deferrals\DeferralProbe;
use App\Services\Mail\Deferrals\DeferralScanService;
use App\Services\Mail\Deferrals\RelayQueueSnapshot;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find out whether a provider has stopped accepting our mail, and stop
 * generating into the hole if so.
 *
 * The gap this fills: our relay 250-accepts every message and only discovers
 * afterwards that the receiving provider will not take it. Nothing in the
 * sending path can see that. On 2026-08-15 Yahoo began deferring everything
 * from the relay's IP; three days later nobody had noticed, 85,537 messages
 * were queued and growing by about 1,300 an hour, and roughly 9,400 people
 * had received none of it.
 *
 * So this command goes and looks - reading the relay's own queue over ssh -
 * and then does the two things that were missing: it says so loudly, and it
 * stops the sending jobs generating mail that cannot be delivered.
 */
class ScanDeferralsCommand extends Command
{
    protected $signature = 'mail:deferrals:scan
        {--dry-run : Report what would change without writing anything}
        {--purge : Delete the queued backlog for suppressed relays (dry-run unless --force)}
        {--force : Actually delete when used with --purge}
        {--no-catchup : Skip the post-release catch-up pass}';

    protected $description = 'Read the outbound relay queue, suppress mail to providers deferring us, and release when they recover';

    public function handle(
        DeferralProbe $probe,
        DeferralScanService $scan,
        DeferralCatchUpService $catchUp,
    ): int {
        $cfg = (array) config('freegle.mail.deferrals', []);

        if (! ($cfg['enabled'] ?? false)) {
            $this->info('Deferral scanning is disabled (FREEGLE_MAIL_DEFERRALS_ENABLED).');

            return self::SUCCESS;
        }

        $target = trim((string) ($cfg['host'] ?? ''));
        if ($target === '') {
            // Empty in dev and CI by design - the estate's topology lives only
            // in the environment - so this is information, not an error.
            $this->info('No relay host configured (FREEGLE_MAIL_DEFERRALS_HOST); nothing to scan.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $snapshot = $probe->probe($target, (int) ($cfg['max_queue_bytes'] ?? 64 * 1024 * 1024));

        if ($snapshot === null) {
            // Being unable to see the relay is itself worth knowing: this
            // command running green while blind is how the original incident
            // stayed invisible for three days.
            $this->error('Could not read the relay queue.');
            $this->alertSentry('Mail deferral scan could not read the relay queue', [
                'target' => $target,
            ]);

            return self::FAILURE;
        }

        $this->reportSnapshot($snapshot);

        $result = $scan->apply($snapshot, $dryRun);

        foreach ($result['suppressed'] as $s) {
            $line = sprintf(
                '%s SUPPRESSED %s %s (%d deferred, %d delivered/hr%s)',
                $dryRun ? '[dry-run]' : '',
                $s['scope'],
                $s['value'],
                $s['deferred'],
                $s['delivered'] ?? 0,
                ! empty($s['blind']) ? ', no delivery data' : ''
            );
            $this->warn(trim($line));

            if (($s['scope'] ?? '') === 'mxgroup') {
                // The whole reason this went unnoticed for three days is that
                // nothing shouted. Shout.
                $this->alertSentry(
                    sprintf(
                        'Mail suppressed: %s is not accepting our mail (%d messages deferred since %s)',
                        $s['provider'] ?? $s['value'],
                        $s['deferred'],
                        $s['deferred_since'] ?? 'unknown'
                    ),
                    [
                        'mxgroup' => $s['value'],
                        'provider' => $s['provider'],
                        'deferred' => $s['deferred'],
                        'delivered_last_hour' => $s['delivered'] ?? 0,
                        'domains' => $s['domains'] ?? [],
                        'reason' => $s['reason'] ?? '',
                    ]
                );
            }
        }

        foreach ($result['released'] as $r) {
            $this->info(sprintf(
                '%s RELEASED %s %s%s',
                $dryRun ? '[dry-run]' : '',
                $r['scope'],
                $r['value'],
                $r['stale'] ? ' (stale - probe had not confirmed it, failing open)' : ''
            ));

            if ($r['stale']) {
                $this->alertSentry(
                    sprintf('Mail suppression released as stale: %s %s', $r['scope'], $r['value']),
                    ['scope' => $r['scope'], 'value' => $r['value']]
                );
            }
        }

        if ($this->option('purge')) {
            $this->purge($probe, $snapshot, $target);
        }

        if (! $this->option('no-catchup')) {
            $catch = $catchUp->run($dryRun);
            $this->info(sprintf(
                'Catch-up: %d sent, %d dropped by policy, %d still waiting.',
                $catch['sent'],
                $catch['dropped'],
                $catch['skipped']
            ));
        }

        return self::SUCCESS;
    }

    private function reportSnapshot(RelayQueueSnapshot $snapshot): void
    {
        $total = array_sum(array_column($snapshot->groups, 'count'));
        $this->line(sprintf(
            'Relay queue: %d deferred recipients across %d relay families, %d distinct addresses.',
            $total,
            count($snapshot->groups),
            count($snapshot->addresses)
        ));

        if ($snapshot->unattributed > 0) {
            $this->line(sprintf('%d deferrals blamed no provider (local failures).', $snapshot->unattributed));
        }

        if (! $snapshot->hasDeliveryData()) {
            // Say it out loud rather than let a suppression decision quietly
            // rest on half the evidence.
            $this->warn('No delivery data from the relay: suppression will run on queue depth alone.');
        }

        if ($snapshot->truncated) {
            $this->warn('Relay queue listing was truncated at the byte cap; counts are a lower bound.');
        }

        foreach ($snapshot->groups as $group => $stats) {
            $this->line(sprintf(
                '  %-40s %6d deferred  %5d delivered/hr',
                $group,
                $stats['count'],
                $snapshot->deliveriesFor($group)
            ));
        }
    }

    /**
     * Delete the queued backlog for relays we have suppressed.
     *
     * Silent and irreversible, so it is dry-run unless --force, and it only
     * ever names ids we just read from the queue for a relay that is actually
     * suppressed right now.
     */
    private function purge(DeferralProbe $probe, RelayQueueSnapshot $snapshot, string $target): void
    {
        $suppressed = DB::table('mail_suppressions')
            ->where('scope', MailSuppressionService::SCOPE_MXGROUP)
            ->whereNull('released_at')
            ->pluck('value')
            ->all();

        if ($suppressed === []) {
            $this->info('Purge: nothing is suppressed, so nothing to purge.');

            return;
        }

        $ids = [];
        foreach ($suppressed as $group) {
            $ids = array_merge($ids, $snapshot->queueIdsFor($group));
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            $this->info('Purge: no queued messages found for the suppressed relays.');

            return;
        }

        if (! $this->option('force')) {
            $this->warn(sprintf(
                'Purge: %d queued message(s) for %s would be deleted. Re-run with --force to do it.',
                count($ids),
                implode(', ', $suppressed)
            ));
            $this->line('First 10 ids: '.implode(' ', array_slice($ids, 0, 10)));

            return;
        }

        $deleted = $probe->purge($target, $ids);
        $this->info(sprintf('Purge: asked the relay to delete %d message(s).', $deleted));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function alertSentry(string $message, array $context = []): void
    {
        Log::warning($message, $context);

        // Guarded because Sentry's helpers are not loaded in every
        // environment (e.g. tests).
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\withScope(function ($scope) use ($message, $context) {
                foreach ($context as $key => $value) {
                    $scope->setExtra($key, $value);
                }
                \Sentry\captureMessage($message);
            });
        }
    }
}
