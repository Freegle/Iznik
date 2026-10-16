---
last_reviewed: 2026-10-16
owner: Freegle dev team
covers:
  - iznik-batch/app/Support/EnvSetting.php
  - iznik-batch/tests/Unit/Config/BlankEnvMeansDefaultTest.php
  - iznik-batch/config/freegle.php
  - iznik-batch/routes/console.php
  - iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php
  - iznik-batch/app/Mail/Session/LoginLinkMail.php
  - iznik-batch/resources/views/emails/mjml/session/login-link.blade.php
  - iznik-batch/app/Mail/Traits/TrackableEmail.php
  - iznik-batch/app/Mail/Traits/FeatureFlags.php
  - iznik-batch/tests/Unit/Mail/UnsubscribeCategoryCoverageTest.php
  - iznik-batch/tests/Unit/Console/ScheduleProfileTest.php
  - iznik-batch/tests/Fixtures/console.deployment.php
  - iznik-batch/database/migrations/2026_09_06_000001_add_agreement_columns_to_messages_promises.php
  - iznik-server-go/message/messageOutcome.go
  - iznik-server-go/test/message_agreement_test.go
  - iznik-nuxt3/eslint.config.mjs
  - iznik-batch/app/Services/Ux/UxExperimentService.php
---

# Deployment switches

Freegle's code is also the base for other, separate services (the first is a garden-sharing
site, built as a Nuxt layer on top of `iznik-nuxt3` with its own database and its own copy
of the Go API and the batch jobs). A separate service like that wants to keep pulling
Freegle's fixes and improvements. It can only do that cheaply if it never has to edit the
files it shares with Freegle.

So the things such a service needs to do differently are **switches in the shared code**,
not edits to it. Every switch here defaults to how Freegle already behaves. Leave them all
unset and nothing changes. A deployment sets the ones it needs in its environment, keeps
its own code in files Freegle does not ship, and merges from Freegle whenever it likes.

## The switches

All of these are read through `config('freegle.*')`, so they are set with environment
variables like every other Freegle setting.

