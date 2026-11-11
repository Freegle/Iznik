# Candidate: move the archived images off Azure

Status: draft
Last reviewed: 2026-10-28

## Goal

The old photos that V1 archived to Azure Blob Storage are copied once to Freegle's own object
storage, served from there by the same URLs the site builds today, verified, and then deleted
from Azure so the account can close. Brief from Edward, 2026-10-28: beware the exfiltration
cost, and dig into the V1 PHP to see how they were stored.

## Why it qualifies

- Token-heavy because: it is a walker over eight tables with an `archived` flag, a copier that must be resumable, verified and cheap on egress, a change to the delivery URL at two code sites, and an end-to-end check that sampled old posts still show their photos; most of the time is the careful plumbing and the proof, not the decision.
- Unattended because: once the account credentials and the size are known, the copy runs by itself and is checked by code; nothing waits on a person mid-run.
- Worth it because: it ends an Azure account and bill that exist for one legacy purpose, removes a third storage system from the ops picture, and the images object-storage work already gives the destination.

## What exists today

- `iznik-batch/config/freegle.php` line 632: `images.archived_domain` defaults to `https://freegle.blob.core.windows.net`; Go reads `IMAGE_ARCHIVED_DOMAIN` in `iznik-server-go/misc/imagedelivery.go` (`GetArchivedImageDeliveryUrl`). An archived image is served as `https://<archived domain>/<prefix>_<id>.jpg` through the delivery proxy, with resize parameters honoured only for this case. Two places to change, both already behind an environment variable.
- Prefixes by attachment type, from V1 `include/message/Attachment.php` lines 425 to 445: `img` (message), `mimg` (chat), `fimg` (newsfeed), `cimg` (community event), `bimg` (noticeboard), and so on; thumbnails were written as `timg_<id>.jpg`.
- How they got there, from the V1 repository (shallow clone, 2026-10-28): the composer packages `microsoft/azure-storage-blob` and friends; `scripts/fix/fix_archive.php` wrote `createBlockBlob("images", "img_{id}.jpg", ...)` and the thumbnail beside it, container `images`; `scripts/fix/fix_movecdn.php` later moved blobs between CDN locations. `scripts/cron/archive_attachments.php` no longer archives anything; it only prunes duplicate profile images. So nothing new has gone to Azure for a long time; the set is closed.
- `archived tinyint` exists on eight tables in the V1 schema (`install/schema.sql` lines 516, 784, 1153, 2147, 2853, 2999, 3078, 4415) and survives in the Laravel migrations (`messages_attachments` and the others). The rows with `archived = 1` are the inventory; the blob names follow from the row id and type.
- The images object-storage runbook (`docs/ops/runbooks/images-to-object-storage.md`, line 107) already names the archived case as the one the legacy copier skips; the destination bucket and the read chain exist in design.
- Unknown: how many archived rows, how many blobs and how many GB, and which Azure access tier they sit in. These decide the cost and the method.

## The cost that matters

Azure charges to take data out, not to hold it. List prices, Europe zone: the first 100 GB a
month free, then about $0.087 per GB for the next 10 TB. If the blobs were moved to the Archive
tier, each GB also costs about $0.02 to rehydrate and the reads take up to 15 hours at standard
priority. Read operations are charged per 10,000. So:

| Size | Egress at list | Note |
|---|---|---|
| 200 GB | about $9 | trivial; just do it |
| 1 TB | about $80 | fine, copy once |
| 5 TB | about $430 plus any rehydration | still fine for a one-off, but copy once and never re-read |

Three ways to pay less than list, checked on 2026-10-28:

- **Physical export does not avoid egress.** Azure Import/Export (your own drives, a handling fee
  per drive, up to ten drives a job) and Data Box export both charge the same standard egress on
  top of the device fee; Microsoft's own docs say so. Their benefit is your network, not the bill.
  Not worth it at our scale.
