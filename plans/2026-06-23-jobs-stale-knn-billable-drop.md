# Fix: billable job-click drop from 2026-06-16 (stale KNN jobs index)

## Diagnosis

Billable job clicks (WhatJobs partner dashboard) stepped down ~37% on 2026-06-16,
while our own click log (`logs_jobs`) barely moved (-18%, no step) and total email
clicks were steady-to-rising. So clicks kept happening but stopped *converting* to
billable — i.e. we served stale/wrong jobs (matches user reports of clicking a job
and being redirected to a different one = a closed posting).

Root cause: PR #764 (deployed to master 2026-06-16 08:26) moved job selection (web
and digest) from a direct MySQL query on the `jobs` table to the **spatial KNN
`jobs` index** (a separate snapshot). The WhatJobs sync replaces `jobs` by RENAME
swap (`jobs_new` -> `jobs`), but nothing rebuilds the KNN index on swap. The index
only fully rebuilds nightly at 03:00 UTC; the 5-min delta adds/updates rows
(`seenat > since`) but never removes IDs that vanished in the swap. So between syncs
the KNN index returns IDs that are gone or now map to different postings.

Compounding: the morning daily digest fires 07:00 UK, but the first WhatJobs sync of
the day is 09:00 UTC (`cron('0 */3 * * *')->between('08:00','22:00')`), so the digest
ships jobs last synced ~21:00 the previous night (9-10h stale).

Ruled out: unified digest / email volume (email + job-email clicks steady/up); CPC
floor change (zero jobs sit in the excluded 0.02-0.10 band).

## Fixes (all approved)

### A. Rebuild the KNN `jobs` index on swap  (the regression fix)
- `SpatialAdminService::rebuildDataset(string $dataset)` -> POST `/v1/{dataset}/rebuild`
  (async on the server; non-throwing + logged, mirroring `removeItems`).
- Call `rebuildDataset('jobs')` in `WhatJobsService::sync()` immediately after the
  swap + clickability update succeed (real runs only, not when swap is skipped).

### B. Sync (and therefore rebuild) before the 07:00 UK digest
- Add a dedicated `integrations:sync-whatjobs` run at **05:00 UK** (Europe/London),
  `withoutOverlapping(240)`, sharing the command mutex with the existing every-3h
  UTC schedule. 2h margin before the 07:00 digest; the post-swap rebuild (A) makes
  the fresh jobs visible to the KNN-backed digest path.

### C. Weight ranking by `posted_at` freshness (secondary guard)
- Older WhatJobs postings are likelier already filled/closed. Add a mild freshness
  multiplier to the ranking score in both ranking sites so the stale tail is
  de-prioritised without nuking high-CPC ads:
  `cpc * clickability * GREATEST(<floor>, 1 - days_old * <decay>)`.
  - PHP: `Job::nearLocation` ORDER BY (`iznik-batch/app/Models/Job.php`).
  - Go: `JobsForIDs` ORDER BY (`iznik-server-go/job/job.go`).
  - Keep web + digest ordering identical (they intentionally match today).

## Validation
- iznik-batch: PHPUnit (WhatJobsService swap triggers rebuild; ordering).
- iznik-server-go: Go job tests (ordering with freshness factor).
- Full relevant suites green before PR. Update the orb if test changes need it.
