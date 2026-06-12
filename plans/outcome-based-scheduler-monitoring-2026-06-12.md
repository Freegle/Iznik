# Brief: Outcome-based monitoring for ALL scheduled tasks (iznik-batch)

**For:** a fresh Claude instance, working **off the live system** (use a git
worktree / feature branch — see "Working environment" below). Do NOT iterate
on prod for this.

**Date:** 2026-06-12
**Requested by:** Edward (geeks@ilovefreegle.org)

---

## The goal

Edward wants to know, reliably, when a scheduled job **didn't do its work** —
not just when the scheduler process died. Build **outcome-based monitoring for
every scheduled task** in `iznik-batch/routes/console.php` (~100 tasks), with
alerts going to **Sentry** (the team actively watches Sentry; it's free for us
on the open-source plan, so check-in/alert volume is not a constraint).

## Why (the motivating problem)

Laravel's scheduler has **no catch-up**: each minute `schedule:run` asks "is
anything due *this* minute?" and runs it. If the scheduler isn't ticking at a
job's exact due-minute (container restart, deploy, crash, a long previous
tick), that job is **silently skipped for the period** — no retry, no error.
This deployment runs the scheduler as a host-side `while true; schedule:run;
sleep 60` loop, so if that loop dies, *everything* stops silently.

Two distinct kinds of monitoring are needed, and they answer different questions:

1. **Schedule-based** ("did the task *fire* on cadence?") — Sentry Crons
   check-ins via the `->sentryMonitor()` macro. Good for fixed-cadence jobs.
   **Already done** for a scheduler heartbeat (see below).
2. **Outcome-based** ("did the *work* actually happen?") — assert on the job's
   side effect (rows written, mails sent, cursor advanced) regardless of HOW
   it ran. **This is the new work.** It's the only thing that catches "the
   scheduler was alive but this specific job errored / was skipped / produced
   nothing."

## What's already done (committed + pushed to master — do NOT redo)

- **`a82e431f9` scheduler heartbeat → Sentry Crons.** A no-op
  `Schedule::call(fn () => null)->everyFiveMinutes()->name('scheduler-heartbeat')
  ->sentryMonitor('scheduler-heartbeat', 5)` in `routes/console.php`. Detects
  total scheduler death (the catastrophic case covering all jobs at once).
  Visible in Sentry → Crons as monitor `scheduler-heartbeat`. Verified live via
  `php artisan schedule:test --name=scheduler-heartbeat`.
- Daily digest hardening (context, not part of this task): once-per-London-day
  send guard (`UnifiedDigestService::getUsersForDigest`), UTF-8-safe spool,
  withdrawn/outcome exclusion, "came and went" section, 07:00 UK-local
  timezone-aware schedule, and a **windowed catch-up** schedule
  (`->cron('*/30 7-11 * * *')` style via `everyThirtyMinutes()->between(...)`
  in current master — check the live file) so a missed 07:00 self-heals.

Sentry is installed and configured: `sentry/sentry-laravel ^4.20`, DSN set in
prod (org `o118493`, US SaaS, project `4510630630326272`). The
`->sentryMonitor()` macro lives in
`vendor/sentry/sentry-laravel/src/Sentry/Laravel/Features/ConsoleSchedulingIntegration.php`.

## Key design gotchas discovered (read before designing)

1. **`->sentryMonitor()` derives the expected cadence from the raw cron
   expression** (`MonitorSchedule::crontab($scheduled->getExpression())`). A
   job using `everyThirtyMinutes()->between('7:00','12:00')` reports
   `*/30 * * * *` to Sentry — Sentry then expects check-ins all day and
   false-alarms outside the window. If you want schedule-based monitoring on a
   windowed job, encode the window in the cron expression itself
   (`->cron('*/30 7-11 * * *')`).
2. **`withoutOverlapping()` skips are invisible to Sentry Crons** but look like
   missed check-ins. The digest's multi-hour run legitimately skips its
   07:30–11:30 ticks → schedule-based monitoring would false-alarm. This is the
   core reason outcome-based monitoring is the right tool for jobs like the
   digest.
3. **Outcome checks must be allowlist/config-aware.** A digest with an empty
   `FREEGLE_DIGEST_DAILY_ALLOWLIST` is *deliberately disabled* — zero sends is
   correct, not a failure. Each task's "expected outcome" has to know its own
   "am I even enabled?" precondition.
4. **Boundaries are London-local.** The digest's once-per-day logic uses
   "start of today's Europe/London day, as a UTC instant"
   (`Carbon::now('Europe/London')->startOfDay()->setTimezone('UTC')`). Outcome
   windows should match the job's own period semantics, not naive UTC days.
5. **Sentry alert pattern already in the codebase** (mirror it):
   ```php
   if (function_exists('\Sentry\captureMessage')) {
       \Sentry\captureMessage('[Tag] ' . $msg);
   }
   ```
   guarded because Sentry helpers aren't loaded in every env (tests). See
   `app/Console/Commands/Monitor/EmailHealthCommand.php` (the closest existing
   precedent — an outcome-style check on email flow) and `TNSyncCommand.php`.
