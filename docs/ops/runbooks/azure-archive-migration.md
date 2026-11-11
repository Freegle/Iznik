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
- **Any bandwidth charge stops everything**, in this billing period or the last (Cost Management
  lags, so a charge from the last days of a period shows after it has closed). It raises
  `AzureArchiveAlert` in Sentry and is latched: it holds until a person has looked at the bill
  and runs `azure-archive:status --clear-stop`. A charge from members' views before the copy
  ever ran latches it too, on purpose.
- **Azure's figures are checked, not just read.** Hours Azure has not recorded yet come back as
  zero, so the newest hour that counts is the newest with traffic, and the copy's own bytes from
  that hour on are added. If Azure shows less egress for the hours before than the copy alone
  fetched, its figures are behind and nothing is fetched. Figures that cannot be read or trusted
  raise an alert from the hourly status check, because they leave the bill unwatched.
- **A wrong container stops the copy.** Only Azure's `BlobNotFound` marks a row Missing. Any
  other 404 (the container gone or made private) stops the run with an alert, and so does a run
  where most blobs come back missing.
- **Each blob is read once.** The ledger row (`azure_archive_copies`) is written before the
  fetch with the worst case counted, and settled to the real count after, so a run that dies half
  way still counts. A crash or a corrupt read gets one retry. The bytes are checked against the
  `Content-MD5` Azure stored with the blob, or, where a blob has none, against its length. Our
  count adds 4% and 1 KB a request for framing, and one connection serves a whole run. Nothing
  lists the container. A run stops after 40 minutes, inside its hour-long lock.

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

Once the address is switched, thumbnails of archived photos are made from the full photo by the
delivery resizer. V1's own thumbnail blobs (`timg_` and friends) are not copied, so nothing
links to them from then on, and the chain refuses their names.

What stored images are there is a cache, not a record: link previews of our own pages pick up the
new address the next time they are refreshed. Old emails that linked straight to Azure will lose
their photos when the container goes. That cannot be helped: the Azure address is not ours to
redirect.

## Setting it up

The order is chosen so that members' own Azure traffic is known before anything changes it, and
before the copy adds to it. Deploying the code changes nothing on its own: while the archive
address is still Azure, the API keeps linking V1's own thumbnails, and every scheduled job is off.

1. **Deploy the code** and create the `azure_archive_copies` table.
2. **The read-only Azure app.** An app registration (service principal) with three roles:
   Monitoring Reader on the storage account, and Cost Management Reader and Billing Reader on the
   subscription. It can read figures and nothing else; it cannot read the blobs' keys or change
   anything. With the Azure CLI, signed in as the account owner (in the portal's Cloud Shell,
   choose "No storage account required", so nothing billable is created):

   ```
   ACCOUNT=$(az storage account show --name freegle --query id --output tsv)
   SUB=$(az account show --query id --output tsv)
   az ad sp create-for-rbac --name freegle-archive-reader --role "Monitoring Reader" --scopes "$ACCOUNT"
   # note appId (AZURE_ARCHIVE_CLIENT_ID), password (AZURE_ARCHIVE_CLIENT_SECRET), tenant
   az role assignment create --assignee <appId> --role "Cost Management Reader" --scope "/subscriptions/$SUB"
   az role assignment create --assignee <appId> --role "Billing Reader" --scope "/subscriptions/$SUB"
   echo "$ACCOUNT"   # AZURE_ARCHIVE_STORAGE_ACCOUNT_ID; $SUB is AZURE_ARCHIVE_SUBSCRIPTION_ID
   ```

   These go in `.env.background` on the batch host as the `AZURE_ARCHIVE_*` settings (see
   `.env.background.example`). From then on the hourly `azure-archive:status --report` watches
   the bill, copy or no copy. Run `php artisan azure-archive:status` once by hand: it shows this
   and the last billing period by meter, which answers whether anything is being charged now.
3. **Measure.** `php artisan azure-archive:measure` counts the archived rows per table and reads
   what Azure holds by tier, the account type, members' egress and the bill. Then leave it a week,
   so `azure-archive:status` shows members' own egress before anything changes.
4. **Switch the serving address.** Check that
   `https://images.ilovefreegle.org/archive/img_<an archived id>.jpg` answers 200 with
   `X-Archive-Source: azure`, then set `IMAGE_ARCHIVED_DOMAIN=images.ilovefreegle.org/archive` on
   the API hosts and `FREEGLE_IMAGES_ARCHIVED_DOMAIN=https://images.ilovefreegle.org/archive` for
   the batch. Nothing is copied yet. Members' views now go through us to Azure, and archived
   thumbnails come from the delivery resizer, which fetches the full photo once and caches the
   result, instead of each browser fetching V1's thumbnail from Azure. Whether that is more or
   less Azure egress is not known: compare a week of `azure-archive:status` with the week before.
5. **Look before copying.** `php artisan azure-archive:copy --dry-run` shows the figures, the
   decision and the next blobs, and fetches nothing.
6. **Turn it on.** `AZURE_ARCHIVE_ENABLED=true` and recreate `batch-prod`. The copy runs every
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
`Retry:` prefix is not tried again and needs a person. Once the cause is dealt with,
`php artisan azure-archive:status --requeue-failed` gives every such row one more fetch.

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
