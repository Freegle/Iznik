---
last_reviewed: 2026-11-11
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/AzureArchive/
  - iznik-batch/app/Console/Commands/AzureArchive/
  - iznik-batch/database/migrations/2026_11_11_000001_create_azure_archive_copies_table.php
  - iznik-server-go/misc/imagedelivery.go
---

# Archived images: moving them off Azure for free

V1 moved the photos of old posts, chats, events and newsfeed items to an Azure Blob Storage
container and marked their rows `archived = 1`. About 7.5 million rows, roughly a terabyte. They
are being copied into our own images bucket, under `archive/`, slowly enough that Azure never
charges for the transfer. Then the container is deleted and the Azure account can close.

Account names, ids and keys are in the ops team's notes, never here.

## How it stays free

Azure charges nothing for the first 100 GB a month that leaves it, then charges per GB. Members'
views of old photos come out of the same 100 GB. So the copy is paced by Azure's own figures:

- **Any 31 days stay under a ceiling of 80 GB** (`AZURE_ARCHIVE_CEILING_GB`), members' views
  included. Billing months can start on any day; if every 31-day window is under the ceiling,
  every billing month is too. The 20 GB gap is the margin.
- **Members come first.** Their share of the last 31 days (Azure's egress less the copy's own) is
  reserved, plus a quarter for growth. The copy gets a 31st of what is left each day.
- **Azure is asked, not guessed.** Before every run the copier reads the storage account's egress
  from Azure Monitor and the billing period's charges from Cost Management, with a read-only app
  registration. A figure it cannot read stops the run. Neither query costs anything.
- **Any bandwidth charge stops everything** and raises `AzureArchiveAlert` in Sentry. The stop is
  latched: it holds until a person has looked at the bill and runs
  `azure-archive:status --clear-stop`.
- **Each blob is read once.** The ledger row (`azure_archive_copies`) is written before the
  fetch, so a run that dies half way still counts what it fetched. A crash or a corrupt read gets
  one retry. The bytes are checked against the `Content-MD5` Azure stored with the blob. Nothing
  lists the container.

The one charge that cannot be avoided is Azure's fee per read request, about £2 for the whole
copy at Hot-tier list prices. `azure-archive:measure` works it out for the account's real tier.

## How the photos are served during the copy

The API builds every archived image URL from `IMAGE_ARCHIVED_DOMAIN` (the batch:
`FREEGLE_IMAGES_ARCHIVED_DOMAIN`). It points at `images.ilovefreegle.org/archive`, where
frontend-nginx tries the bucket first and asks Azure only when the bucket says 404. So a photo is
served from our bucket from the moment it is copied, with no row changed. The response header
`X-Archive-Source` says `bucket` or `azure`.

A bucket that answers 401, 403 or 5xx is **not** passed to Azure. That would send every copied
photo back to Azure and spend the free allowance while nothing looked broken.

Thumbnails of archived photos are made from the full photo by the delivery resizer. V1's own
thumbnail blobs (`timg_` and friends) are not copied, and nothing links to them any more.

What stored images are there is a cache, not a record: link previews of our own pages pick up the
new address the next time they are refreshed. Old emails that linked straight to Azure will lose
their photos when the container goes. That cannot be helped: the Azure address is not ours to
redirect.

## Setting it up

1. **The read-only Azure app.** An app registration (service principal) with three roles:
   Monitoring Reader on the storage account, and Cost Management Reader and Billing Reader on the
   subscription. It can read figures and nothing else; it cannot read the blobs' keys or change
   anything. Its tenant id, client id and secret, the subscription id and the storage account's
   resource id go in `.env.background` on the batch host as the `AZURE_ARCHIVE_*` settings (see
   `.env.background.example`). From that moment the hourly `azure-archive:status --report`
   watches the bill, copy or no copy.
2. **Measure.** `php artisan azure-archive:measure` counts the archived rows per table and reads
   what Azure holds by tier, members' egress and the bill so far. Read the expected time and cost
   before going on.
3. **Switch the serving address.** Deploy frontend-nginx with the `/archive/` chain, check that
   `https://images.ilovefreegle.org/archive/img_<an archived id>.jpg` answers 200 with
   `X-Archive-Source: azure`, then set `IMAGE_ARCHIVED_DOMAIN=images.ilovefreegle.org/archive` on
   the API hosts and `FREEGLE_IMAGES_ARCHIVED_DOMAIN=https://images.ilovefreegle.org/archive` for
   the batch. Nothing is copied yet; members' views now go through us to Azure.
4. **Look before copying.** `php artisan azure-archive:copy --dry-run` shows the figures, the
   decision and the next blobs, and fetches nothing.
5. **Turn it on.** `AZURE_ARCHIVE_ENABLED=true` and recreate `batch-prod`. The copy runs every
   15 minutes and `azure-archive:check-serving --report` once a day.

## Watching it

```
php artisan azure-archive:status
```

Shows rows by table and status, egress over the last 31 days split between members and the copy,
today's allowance, the bill for this and the last billing period by meter, and the time left at
the current pace. Each run of the copy appends to `storage/logs/cron/azure-archive_copy.log`.

`Missing` rows are rows whose blob Azure does not have (about 7% of posts, 20% of chats in a
2026 sample); they are finished, not failures. `Superseded` rows have an uploads id, which the API
serves first, so their Azure copy is never used. `Failed` rows carry the reason; one without the
`Retry:` prefix is not tried again and needs a person.

## If Azure charges for bandwidth

The copy has stopped itself and Sentry has the alert. Look at the bill in the Azure portal
(Cost Management) and at `azure-archive:status`. Usually it is members' views growing, or other
traffic on the same billing account. Lower `AZURE_ARCHIVE_CEILING_GB` if the margin was too thin,
then clear the stop with `php artisan azure-archive:status --clear-stop`.

## The end

When every measured row has a ledger row in `Copied`, `Missing` or `Superseded`, and
`azure-archive:check-serving` has passed every day for a fortnight:

1. Remove the `@azure_archive` fallback from `frontend-nginx.conf` and redeploy, so nothing
   reaches Azure any more. A miss is then a 404 from the bucket.
2. In the Azure portal, delete the container, then the storage account, then cancel the
   subscription if nothing else uses it. Deleting is free, and it takes V1's thumbnails and any
   orphaned blobs with it.
3. Check the final invoice shows no bandwidth charge.
4. Set `AZURE_ARCHIVE_ENABLED=false` and remove the `AZURE_ARCHIVE_*` settings.

## What it costs

Nothing on Azure beyond the read fees above, and the storage Azure already bills until the
container is deleted. On our side the photos add about a terabyte to the images bucket, a little
less after the lossless optimisation (about 8% on a sample; `jpegtran`, checked pixel for pixel).
