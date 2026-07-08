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
| 3b | Verify endpoint end-to-end (timing/shape) | 🔄 | direct-container call running the routing sample; metrics endpoint 200 |
| 7 | Vue: Section 1 (density selector, intro, pies/bignum) | 🔄 | fetchAnalytics added to RipplingAPI.js; component next |
| 4 | Go: Section 2 trend buckets | ⬜ | day/week series |
| 5 | Go: Section 3 rippled-out (server-derived + client cross-check) | ⬜ | fetchDriveSample already tags rippled per reply |
| 8 | Go + vitest tests | ⬜ | |
| 9 | Deploy + verify browser | ⬜ | modtools-dev-live sync |

### KEY RISK under test: traefik reset (000) on the slow analytics call via apiv2-live.localhost, though metrics (fast) = 200 and the DIRECT in-container call runs. If traefik times out the ~15-30s analytics request, need a traefik timeout bump OR make the endpoint faster (smaller sample / parallel SQL). Browser calls go via traefik, so this must be solved.

## Notes
- Read-only prod convention: endpoint must not write. Sampling is read-only.
- Coverage: this is exploratory sysadmin analytics; keep the routing helper thin + unit-test the pure bits (band/stratum classification, mean calc). Full routing not unit-tested (external).
