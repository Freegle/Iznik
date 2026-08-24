# Outcome-based monitoring for scheduled tasks

**Command:** `monitor:scheduled-outcomes` — scheduled every 10 minutes in
`routes/console.php` and itself heartbeated to Sentry Crons.

## Why

Laravel's scheduler has **no catch-up**: each minute `schedule:run` asks "is
anything due *this* minute?" and runs it. If the scheduler isn't ticking at a
job's exact due-minute (container restart, deploy, crash, a long previous tick)
that job is **silently skipped for the period** — no retry, no error. And a job
can be alive yet do nothing useful (errored mid-run, wrong config, upstream
empty).

There are two complementary questions, answered by two mechanisms:

| Question | Mechanism | Status |
|---|---|---|
| Did the scheduler *fire* on cadence? | Sentry Crons check-ins (`->sentryMonitor()`) | `scheduler-heartbeat` covers total scheduler death; `monitor:scheduled-outcomes` is itself heartbeated |
| Did the *work* actually happen? | **Outcome assertions** on each job's side-effect | **This feature** |

Outcome-based monitoring is the only thing that catches "the scheduler was
alive but this specific job errored / was skipped / produced nothing", and it
doesn't false-alarm on `withoutOverlapping()` skips the way schedule-based
monitoring does.

## How it works

A **declarative registry** (`App\Monitoring\ScheduledOutcomeRegistry`) maps a
scheduled task to an *outcome assertion*. A single evaluator command
(`monitor:scheduled-outcomes`) runs every check, prints a line per result, and
on any breach escalates to Sentry (`\Sentry\captureMessage`, guarded by
`function_exists` exactly like `EmailHealthCommand`/`TNSyncCommand`) and
`Log::warning`, then exits non-zero (red badge in the cron-jobs sysadmin tab).
`SKIPPED` results (precondition unmet or outside active window) never fail it.

### Check primitives (`App\Monitoring\Checks`)

Rather than ~100 bespoke commands, four reusable primitives cover the field:

| Primitive | Asserts | Use for |
|---|---|---|
| `FreshnessCheck` | `max(timestamp)` on a table is within a max age | jobs that should *always* have produced something recently |
| `ProducedSinceCheck` | count of rows since a window start meets a floor | **fire-once** jobs ("did it produce output this period?") |
| `BacklogCheck` | pending rows older than a max age don't pile up | **cursor/queue** jobs (no false-alarm on an empty queue) |
| `CallbackCheck` | an arbitrary closure result | bespoke signals (e.g. a JSON timestamp inside a `config` value) |

All primitives share, via `AbstractOutcomeCheck`:

- `enabledWhen(fn)` — a config-aware precondition. A job deliberately disabled
  (e.g. an empty allowlist) is `SKIPPED`, not a failure.
- `activeBetween(from, to, tz)` — only evaluate during an hour window in a given
  timezone. This is how a daily job is checked *after* its due time + slack
  (and a windowed job isn't checked outside its window — the Sentry-Crons gotcha
  the brief calls out).
- `withSeverity()` / `inCategory()` / `describedAs()` — metadata surfaced in the
  output and the Sentry message.

### Choosing a signal

The natural "did it do its work" signal for most jobs is **freshness of a side
effect** — a `max(timestamp)` (or a count) on the table the job writes. The key
correctness rules learned while populating the registry:

1. **Cursor/queue jobs must use `BacklogCheck`, not freshness.** A freshness
   check on a queue table false-alarms whenever the queue is legitimately empty
   (e.g. no new members overnight). Instead assert "no *pending* row has been
   waiting longer than X" — which only fires when a worker is genuinely stuck.
2. **Checks must be config/allowlist-aware.** A digest with an empty
   `FREEGLE_DIGEST_DAILY_ALLOWLIST` is *deliberately* sending to nobody — zero is
   correct. Encode that as `enabledWhen()`.
3. **Boundaries are London-local.** Daily windows use
   `now($tz)->startOfDay()` (per `freegle.timezone`), matching each job's own
   period semantics, not naive UTC days.
