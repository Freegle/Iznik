---
last_reviewed: 2026-10-15
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
  - iznik-server-go/utils/autoapprovetrial.go
  - iznik-server-go/utils/envsetting.go
  - iznik-nuxt3/eslint.config.mjs
  - .env.background.example
  - iznik-batch/app/Support/EnvSetting.php
  - iznik-batch/app/Services/Automod/AutomodService.php
  - iznik-batch/app/Console/Commands/Monitor/AutomodHealthCommand.php
  - automod/src/backend.js
  - automod/src/server.js
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
| `autoapprove.enabled`, `autoapprove.trial_group_ids` | `FREEGLE_AUTOAPPROVE_ENABLED`, `FREEGLE_AUTOAPPROVE_TRIAL_GROUPS` | `false`, empty | The approve-mode communities: the comma-separated list, or every community when the switch is on. There a post automated review approves publishes itself after the wait (`messages:auto-approve-clean`). **These two and the shadow list are fixed when each container is created, by both the Go API (the countdown, the Check queue, what moderators see) and the batch container (what actually publishes). Change them in both environments and recreate both together** (`docker compose up -d <service>`; a plain restart keeps the old environment); one recreated without the other shows a countdown the cron never honours, or publishes posts no countdown announced. See [../../moderators/post-moderation.md](../../moderators/post-moderation.md). |
| `automod.shadow_group_ids` | `FREEGLE_AUTOMOD_SHADOW_GROUPS` | empty | Comma-separated community ids where automated review runs every minute (`messages:automod`) and records its decision without acting on it. Shadow wins: a community in this list stays shadow even when it is also in the approve list or `FREEGLE_AUTOAPPROVE_ENABLED` is on, so the global switch can never silently promote a community still being watched (`AutomodMode`). Neither list set, the command does nothing. |
| `automod.url` | `AUTOMOD_URL` | `http://automod:8090` | The internal automated review service. Unreachable, every review is recorded as unavailable and the post waits for a moderator, so nothing publishes by default. `monitor:automod-health` (every 15 minutes) fails, and so reaches Sentry, when a post has waited behind an unavailable review for a quarter of an hour or through three tries, or an approve-mode post is past the delay with no decision at all. |
| `automod.timeout` | `AUTOMOD_TIMEOUT` | `60` | Seconds to wait for one decision; blank, zero or anything that is not a positive whole number means 60. One call answers every question about a post, and the frontier model takes 10 to 20 seconds. The service is told to stop two seconds before this (`deadline_ms`) and answers with a hold and the reason. A review that still does not come back in time is recorded as unavailable, which leaves the post waiting for a moderator, and is tried again after five minutes. A service that says it is busy (503) is different: nothing is written and the post is tried again on the next run. A run of `messages:automod` stops taking posts when its time budget is spent (`RUN_BUDGET_SECONDS`, 40 seconds) or after three reviews in a row get no answer (`MAX_CONSECUTIVE_FAILURES`), and the next minute carries on, so a slow model builds a visible backlog (the per-run Loki line's `candidates_waiting`) rather than overlapping runs. |
| `autoapprove.delay_minutes`, `autoapprove.danger_log_days` | `FREEGLE_AUTOAPPROVE_DELAY_MINUTES`, `FREEGLE_AUTOAPPROVE_DANGER_LOG_DAYS` | `20`, `90` | Blank or zero means the default, because docker-compose passes an unset variable as an empty string. The wait runs from the later of the post's arrival and its decision, so a decision that lands late still gives moderators the full window. |
| `autoapprove.max_per_run` | `FREEGLE_AUTOAPPROVE_MAX_PER_RUN` | `20` | The most posts one run of `messages:auto-approve-clean` publishes. When many fall due at once (a community moving from shadow to approve, an outage ending) they go out over several minutes instead of all together. Blank or zero means 20. |
| `autoapprove.quality_check_percent` | `FREEGLE_AUTOAPPROVE_QUALITY_CHECK_PCT` | `0` | Share of approved posts held back in Pending for a moderator's verdict, decided (and stamped on the copy) the moment automated review records the approve, so the card can say so. The 48-hour sweep remains the backstop for a sample nobody looks at. |
| `ripple.earned_reach_enabled` | `RIPPLE_EARNED_REACH_ENABLED` | `false` | The earned-reach gate on rippling (hold, then spread earned by clean exposure; [rippling-algorithm.md](rippling-algorithm.md) §5). It applies only to a post on an approve-mode community that published itself, so outside the trial it changes nothing even when on. |
| `ripple.autoapprove_hold_seconds` | `RIPPLE_AUTOAPPROVE_HOLD_SECONDS` | `3600` | With the gate on, how old a post that published itself must be before it may start rippling. A moderator's approve or check lifts the hold. `0` turns the hold off. Leave the variable unset to get the default. |
| `ripple.clean_views_per_group` | `RIPPLE_CLEAN_VIEWS_PER_GROUP` | `5` | With the gate on, how many different members other than the poster must have seen a post that published itself, with nobody flagging it, for each community it would then be on. A member's report to the moderators, or a microvolunteer's Reject, pauses further spread until a moderator looks. `0` turns the cap off. Leave the variable unset to get the default. |
| `ripple.rippled_in_pending_hours` | `RIPPLE_RIPPLED_IN_PENDING_HOURS` | `0` | Hours a rippled-in copy, already approved on its origin community, waits in the receiving community's Pending queue before it is approved there. `0` approves it the moment it ripples in. The ModTools countdown on such a copy reads the same figure (`iznik-server-go/message/autoapproveat.go`), so set it in the Go environment too. |
| `cookieyes.enabled` | `COOKIEYES_ENABLED` | `true` | Off, the weekly `cookieyes:check` is not scheduled. For a deployment with no CookieYes account, which would otherwise fail and email every week. See [cookieyes-watchdog.md](cookieyes-watchdog.md). |
| `image_store.enabled` | `IMAGE_STORE_ENABLED` | `false` | On, `images:push-spool` runs every minute, moving completed tusd uploads from the local spool to the `images` S3 disk. Off, uploads stay in the spool and are served from there, which is how a deployment without a bucket runs. Turn on only after `images:object-store-check` passes; see [the runbook](../../ops/runbooks/images-to-object-storage.md). |
| `backup.drain.enabled` | `BACKUP_DRAIN_ENABLED` | `false` | Holds batch work off while the nightly database backup runs. Off ships as a no-op. See below. |
| `backup.drain.start` | `BACKUP_DRAIN_START` | `03:50` | When the hold starts, `HH:MM` in the app timezone. Anything that is not a valid `HH:MM` leaves the drain off rather than holding the schedule back for ever. |
| `backup.drain.minutes` | `BACKUP_DRAIN_MINUTES` | `45` | How long the hold lasts. Zero or negative leaves it off, on the same reasoning. |
| `backup.drain.always_run` | `BACKUP_DRAIN_ALWAYS_RUN` | `lockdown:tick,lockdown:report` | Comma-separated artisan command names that run anyway, matched without their arguments. Anything listed is competing with the backup, so keep it short. |
| `backup.database.enabled` | `BACKUP_DB_ENABLED` | `false` | Takes the nightly physical backup from Laravel instead of the shell script on the database node. Off ships as a no-op: the command refuses and the scheduled entry does not fire. |
| `backup.database.host` | `BACKUP_DB_HOST` | empty | The node being backed up. xtrabackup copies a local data directory, so the pipeline runs there and only control flow crosses ssh. Empty is a configuration error rather than a default. |
| `backup.database.ssh_key` | `BACKUP_DB_SSH_KEY` | `/etc/monitoring-ssh-key` | The private key the batch container uses to reach the node, at its path inside the container. The default is the monitoring key, which docker-compose already mounts and which is the root shell the backup needs anyway. |
| `backup.database.ssh_timeout_seconds` | `BACKUP_DB_SSH_TIMEOUT` | `7200` | How long the ssh session may run. The backup measured eighteen minutes; the monitoring probe's thirty seconds would kill it partway. |
| `backup.database.target_dir` | `BACKUP_DB_TARGET_DIR` | `/backup` | xtrabackup's scratch directory on the node. Streaming writes nothing of size there. |
| `backup.database.bucket` | `BACKUP_DB_BUCKET` | `gs://freegle_backup_uk` | Where the compressed stream goes. |
| `backup.database.compress_threads` | `BACKUP_DB_COMPRESS_THREADS` | `4` | xtrabackup compression threads. More finishes sooner and competes harder. |
| `ux.report_to` | `UX_REPORT_TO` | falls back to `FREEGLE_GEEKS_ADDR` | Where `ux:weekly-report` sends the Monday summary of the biggest week-on-week UX changes. See [ux-telemetry.md](ux-telemetry.md). |
| `backup.database.alert_email` | `BACKUP_DB_ALERT_EMAIL` | `geek-alerts@ilovefreegle.org` | Where a failure is mailed. Plain text, no template, because this has to work when the database is unhappy. |

Every setting in `config/freegle.php` is read through `EnvSetting::env`, which is Laravel's
`env()` with one change: a variable that is unset, empty or only spaces is the default.
docker-compose passes a variable nobody set as an empty string, and `env()` returns that
instead of the default, so a bare `(int)` or `(bool)` cast turned a default of `30` into `0`
and a switch that is on by default into off, with no error anywhere. Anything that is not
blank comes back exactly as `env()` gives it, so `false` is still false and `0` is still `0`.
`BlankEnvMeansDefaultTest` loads the file with every variable it names removed, then with every
one blank, and fails if the two results differ, so a new bare `env()` read cannot slip in.

### How the post-moderation settings are read

The batch reads the post-moderation switches, group lists and numbers above through
`App\Support\EnvSetting`, and the Go API applies the same rule
(`iznik-server-go/utils/envsetting.go`, tested against the same cases), so the cron and the
countdown cannot disagree about a community. A switch is on only for `true` or `1`, in any
case: `yes`, `on`, `off` and `no` are all off, so a word that merely looks like a value cannot
turn on something that publishes posts. The earned-reach gate is read the same way. A number is
a positive whole number or the default; blank, zero, negative, fractional and non-numeric all
mean the default. A group list keeps only plain-digit ids, so `12abc` is not community 12. The
two earned-reach numbers (`RIPPLE_AUTOAPPROVE_HOLD_SECONDS` and `RIPPLE_CLEAN_VIEWS_PER_GROUP`)
are counts where `0` is a real setting (hold off, cap off): blank or unset means the default,
and only an explicit `0` turns them off.

### The automated review service's own settings

The `automod` service (`automod/`, a Node container) is not Laravel, so these are not
`config('freegle.*')` settings. It reads them from its own environment, which docker-compose
fills for the `automod` service, and a value is fixed when the container is created: change
one, then recreate the container (a plain restart keeps the old environment). The Anthropic key
or subscription token is read the same way (`ANTHROPIC_API_KEY`, else
`CLAUDE_CODE_OAUTH_TOKEN`; see [external-services.md](external-services.md)). Blank means the
default.

| Env var | Default | What it changes |
|---|---|---|
| `AUTOMOD_BACKEND` | `claude` | Which backend answers a question when neither the request nor the chart node names one. `claude` is the frontier model, through the API when `ANTHROPIC_API_KEY` is set and otherwise through the `claude` CLI on `CLAUDE_CODE_OAUTH_TOKEN`. The others are `nli` (the local model), `jev` (TypeSafe's) and `fake` (deterministic, for tests and local runs). No chart node names a backend today, so this chooses for all of them. |
| `AUTOMOD_CLAUDE_MODEL` | `claude-opus-5-5` | The model the API backend asks for. docker-compose supplies this default; the service on its own falls back to `claude-opus-5`. The model that answered each step is stored with it and shown by Why? in ModTools. |
| `AUTOMOD_CLAUDE_EFFORT` | `medium` | The effort the API backend asks the model to put in (`output_config.effort`). The CLI backend does not use it. |
| `AUTOMOD_CLAUDE_CLI_MODEL` | `opus` | The model alias the CLI backend asks for. The model the CLI reports having used is what gets recorded, not the alias. |
| `AUTOMOD_CLAUDE_BIN` | `claude` | The CLI binary the CLI backend runs. |
| `AUTOMOD_CONCURRENCY` | `2` | Reviews the service runs at once. |
| `AUTOMOD_QUEUE_MAX` | `10` | Reviews that may wait for a free slot. Past that the service answers 503, which the batch treats as busy: nothing is written and the post is tried again on the next run. `0` means none wait. |
| `AUTOMOD_UNHEALTHY_AFTER` | `20` | Reviews in a row the backend can fail to answer before `/health` answers 503 and the container reports unhealthy. The last error and the time of the last success are in `/health`. |
| `TYPESAFE_API_KEY`, `TYPESAFE_BASE_URL`, `AUTOMOD_JEV_MODEL` | empty, `https://api.typesafe.ai`, `jev-latest` | TypeSafe's Jev backend, a comparison backend for `automod/src/evaluate.js` that makes live decisions only if `AUTOMOD_BACKEND` names it. The base URL points it at a compatible server. |

docker-compose passes the container every name above except `AUTOMOD_CLAUDE_EFFORT`,
`AUTOMOD_CLAUDE_BIN` and `AUTOMOD_JEV_MODEL`. The service reads those three but nothing sets
them yet, so to use one, name it in the `automod` service's `environment` first.

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