- **The "leaving Azure" egress credit.** Since March 2024 Microsoft credits internet egress for a
  customer taking all its data off Azure: open a support request with the subscription id, a start
  date and the estimated size; copy the data out; cancel every subscription on the account; then
  ask for an invoice-level credit of the egress. The window is 60 days from the stated start date,
  and **180 days for a UK billing address moving data from UK data centres**, which is us. This is
  the route to take: the archive is the only thing Freegle has on Azure, and closing the account is
  the goal anyway.
- **The nonprofit sponsorship.** Microsoft's nonprofit offer is a $2,000 a year Azure credit for
  first-party services (bandwidth is first-party), renewed annually, unused balance not carried
  over. There is no separate bandwidth quota beyond the platform-wide 100 GB a month free. If the
  account holds that sponsorship, egress up to about 23 TB a year is inside the credit anyway.

**A slow copy, if neither credit applies.** The copier takes a monthly byte budget: the free 100 GB
plus whatever credit remains divided by $0.087, with the renewal date, and paces itself to spend
exactly that each calendar month, oldest rows first, with the ledger recording bytes per month.
At 100 GB a month a 1 TB archive takes ten months and costs nothing; the site keeps serving from
Azure for rows not yet copied, because the domain switch can be per row once the ledger exists
(a second delivery branch: ledger says copied, serve from the bucket; else from Azure). That is a
little more code than the single switch, and it is the price of a free exit.

The rule that applies to every route: **read every blob from Azure exactly once.** No listing-and-retrying by
re-download, no verification by re-fetch; verify with the content hash Azure returns in the blob
properties (`Content-MD5`) against the copy, and keep a ledger so a restart never fetches a blob
it already has.

## Decisions settled

- **Destination is Freegle's object storage, with the same key scheme.** `img_<id>.jpg` and friends in a bucket, so the only code change is the archived domain. No rewriting of rows, no new columns beyond a migration ledger.
- **Measure before moving.** The first task is a count and size by table and tier, from the rows and from the Azure account; the copy does not start until Edward has seen the number.
- **Ask for the exit credit first, then pace to the free allowance only if refused.** The UK 180-day window covers even a multi-terabyte archive at a modest rate; the support request is the first external step and sets the clock, so it is not raised until the copier is ready to run.
- **Copy once, verify by hash, keep a ledger.** As above. A blob that fails verification is re-fetched once and then listed, never looped.
- **Serve from the new place before deleting from the old.** Switch the domain, run the sampled checks for a week, then delete the Azure blobs and close the account.
- **Thumbnails are not copied unless something reads them.** V1 wrote `timg_` thumbnails; the current delivery proxy resizes on the fly. Confirm nothing requests `timg_` before deciding, and do not pay egress for files nobody fetches.

## Open questions for Edward

- Does the Azure account hold the nonprofit $2,000 sponsorship, and what is its balance and renewal date? Recommended: check the Azure Sponsorships portal; if the balance covers the measured egress, no slow copy is needed.
- Where is the Azure account and who holds the key? Recommended: Edward supplies a read-and-delete scoped key per session, as with the Krystal object-storage token; it is never stored in the repo.
- Is the destination the same bucket as the images migration or a separate `archive` bucket? Recommended: separate bucket, public read, so its lifecycle and cost are visible on their own.
- If the measured size is small (under about 200 GB), still move it, or leave it on Azure and only tidy the code? Recommended: still move it; the point is one storage system fewer.
- Delete from Azure after how long serving from the new place? Recommended: 14 days of sampled checks with no misses.

## External prerequisites

