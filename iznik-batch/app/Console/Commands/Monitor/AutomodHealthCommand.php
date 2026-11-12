<?php

namespace App\Console\Commands\Monitor;

use App\Models\MessageAutomod;
use App\Services\AutoApproveCleanService;
use App\Services\Automod\AutomodEligibility;
use App\Services\Automod\AutomodMode;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Watches automated review (messages:automod) the way monitor:email-health watches mail.
 *
 * Fails, and escalates to Sentry, when either:
 *
 * - UNAVAILABLE: automated review has failed to decide a Pending post for a quarter of an hour,
 *   or at the third try. A failed decision is retried every five minutes and the retry rewrites
 *   the row's timestamp, so the row keeps its own count and the time of the first failure
 *   (messages_automod.detail attempts and first_failed_at). One transient failure on a post
 *   that has been waiting behind a backlog is a blip, not an outage.
 * - UNDECIDED: a post on an approve-mode community from an auto-moderated member is older
 *   than the publish delay and has no decision made in approve mode (or only one that predates a
 *   later content check). The countdown shows nothing for such a post and nothing will publish
 *   it, so moderators see a post that looks unmoderated and is going nowhere.
 *
 * Both count only posts the review would pick up (AutomodEligibility, the same definition
 * messages:automod uses): a post that is held, deleted, flagged as spam, pulled back or rippled
 * in is never retried, so a failure on one could never clear and would page for ever.
 *
 * It also prints a warning, which is not a failure, for a post the chart held on an approve-mode
 * community more than 48 hours ago that no moderator has touched: the 48-hour sweep leaves a
 * chart hold for a moderator, so on a community with no active moderators it waits for ever.
 *
 * Escalation is once per state, not once per run: Sentry hears when the set of failing kinds
 * changes and then again hourly while it goes on, with a fixed message and a fingerprint so the
 * runs group together. Both lists empty (neither list set) is quiet, so the schedule costs
 * nothing until a community is named. Failures show as red badges in the cron jobs sysadmin tab,
 * like the other monitors.
 */
#[AsCommand(name: 'monitor:automod-health')]
class AutomodHealthCommand extends Command
{
    protected $signature = 'monitor:automod-health';

    protected $description = 'Fails when automated review has been unavailable for 15 minutes or a trial post is overdue with no decision';

    private const UNAVAILABLE_MINUTES = 15;

    private const UNAVAILABLE_ATTEMPTS = 3;

    private const HELD_WARNING_HOURS = 48;

    /** The cache entry that remembers what was last reported, so the same failure is not reported every run. */
    private const STATE_KEY = 'automod-health:last-escalation';

    private const REPEAT_AFTER_MINUTES = 60;