4. **Cleanup/purge/in-place-update jobs are usually not monitorable** — zero
   output is the normal, healthy case (see the table below).

## It also feeds the ModTools status dot

Every full pass publishes its verdict through
`App\Monitoring\PlatformStatusWriter` into the `config` row `status.platform`,
which the Go API serves at `/api/status` (`iznik-server-go/status/status.go`)
and `ModStatus.vue` renders as the platform status dot in the ModTools navbar.

This is deliberately the *same* evaluation that alerts to Sentry, not a second
opinion. The dot cannot drift away from the monitoring, and adding a check to
the registry surfaces it in both places at once.

Three properties are load-bearing, and each one exists because its absence
caused a real month-long outage of the dot:

- **The payload carries `generated_at`.** The Go reader turns a status older
  than 30 minutes (three missed passes) into a warning naming the staleness,
  instead of serving it as current. Before this, the dot's feed died with the
  V1 PHP removal and showed green to ordinary mods for a month.
- **Only breaches are published.** The modal renders one row per entry, so
  carrying the healthy checks would bury the real problem.
- **`--only` does not publish.** A single-check pass has no evidence about
  anything else, and reporting the platform as fine on that basis would be a
  lie. Monitoring being disabled does not publish either — the status then goes
  stale and says so.

`OutcomeResult` severity decides which half of the dot a breach lands in:
`error` means part of the platform is not working, anything else is a warning.
Severity also decides the response: only `error` breaches escalate to Sentry
and fail the command (red badge in the cron-jobs tab). A `warning` breach
turns the dot amber and is logged, but a long-standing warning — a host
awaiting a reboot for a fortnight — must not page Sentry every 10 minutes or
pin the cron-jobs tab red; that alarm fatigue is how real errors get missed.

## Host health checks (V1 status.php parity)

V1's `scripts/cron/status.php` also ssh'd into every server and warned on
pending security patches, `reboot-required` and monit failures. Those checks
died with the V1 removal too, leaving the dot blind to host state (every host
in the estate sat on "reboot required" with the dot green). `HostHealthCheck`
ports them into this pipeline:

| Probe | V1 equivalent | Severity |
|---|---|---|
| `/var/run/reboot-required` exists | "Server reboot required" | warning (names the packages) |
| `apt-get upgrade -s` lists security packages | "Security patches to apply" | warning (with count) |
| `monit summary -B` per-service status | per-line OK-pattern match | `Not monitored` / `Initializing` / `Resource limit matched` ⇒ warning; anything else non-OK (incl. a dead monit daemon) ⇒ **error** — monit restarts the API services, and a silent monit death has caused a real outage |
| host unreachable over ssh | (implicit) | warning |

One ssh round-trip per host gathers all three signals. V1's beanstalkd,
exim-queue and V1-spool checks are deliberately not ported (dead tech /
covered by `email:health`).

**Topology never enters the codebase.** The ssh targets come from
`FREEGLE_MONITORING_HOSTS` (comma-separated, in the uncommitted
`.env.background`); the key is bind-mounted from `MONITORING_SSH_KEY_HOST_PATH`
to `/etc/monitoring-ssh-key` (see `docker-compose.yml` and
`.env.background.example`). Empty target list — dev, CI — registers no host
checks at all. The batch image ships `openssh-client` for this
(`docker/Dockerfile`).

## Implemented checks

Eleven per-job checks across all four primitives, plus one `HostHealthCheck`
per ssh target in `FREEGLE_MONITORING_HOSTS` (see "Host health checks" above):

