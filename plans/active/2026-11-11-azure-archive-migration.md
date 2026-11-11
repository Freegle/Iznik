# Build: move the archived images off Azure inside the free egress

Started 2026-11-11 from `plans/token-burn/azure-archive-migration.md`. Worktree
`FreegleDocker-azure-archive`, branch `feature/azure-archive-migration`.

## Measured before building (2026-11-11, read-only)

- Archived rows: `messages_attachments` 7,261,162 (ids 244,482 to 36,195,572), `chat_images`
  296,578, `communityevents_images` 8,158, `newsfeed_images` 7,572, `noticeboards_images` 54. The
  other five tables with the column have none. 417 message rows also carry an `externaluid`, so
  they are served from the uploads store and their Azure copy is not used.
- A stratified sample of 223 rows: 7% of message rows and 20% of chat rows have no blob (404).
  Mean sizes: `img_` 156 KB, `mimg_` 91 KB. Estimated total about 980 GB. Thumbnails exist for
  every sampled row and add about 450 GB; they are not copied.
- The account is `freegle`, North Europe (`dub` cluster). The `images` container is public read,
  and every blob carries `Content-MD5`. Tier is not visible anonymously; no blob answered as
  Archive tier.
- Production serves archived images straight from Azure: the Go API on db-2 and db-3 has
  `IMAGE_ARCHIVED_DOMAIN=freegle.blob.core.windows.net/images`. So members' views already spend
  part of the free 100 GB.
- Retail prices (prices.azure.com, GBP, North Europe): internet egress 0 for the first 100 GB a
  month, then 0.0654/GB; Hot read operations 0.003 per 10k; Hot LRS storage 0.0159 to 0.0165/GB
  a month (GRS 0.0266, standard v1 GRS 0.0355). Native metric queries: the first million calls a
  month free. Cost Management queries: no charge.
- Lossless JPEG optimisation (`jpegtran -copy all -optimize -progressive`) on 42 sample blobs:
  8.6% smaller, decoded pixels identical in all 42. JPEG XL lossless would save 18% but browsers
  cannot show it.

## Decisions (Edward, 2026-11-11)

- **Pace to the free allowance, never pay for egress.** The copier spends only what the free
  100 GB leaves after members' own views, measured by Azure itself.
- **Monitoring is Azure's own figures.** A read-only app registration (Monitoring Reader on the
  storage account, Cost Management Reader and Billing Reader on the subscription). Without it the
  copier does not run.
- **Thumbnails are made from the full photo** by the delivery resizer, as the legacy
  `/image` resolver already does; the `t*img_` blobs are not copied.
- **Nothing is deleted from Azure until the end.**
- **Destination is the existing images bucket under `archive/`**, same blob names, stored with the
  best lossless JPEG optimisation and the original kept where that is not smaller.

## Decisions taken in the build (with the reason)

- **Serve through one address of ours, bucket first then Azure.** `images.ilovefreegle.org/archive/<name>`
  on frontend-nginx tries the bucket and falls back to Azure, like the uploads read chain. The
  domain changes once, before any copy; a copied photo then stops costing Azure egress at once,
  with no per-row switch and no row rewritten. The host already reaches frontend-nginx through
  haproxy with its own certificate, so no DNS or haproxy change.
- **A rolling 31-day window, not a calendar month.** If the Azure-measured egress over every
  trailing 31 days stays under the ceiling, so does every billing period, whatever day it starts
  on. Ceiling 80 GB, leaving 20 GB for measurement error and growth in members' views.
- **Fail closed.** No credentials, a failed or stale metric, an unreadable cost query, or any
  bandwidth charge in the billing period: no copy. A bandwidth charge also latches a stop that
  only a person clears.
- **Read each blob once.** The ledger row is written before the fetch, so a crash counts. One
  retry for a crash or a hash mismatch, then `failed`. No listing (List operations are the dearest
  read).
- **The ledger is the cursor.** No pre-seeded inventory of 7.5M rows: the walker resumes from the
  highest source id it has a ledger row for, per table.

## Tasks

| # | Task | Status |
|---|------|--------|
| 1 | Ledger migration, config, Azure client (token, metrics, capacity, cost) | ✅ |
| 2 | Budget (pure): rolling-window allowance, site reserve, fail-closed reasons, latch | ✅ |
| 3 | Copier: walker, read-once fetch with MD5, lossless optimise with pixel check, put, ledger | ✅ |
| 4 | Commands: measure, status (`--report` to Sentry), copy (guarded, `--dry-run`), schedule off by default | ✅ |
| 5 | Serving: frontend-nginx `/archive/` chain; Go and batch URL builders, thumbnails via delivery | ✅ |
| 6 | End: sampled serving check (`check-serving`); deletion is the portal's, after the fallback is removed (no delete key needed); the one stored preview of ours refreshes itself | ✅ |
| 7 | Runbook `docs/ops/runbooks/azure-archive-migration.md` with `covers:`, `.env.background.example` | ✅ |
| 8 | Tests pass locally (Laravel, Go), one PR opened | 🔄 |
