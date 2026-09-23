# Docker host CPU: where it goes, what would cut it, and what could move overnight

> Superseded in scope by `plans/2026-09-20-docker-host-cpu-radical-reduction.md` (a full-day trace with
> profilers; the findings here still hold and are cited there).

Date: 19 September 2026. Analysis only; nothing here has been implemented. Sources: sysstat on
the Docker host (hourly, 15-19 Sept), cgroup CPU accounting since the last boot (31 Aug), a
five-minute per-process sample inside the batch container, the batch container's own Laravel
log for 16-19 Sept, `docker stats`, the Laravel schedule, and `email_tracking` on db2.

## 1. Summary

- **The batch container is the host's CPU.** Since boot, `batch.slice` (batch-prod plus the two
  spatial containers it calls) has used 3.1 cores on average; every other container and the
  Docker daemon together 0.31; interactive shells 0.18. Within the slice, the routing container
  averages 0.54 cores and the KNN container 0.27, so batch-prod itself is about 2.3 cores.
- **The daily shape is 1.4-2.3 cores overnight (21:00-05:00), 3-4 cores by day, and since
  18 September a 7.5-8 core block at 06:00-08:00 UTC.** That block is the daily digest: eight
  shards, window 07:00-12:00 London, now CPU-bound because the db2 query fix on 17 September
  (#1542/#1543) removed the database wait that used to smear the same work across the morning.
  It is the same total work finishing sooner, which is what the send-time analysis wanted.
- **Overnight is already the quiet period, and the host has 12 cores for a 3-4 core day.** CPU is
  not what stops the host dropping a package; RAM is (see the hosting-cost plan). Time-shifting
  work therefore buys headroom and calmer mornings, not money, unless it is combined with
  absolute cuts large enough to make a C-24 (8 vCPU / 24 GB) or smaller fit.
- **Absolute cuts, in order of size:** the two chat mailers re-hydrating every unmailed message
  once a second (section 3.1, ~0.5 cores at midday), the digest's two-pass render (3.2), the ~75
  Laravel bootstraps a minute the scheduler and its `schedule:finish` calls cost (3.3),
  every-minute jobs that scan and find nothing (3.4), and logging volume (3.5). Together they
  are roughly a core, a third of the host's average.
- **Time-shift candidates** (section 4): the rippling morning catch-up start, a handful of daily
  and hourly maintenance jobs, and link-preview generation. The digest itself must not move
  overnight: the engagement cliff below 05:00 London is 3-7x.

## 2. Measurements

### 2.1 Host CPU by hour (cores in use of 12, from sysstat, UTC)

| Hour | 00 | 01 | 02 | 03 | 04 | 05 | 06 | 07 | 08 | 09 | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 | 19 | 20 | 21 | 22 | 23 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 15 Sep | 1.9 | 2.3 | 2.2 | 2.0 | 1.8 | 2.2 | 3.6 | 3.2 | 3.1 | 4.0 | 2.8 | 3.6 | 3.4 | 3.1 | 3.0 | 3.2 | 3.6 | 3.6 | 3.7 | 3.1 | 2.5 | 3.1 | 2.3 | 2.0 |
| 16 Sep | 2.3 | 3.7 | 2.4 | 1.9 | 1.5 | 2.2 | 3.8 | 3.0 | 3.2 | 3.8 | 3.4 | 3.0 | 3.2 | 3.3 | 3.2 | 4.2 | 3.6 | 3.6 | 3.5 | 2.9 | 2.7 | 2.9 | 2.4 | 1.7 |
| 17 Sep | 1.4 | 2.4 | 2.1 | 2.5 | 3.0 | 2.8 | 3.5 | 2.9 | 3.9 | 3.8 | 2.9 | 3.6 | 3.2 | 3.5 | 2.6 | 3.2 | 3.4 | 3.0 | 3.3 | 5.8 | 5.1 | 2.1 | 1.9 | 2.0 |
| 18 Sep | 1.4 | 2.3 | 2.1 | 1.9 | 1.5 | 2.1 | **7.6** | **7.2** | 3.9 | 4.4 | 3.4 | 3.2 | 2.6 | 2.6 | 2.6 | 2.6 | 2.6 | 2.4 | 2.4 | 2.2 | 2.5 | 2.0 | 1.8 | 1.4 |
| 19 Sep | 1.3 | 2.3 | 2.1 | 1.9 | 1.4 | 2.0 | **7.6** | **8.1** | 3.8 | 3.8 | 3.3 | 2.8 | 3.3 | 2.6 | 2.7 | | | | | | | | | |

The 06:00-08:00 block appeared on 18 September. In the batch log for 19 September, the 06:00
hour holds 39k `--mode=daily` digest lines and 127k mail-spooler lines against 20k digest lines
at midday; the daily digest sent 66,892 emails between 06:00 and 11:00 UTC. At ~4.5 extra
cores for two hours that is about 9 core-hours, or 0.48 CPU-seconds per digest, which matches
the July profiling (0.25 s query wall, 0.26 s two-pass render, 0.06 s MJML per user).

### 2.2 CPU by cgroup since boot (18 days)

| cgroup | avg cores | what |
|---|---|---|
| `batch.slice` | 3.11 | batch-prod (all incarnations), routing container (0.54 since 5 Sep), KNN container (0.27) |
| `system.slice` | 0.31 | every other container: delivery 0.12, wiki-media 0.06, wiki-mysql 0.05, Loki 0.04, tusd/nginx/sidecar 0.02 each; dockerd 0.05, containerd 0.04 |
| `interactive.slice` | 0.18 | shells and Claude sessions on the host |
| `user.slice` | 0.03 | |

The slice total exceeds the sysstat average slightly because the sysstat figure is derived from
%idle and ignores steal; the ranking is what matters.

### 2.3 What the scheduler spawns

`schedule:work` starts about 39 artisan processes a minute (380-410 "Running" lines per
ten-minute bucket of `scheduler.log` on 19 September). Twelve a minute are `mail:digest:unified`
(8 immediate shards, 4 reach shards; 8 daily shards on top during the morning window); the rest
are the every-minute jobs below. Each `runInBackground()` job also spawns `php artisan
schedule:finish` when it ends, which is a second full bootstrap (measured 0.30 CPU-seconds each
in the sample below) whose only job is to release the mutex and record the exit code. A cold artisan bootstrap in this container costs 0.23 s user + 0.09 s
system CPU (measured three times), because `opcache.enable_cli` is off. With a CLI opcache file
cache it costs 0.09 s user + 0.12 s system (also measured, second and third runs). Thirty-nine job
bootstraps plus about 35 `schedule:finish` bootstraps a minute at ~0.3 CPU-seconds is 0.35-0.4
cores of the host's ~2.8 average, before any of them does any work.

Every-minute jobs: `mail:digest:unified` (immediate x8, reach x4), `ripple:expand`,
`queue:background-tasks`, `mail:welcome:send`, `mail:chat:user2user`, `mail:chat:user2mod`,
`mail:chat:mod2mod`, `mail:admin:send`, `donations:update-ads-target`, `tn:sync`,
`newsfeed:generate-link-previews`, `users:process-exports`, `messages:contentcheck`,
`chats:process-incoming`, `memberships:process`. Every five minutes: `microvolunteering:notify`,
`chats:process-spam`, `chats:update-expected`, `users:update-modmails`, `users:remove-spammers`.
Every ten: `users:update-ratings`, `matches:notify`. Hourly: `users:update-support-roles`,
`groups:update-counts`, `chats:update-counts`, `community-news:research`.

### 2.4 Work that finds nothing

- The eight immediate digest shards log "Immediate digest send complete ... emails_sent: 0,
  no_new_posts_groups: N" every minute overnight: 14k digest log lines an hour at 05:00 with
  nothing to send. Each shard bootstraps, scans its ~60-70 groups, and exits.
- `newsfeed:generate-link-previews` logs 3,060 lines an hour, every hour, day and night: 51 a
  minute of "Url ... has preview <id>", i.e. it re-examines every linked newsfeed item and
  re-logs the ones already done, each minute.
- `donations:update-ads-target` and `tn:sync` run every minute and produced ~20k log lines each
  over seven days.

### 2.5 The AMP render is done for recipients who cannot use it

`UnifiedDigest` renders the AMP Blade pass for every recipient when AMP is enabled; only the
tracking flag consults `AmpEmailSupport::isSupported`. Today's `email_tracking` rows:

| type (00:00-12:00 UTC, 19 Sep) | has_amp = 1 | has_amp = 0 |
|---|---|---|
| UnifiedDigestDaily | 45,032 | 21,860 (33%) |
| UnifiedDigestImmediate | 25,682 | 18,108 (41%) |

The July profiling put the render at 0.26 s per user for the two passes, so the AMP pass is
roughly 0.13 s. A third of the daily recipients and two fifths of the immediate ones get that
pass rendered and then dropped from the message.

### 2.6 Per-command CPU sample (five minutes, 14:22-14:27 UTC, inside batch-prod)

Method: `/proc/*/stat` user + system ticks read every 0.5 s, max per pid, summed per artisan
command (script in the appendix). Processes shorter than the sample interval are under-counted,
so it understates the every-minute jobs and is fair to the long-running ones. The container
averaged 1.24 cores over the window (372 CPU-seconds, 254 processes).

| Command | CPU-s in 300 s | cores | processes | note |
|---|---|---|---|---|
| `mail:chat:user2user` | 104.3 | 0.35 | 4 | 60 iterations per run; see 3.1 |
| `mail:digest:unified` | 93.9 | 0.31 | 45 | immediate + reach shards, midday |
| `mail:chat:user2mod` | 51.1 | 0.17 | 5 | same loop as user2user |
| `mail:spool:process` | 47.1 | 0.16 | 4 | the four spooler daemons |
| wrapper shells + `schedule:finish` | 41.4 | 0.14 | 146 | 0.28-0.30 s each: the per-job overhead |
| mail receiver (`php -S`) | 8.1 | 0.03 | 1 | |
| `tn:sync` | 2.8 | 0.01 | 5 | |
| everything else (30 commands) | ~23 | 0.08 | ~50 | none above 2.3 CPU-s |

The two chat mailers are 42% of the container's CPU at this hour. Both are already "daemons for
a minute": `--max-iterations=60`, and each iteration calls `notifyByEmail()`, which runs the
`rippling_held_replies` pluck plus the `chat_messages` x `chat_rooms` x `users` query over the
whole look-back window, hydrates every unmailed-and-unseen message with three eager-loaded
relations, de-duplicates in PHP, and then decides per message whether anyone needs mailing. It
sleeps one second only when it sent nothing. The schedule runs it with the command defaults:
`--delay=30` (a message is eligible 30 s after it is sent) and `--since=4` (a four-hour
look-back), so the population re-fetched each second is everything unmailed and unseen from
the last four hours. So the same standing population of messages that
will never be mailed (recipient has notifications off, already seen elsewhere, gated) is
re-fetched and re-hydrated up to sixty times a minute, at ~0.43 CPU-seconds a time.

## 3. Absolute reductions (largest first)

### 3.1 The chat mailers: stop re-hydrating the same messages every second

`mail:chat:user2user` and `mail:chat:user2mod` together are ~0.5 cores at midday (2.6), the
largest single item on the host and larger on a daily average than the digest render. The
work is not the emails; it is the per-second re-evaluation of everything unmailed in the
look-back window. Options, cheapest first, all of which keep the notification delay as it is:

- **Remember what was decided within a run.** Keep the set of message ids already evaluated
  in the 60-iteration loop and query only `chat_messages.id > <max seen>` (plus the released
  held replies) on later iterations. First iteration costs what it costs today; the other 59
  cost a keyed range scan and hydrate only new messages.
- **Mark messages that need no mail** so they leave the candidate set: `mailedtoall = 1` (or a
  separate decided flag if the column's meaning matters to ModTools) when `getMembersToNotify`
  returns nobody. Today such a message is re-fetched every second for the whole look-back
  window.
- **Iterate every 5-10 seconds instead of every second** when the previous iteration sent
  nothing. The `delay` option already holds each message back before it is eligible, so a
  few seconds of scheduling granularity is invisible next to it.
- Do the same in `mail:chat:user2mod` and `mail:chat:mod2mod`, which share the service.

Expected: most of the 0.5 cores. Measure with the sampler before and after, at the same hour.

### 3.2 Digest render: skip AMP for recipients who cannot use it, and share view-data between passes

- Skip `renderAmpTemplate` when `ampForRecipient()` is false. It is the same test the tracking
  flag already makes; the text and MJML parts are unchanged. Saves ~0.13 s x 33% of daily and
  41% of immediate recipients: about 0.8 core-hours a day on the daily alone.
- Share the per-card view-data between the HTML and AMP passes (the July lever). About half of
  the remaining render, so up to 3 core-hours a day, and the 06:00-08:00 peak drops from ~8 to
  ~5.5 cores. Needs a render-diff check in CI; not a config change.
- Together: roughly 0.15 cores average and 2.5 cores off the morning peak.

### 3.3 Stop paying a Laravel bootstrap eighty times a minute

Two levels, and the second makes the first mostly moot for the jobs it covers:

1. **CLI opcache with a file cache** (`opcache.enable_cli=1`, `opcache.file_cache=<dir>` in the
   batch image's PHP ini). Measured 0.32 -> 0.21 CPU-seconds per bootstrap; at ~75 a minute
   (39 jobs plus their `schedule:finish` calls) that is ~0.14 cores (5% of the host). Safe with the bind mount because
   `opcache.validate_timestamps` stays on; the cache directory must be inside the container
   (tmpfs is fine) and cleared on deploy. Cheapest change in this document.
2. **Turn the every-minute commands into daemons or fold them into one tick.** The mail spooler
   already runs as `--daemon` under supervisor. `ripple:expand`, `queue:background-tasks`,
   `messages:contentcheck`, `chats:process-incoming`, `memberships:process`, the five `mail:*`
   senders and the twelve digest shards are all "wake up, look for work, exit" loops that could
   sleep instead of exit. Each one converted removes a 0.32 s bootstrap a minute; converting the
   twelve digest shards and the seven single-purpose loops removes ~19 of the 39 job
   bootstraps and their 19 `schedule:finish` bootstraps, about 0.2 cores, and the immediate/reach digest shards' per-minute group scans become a
   single warm process with a watermark (3.3). This is the structural fix; the monitoring
   already asserts on outcomes rather than cron cadence, so supervisor-managed daemons do not
   lose observability. The drain (`BackupDrain`) pauses queue workers via `Queue::looping`; a
   daemon would need the same check in its loop.

### 3.4 Do not scan when there is nothing to do

- **Immediate and reach digests:** one cheap query per minute ("any message approved, or any
  reach row advanced, since the shard's last run?") before bootstrapping the service. Overnight
  that turns 8 + 4 scans a minute into 12 short-circuits. The July per-user costs do not apply
  here; the cost is the scan of ~530 groups' recent posts, eight times a minute, for nothing.
- **`newsfeed:generate-link-previews`:** select only items without a preview (and not
  previously failed), rather than iterating all linked items and logging the ones that already
  have one. Every minute is also more often than newsfeed links appear; every five minutes with
  the narrowed query would be invisible to members.
- **`donations:update-ads-target`** every minute: an ad target changes with donations, which
  arrive a few times an hour. Every ten minutes.
- **`tn:sync`** every minute: the partner feed is polled at that rate by design for reply
  latency; leave it, but it is 7% of the every-minute bootstraps and a daemon candidate (3.2).

### 3.5 Logging

The batch log for 19 September is 305 MB by 14:00, and the 06:00 hour alone has 127k spooler
lines ("Email spooled" and its siblings) and 29k "Email spooled" digest lines. Every line is a
JSON encode of its context plus a write, and `logs:rotate` then compresses it. Dropping the
per-email INFO lines to DEBUG (the counts are already in the per-run summary line) removes
~200k lines a day at peak hours. Small CPU, larger I/O and disk, and it makes the log readable.

### 3.6 Things that look large and are not

- The routing container (0.54 cores) is the rippling engine; the reach shrink and the
  proximity-notes work already own it. Not a Docker-host CPU lever on its own.
- The KNN container (0.27) serves browse counts and the jobs feed; its cost tracks API traffic.
- Delivery (0.12), the wiki trio (0.12), Loki (0.04), tusd, nginx, the embedding sidecar,
  rspamd, redis, postfix: 0.3 cores between them. Nothing to win.
- Interactive sessions on the host, 0.18 cores: worth knowing when reading `top`.

## 4. Time-shifting to the overnight trough

The trough is 21:00-05:00 UTC at 1.4-2.3 cores. What could go there:

| Job | Now | Could be | Why it can move | Why it might not |
|---|---|---|---|---|
| Rippling active hours start | 06:00 UTC (`RIPPLE_ACTIVE_START_HOUR`) | 04:00 or 05:00 | The 06:00 catch-up of 1-2k overdue rows lands on the digest burst; the memory notes call it "the fragile moment" (expand runs stack, routing gate saturates). Starting the catch-up at 04:00 finishes it before the digest starts. | The pause exists so posts do not ripple, and notify, while people sleep; 04:00 is still asleep for most, so the publicity-budget reasoning holds. Product call, not a technical one. |
| `users:fix-tn-names` | 06:30 | 03:xx | 9.6k log lines and a full pass at the start of the morning peak. | None known. |
| `users:cleanup` | 06:00 | 03:xx | Lands on the digest burst. | Check the drain window (03:50-04:35) and `BackupDrainWindowTest`. |
| `monitor:deprecated-endpoints` | 06:20 | any | Tiny. | None. |
| `community-news:research` | hourly at :30 | nightly | Research for a weekly email. | If it is also feeding something interactive, check first. |
| `users:update-support-roles`, `groups:update-counts`, `chats:update-counts` | hourly | 2-hourly by day, hourly at night, or nightly | ModTools counts. Does anyone need them fresher than an hour? | Check ModTools' expectations before slowing them. |
| Link previews (3.3) | every minute | every 5 min, narrowed | Nobody notices a preview arriving four minutes later. | None. |
| `push:daily-posts` | every 30 min, trails the digest | leave | Tied to digest timing by design. | Moving it breaks the "digest then push" ordering. |

What must not move: the daily digest (engagement cliff overnight; the send-time analysis wants
it later in the morning, not earlier), the immediate digest and chat mails (latency), the mail
spoolers, `tn:sync` (partner reply latency), backups (already 04:00 with the drain).

Net effect of the movable items: the 06:00-08:00 block loses the rippling catch-up and two
daily jobs, which is probably a core or two off the peak; the overnight trough gains the same.
Average host CPU is unchanged. That is the honest limit of time-shifting here: this host is
busier at night than by day already, and CPU is not what its package size is bound by.

## 5. Suggested order

1. Chat mailers: remember what was evaluated within a run, and mark messages that need no
   mail (3.1). Largest saving, contained in one service.
2. CLI opcache file cache (3.3.1): a two-line ini change, measured saving, no code.
3. Skip the AMP pass for recipients who cannot use it (3.2): one condition around
   `renderAmpTemplate`, existing test the tracking flag already relies on.
4. Narrow link-preview generation and slow it to five minutes; `donations:update-ads-target`
   to ten (3.4). Small code, big log-noise cut.
5. Watermark short-circuit for immediate and reach digest shards (3.4).
6. Share digest view-data between passes (3.2): the real render win, needs a render-diff.
7. Daemonise the every-minute loops (3.3.2), digests first.
8. Rippling start hour and the 06:xx daily jobs (section 4), if the product answer is yes.

Measure each with the same instruments: sysstat hourly (already collecting), `batch.slice`
cpu.stat deltas, and the sampler in the appendix, before and after, on more than one day.

## Appendix: per-command sampler

`plans/2026-09-19-docker-host-cpu-reduction/cpu-sampler.sh`, run as
`docker exec -i freegledocker-batch-prod bash -s 300 < cpu-sampler.sh`. Read-only: it reads
`/proc` inside the container and prints a table. Run it at the same hour before and after any
change; one five-minute window is a sample, not a day.
