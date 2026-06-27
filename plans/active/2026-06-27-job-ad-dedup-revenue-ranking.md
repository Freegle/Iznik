# Job-ad selection optimisation — bodyhash dedupe + revenue-weighted variety

Branch: `master` (per user — committing directly to master, no PR branch)
Date: 2026-06-27

## Why
Analysis of `email_tracking_clicks` (9,179 attributed job-ad clicks since 2026-05-28):
- **94% of clicks >80km from the user are on nationwide bodyhash-duplicate jobs** (Bike Courier / Delivery Driver). The spatial-knn dedup keys on (company,title), but these dups carry *differing* company/title with the *same body*, so they slip the dedup and far copies get served.
- Clicked jobs avg **cpc 0.233 vs pool 0.293** while clickability of clicked (1.50) >> pool (0.92): the `cpc*clickability*freshness` ranking in `Job::nearLocation` is computed **then discarded by a uniform `shuffle()`** before `take($limit)` — so the served 4 are a uniform random sample of the *proximity* pool, not revenue-weighted.
- `housekeeper.go:306` advertises `cleanup:whatjobs-spam` (Active, every 10m) but the Laravel command does not exist and nothing runs it — a phantom in the MT sysadmin console.

Decision: do NOT port V1's bodyhash>50 *deletion* — those nationwide roles are the #1 click/revenue driver; they must be **deduped to the nearest copy**, not deleted.

## Status
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Branch + plan | ✅ | this file |
| 2 | Lever 1: bodyhash dedup in spatial-knn (`dataset_jobs.go` Extra + `jobsDedupKey`) + test | ⬜ | iznik-spatial-go |
| 3 | Lever 2: replace uniform `shuffle()` with score-weighted variety in `Job::nearLocation` + test | ⬜ | iznik-batch |
| 4 | Housekeeper: remove dead `cleanup:whatjobs-spam` entry (`housekeeper.go:306`) | ⬜ | iznik-server-go; status restart on deploy |
| 5 | Quality review + PR | ⬜ | |

## Deploy (separate step — NOT part of this PR)
- spatial-knn change → rebuild BOTH targets: native db1/2/3 (web path) AND local `freegledocker-spatial-knn` container (batch digest path).
- housekeeper change → restart `freegledocker-status`.
- No DB migration; bodyhash column already exists.

## Notes
- Lever 2 ranking already existed; the fix is making the variety step preserve it (weighted, not uniform).
- Existing tests `test_near_location_varies_picks_within_pool` / `_orders_by_cpc_desc` / `_prefers_fresher` must stay green.