- [ ] Azure account access with list, read and (later) delete on the `images` container, and the portal view of total size and access tier. Owner: Edward.
- [ ] The "leaving Azure" support request (subscription id, start date, estimated GB), raised when the copier is ready; and, after the copy, cancelling the subscriptions and claiming the credit. Owner: Edward.
- [ ] The Krystal object-storage account from the images migration (its creation starts the £5/month fee). Owner: Edward.
- [ ] A count of `archived = 1` rows per table from production, read-only. Owner: whoever runs the measurement task with the prod read tunnel.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Measure: rows by table, blobs by prefix, bytes and tier from Azure, expected egress cost; a report, no copy | - | A | iznik-batch command |
| 2 | Ledger and copier: walk the archived rows by cursor, fetch each blob once, verify by Content-MD5, write to the bucket, record; resumable; a monthly byte budget and pacing; dry run | 1 | A | iznik-batch |
| 2a | Per-row delivery switch: serve a copied row from the bucket and an uncopied one from Azure, read from the ledger, so a slow copy is invisible | 2 | B | iznik-server-go/misc, iznik-batch |
| 3 | Serve: archived domain switched by environment on batch and Go; a check that samples old posts across all eight types and fetches their images from the new domain | - | B | iznik-batch config, iznik-server-go/misc, docs |
| 4 | Thumbnail decision: delivery-cache and nginx logs for `timg_` requests over 30 days; copy them only if hit | 1 | B | - |
| 5 | Cut-over: switch the domain, run the sampled check daily for the agreed period, then delete from Azure by ledger and close out; runbook | 2, 3 | A | docs/ops/runbooks |

## Acceptance

- The measurement report states rows, blobs, bytes, tier and the expected Azure bill before any copy starts, and Edward has seen it.
- After the copy, every ledger row has a verified hash, and the count of verified rows equals the count of archived rows that have a blob; the rows with no blob are listed.
- The Azure egress billed is within 10% of the measured size, and either credited under the exit programme or kept inside the monthly budget; no blob was fetched twice except the listed re-fetches.
- A sample of old posts of every attachment type shows its images from the new domain, with resize parameters working.
- After deletion, the Azure container is empty and the account can be closed; the code has no reference to `blob.core.windows.net` except in history.

## Review burden

- PRs: 4 or 5
- Each UI PR carries: none; the sampled-check output is the evidence
- Each migration PR carries: raw production SQL for the ledger table in the description
- Docs page(s) to add or update, with `covers:`: the images object-storage runbook, and the developer reference that names the archived domain

## Out of scope

- The main images migration from NFS to object storage (its own plan).
- Re-encoding, de-duplicating or resizing the archived images.
- Anything stored in Azure other than the `images` container (check there is nothing; V1 also pulled in table, queue and file packages, apparently unused).

## Sources

- Import/Export and Data Box charge egress: [Microsoft Learn, Import/Export](https://learn.microsoft.com/en-us/azure/import-export/storage-import-export-service), [Data Box pricing FAQ](https://azure.microsoft.com/en-us/pricing/details/databox/), [azure-docs issue 15641](https://github.com/MicrosoftDocs/azure-docs/issues/15641)
- Exit credit and the UK 180 days: [Cancel and delete your Azure subscription](https://docs.azure.cn/en-us/cost-management-billing/manage/cancel-azure-subscription), [Computing](https://www.computing.co.uk/news/4185418/microsoft-waive-cloud-egress-fees)
- Nonprofit credit: [Microsoft for Nonprofits, activate the grant](https://learn.microsoft.com/en-us/industry/nonprofit/microsoft-for-nonprofits/claim-activate-nonprofit-azure-grant), [renew](https://learn.microsoft.com/en-us/industry/nonprofit/microsoft-for-nonprofits/renew-azure-grant)
- Egress rates: [egresscost.com Azure](https://egresscost.com/azure/), [Azure Blob pricing breakdown](https://www.opslyft.com/blog/azure-blob-storage-pricing)

## Related plans

- `docs/ops/runbooks/images-to-object-storage.md` - linked; same destination.
- V1 repository `Freegle/iznik-server`: `scripts/fix/fix_archive.php`, `scripts/fix/fix_movecdn.php`, `include/message/Attachment.php` - the record of how the blobs were written.
