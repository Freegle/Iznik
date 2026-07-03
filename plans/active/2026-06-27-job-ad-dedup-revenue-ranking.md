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
| 1 | Branch + plan | ✅ | committed master 2a41855f9 |
| 2 | Lever 1: bodyhash dedup in spatial-knn (`dataset_jobs.go` Extra + `jobsDedupKey`) + test | ✅ | NB loadJobs Extra map (full-rebuild path) needed the bodyhash key too — caught in verify |
| 3 | Lever 2: replace uniform `shuffle()` with score-weighted variety in `Job::nearLocation` + test | ✅ | live in batch-prod via bind mount |
| 4 | Housekeeper: remove dead `cleanup:whatjobs-spam` entry (`housekeeper.go:306`) | ✅ | needs apiv2 rebuild + status restart to show |
| 5 | Local digest deploy + verify | ✅ | spatial-knn container rebuilt, jobs index rebuilt with bodyhash, 50/50 distinct bodyhash, digests relaunched on new code |
| 6 | Push to origin/master + CI | ✅ | build-and-test=success (06f28689e); Coveralls upload failures ignored per user. CI infra (registry-cache 500 / apiv1 composer 400) fixed by user. |
| 7 | Website deploy: native iznik-spatial-go on db1/2/3 (lever 1 for /api/job) | ✅ | db1/2/3 rebuilt + jobs index rebuilt with bodyhash; all 30/30 distinct, 0 repeats; /api/job 47→46 distinct. Gotchas: must `go build -o iznik-spatial-go .`; rm `.building` variants; db3 hit the monit-restart-race partial .building → SQLite 522 → fixed via unmonitor/kill/clean/manual-start/monitor. See reference_prod_deploy_procedure. |
| 8 | (optional) apiv2 rebuild on db1/2/3 for housekeeper console entry | ⬜ | cosmetic (MT console mirror); not data-affecting |

## Deploy (separate step — NOT part of this PR)
- spatial-knn change → rebuild BOTH targets: native db1/2/3 (web path) AND local `freegledocker-spatial-knn` container (batch digest path).
- housekeeper change → restart `freegledocker-status`.
- No DB migration; bodyhash column already exists.

## Notes
- Lever 2 ranking already existed; the fix is making the variety step preserve it (weighted, not uniform).
- Existing tests `test_near_location_varies_picks_within_pool` / `_orders_by_cpc_desc` / `_prefers_fresher` must stay green.