| Task | Primitive | Signal | Notes |
|---|---|---|---|
| `stats:generate-daily` | `ProducedSinceCheck` | `stats` rows with `date >= yesterday` (London), floor 1 | Active 06:00–24:00 so it isn't checked before the 02:30 run. Strongest fire-once signal in the system (one `REPLACE` per group/type/day). |
| `mail:digest:unified --mode=daily` | `ProducedSinceCheck` | `users_digests.lastsent` today WHERE `mode='daily'`, floor 1 | Gated `enabledWhen(daily_allowlist != '')` — inert (skipped) until the daily pilot is switched on. Active 13:00–24:00 (after the 07:00–12:00 send window). |
| `queue:background-tasks` | `BacklogCheck` | `background_tasks` rows `processed_at IS NULL AND failed_at IS NULL AND attempts < 3` older than 10 min | Go-API → Laravel task bridge; a stuck worker is the failure mode, not an empty queue. |
| `messages:contentcheck` | `BacklogCheck` | `messages_groups` (joined to live `messages`/`users`) `collection='Pending' AND contentcheck_checked_at IS NULL AND deleted=0` older than 15 min | Moderation pipeline. The joins mirror the worker's exact selection so rows it permanently skips (deleted message / null fromuser) don't false-alarm. |
| `chats:process-incoming` | `BacklogCheck` | `chat_messages` `processingrequired=1` (by `date`) older than 15 min | Worker clears `processingrequired` on every visited row (success or fail). |
| `memberships:process` | `BacklogCheck` | `memberships_history` `processingrequired=1` (by `added`) older than 15 min | Welcome-mail / review processing queue. |
| `users:process-exports` | `BacklogCheck` | `users_exports` `completed IS NULL` (by `requested`) older than 30 min | GDPR exports are rare, so an empty queue is normal — only a genuinely-stuck export fires. |
| `spam:refresh-mobile-cidrs` | `FreshnessCheck` | `max(spam_whitelist_ips.date)` WHERE `comment LIKE 'UK mobile:%'`, ≤ 40 days | Monthly job; the 40-day floor tolerates the cadence. |
| `integrations:sync-whatjobs` | `FreshnessCheck` | `max(jobs.seenat)`, ≤ 24h | Gated on `freegle.whatjobs.feed1` being set. 24h floor tolerates the 08:00–22:00 window + slow cold runs. |
| `data:git-summary` | `CallbackCheck` (config) | unix timestamp in `config['git_summary_last_run']`, ≤ 10 days | Weekly. Missing key ⇒ skipped (may not have run since deploy); unparseable ⇒ breach. |
| `data:update-cpi` | `CallbackCheck` (config) | ISO-8601 `updated_at` in JSON `config['cpi_annual_data']`, ≤ 40 days | Monthly. Same missing/unparseable handling. |

Thresholds are env-overridable under `config('freegle.monitoring.*')` (see
`.env.example`). A master kill-switch `FREEGLE_MONITORING_ENABLED=false` no-ops
the whole command.

## Adding a check

Add one entry to `ScheduledOutcomeRegistry::checks()`. Verify the table/column
against the actual command/service (and a migration) before adding it — a wrong
column would page the team at 4am. `ScheduledOutcomeRegistryTest` runs every
registered check against the migrated schema, so a typo fails CI rather than
production.

## Categorisation of all scheduled tasks

Legend: ✅ implemented · 🔵 already covered elsewhere · 🟡 monitorable, deferred
(recommended primitive noted) · ⚪ not worth monitoring (zero output is normal).

### Already covered (do not duplicate)

| Task | By |
|---|---|
| *(all jobs at once — scheduler death)* | 🔵 `scheduler-heartbeat` Sentry Cron |
| Aggregate outgoing/incoming email flow | 🔵 `monitor:email-health` (`email_tracking.sent_at` stall + volume floor, 24/7) |
| `tn:sync` | 🔵 self-alerts via its own `alertIfSyncStale` → Sentry (ratings staleness >12h) |
| `monitor:scheduled-outcomes` itself | 🔵 `->sentryMonitor()` heartbeat |

Because every email-sending job flows through `email_tracking.sent_at`,
`monitor:email-health` already catches a smarthost/spooler stall affecting the
**aggregate** mail pipeline. Individually monitoring sparse per-job mail
(welcome, chat notifs, mod-notifs, admin mails, donation thanks, engage,
stories, birthday, noticeboard thanks, alerts, notification chaseup) adds little
— they are sparse by nature, so a single missed run is invisible and harmless.

### Fire-once output — deferred (🟡)