| Setting | Env var | Freegle default | What it changes when set |
|---|---|---|---|
| `auth.passwordless` | `FREEGLE_PASSWORDLESS_LOGIN` | `false` | A "forgot password" request sends a **sign-in link** email (`LoginLinkMail`) instead of the "set a new password" one. |
| `auth.login_link_path` | `FREEGLE_LOGIN_LINK_PATH` | `/` | Where that sign-in link lands on the site. The page there must consume `?u=&k=` to sign the member in; the root app already does. |
| `trashnothing.merge_legacy_duplicates` | `FREEGLE_TN_MERGE_LEGACY_DUPLICATES` | `false` | On, `tn:sync` narrows to Trash Nothing addresses on the address itself rather than on one form of `users_emails.backwards`, and so sees the ~96 duplicate account pairs the old filter could not. Merging a pair deletes one of the two accounts, so read `tn:sync --report-duplicates` before setting it. |
| `freebie_alerts.api_key`, `yeybu.api_key` | `FREEBIE_ALERTS_KEY`, `YEYBU_KEY` | blank | Set, approved Offers are pushed to that partner's post feed (see [partner-integrations.md](partner-integrations.md)). Blank, nothing is sent, so a separate deployment never pushes its posts as Freegle's. |
| `mail.tracking_enabled` | `FREEGLE_MAIL_TRACKING_ENABLED` | `true` | Off, no `email_tracking` row is written and every tracked link, image and pixel helper returns the plain destination. For a deployment whose API does not serve the tracking redirect and pixel. |
| `mail.enabled_types` | `FREEGLE_MAIL_ENABLED_TYPES` | (as before) | New: a `*` in the list enables every type, so a deployment with its own mailables need not re-list Freegle's whole catalogue. |
| `mail.relay_logs.enabled` | `FREEGLE_MAIL_RELAY_LOGS_ENABLED` | `true` | Off, or with no relay host set, nothing reads the outbound relay's maillog into `logs_emails` and the job is not even scheduled. A deployment whose relay it cannot reach - and any dev or CI environment - wants this off. |
| `mail.relay_logs.host` | `FREEGLE_MAIL_RELAY_LOGS_HOST` | falls back to `FREEGLE_MAIL_DEFERRALS_HOST` | The ssh target for that read. It defaults to the deferral probe's target because it is the same relay and the same restricted account; set it only if they differ. Empty disables the job. |
| `mail.relay_logs.ssh_key` | `FREEGLE_MAIL_RELAY_LOGS_SSH_KEY` | falls back to `FREEGLE_MAIL_DEFERRALS_SSH_KEY` | The key for that read, defaulting to the deferral probe's restricted one. Reading a log file needs no more rights than that account already has, so it deliberately does not use the monitoring key. |
| `mail.relay_queue.min_queued` | `FREEGLE_MAIL_RELAY_QUEUE_MIN` | `25` | How many messages a recipient domain needs in the relay queue before the delayed view shows it. Lower and every paced provider is listed permanently, because a sending address on a rate delay always has something queued. |
| `mail.relay_queue.min_age_minutes` | `FREEGLE_MAIL_RELAY_QUEUE_MIN_AGE` | `120` | The other way a domain earns a row. A handful of messages stuck for a day never reaches the size threshold and is the shape nobody notices, so age qualifies on its own. |
| `mail.relay_queue.max_rows` | `FREEGLE_MAIL_RELAY_QUEUE_MAX_ROWS` | `500` | Most rows kept. An estate-wide episode names thousands of domains; the worst are kept and the rest dropped. |
| `schedule.profile` | `FREEGLE_SCHEDULE_PROFILE` | `full` | `overlay-only` runs nothing from `routes/console.php` except what the overlay file below schedules. Any other value behaves as `full`, so a typo can never quietly stop the schedule. |
| `schedule.overlay` | `FREEGLE_SCHEDULE_OVERLAY` | `routes/console.deployment.php` | A schedule file loaded **if it exists** (relative to the app root, or absolute). Freegle ships none. A deployment puts its own jobs there. |
| `cookieyes.enabled` | `COOKIEYES_ENABLED` | `true` | Off, the weekly `cookieyes:check` is not scheduled. For a deployment with no CookieYes account, which would otherwise fail and email every week. See [cookieyes-watchdog.md](cookieyes-watchdog.md). |
| `image_store.enabled` | `IMAGE_STORE_ENABLED` | `false` | On, `images:push-spool` runs every minute, moving completed tusd uploads from the local spool to the `images` S3 disk. Off, uploads stay in the spool and are served from there, which is how a deployment without a bucket runs. Turn on only after `images:object-store-check` passes; see [the runbook](../../ops/runbooks/images-to-object-storage.md). |
| `backup.drain.enabled` | `BACKUP_DRAIN_ENABLED` | `false` | Holds batch work off while the nightly database backup runs. Off ships as a no-op. See below. |
| `backup.drain.start` | `BACKUP_DRAIN_START` | `03:50` | When the hold starts, `HH:MM` in the app timezone. Anything that is not a valid `HH:MM` leaves the drain off rather than holding the schedule back for ever. |
| `backup.drain.minutes` | `BACKUP_DRAIN_MINUTES` | `45` | How long the hold lasts. Zero or negative leaves it off, on the same reasoning. |
| `backup.drain.always_run` | `BACKUP_DRAIN_ALWAYS_RUN` | `lockdown:tick,lockdown:report,ux:experiments-tick` | Comma-separated artisan command names that run anyway, matched without their arguments. Anything listed is competing with the backup, so keep it short. Set it starting from this list, not from the lockdown pair alone, or the experiment tick is held off again. |
| `backup.database.enabled` | `BACKUP_DB_ENABLED` | `false` | Takes the nightly physical backup from Laravel instead of the shell script on the database node. Off ships as a no-op: the command refuses and the scheduled entry does not fire. |
| `backup.database.host` | `BACKUP_DB_HOST` | empty | The node being backed up. xtrabackup copies a local data directory, so the pipeline runs there and only control flow crosses ssh. Empty is a configuration error rather than a default. |
| `backup.database.ssh_key` | `BACKUP_DB_SSH_KEY` | `/etc/monitoring-ssh-key` | The private key the batch container uses to reach the node, at its path inside the container. The default is the monitoring key, which docker-compose already mounts and which is the root shell the backup needs anyway. |
| `backup.database.ssh_timeout_seconds` | `BACKUP_DB_SSH_TIMEOUT` | `7200` | How long the ssh session may run. The backup measured eighteen minutes; the monitoring probe's thirty seconds would kill it partway. |
| `backup.database.target_dir` | `BACKUP_DB_TARGET_DIR` | `/backup` | xtrabackup's scratch directory on the node. Streaming writes nothing of size there. |
| `backup.database.bucket` | `BACKUP_DB_BUCKET` | `gs://freegle_backup_uk` | Where the compressed stream goes. |
| `backup.database.compress_threads` | `BACKUP_DB_COMPRESS_THREADS` | `4` | xtrabackup compression threads. More finishes sooner and competes harder. |
| `ux.report_to` | `UX_REPORT_TO` | falls back to `FREEGLE_GEEKS_ADDR` | Where `ux:weekly-report` sends the Monday summary of the biggest week-on-week UX changes. See [ux-telemetry.md](ux-telemetry.md). |
| `ux.experiments.max_concurrent` | `UX_EXPERIMENTS_MAX_CONCURRENT` | `5` | Most UX experiments running at once. `ux:experiment start` refuses one more. The Go API reads the same variable from its own environment to report the cap, and `docker-compose.yml` does not pass it to `apiv2`: set it on both sides or on neither, or the experiment routes report a cap that the batch does not use. The whole loop is in [ux-experiments.md](ux-experiments.md). |
| `ux.experiments.max_hours` | `UX_EXPERIMENTS_MAX_HOURS` | `24` | How long a UX experiment may run before `ux:experiments-tick` must decide it. |
| `ux.experiments.settle_minutes` | `UX_EXPERIMENTS_SETTLE_MINUTES` | `45` | Only flows that started at least this many minutes before now (or the end) are counted, with everything they did up to then, so flows that have not had time to finish do not count against the arm that got more traffic. |
| `ux.experiments.min_starts_per_arm` | `UX_EXPERIMENTS_MIN_STARTS` | `50` | Flow starts each arm needs before any decision at the end. Fewer is inconclusive. |
| `ux.experiments.early_min_starts_per_arm` | `UX_EXPERIMENTS_EARLY_MIN_STARTS` | `300` | Flow starts each arm needs before an early stop or any re-weighting of traffic. Every hourly look is another chance to be fooled by noise, so these ask for far more than a decision at the end. |
| `ux.experiments.adopt_probability` | `UX_EXPERIMENTS_ADOPT_P` | `0.80` | At the end, adopt at or above this probability the change is better (and the expected loss is small enough), revert at or below one minus it, and call it inconclusive between. |
| `ux.experiments.max_expected_loss_points` | `UX_EXPERIMENTS_MAX_LOSS_POINTS` | `0.5` | Adoption also needs the expected loss of choosing the change, if it turns out worse, to be at most this many points of completion. Stops a near-zero effect being adopted on a lucky probability. |
| `ux.experiments.early_adopt_probability` | `UX_EXPERIMENTS_EARLY_ADOPT_P` | `0.99` | On any hourly read, once both arms have `early_min_starts_per_arm`, adopt at once at this probability (and the same loss bar, and no guardrail blocking). |
| `ux.experiments.early_revert_probability` | `UX_EXPERIMENTS_EARLY_REVERT_P` | `0.02` | On any hourly read, once both arms have `early_min_starts_per_arm`, revert at once at or below this probability the change is better. |
| `ux.experiments.guardrail_margin_points` | `UX_EXPERIMENTS_GUARDRAIL_MARGIN` | `2.0` | A guardrail (submit failures, validation failures, help sought, another touched flow's completion) trips only when the change is worse by more than this many points. A null change read every hour would otherwise trip one by chance. |
| `ux.experiments.guardrail_error` | `UX_EXPERIMENTS_GUARDRAIL_ERROR` | `0.10` | The chance of a false alarm across all of an experiment's guardrails together at the end, split between the `k` guardrails: one blocks adoption at probability `1 - 0.10 / k`. Replaces `UX_EXPERIMENTS_GUARDRAIL_P`, which no longer does anything. |
| `ux.experiments.early_guardrail_error` | `UX_EXPERIMENTS_EARLY_GUARDRAIL_ERROR` | `0.03` | The same for the early revert on any hourly read: probability `1 - 0.03 / k`. Replaces `UX_EXPERIMENTS_EARLY_HARM_P`, which no longer does anything. |
| `ux.experiments.guardrail_min_events` | `UX_EXPERIMENTS_GUARDRAIL_MIN` | `100` | Events needed behind a guardrail, across both arms, before it is read at all. |
| `ux.experiments.bandit` | `UX_EXPERIMENTS_BANDIT` | `true` | Each hourly read moves traffic towards the winning arm by Thompson sampling, once both arms have `early_min_starts_per_arm`. Off keeps the opening split. |
| `ux.experiments.min_control_share` | `UX_EXPERIMENTS_MIN_CONTROL` | `35` | Percent of traffic control always keeps, so the comparison never stops being one. Every variant keeps the same floor (scaled by 2/k for k arms), so no arm is starved or emptied. |
| `ux.experiments.max_variant_share` | `UX_EXPERIMENTS_MAX_VARIANT` | `65` | Percent of traffic one variant can take. |
| `ux.experiments.cleanup_days` | `UX_EXPERIMENTS_CLEANUP_DAYS` | `7` | Days after an experiment is adopted before its switch still being in the code is reported as overdue. The Go API reads this variable too (for the overdue flag), so set it on both sides or on neither. |
| `ux.experiments.min_power` | `UX_EXPERIMENTS_MIN_POWER` | `0.30` | `ux:experiment start` refuses an experiment whose expected effect a day of its flow would adopt less often than this. `0` turns the check off. Only applies where the daily rollup already has figures for the flow. |
| `ux.experiments.default_expected_points` | `UX_EXPERIMENTS_DEFAULT_EXPECTED_POINTS` | `5.0` | The effect, in points of completion, the power check assumes when the experiment's design does not say. |
| `ux.experiments.stale_read_hours` | `UX_EXPERIMENTS_STALE_READ_HOURS` | `2` | When Loki is down, the tick may carry on from its last stored read only if it is younger than this. |
| `ux.experiments.stale_wait_hours` | `UX_EXPERIMENTS_STALE_WAIT_HOURS` | `6` | Past an experiment's end with no usable read, the tick waits this long for Loki and then marks the experiment inconclusive. |
| `ux.experiments.sample_ratio_points` | `UX_EXPERIMENTS_SAMPLE_RATIO_POINTS` | `5` | A variant whose share of starts is further than this many points (and over 3.5 standard errors) from the share it was served is never adopted. A change that loses people before the flow starts looks better on the rate among starters while fewer finish. |
| `ux.experiments.holdout_share` | `UX_EXPERIMENTS_HOLDOUT_SHARE` | `10` | After adoption this percent of members keep the old version until the experiment is closed. `0` turns the holdout, and the decay check that needs it, off. The Go API reads this variable too (to report whether a holdout runs), so set it on both sides or on neither. |
| `ux.experiments.holdout_days` | `UX_EXPERIMENTS_HOLDOUT_DAYS` | `14` | How many days after adoption the tick compares the holdout with the adopted arm, once a day. The Go API reads this variable too (to report when the holdout ends), so set it on both sides or on neither. |
| `ux.experiments.holdout_revert_probability` | `UX_EXPERIMENTS_HOLDOUT_REVERT_P` | `0.99` | The holdout check reverts an adopted change when the old version is this likely to be better on `holdout_revert_looks` looks in a row (both sides need `early_min_starts_per_arm`). High because there are up to 14 daily looks. |
| `ux.experiments.holdout_revert_looks` | `UX_EXPERIMENTS_HOLDOUT_REVERT_LOOKS` | `2` | How many daily looks in a row must be against an adopted change before it is reverted. |
| `ux_server_arms` | none: a row in the `config` table, set with `PATCH /config/admin` and `{"ux_server_arms":"1"}` | unset, which is off | The one switch for every server arm. Only `1` serves arms. Missing or anything else makes every sender in the batch and the Go API act as control within a minute and stop writing exposure rows, with no deploy. `ux:experiment start` refuses a mail, push or server experiment while it is off. See [Experiments in mail, push and the server](ux-experiments.md#experiments-in-mail-push-and-the-server). |
| `ux.channels.<channel>.ceiling_hours`, `.intake_hours` | none, set in `config/freegle.php` | push `48` and `24`, email `168` and `24`, server `336` and `168` | For an experiment decided on the server: the longest run `start` accepts, and the hours members are reached by default (the rest of the run is the window they have to convert in). A web experiment keeps `ux.experiments.max_hours`. |
| `ux.sources.<name>` | none, set in `config/freegle.php` | `email_click`, `active_after` | The metric sources an experiment can name in `design.source`, each a class that counts from the database instead of Loki. A new source is a class and a line here. |
| `backup.database.alert_email` | `BACKUP_DB_ALERT_EMAIL` | `geek-alerts@ilovefreegle.org` | Where a failure is mailed. Plain text, no template, because this has to work when the database is unhappy. |

Every setting in `config/freegle.php` is read through `EnvSetting::env`, which is Laravel's
`env()` with one change: a variable that is unset, empty or only spaces is the default.
docker-compose passes a variable nobody set as an empty string, and `env()` returns that
instead of the default, so a bare `(int)` or `(bool)` cast turned a default of `30` into `0`
and a switch that is on by default into off, with no error anywhere. Anything that is not
blank comes back exactly as `env()` gives it, so `false` is still false and `0` is still `0`.
`BlankEnvMeansDefaultTest` loads the file with every variable it names removed, then with every
one blank, and fails if the two results differ, so a new bare `env()` read cannot slip in.

### The backup drain

The nightly backup takes a physical copy with `wsrep_desync = ON`. Desync is not
replication lag: Galera is virtually synchronous, and desync deliberately takes the node
out of flow control so the cluster stops waiting for it and it falls behind for the
duration, which measured about eighteen minutes on 18 September 2026.

While the backup ran on a node nothing reads from, that did not matter. Under the two-node
topology it moves to the node serving bulk reads, so batch work has to be held off: partly
for I/O, and partly because `wsrep_sync_wait` is 0, so nothing makes a reader on the
desynced node wait for it to catch up.

Two halves, both driven by the settings above:

- **The schedule.** `App\Console\BackupDrain::apply()` runs at the end of
  `routes/console.php`, after every command is defined, so a new job cannot be forgotten.
  It gives each one a filter that is re-checked on every tick.
- **The queues.** `AppServiceProvider` registers a `Queue::looping` listener that makes
  workers sleep rather than reserve a job. Nothing is lost: jobs stay queued and are picked
  up when the window closes.

A job already running when the window opens is left to finish, which is why `start` is set
earlier than the backup's own cron. That gap is the drain; the rest is the delay.

**A job that fires once a day inside the window is skipped, not delayed.** Laravel's
scheduler has no catch-up: a `dailyAt()` that falls in the window simply does not run that
day. Every-minute jobs and anything with a second slot later in the day are only delayed.
So nothing once-a-day may be scheduled inside the window, and
`BackupDrainWindowTest` fails the build if something is. Move the job, or move the window
and the backup together; the same test checks that the backup itself still sits inside it.
The window is fixed in the app timezone (UTC). A job pinned to London time moves against it
by an hour twice a year, so the test takes each job's firing time in the job's own timezone
on a summer date and a winter date. A job that must run just after the window is best
scheduled in UTC, as the WhatJobs digest-prep sync is at 04:40.

`php artisan backup:drain-status` reports whether the hold is in force and exits 0 if it
is, for the backup script to check before it desyncs. It deliberately does not stop a
backup: a backup that did not run is worse than one that ran alongside some batch work.

`backup:` commands are never held off, structurally rather than through `always_run`. The
window exists for the backup, so holding the backup back inside it would mean it never ran,
and leaving that to a config entry would be a way to stop backups silently. The scheduler
heartbeat and `monitor:scheduled-outcomes` are structural exemptions for a different
reason: both carry Sentry Crons check-ins, and two consecutive misses raise an issue, so a
45-minute hold would page every night about a scheduler that is fine. Their cursor-staleness
checks (`BacklogCheck`) report *skipped* rather than a breach once the hold has lasted longer
than the check's own maximum age, and for up to fifteen minutes after the window while the
workers catch up, because a backlog then is the drain doing its job. A check whose maximum
age is longer than the window, such as the 24-hour rippling backlog check, is never skipped:
a 45-minute hold cannot explain a day-old row.
`always_run` matches an artisan command name or, for a scheduled closure, its `->name()`.

**`lockdown:tick` and `lockdown:report` are in the default `always_run` list** because a
lockdown is a live security incident, not routine batch work: the announce mail, holding
and releasing, and the hourly report cannot wait out a 45-minute backup drain
window while a spam wave is still going out. See the [lockdown runbook](../../ops/runbooks/lockdown.md).

**`ux:experiments-tick` is in the default list too.** It only reads Loki and writes a few small rows, and
it is the clock a 24-hour experiment is decided by. An hourly job that falls inside the window is skipped,
not delayed, so holding it would leave an experiment that ends in the window decided an hour late, on more
than a day of data, and the early-revert guardrails unchecked for two hours. See
[the hourly tick](ux-experiments.md#the-hourly-tick).

### Taking the backup from Laravel

`php artisan backup:database` is a port of the shell script that has lived on the database
node at `/var/www/iznik/scripts/backup`. It is off until `backup.database.enabled` is set,
and the shell script remains what actually runs until then. `--dry-run` prints the script it
would run on the node without running anything, which is the way to review it before
switching over.

The command reaches the node with its own ssh runner, bound in `AppServiceProvider` with the
key and timeout above and injected through the command's constructor. That is deliberate: a
contextual binding only applies while the container is building a class, so a runner asked
for as a `handle()` parameter would be the monitoring one, with its thirty-second timeout.

The node reports back with three markers on its output, and the command treats a missing
one as failure: `FREEGLE_BACKUP_ABORT=` when it stopped before desyncing (a tool that is
not executable, or a desync that did not take), `FREEGLE_BACKUP_RESULT=` with the
pipeline's exit status, and `FREEGLE_BACKUP_RESYNC=` from the exit trap, saying whether
the node rejoined flow control. A good backup on a node that is still desynced alerts too.
The alert carries the last forty lines the node printed.

Deliberate differences from the script:

- **`set -o pipefail`.** The script ran `xtrabackup ... | gsutil cp -` and then tested `$?`,
  which in bash is the status of the last command in the pipeline. An xtrabackup that died
  halfway still left gsutil uploading the truncated stream and exiting 0, so the script
  mailed "BACKUP SUCCESS" for an incomplete backup. Only the nightly Yesterday restore would
  have caught it.
- **The desync is released from a trap on EXIT**, not from the last line, so a kill, a
  timeout or a failure anywhere above it cannot leave the node desynced.
- **The tools are checked before the desync, and a desync that does not take is fatal.**
  A backup taken on a node still inside flow control stalls every node's writes for the
  duration, which is worse than no backup.

The `/etc` and crontab sweep at the end of the shell script is a separate concern and has
not moved; that part of the script still runs.

### Sign-in links

The Go API already mints a one-click `u`/`k` credential pair whenever someone asks to
reset their password; the batch job then emails a link carrying it. With
`auth.passwordless` on, the same job sends `LoginLinkMail` instead, pointing the same
credentials at `auth.login_link_path` on the configured `sites.user` host rather than at
the settings page. Nothing about how the credentials are made or checked changes. The
email is transactional (no unsubscribe link), like the password one it replaces.

### The schedule overlay and profile

`routes/console.php` is one long file, and a deployment that wants six jobs out of a
hundred used to have to edit it. Now it drops a file at `routes/console.deployment.php`
(or wherever `schedule.overlay` points) written exactly like the main file, and that is
loaded first. With the default `full` profile the overlay simply adds to Freegle's
schedule. With `overlay-only` the rest of the file is skipped, so only the overlay runs.

## Promises that become agreements

A Freegle promise is one-sided: the item's owner promises it to someone, and that is the
whole story. A deployment may need a two-sided version, where the owner proposes terms and
the other person accepts them. That is now available to any client, and unused by Freegle:

- The `Promise` action on `POST /api/message` accepts an optional `terms` field (any JSON),
  stored on `messages_promises.terms`.
- A new `AcceptAgreement` action lets the person a message was promised **to** accept it,
  which stamps `acceptedat` and `acceptedby` on their promise row. Only they can accept,
  only once, and only while the promise exists; anything else is a 404 and writes nothing.
- The message's `promises` array carries `terms`, `acceptedat` and `acceptedby` **only when
  set**, so a plain Freegle promise serialises exactly as it always did.
- Two further nullable JSON columns, `checkins` and `checkin_reminders_sent`, are there for
  a deployment's own follow-up jobs after an agreement. The Go API does not touch them.

All five columns are nullable and added with an instant (metadata-only) `ALTER`.

## Two more things a deployment gets for free

- **Unsubscribe categories for its own mailables.** `UnsubscribeCategoryCoverageTest`
  refuses any mailable that has not declared its unsubscribe category. A deployment lists
  its own in `iznik-batch/tests/Unit/Mail/unsubscribe-categories.deployment.php`, a file
  Freegle does not ship, returning the same `class => category` array. Freegle's own
  entries always win.
- **Lint for its Nuxt layer.** The ESLint rule that allows single-word page and layout
  names now matches `*/layouts/*.vue` and `*/pages/**/*.vue`, so any layer directory is
  covered, not just `modtools/`.

## What a deployment should never do

Edit a file Freegle ships. If it needs a behaviour Freegle does not have, the right move is
another switch here, defaulting to Freegle's current behaviour, with a test that pins that
default. That is what keeps the merge cheap in both directions.
