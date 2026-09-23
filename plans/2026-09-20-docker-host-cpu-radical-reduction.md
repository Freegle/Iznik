# Docker host CPU: a full-day trace, and the radical options

Date: 20 September 2026 (a Sunday). Traced 09:02-20:00 UTC with kernel-exact per-process
accounting, per-minute container accounting, whole-host perf, and the Go services' own
profiles. Analysis only, except one change the operator authorised during the day (pcov off in
production, section 3.1, PR #1583, live 09:18 and rebuilt into the image 13:02).

Follows `plans/2026-09-19-docker-host-cpu-reduction.md`, which was a five-minute sample. That
plan's findings stand (chat mailers, bootstraps, idle digest scans, logging). This one adds the
things a full day and real profilers show: where the routing and KNN containers' CPU actually
goes, which jobs are doing work that is wasted or duplicated, and the shape of the fixes that
would take the host from 3-4 cores to about 1.5.

Instruments are in `plans/2026-09-20-docker-host-cpu-radical-reduction/` (appendix A).

## 1. Summary

The host is 12 cores. Today by hour (sysstat, cores in use, row = hour ending):

| 01 | 02 | 03 | 04 | 05 | 06 | 07 | 08 | 09 | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 | 19 | 20 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1.1 | 2.4 | 1.3 | 0.8 | 1.3 | 3.1 | 7.9 | 7.4 | 3.9 | 3.2 | 3.1 | 2.5 | 2.9 | 2.6 | 2.7 | 2.8 | 2.7 | 2.2 | 2.2 | 2.2 |

Where it goes, per container, in the traced hours (cores; cgroup accounting):

| hour | host | batch-prod | KNN | routing | delivery | everything else | what was special |
|---|---|---|---|---|---|---|---|
| 09 | 4.16 | 2.08 | 0.84 | 0.56 | 0.14 | 0.5 | push job to 09:50, daily re-scans, WhatJobs 09:00-09:27 |
| 10 | 3.14 | 1.53 | 0.55 | 0.51 | 0.17 | 0.4 | daily re-scans to 12:00 |
| 11 | 2.58 | 1.41 | 0.08 | 0.49 | 0.17 | 0.4 | |
| 12 | 3.09 | 1.78 | 0.10 | 0.53 | 0.13 | 0.5 | WhatJobs 12:01-12:27; my image build (0.03) |
| 13 | 2.64 | 1.47 | 0.08 | 0.50 | 0.14 | 0.4 | batch-prod recreated 13:01 |
| 14 | 2.69 | 1.50 | 0.08 | 0.54 | 0.14 | 0.4 | |
| 15 | 2.80 | 1.53 | 0.08 | 0.59 | 0.17 | 0.4 | WhatJobs 15:01 skipped (feeds unchanged, 43 s) |
| 16 | 2.96 | 1.62 | 0.08 | 0.55 | 0.23 | 0.4 | mail:engage daily run (312 s) |
| 17 | 2.57 | 1.44 | 0.08 | 0.47 | 0.17 | 0.4 | |
| 18 | 2.50 | 1.39 | 0.07 | 0.45 | 0.18 | 0.4 | WhatJobs 18:01 skipped (feeds unchanged) |
| 19 | 2.29 | 1.28 | 0.07 | 0.38 | 0.14 | 0.5 | ai-support-helper 0.07 |

"Everything else" is dockerd + containerd (~0.1, mostly Docker DNS and health-check execs), the
wiki trio (0.15), Loki and alloy (0.05), and the interactive sessions on the host including this
one (0.05-0.1).

Ranked by what can be removed:

1. **The routing container does per-member work that should be per-post, and its catchment
   walks the whole 56.9M-node graph on every call** (2.2). 0.5 cores all day, more in the
   morning, all of it driven by calls from the batch: a Dijkstra per member evaluated for
   "N miles by road", a full-graph Dijkstra per new post for its ripple schedule, and a
   fixed 1-second pass per catchment polygon.
2. **The batch pays for process birth 90 times a minute** and runs every PHP process without
   an opcode cache (2.6): about 0.5 cores between the job bootstraps, their `schedule:finish`
   twins, and compiling source in every process.
3. **The daily digest and the push job compute the same thing for the same members** (2.7):
   the 06:00-08:00 block is 9 core-hours, the push job then re-derives every push-enabled
   member's posts for 3h20m.
4. **Twelve digest shards poll every two seconds and find nothing 99.8% of the time** (2.7):
   0.4 cores of PHP plus ~17,000 database queries a minute against db2.
5. **Every HTTP call from PHP opens a new connection after a fresh DNS lookup** (2.5): ~70 a
   second at peak, including 30 TLS connections a second from the WhatJobs sync to this
   host's own public address, and 11,000 lookups forwarded to 8.8.8.8 every three minutes.
6. **Jobs doing wasted work** (2.8): community-news research spawning the Claude CLI 870 times
   an hour into a quota error, embeddings loading an ONNX model from cold every five minutes,
   PHP running with a coverage extension hooked into the VM (fixed today).
7. **The chat mailers** (previous plan, 3.1): still 0.25-0.35 cores, unchanged.

MJML is not on the list. It is compiled in-process by the Rust `mrml` engine through a PHP
extension and is 0.05-0.14% of host samples; the `freegledocker-mjml` node container is the
fallback engine and has used 0.6 CPU-hours in 20 days. The operator's idea of caching rendered
fragments per post is right, but the stage it attacks is the Blade render and the per-member
routing calls, not MJML (4.1).

## 2. Measurements

### 2.1 Batch container, CPU-seconds per hour by command

Exact: the kernel's utime+stime at each process exit, attributed by argv (appendix A). A row is
an hour of wall clock; 3,600 s would be one core.

| command | 09 (52 min) | 10 | 11 | 12 | 13 | 14 | 15 | 16 | 17 | 18 | 19 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `mail:chat:user2user` (60 runs) | 560 | 731 | 791 | 931 | 983 | 883 | 984 | 1,008 | 919 | 952 | 811 |
| `mail:digest:unified --mode=immediate` (8 shards x 60) | 758 | 833 | 851 | 879 | 870 | 852 | 880 | 893 | 859 | 862 | 830 |
| `schedule:finish` (one per background job, ~0.31 s each) | 739 | 855 | 718 | 722 | 711 | 720 | 734 | 727 | 721 | 724 | 716 |
| `mail:digest:unified --mode=reach` (4 shards x 60) | 464 | 489 | 599 | 573 | 525 | 679 | 702 | 659 | 591 | 489 | 468 |
| `mail:chat:user2mod` | 158 | 236 | 327 | 499 | 531 | 505 | 415 | 286 | 273 | 302 | 311 |
| `node resources/js/embed.mjs` (from `embeddings:generate`) | 344 | 425 | 390 | 403 | 324 | 340 | 335 | 385 | 366 | 263 | 167 |
| `claude -p` (from `community-news:research`, every call a 429) | 222 | 220 | 215 | 220 | 247 | 228 | 228 | 230 | 228 | 240 | 235 |
| `mail:digest:unified --mode=daily` (30-min re-scans in the window) | 495 | 492 | - | - | - | - | - | - | - | - | - |
| `push:daily-posts` (one process, 06:30-09:50) | 2,834 lifetime | - | - | - | - | - | - | - | - | - | - |
| `integrations:sync-whatjobs` (one process per run) | 768 | - | - | 933 | - | - | 43 (feeds unchanged) | - | - | 43 (unchanged) | - |
| the other ~30 every-minute and hourly commands together | ~450 | ~500 | ~500 | ~500 | ~500 | ~500 | ~500 | ~500 | ~500 | ~500 | ~500 |
| the four spool daemons + scheduler + receiver (not exits; from cputimes) | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 | ~250 |

Immediate digest: 11,259 / 12,573 / 12,691 / 12,719 / 12,618 / 13,167 iterations an hour for
3,375 / 2,904 / 4,045 / 5,057 / 4,229 / 3,370 / 4,078 / 3,990 / 3,053 / 1,848 / 1,559 emails (hours 09-19; by the evening 13,300 iterations an hour carry 1,500 emails). Reach digest:
5,585 / 7,508 / 4,318 / 6,482 / 8,625 / 6,475 / 4,630 / 3,306 / 3,598 emails in hours 11-19 (its per-run summary is not logged).

### 2.2 The routing container (freegledocker-spatial, iznik-routing-go)

Its own `net/http/pprof` on 127.0.0.1:6060 (appendix A), 30-second CPU profiles:

| | 09:24 (push job + daily re-scans running) | 12:04 (steady afternoon) |
|---|---|---|
| CPU in the window | 19.8 s = 0.66 cores | 19.8 s = 0.66 cores |
| `/v1/catchment` | 35% | 7% |
| `/v1/reach-eval` | 28% | small |
| `/v1/drive-metrics` | 18% | 25% |
| `/v1/ripple-schedule` | 12% | 65% |

What the time is, by function:

- `ReachedNodes` (`reach_isochrone.go`) is 31% of the morning profile, 22% of it flat in
  `runtime.mapaccess2_fast32`. The function ends with `for vi := 1; vi < len(e.Ov.Ref); vi++`,
  one iteration per graph node (56,874,451), with a map lookup for each of the ~44M
  chain-interior nodes, on every call, whatever the size of the isochrone. Measured from
  inside the container: a 15-minute catchment takes 1.19 s (172 KB), 30 minutes 2.38 s
  (1.1 MB), 45 minutes 6.28 s (2.5 MB). `ripple:expand` asks for 14-39 of these a minute.
- `handleRippleSchedule` (`ripple.go:583`) calls the flat full-graph `Isochrone()`, not
  `engineOrFlatIsochrone`, which exists precisely to avoid "a full-graph sweep". Measured:
  2.8 s for a Manchester origin, 0.6 s for Cambridge, at `polygons=0`. `ripple:expand`
  initialised 683 posts by 12:05 today, about 10 schedules a minute in the afternoon, which
  is why it is 65% of the steady-state profile.
- `QueryLabelsFromNode` (a Dijkstra from the member's snap node) is behind `drive-metrics`.
  `QueryLabelsCached` keys on (snap node, minutes) with a 256-entry LRU; today's daily digest
  went to 67,455 members at 51,882 distinct locations, iterated by member id, so the cache
  cannot hit and every call is a fresh Dijkstra. 67% of every byte the process has allocated
  since 5 September came from here (allocation profile).
- `evalLoad` (17% of the morning profile) reads label blobs for candidate posts from MySQL,
  JSON-decodes them and evicts an LRU; `ArrivalAtBaseNode` itself, the actual verdict, is 7%.
- GC: `GOGC=50`, `GOMEMLIMIT=9GiB`, heap 4.9 GB, about one cycle a minute, `scanstack` 6% of
  samples.

### 2.3 The KNN container (freegledocker-spatial-knn, iznik-spatial-go)

`perf record -p`, 30 s at 09:32 (with `--symfs`, see appendix): garbage collection 19% of
samples, `modernc.org/sqlite` (the pure-Go SQLite behind the R-tree) about 10%, socket reads
3.5%. No `GOGC` set. It runs at 0.08-0.10 cores when nothing is iterating members, and 0.55-0.89
cores while the daily digest re-scans and the push job call `/v1/reach/containing` and
`/v1/reachoverflow/containing` once each per member: derived, about 0.05 CPU-seconds per call.
`/v1/jobs/knn` is called once per email by every digest and the chat mailer (the jobs block).

### 2.4 What the batch asks the two Go services, per minute

One minute of request lines captured at the socket (`reqtrace.bt`):

| request | 09:26 (daily re-scan + push) | 12:02 (steady) | from |
|---|---|---|---|
| `POST /v1/reach-eval` | 924 | 16 | daily 806, push 95, release-replies 22 / 16 |
| `GET /v1/reach/containing` + `reachoverflow/containing` | 901 + 902 | 5 | daily 806 each, push 95 each |
| `POST /v1/drive-metrics` | 350 | 687 | reach digest 285 / 602, immediate 21 / 85, push 40 |
| `POST /v1/reach-arrival` | 11 | 286 | reach digest |
| `GET /v1/jobs/knn` | 156 | 121 | reach 125 / 57, immediate 18 / 51, user2user 9 / 13 |
| `GET /v1/catchment` | 14 | 39 | ripple:expand |
| `GET /v1/ripple-schedule` | 5 | 10 | ripple:expand |
| `GET /v1/postcodes/knn` | 54 | 51 | tn:sync |
| vectorize / rasterize / reach-labels / admits | ~30 | ~120 | ripple:expand, reach digest |

The reach digest's 602 drive-metrics calls a minute against ~100 emails a minute means about
six members are evaluated (a Dijkstra each, `filterByDistancePreference` ->
`DriveMinutesService::prefetch`) for every email that is sent.

### 2.5 Connections and DNS

Every `connect()` on the host for three minutes, 09:10-09:13 (`conntrace.bt`):

| what | count | why |
|---|---|---|
| batch php -> 127.0.0.11:53 (Docker's DNS) | 11,884 | one lookup per HTTP request: `Http::` builds a new Guzzle client, so a new curl handle, per call; no DNS cache, no keep-alive |
| dockerd -> systemd-resolved -> 8.8.8.8 | 11,895 / 11,684 | the geocoder lookups below; resolved's cache has 3.8M hits against 10.7M misses since boot |
| batch php -> the host's own public address:443 | 5,560 | `integrations:sync-whatjobs` geocoding via `https://geocode.ilovefreegle.org`, which is this host's public address: TLS to itself, through the host nginx (which carries a rate-limit exemption comment for exactly this), 127.0.0.1:8198, docker-proxy, then the KNN container |
| batch php -> spatial-knn:8194 | 3,921 | daily 4,938 + push 554 + reach 175 per command split |
| batch php -> routing:8194 | 2,383 | daily 2,469, reach 566, push 421, ripple 69 |
| batch php -> MySQL | 167 | one pool per process; fine |

Each is a connect through the bridge and nftables, a conntrack entry, and for the geocoder an
OpenSSL handshake on the PHP side and a Go one on the nginx side. Kernel time is a third of all
PHP CPU: 15% of host samples in-kernel under `php` at 09:01, split memory 4.6% (process
birth and teardown), file 3.0%, network 2.8%, scheduling 1.4%.

`batch-prod` has no `FREEGLE_GEOCODER_URL`, so `config('freegle.geocoder')` falls to the
public default. The same API is served on `http://spatial-knn:8194` inside the network.

### 2.6 PHP itself

| measurement | value |
|---|---|
| `artisan list` bootstrap, as shipped this morning (pcov on, no CLI opcache) | 0.23 user + 0.09 sys = 0.32 s |
| same, pcov off | 0.21 + 0.08 = 0.29 s |
| same, pcov off + `opcache.enable_cli=1` + `opcache.file_cache` (warm; 1,086 files, 31 MB) | 0.07 + 0.11 = 0.18 s |
| CPU-bound PHP micro-benchmark, pcov on / off | 0.47 s / 0.31 s |
| `zend_vm_call_opcode_handler` + `php_pcov_execute_ex`, share of all host samples, 09:17 | 5.2% + 1.1% (the two hottest PHP symbols) |
| same at 11:02 after the change | not in the top ten |
| `zendparse` + `lex_scan` (compiling PHP source in every process) | 2-2.4% of host samples all day |
| `schedule:finish` bootstraps | 38-47 a minute at 0.30-0.31 s = 0.20-0.24 cores |
| every-minute job bootstraps (the job's own 0.3 s before it does anything) | about the same again |
| `mjml.so` (mrml) | 0.05-0.14% of host samples |

pcov before/after on the same hour and load, per-run CPU of steady every-minute jobs (exits
09:02-09:18 vs 09:19-09:50): `schedule:finish` 0.320 -> 0.304 s, `mail:welcome:send` 0.356 ->
0.347, `memberships:process` 0.419 -> 0.400, `tn:sync` 0.700 -> 0.630, `chats:process-incoming`
0.669 -> 0.587, `ripple:expand` 0.588 -> 0.590. So 5% on bootstrap-dominated jobs, 10-12% on
jobs that run PHP for a while; the 32% figure applies to VM-bound work such as digest rendering
and chat-mailer hydration, which is where most of the PHP CPU is.

With pcov on, its execute hook ran the VM one opcode at a time; the hybrid dispatch loop cannot
be used when `execute_ex` is overridden. It is compiled into the image because CI's phpunit
uses it for coverage. The entrypoint now writes `pcov.enabled=0` when `APP_ENV=production`.

### 2.7 The digests and the push

- Daily: 8 shards from 06:00:31, complete 07:56-08:00:21; 67,455 members, 66,653 emails,
  51,882 distinct member locations; then every 30 minutes to 12:00 each shard re-scans ~215
  members for 0-13 emails. Per member: one `reach-eval`, two KNN `containing` calls, the
  five-subquery `getPostsForUser` query, two Blade passes, mrml, `drive-metrics` and `jobs/knn`
  per email. The host runs 7.4-7.9 cores in those two hours against ~3 either side: about 9
  core-hours, 0.48 CPU-seconds per email host-wide.
- Immediate: 8 shards every minute, `--max-iterations=60`, one iteration every 2 s. 99.8% of
  iterations send nothing, and each one still queries every group in the shard (~70,
  `getGroupMessagesSinceCursor`, plus `advanceCursorPastExcluded`): 17,000-34,000 queries a
  minute against db2 to find nothing. 0.23-0.25 cores of PHP.
- Reach: 4 shards, same loop; 4,300-7,500 emails an hour at 0.107 CPU-seconds each in PHP,
  plus the six Dijkstras per email on the routing side (2.4).
- `push:daily-posts`: 06:30 to 09:50, 29,676 members processed, 18,565 pushes, 10,096 with
  no posts, 999 failed on disabled APNs tokens; 2,834 CPU-seconds in PHP and about one
  `reach-eval` + two `containing` calls per member. It calls the same `getPostsForUser` the
  email digest just ran for the same members.

### 2.8 Jobs doing wasted work

- **`community-news:research`**, hourly at :30, 239 areas, ~435 `claude -p` spawns per run
  (872 by 09:40, 1,885 "claude CLI failed" log lines by then). Every call returns
  `api_error_status 429, "You've hit your weekly limit, resets Sep 22 5pm UTC"` in 0.4 s,
  after about one CPU-second of Node start-up. No breaker; 215-247 CPU-seconds an hour, and 13%
  of all host samples (1.6 cores) for the five minutes it runs. The memory note from 8 August
  describes this exact signature. The subscription token is shared with `ai-support-helper`.
- **`embeddings:generate`**, every five minutes: shells out to `node resources/js/embed.mjs`,
  which loads the nomic ONNX model from cold: 17-20 CPU-seconds per spawn, 2-3 spawns per run,
  for 23-32 messages. 0.09-0.12 cores. The embedding sidecar has the same model resident and
  answers `/embed` in 40-90 ms; `ContentEmbeddingService` already uses it.
- **`integrations:sync-whatjobs`**, 09/12/15/18/21 UTC: 768 and 933 CPU-seconds for the two
  runs traced (26 minutes at ~0.5 cores each), 1.5M jobs parsed, geocoding as in 2.5 (7,853
  resolver threads in four minutes).

### 2.9 Small things

Docker health checks: 76 `runc` execs a minute (redis and tusd every 5 s, wiki-mysql, delivery,
Loki, rspamd every 10 s), 0.01-0.02 cores. `stty -a` on every artisan boot (Symfony's terminal
size probe), ~94 a minute, negligible CPU, avoidable with `COLUMNS`/`LINES` in the container
env. Log volume: 215k lines in the 06:00 hour, 200 MB by 09:14, ~500 MB a day; the spooler
logs 26 MB each. The delivery container (libvips) 0.13-0.17 cores; the wiki trio 0.15; nothing
to win there.

## 3. Quick wins

Each is a few lines, each has a number from today.

### 3.1 pcov off in production. Done.

PR #1583, live in the container from 09:18, baked into the image and recreated at 13:02.
Expected 0.2-0.3 cores off the batch container's ~1.5-2.

### 3.2 A breaker in `community-news:research`

`researchViaCli` already parses the CLI's JSON; when `is_error` is true with
`api_error_status` 429 (or the result text says weekly limit), stop the run and skip the
remaining areas, and skip the whole run while the resets-at time is in the future. Saves
0.06 cores average and the hourly 1.6-core spike, and ~10,000 wasted Node start-ups a day
until Tuesday. The 8 August memory note asked for the same thing.

### 3.3 `embeddings:generate` through the sidecar

Bind `EmbedderContract` to a sidecar-backed embedder when `EMBEDDING_SIDECAR_URL` is set
(the batch already has `fetchEmbeddings`); keep `NodeEmbedder` for the fallback. 0.1 cores,
and the 10% of host samples that is `libonnxruntime` whenever the job runs. Check the two
produce the same vectors (same model, fp32; the sidecar's `fp` in its log makes that a
one-line comparison).

### 3.4 WhatJobs geocoding over the internal URL

`FREEGLE_GEOCODER_URL=http://spatial-knn:8194` in `batch-prod`'s environment. Removes
5,500 TLS handshakes and 11,000 upstream DNS queries per three minutes of the run, the host
nginx and docker-proxy hops, and the rate-limit exemption they needed. Probably a third of the
run's 26 minutes; the rest is parsing 1.5M jobs (a Go port is the bigger lever, section 4.8).

### 3.5 CLI opcache with a file cache

`opcache.enable_cli=1`, `opcache.file_cache=/tmp/opcache` (tmpfs, cleared on start),
`opcache.file_cache_only=1`, `validate_timestamps` on. Measured 0.32 -> 0.18 s per bootstrap
today; at ~90 bootstraps a minute that is 0.2 cores, and the 2% of host samples spent in
`zendparse`/`lex_scan` inside long-running jobs goes with it. Do not enable it with pcov on:
the two together produced a 2.4 s bootstrap in testing (the cache was rewritten every run).

### 3.6 `ripple-schedule` through the engine isochrone

`handleRippleSchedule` calls `Isochrone(g, ...)`; `engineOrFlatIsochrone` gives the same
reached-nodes contract from the reach engine. Halves the 2.8 s Manchester call today, and
becomes tens of milliseconds once 4.2 lands. ~0.2 cores of the routing container.

### 3.7 GC settings on the two Go services

Routing: `GOGC=50` with a `GOMEMLIMIT` already set; `GOGC=off` and let the memory limit drive
collection. KNN: nothing set; give it a limit and `GOGC=200-400`. A few percent of each.

## 4. Radical options

### 4.1 Invert the digest: do the work per post, not per member

Today's per-member pipeline is 4-6 HTTP calls, one heavy SQL query, two Blade passes and an
MJML compile, repeated 67,000 times a morning and again 30,000 times for the push. Almost all
of it is the same for every member who sees the same post.

- **Cards rendered once per post.** Render each post's card HTML once (when it is approved, or
  the first time a digest needs it) and store it. The MJML layout is compiled once into an
  HTML shell with slots; cards are HTML fragments in the markup mrml emits for one card. Per
  member, the digest is: pick cards, substitute the per-member tokens (distance text, tracked
  link signatures), concatenate. This is the operator's fragment-cache idea with the cache at
  the Blade level, where the cost is; mrml itself is already cheap.
- **Selection from the post's side.** `reach-eval` is already the cheap direction (a stored
  per-post label evaluated at the member's node, ~7% of routing time). The expensive parts are
  the two KNN `containing` calls that build the candidate set and the per-member Dijkstra for
  road miles (4.3). A per-day candidate index (post id -> reach leaves) held in the digest
  process, or one routing call that returns candidates for a member's node, replaces both KNN
  calls.
- **One selection, two channels.** Store the selected post ids per member when the email is
  built; `push:daily-posts` reads them (4.7).

Expected: the 06:00-08:00 block from ~9 core-hours to ~2, the push job from 3h20m of
0.24 cores plus routing/KNN load to minutes, and the reach/immediate digests' per-email
render (~3 core-hours a day) mostly gone. About 0.5 cores average and 4-5 cores off the
morning peak, which is the single biggest item in this document. It is also the biggest
change: a render cache with invalidation, and a CI render-diff to prove the emails are
byte-identical apart from the per-member tokens.

### 4.2 Make catchment O(reached), not O(graph)

`ReachedNodes` reconstructs chain-interior arrivals by scanning every node in the graph and
asking whether its chain end was reached. Invert it: for each reached junction, walk its
chains outward (the graph has `EdgesFrom`; `refineOriginChain` already walks a chain this
way), or build a one-time junction -> interior-nodes index at load (~44M uint32, ~176 MB,
against a 16 GB container limit). Either way the pass becomes proportional to the isochrone.
Today's 1.2-6.3 s catchments become tens of milliseconds; `ripple:expand`'s 14-39 catchments a
minute and every schedule (after 3.6) stop costing a second each. ~0.3 cores of the routing
container, and the routing part of the morning peak.

### 4.3 Drive metrics from the post's stored label, not a Dijkstra per member

`drive-metrics` runs a labelling query from the member's node to answer "how far is each of
these posts by road". The reach engine already holds a label per post that answers the same
question in the other direction: `ArrivalAtBaseNode(label, memberNode)` is what `reach-eval`
uses, in microseconds. For digests the posts are exactly the ones with labels. Serve
`drive-metrics` from the labels when the targets are posts: minutes come for free (the reach
digest's `reach-arrival` call already returns them). Miles do not: decoded labels carry no
metres (`EntryMet` is "live queries only"), so either extend the blob with metres (a few bytes
per entry, one rebuild) or show minutes on the card. The reach digest's 602 Dijkstras a minute
and the daily digest's 350 become lookups: ~0.2 cores of the routing container, and 67% of
its allocation rate, so most of its GC.

### 4.4 One long-lived batch worker instead of 90 process births a minute

Every every-minute command is "bootstrap, look for work, exit" plus a `schedule:finish`
bootstrap to release the mutex. Run them in one supervisor-managed daemon that ticks each
command's `handle()` in-process on its cadence: no bootstrap, no `schedule:finish`, no
`stty -a`, no fresh MySQL and Redis connections per tick, and the per-process opcode cache
becomes permanent. `mail:spool:process --daemon` is already this shape. Needs the drain
check (`BackupDrain`) in the loop, memory hygiene (restart the daemon hourly or on RSS), and
the cron-status writer moved into the tick. ~0.45 cores of PHP plus the kernel's share of
process birth and teardown (memory management was the largest in-kernel bucket for PHP).
Supersedes 3.5 for the commands it covers.

### 4.5 A shared keep-alive HTTP client

Laravel 12's `Http::` builds a new Guzzle client, and so a new curl handle, for every call.
Register one `HandlerStack` over one `CurlMultiHandler` per process (`Http::globalOptions`
and `PendingRequest::setHandler` are the supported hooks; the routing and KNN clients in
`ReachService`/`DriveMinutesService`/`CellSetService` can also simply hold one Guzzle client).
Removes one DNS lookup, one TCP handshake and one conntrack entry per request: ~40-70
connects a second at peak, and with 3.4 the TLS handshakes. Expected 0.2-0.3 cores across
php-in-kernel, dockerd's DNS and the resolver.

### 4.6 Event-driven immediate and reach digests

Twelve shards, 30 iterations a minute each, 99.8% idle, each iteration a query per group.
Replace the loop with a watermark: one cheap query per shard per tick ("any approved message
or reach advance since my last tick?"), or a Redis signal set by the approval path in the Go
API, and only then run the group scan. The 17,000-34,000 idle queries a minute off db2, and
~0.35 cores of PHP (immediate + reach loops) down to what the sends themselves cost.

### 4.7 The push job reuses the email's selection

Covered by 4.1's third bullet, but it stands alone: when the daily digest builds a member's
email, write the selected post ids (and the cursor) to `users_digests`; `push:daily-posts`
sends those. 2,834 CPU-seconds and ~90,000 routing/KNN calls a day for nothing new.

### 4.8 WhatJobs in Go

The sync parses a 1.5M-job feed in PHP five times a day at 0.5 cores for 26 minutes. The
Go API already owns the jobs data model and the KNN owns the jobs index. A Go sync that
streams the feed, geocodes against the KNN in-process and writes in batches would be a
minute, not 26. Lower priority than the above because it is 0.05 cores average; listed
because it is the last big block after the morning.

## 5. What it adds up to

Average host CPU in the traced hours (09:00-20:00) was 2.9 cores, and 2.6 outside the push job; with the 06:00-08:00 block the day averages about 3.2. Rough
expected savings, cores average (and the morning peak), each measured or derived above:

| item | average | morning peak |
|---|---|---|
| 3.1 pcov (done) | 0.2-0.3 | 0.5-1 |
| 3.2 community-news breaker | 0.06 (+1.6-core spikes gone) | |
| 3.3 embeddings via sidecar | 0.1 | |
| 3.4 geocoder internal URL | 0.03-0.05 | |
| 3.5 CLI opcache | 0.3 | 0.5 |
| 3.6 + 4.2 catchment and schedule O(reached) | 0.35-0.45 | 0.5-1 |
| 4.3 drive metrics from labels | 0.2 | 0.5 |
| 4.1 + 4.7 invert the digest, push reuses it | 0.5 | 4-5 |
| 4.4 one worker (supersedes 3.5's 0.3) | 0.45 | 0.5 |
| 4.5 keep-alive client | 0.2-0.3 | 0.5 |
| 4.6 event-driven digests | 0.3 | |
| chat mailers (previous plan 3.1) | 0.2-0.3 | |

The quick wins (section 3) are about 1 core. Sections 3 and 4 together take the average from
~3.2 to ~1.3-1.5 cores and the 06:00-08:00 block from 8 cores to about 3, which is what
makes an 8-vCPU package fit on CPU (RAM is the other constraint; see the hosting-cost plan).

## 6. Suggested order

1. 3.2 breaker, 3.3 sidecar, 3.4 geocoder URL: three small PRs, a day, ~0.2 cores and the
   silliest waste gone.
2. 3.5 CLI opcache (two ini lines; measure with `checkpoint.sh` at the same hour).
3. 3.6 and 4.2 in the routing server, then 4.3. These are the routing container's whole
   problem and they are contained in three functions.
4. 4.5 shared client and 4.6 watermark digests: batch-side, moderate, independent of each
   other.
5. 4.7, then 4.1 in stages (selection first, then the render cache), each with a render diff.
6. 4.4 once the every-minute commands are stable enough to share a process.

Measure every step with the same instruments (appendix A) at the same hour on more than one
day; the hour tables above are the baseline.

## Appendix A: the instruments

All in `plans/2026-09-20-docker-host-cpu-radical-reduction/`. bpftrace was installed today
(`apt install bpftrace`, Ubuntu package, no service).

- `exectrace.bt`: `bpftrace exectrace.bt > exectrace.log`. Every exec (argv, parent, cgroup)
  and every exit (utime, stime, wall) on the host. ~30 MB an hour.
- `cgsample.sh`: per-minute `cpu.stat` for every container and slice.
- `attr2.awk`: `awk -v from=<ms> -v to=<ms> -v pre=<ps snapshot> -f attr2.awk cgroup-ids.txt
  exectrace.log`. Timestamps are milliseconds since boot (`boot-epoch.txt`). Attributes exited
  processes' CPU to `artisan <command>`, wrappers, `schedule:finish`, node, claude etc., per
  container. Processes alive before the tracer started need a `ps -e -o pid,ppid,cputimes,
  etimes,args` snapshot to be named and to have their pre-snapshot CPU subtracted.
- `cgreport.awk`, `checkpoint.sh HH`: the hourly report used above.
- `conntrace.bt`: every `connect()` with pid, comm, cgroup, destination. `reqtrace.bt`: HTTP
  request lines the batch container sends (first 72 bytes of `sendto`/`write` buffers).
- The routing server's pprof: `docker exec freegledocker-spatial curl -s
  'http://127.0.0.1:6060/debug/pprof/profile?seconds=30' -o /tmp/cpu.pprof`, then
  `go tool pprof -text` on db1 (no Go toolchain on this host; `docker run golang:` is blocked
  by the repo hook). `/debug/memsummary` for GC counters.
- `perf record -a -g -F 99`: for the container binaries, report with
  `--symfs=/proc/<container pid>/root`, or the Go frames are attributed to the wrong binary
  and look plausible (the first routing profile showed TLS handshakes in a plain-HTTP server).

## Appendix B: per-command split of the three-minute connection trace

routing (172.20.0.15:8194): daily 2,469, reach 566, push 421, release-replies 42,
ripple:expand 27. KNN (172.20.0.3:8194): daily 4,938, push 554, docker-proxy (the public
geocoder path) 261, reach 175, tn:sync 146, user2user 29, expand 7, user2mod 7.