Not implemented because a strict output floor would **false-alarm**: the output
is legitimately zero on many periods (eligibility-gated, due-only, or an upstream
feed that is genuinely empty), or there is no durable timestamp to key on.

| Task | Why not (yet) |
|---|---|
| `data:fetch-app-versions` | The `config` value is just the version string with **no embedded timestamp**, and the store version rarely changes — no freshness signal without adding instrumentation. |
| `domains:update-common` | `domains_common` has **no timestamp column** (id/domain/count only) — nothing to key freshness on. |
| `integrations:sync-restartproject` / `-repaircafewales` / `-reachvolunteering` | `communityevents`/`volunteering` by `externalid` prefix — the upstream feed can be **legitimately empty** of upcoming events, so a floor would false-alarm. |
| `mail:donations:thank-prep` | Dedup is a `config` id high-water-mark with no timestamp, and it only advances when new donations exist — zero is correct on a quiet day. |
| `mail:volunteering-digest` / `mail:events-digest` | `users_digests.lastsent` mode `volunteering`/`events`, but the 3-day cadence guard + eligibility mean a zero week is correct. |
| `groups:check-mod-welfare` | `groups_mods_welfare.warnedat` only advances when an inactive mod is found — zero is the healthy case. |
| `groups:welcome-review` | Processes only groups *due* for review (≤10/run); most days none are due. |
| `messages:process-expired` | `messages_outcomes outcome='Expired'` is zero when no deadlines lapsed that day. |

Adding any of these would need a per-job "am I expected to have output *this*
period?" precondition richer than a simple floor.

### Cursor / queue staleness — deferred (🟡)

The high-value processing queues are now implemented (see above). These remain,
to be added with `BacklogCheck`/`FreshnessCheck` as the failure modes are
prioritised (each needs its exact "pending" predicate verified first):

| Task | Backlog/freshness signal |
|---|---|
| `embeddings:generate` | messages awaiting embeddings (`messages_spatial successful=0 promised=0` minus `messages_embeddings`) — multi-table predicate, verify before adding |
| `messages:update-spatial-index` / `messages:update-index` | newest indexed vs newest message |
| `newsfeed:generate-link-previews` | `link_previews.retrieved` recency vs new URLs (quiet-period false-alarm risk) |
| `microvolunteering:notify`, `chats:update-expected`, `users:update-modmails` | cursor recency |

### Not worth monitoring (⚪)

Zero output is the normal, healthy case — monitoring a count/freshness here
would false-alarm constantly:

- **Cleanup / purge / dedup** (delete nothing when there's nothing to delete):
  `cleanup:search-duplicates`, `cleanup:chat-duplicates`,
  `cleanup:archive-profile-images`, `cleanup:sessions`, `purge:chats`,
  `purge:logs`, `purge:messages`, `emails:validate`, `messages:deindex`,
  `mail:spool:process` (filesystem), `mail:cleanup-archive` (filesystem),
  `users:remove-spammers`.
- **In-place recomputes with no advancing timestamp** (UPDATE existing rows):
  `groups:update-counts`, `chats:update-counts`, `users:update-lastaccess`,
  `users:update-engagement`, `users:update-ratings`,
  `users:update-support-roles`, `ai:usage-counts:update`,
  `donations:update-ads-target`, `microvolunteering:score`.
- **Backlog-cleared / sparse maintenance** (legitimately a no-op once caught up):
  `users:remap-locations`, `users:fix-tn-names`, `messages:remap-subjects`,
  `locations:fix-skewed`, `locations:sync-pgsql`, `messages:update-visualise`,
  `jobs:generate-illustrations`, `messages:generate-illustrations`,
  `groups:check-boundaries` (read-only validation), `donations:update-giftaid`,
  `deploy:record-commit` (`config` has no timestamp column),
  `eee:sync-mv-labels` (cursor in cache; remote sink),
  `integrations:sync-lovejunk`, `mail:admin:chase`.
- **Email-only "alert when bad"** (zero output = healthy platform):
  `groups:remind-closed`, `groups:alert-no-messages`.