6. **Do not run tests against the live FreegleDocker** (`artisan test`,
   phpunit, dry-runs). Write tests; let CI / the user run them. (Standing rule.)
7. **Galera:** any prod DELETE/UPDATE one row at a time. (Not expected here —
   monitoring is read-only — but noted.)

## Suggested approach (open to your judgement)

A per-task bespoke command for ~100 tasks is unmaintainable. Prefer a **generic
framework**:

- A **declarative registry** mapping each scheduled task to an *outcome
  assertion*: e.g. `{ slug, enabledWhen(), expectedWithin(period),
  check() -> bool|count, floor, severity }`. Could live in config or a
  dedicated class so it sits next to the schedule and is reviewed together.
- The natural "did it do its work" signal for most jobs is **freshness of a
  side effect**: a max(timestamp) on the table the job writes
  (`email_tracking.sent_at` for mail jobs, `logs`/cursor tables, `messages_*`
  for content jobs, etc.), compared against the job's period + a margin.
- **One `monitor:scheduled-outcomes` command** (run every N minutes, itself
  heartbeated) that evaluates the whole registry and raises a Sentry message
  per breached task. This keeps a single, testable evaluation path instead of
  N commands.
- Categorise tasks first: **cursor/queue-driven** jobs (chat, spool,
  contentcheck — already self-healing, a missed tick just means the next does
  more) need a *staleness* check ("no progress in X"); **fire-once daily** jobs
  (digest, purges, stats, kudos, engage) need a *"did it produce output today"*
  check. Many jobs are naturally idempotent and only need staleness alerting.
- Decide per task whether schedule-based (Sentry Crons `->sentryMonitor()`) or
  outcome-based (registry) — or both — is the right fit. Clean fixed-cadence
  jobs can just take `->sentryMonitor()`; windowed/guarded/idempotent ones want
  outcome checks.

## Prototype to build on (removed from live, preserved here)

I'd started a single-task version (`monitor:digest-sent`) before pausing. It's a
good reference for the outcome-check shape; generalise it into the framework
above rather than shipping ~100 of these.

```php
<?php

namespace App\Console\Commands\Monitor;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DigestSentCommand extends Command
{
    protected $signature = 'monitor:digest-sent';
    protected $description = 'Fails (and alerts Sentry) if the daily digest did not go out today';

    public function handle(): int
    {
        // Disabled digest (empty allowlist) = config state, not a failure.
        $allowlist = trim((string) config('freegle.digest.daily_allowlist', ''));
        if ($allowlist === '') {
            $this->info('Daily digest disabled (empty allowlist) — nothing to check.');
            return Command::SUCCESS;
        }

        // Start of today's London day as a UTC instant (sent_at is UTC).
        $londonDayStartUtc = Carbon::now('Europe/London')
            ->startOfDay()->setTimezone('UTC')->toDateTimeString();

        $sentToday = (int) DB::table('email_tracking')
            ->where('email_type', 'UnifiedDigestDaily')
            ->where('sent_at', '>=', $londonDayStartUtc)
            ->count();

        // Floor: default 1 → alert only on a hard zero ("never ran"). Raise
        // FREEGLE_DIGEST_DAILY_MIN_EXPECTED to also catch a run that died early.
        $floor = (int) config('freegle.digest.daily_min_expected', 1);

        if ($sentToday < $floor) {
            $msg = "daily digest sent {$sentToday} today (floor {$floor}) since {$londonDayStartUtc} UTC — did it run?";
            $this->error("[DigestSent] {$msg}");
            Log::warning("[DigestSent] {$msg}");
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage('[DigestSent] ' . $msg);
            }
            return Command::FAILURE;
        }

        $this->info("Daily digest OK — {$sentToday} sent today.");
        return Command::SUCCESS;
    }
}
```

If you keep a config floor like the above, add it to `config/freegle.php` under
`digest` (e.g. `'daily_min_expected' => (int) env('FREEGLE_DIGEST_DAILY_MIN_EXPECTED', 1)`)
and document the env in `iznik-batch/.env.example`. (Not added on live — left
for the framework so we don't ship orphan config.)

## Working environment

- Develop in an isolated worktree, NOT on the live checkout:
  `./freegle worktree create scheduler-monitoring` then work there. The live
  `iznik-batch` tree is bind-mounted into `freegledocker-batch-prod` and runs
  the real scheduler against the PROD DB — uncommitted edits there go live on
  the next tick. (See memory `reference_batch_prod_bind_mount_live`.)
- Tests: write them (Feature tests under `iznik-batch/tests/Feature/Monitor/`),
  run via CI / ask Edward — don't execute against live.
- When done: update the orb if you touched test infra; open a PR (never merge —
  humans merge).

## Deliverables

1. A generic outcome-monitoring mechanism (registry + single evaluator command)
   covering the scheduled tasks, escalating per-task breaches to Sentry.
2. Categorisation of the ~100 tasks into cursor-driven (staleness check) vs
   fire-once (output-today check) vs already-covered, with the registry
   populated.
3. Tests. Config + `.env.example` for any new knobs.
4. A short note on which tasks you judged not worth monitoring and why.
```
