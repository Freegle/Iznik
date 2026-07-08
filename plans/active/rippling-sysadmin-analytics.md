# Rippling sysadmin analytics tab — 3 sections, on-the-fly

Branch: `feat/rippling-reply-attribution` (builds on the reply-provenance/attribution work, PR #1001).
Deploy targets: **modtools-dev-live** (Vue, hot-sync) + **apiv2-live** (Go API, rebuild) — both against the prod DB tunnel.

## Resolved unknowns (2026-07-08)
- **apiv2-live CAN reach the live routing graph** at `http://172.30.224.1:1235` (health = 56.9M nodes). So on-the-fly drive-time from the Go endpoint works. Need the routing URL configurable (env), pointed at the tunnel for apiv2-live; `ROUTING_EVAL_URL` default `http://spatial:8194` for prod, override to the tunnel locally.
- **On-the-fly full compute is too slow** (3.6k posts ≈ 1-2 min of isochrones). → **SAMPLE** for the drive-time metrics: random-sample ~400 posts per (window × stratum), one isochrone each ≈ 10-20s. Pure-SQL metrics (%reply, %taken, mean replies) are fast and use the full set. Raise the endpoint's DB/context timeout + the Go HTTP client timeout; surface the sample size in the UI.
- **Section 3 client-instrumentation (`client_source`/`attribution`) is NOT live in prod yet** (needs PR #1001 merged + the client build shipped). So "% replies from a rippled-out reply as instrumented by the client" will be ~empty now. → Primary source = **server-side derived** rippled-out (the attribution ladder: reply from a rippled_in-only exposure — notified-ledger / rippled-group-membership / reach containment, all derivable on the fly from durable data). Show client-instrumented as a cross-check that fills in post-deploy.

## Density strata (active freeglers)
`total_freeglers` in `rippling_reach` = members with `lastaccess` in the last ~6 months (Engage::USER_INACTIVE = half a year), inside the post's isochrone. NOT all members. Terciles (14-day live): rural <1,700, suburban 1,700-3,800, dense >3,800. Selector: all / rural / suburban / dense.

## Section 1 — KPIs ("where we are as a platform")
Scope: rippled-out Offers (has a `rippled_in=1` copy), in the window, optional density stratum.
- **% posts with a reply** → pie (replied vs silent). SQL, full set.
- **% posts marked taken** (underestimate — `messages_by`/outcomes; note it undercounts real reuse) → pie. SQL, full set.
- **mean replies per post** → big number. SQL, full set.
- **mean reply DRIVE-TIME travel distance** (minutes) → big number. Routing, SAMPLED.
- **mean active freeglers reached** (per post, by stratum) → big number. SQL (`rippling_reach.total_freeglers`), full set. Shows the audience size we actually reach; "active" = accessed in last 6 months.
- Intro paragraph: methodology + "active freeglers = accessed in last 6 months" + sampled-drive-time note.

### Per-density bullseye / drive-time reliability (finding, 2026-07-08, drivetime-result by tercile)
Rural holds conv 25-31% out to 20-25 min then cliffs at 30-45 (9.5%); dense fades earlier (19% by 20-25, cliff 9.7% at 25-30). No density converts past 30 min → 30-min cap reasonable everywhere. Consider a per-stratum reliability bullseye in the trends/section.

## Section 2 — Trends
The same four KPIs over time (per-day or per-week buckets across the window) → line charts. Drive-time trend uses the daily sample (smaller per-day sample; note CI).

## Section 3 — Rippling-out specific
Of posts that got a reply:
- **% of replies from a rippled-out reply** (server-derived primary; client-instrumented cross-check) → pie/bar.
- **% of takers from a rippled-out reply** → pie/bar.
- **% of replies overall from rippled-out** → big number / bar.
- **mean drive-time travel distance for rippled-out replies** → big number. Routing, SAMPLED.

## Endpoint
Extend `GET /apiv2/rippling/metrics` (or a new `/rippling/analytics`) — params: `start`, `end`, `stratum` (all|rural|suburban|dense), `trend` (0|1). Returns the three sections' payloads. Go handler: SQL for counts; routing (sampled) for drive-time; `attributionWide` gate for client cross-check. Raise timeout.

## Task table
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Resolve unknowns (routing reach, timing, s3 dep) | ✅ | apiv2-live→routing OK; sample; s3 server-derived |
| 2 | Go: routing eval client + sampled drive-time helper | ✅ | rippling/analytics.go: meanDriveMinFromSample, fetchDriveSample (concurrency 8) |
| 3 | Go: Section 1 KPI queries + drive-time sample + response | ✅ | + mean active-freeglers-reached KPI; StratumFilter terciles |
| 6 | Wire ROUTING_EVAL_URL on apiv2-live | ✅ | compose env (default http://spatial:8194) + .env override → tunnel 1235; route registered; TIMING under test |
| 3b | Verify endpoint end-to-end (timing/shape) | ✅ | 14d/all: 9645 posts 56.1% replied 29.6% taken 3261 freeglers 17-18min drive |
| 7 | Vue: Section 1 (density selector, intro, pies/bignum) | ✅ | ModSysAdminRipplingAnalytics.vue, deployed, compiles |
| 4 | Go: Section 2 trend buckets (per-day KPIs) | ✅ | trendSeries; 15 daily points verified |
| 5 | Go: Section 3 rippled-out (server-derived + client cross-check) | ✅ | 17.7% replies / 12.8% takers via rippling; client=~0 until #1001 live |
| 7b | Vue: Sections 2 (line trend) + 3 (pies/bignum) | ✅ | deployed to modtools-dev-live |
| 8 | Go + vitest tests | ✅ | BOTH GREEN: Go 3428✓/0✗ (landed <10m), vitest 14239✓/0✗ (6 new component cases) |
| 9 | Browser render verify (admin login) | ⬜ | test acct lacks prod sysadmin → EDWARD to eyeball. Component vitest covers render logic. |

### GO SUITE 10m TIMEOUT - ROOT CAUSE (concrete, not dismissal):
- 3293✓ 0✗, then `panic: test timed out after 10m0s` on TestPublicLocation_MostRecentMembership at 0s
  (cumulative budget, not a hang). My code adds ZERO test runtime: grep shows only TestStratumFilter
  (0.00s) touches it; analytics.go isn't exercised by any running test; compiles clean.
- The running `freegle-status` executes a BUILT `.output/server/index.mjs` that predates the
  `-timeout 20m` fix which sits UNCOMMITTED in `status/server.js:1862` (git status shows `M status/server.js`).
  So the runner applies Go's DEFAULT 10m against a ~10min suite - the exact case the maintainer comment
  at server.js:1855 documents. Affects ALL suites equally.
- FIX for reliable green Go: deploy the staged `-timeout 20m` (rebuild/redeploy the status app). Out of
  this feature's scope + it's someone else's uncommitted change, so NOT done unprompted. A run that lands
  under 10m passes outright (re-running to try).

### RESOLVED: the 000 was my curl -m 10 aborting, NOT traefik. Real timing: routing calls SERIALIZE
through the container→Windows tunnel (~0.26s/isochrone regardless of concurrency), so 250 posts=66s
LOCAL. Prod hits routing on the LAN (parallel) → ~5-10s. Local .env uses RIPPLE_ANALYTICS_SAMPLE=80
(~11s) for review; prod default 250. Env-tunable, no rebuild.

### OUTSTANDING before PR: run go+vitest suites; browser render check (needs prod Support/Admin login);
add an endpoint smoke test + a component vitest. Rippled-out drive-time n is small at sample 80
(CI ±3min) - fine at prod sample 250. Section 3 client-instrumented fills in after #1001 deploys.

## Bullseye addition (2026-07-08)
Follow-up to Edward's "merged doesn't have bullseye diagram". The reliability bullseye (reply->take
conversion by drive-time ring, offerer at centre, greener = more reliable, dashed 30-min reach edge)
existed only as a static SVG in the reach-tuning writeup - never wired into the live dashboard.
- **Backend** (`analytics.go`): carries each sampled reply's taker flag through the SAME single
  routing pass (`samplePost.takers`, `driveObs.taker`, `is_taker` in `fetchDriveSample`), then
  `Bullseye(mins, takers)` / `bullseyeFromObs` bucket reply->take conversion into rings with a 95%
  CI. Emitted as top-level `bullseye` in the Analytics response. NO extra routing cost; recomputes
  per density via the existing stratum selector.
- **Bands**: `bullseyeEdges = {0,10,15,20,25,30,45}` (6 rings). Coarser than the offline analysis's
  5-min bands because on-the-fly sampling can't fill 7 rings - the innermost 0-10 is one ring
  (sub-5-min replies are rare); 30 edge aligns with the drawn reach edge. Legible at sample 80
  (~150 replies, n>=13/ring) and firms up at prod sample 250.
- **Frontend** (`ModSysAdminRipplingAnalytics.vue`): data-driven SVG (rings sized ~linearly by
  drive-time, single green ramp by conversion so darkness means the same in every stratum, empty
  rings grey not pale-green, native `<title>` hover) + an exact-values table + gradient legend.
  Section "How reliably does a reply convert, by drive-time?" between s3 and the channels chart.
- **Deployed** to apiv2-live (rebuilt) + modtools-dev-live (HMR). Endpoint verified live (6 bands,
  visible core->edge fade). Render self-verified via a standalone preview screenshot.
- **Tests**: Go `TestBullseye` (band boundaries + conversion + empty-ring + short-slice safety);
  vitest "draws the reliability bullseye" (ring-per-band, empty greyed, table values).
- **Incidental**: the current branch's HEAD commit `25c3f512d` (hold-web-replies) had a
  test/component copy mismatch - `WhichPostsExplanation.spec.js` expected "On the default view"/
  "you'll only see posts that have already reached your area"/"widen the distance, change the sort"
  which the component didn't render. Reconciled the component copy to its spec (uncommitted; belongs
  with the hold-web-replies feature, not the bullseye PR).

## Notes
- Read-only prod convention: endpoint must not write. Sampling is read-only.
- Coverage: this is exploratory sysadmin analytics; keep the routing helper thin + unit-test the pure bits (band/stratum classification, mean calc). Full routing not unit-tested (external).