    public function handle(): int
    {
        $eligible = AutomodMode::eligibleGroupIds();
        if ($eligible === []) {
            Cache::forget(self::STATE_KEY);
            $this->info('Automated review is dark; nothing to check.');

            return Command::SUCCESS;
        }

        /** @var array<string, string> $failures kind => what to say */
        $failures = [];
        /** @var array<string, mixed> $extra */
        $extra = [];

        // --- Reviews that keep failing ---
        $unavailable = $this->unavailable($eligible);
        $this->info('Failing for ' . self::UNAVAILABLE_MINUTES . ' minutes or ' . self::UNAVAILABLE_ATTEMPTS . " tries: {$unavailable['count']}");
        if ($unavailable['count'] > 0) {
            $failures['UNAVAILABLE'] = "UNAVAILABLE: {$unavailable['count']} post(s) automated review has failed to decide for "
                . self::UNAVAILABLE_MINUTES . ' minutes or ' . self::UNAVAILABLE_ATTEMPTS . ' tries'
                . " (oldest first failed {$unavailable['oldest_minutes']} minutes ago; is the automod service up, and answering within AUTOMOD_TIMEOUT?)";
            $extra['unavailable'] = $unavailable['count'];
            $extra['oldest_unavailable_minutes'] = $unavailable['oldest_minutes'];
        }

        // --- Approve-mode posts past the delay with nothing decided ---
        $delay = (new AutoApproveCleanService())->delayMinutes();
        $undecidedQuery = AutomodEligibility::reviewable($eligible)
            ->join('memberships as mem', function ($j) {
                $j->on('mem.userid', '=', 'm.fromuser')->on('mem.groupid', '=', 'mg.groupid');
            })
            // A copy a moderator pulled back waits for a moderator, whatever the chart says.
            ->where('mg.needs_moderator', 0)
            ->whereNull('mem.ourPostingStatus')
            ->where('mg.contentcheck_checked_at', '<=', now()->subMinutes($delay))
            // keep-raw: the approve-mode set is a dynamic id list from config, or always/never.
            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'))
            // A decision recorded in shadow mode is not one for a community that can publish.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_automod as ma')
                    ->whereColumn('ma.msgid', 'mg.msgid')
                    ->whereColumn('ma.groupid', 'mg.groupid')
                    ->where('ma.mode', MessageAutomod::MODE_APPROVE)
                    ->whereColumn('ma.created', '>=', 'mg.contentcheck_checked_at');
            });

        $undecided = (clone $undecidedQuery)->count();
        $this->info("Undecided past the delay: {$undecided}");
        if ($undecided > 0) {
            $oldest = $undecidedQuery->min('mg.contentcheck_checked_at');
            $minutes = (int) now()->diffInMinutes(Carbon::parse($oldest), true);
            $failures['UNDECIDED'] = "UNDECIDED: {$undecided} approve-mode post(s) past the {$delay}-minute delay with no decision "
                . "(oldest waiting {$minutes} minutes; is messages:automod running and keeping up?)";
            $extra['undecided'] = $undecided;
            $extra['oldest_undecided_minutes'] = $minutes;
        }

        // --- A hold nobody is acting on: a warning, not a failure ---
        $this->warnAboutOldHolds($eligible);

        if ($failures === []) {
            Cache::forget(self::STATE_KEY);
            $this->info('Automated review health OK.');

            return Command::SUCCESS;
        }

        foreach ($failures as $f) {
            $this->error($f);
            Log::warning("[AutomodHealth] {$f}");
        }

        $this->escalateOncePerState(array_keys($failures), $extra);

        return Command::FAILURE;
    }

    /**
     * Failed reviews that have been failing long enough to matter.
     *
     * @param int[]|null $eligible
     * @return array{count: int, oldest_minutes: int}
     */
    private function unavailable(?array $eligible): array
    {
        $rows = AutomodEligibility::reviewable($eligible)
            ->join('messages_automod as ma', function ($j) {
                $j->on('ma.msgid', '=', 'mg.msgid')->on('ma.groupid', '=', 'mg.groupid');
            })
            ->where('ma.end_node', 'UNAVAILABLE')
            ->get(['ma.detail', 'ma.created']);

        $count = 0;
        $oldest = null;
        foreach ($rows as $row) {
            $detail = json_decode((string) $row->detail, true);
            $detail = is_array($detail) ? $detail : [];
            $attempts = (int) ($detail['attempts'] ?? 1);
            // A row from before the first failure was kept is aged by its own timestamp.
            $firstFailed = Carbon::parse($detail['first_failed_at'] ?? $row->created);

            if ($attempts >= self::UNAVAILABLE_ATTEMPTS || $firstFailed->lte(now()->subMinutes(self::UNAVAILABLE_MINUTES))) {
                $count++;
                $oldest = $oldest === null || $firstFailed->lt($oldest) ? $firstFailed : $oldest;
            }
        }

        return [
            'count' => $count,
            'oldest_minutes' => $oldest === null ? 0 : (int) now()->diffInMinutes($oldest, true),
        ];
    }

    /**
     * Chart-held posts on approve-mode communities, older than two days, that no moderator has
     * acted on. The 48-hour sweep skips a post the chart holds for a real reason (money, a loan,
     * medicine), so unless a moderator gets to it it waits for ever. A post the review could not
     * decide, or a community that moderates everything, is not counted: the sweep publishes
     * those as it always did.
     *
     * @param int[]|null $eligible
     */
    private function warnAboutOldHolds(?array $eligible): void
    {
        $query = AutomodEligibility::reviewable($eligible)
            ->join('messages_automod as ma', function ($j) {
                $j->on('ma.msgid', '=', 'mg.msgid')->on('ma.groupid', '=', 'mg.groupid');
            })
            ->where('ma.verdict', MessageAutomod::VERDICT_HOLD)
            ->whereNotIn('ma.end_node', ['UNAVAILABLE', 'GROUP_MODERATES_ALL'])
            ->where('mg.needs_moderator', 0)
            ->where('mg.arrival', '<=', now()->subHours(self::HELD_WARNING_HOURS))
            // keep-raw: the approve-mode set is a dynamic id list from config, or always/never.
            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'))
            // Nobody has acted: no log on the post by anyone but the member.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('logs as l')
                    ->whereColumn('l.msgid', 'mg.msgid')
                    ->whereNotNull('l.byuser')
                    ->whereColumn('l.byuser', '!=', 'l.user');
            });

        $count = (clone $query)->count();
        if ($count === 0) {
            return;
        }

        $oldest = $query->min('mg.arrival');
        $hours = (int) now()->diffInHours(Carbon::parse($oldest), true);
        $message = "Chart-held posts older than " . self::HELD_WARNING_HOURS . " hours with no moderator action: {$count} "
            . "(oldest {$hours} hours; the 48-hour sweep leaves a chart hold for a moderator, so these wait until one acts)";
        $this->warn($message);
        Log::warning("[AutomodHealth] {$message}");
    }

    /**
     * Report to Sentry when the set of failing kinds changes, and again hourly while it goes on,
     * not on every run: a failure that lasts a day would otherwise be 96 reports.
     *
     * @param string[] $kinds
     * @param array<string, mixed> $extra
     */
    private function escalateOncePerState(array $kinds, array $extra): void
    {
        $signature = implode('+', $kinds);
        $last = Cache::get(self::STATE_KEY);
        $due = !is_array($last)
            || ($last['signature'] ?? null) !== $signature
            || now()->getTimestamp() - (int) ($last['at'] ?? 0) >= self::REPEAT_AFTER_MINUTES * 60;

        if (!$due) {
            return;
        }

        Cache::put(self::STATE_KEY, ['signature' => $signature, 'at' => now()->getTimestamp()], now()->addDay());

        // The text is fixed and the counts are in extra, so Sentry groups the runs together.
        $this->escalate('[AutomodHealth] ' . implode(' + ', $kinds), ['automod-health', ...$kinds], $extra);
    }

    /**
     * Same escalation as monitor:email-health: the team watch Sentry. A no-op where the SDK or a
     * DSN is absent (CI, dev).
     *
     * @param string[] $fingerprint
     * @param array<string, mixed> $extra
     */
    protected function escalate(string $message, array $fingerprint, array $extra): void
    {
        if (function_exists('\Sentry\withScope')) {
            \Sentry\withScope(function ($scope) use ($message, $fingerprint, $extra) {
                $scope->setFingerprint($fingerprint);
                $scope->setExtras($extra);
                \Sentry\captureMessage($message);
            });

            return;
        }

        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage($message);
        }
    }
}
