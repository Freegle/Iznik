# Move the image store from NFS to Katapult object storage, gradually

**Status (2026-09-28): design settled, build in progress.** Worktree
`/home/edward/FreegleDocker-image-object-storage`, branch `feature/image-object-storage`.

## What is there today

- Every uploaded image is a tusd upload. tusd (`v2.4.0`, filestore) runs on the
  FreegleDocker host under the `edge` profile with `-upload-dir=/images`, which is the
  Katapult NFS share `/srv/tusd-data` bound into the container. One flat directory,
  **1.1 TB, ~1.93 M files** (3.87 M inodes: each upload is `<id>` plus `<id>.info`).
- The database refers to an upload as `externaluid = 'freegletusd-<id>'` in eleven
  tables (`messages_attachments` 1.67 M, `chat_images` 89 k, `ai_images` 56 k,
  `users_images` 8 k, `communityevents_images` 6 k, `newsfeed_images` 5 k, and five
  small ones). About 1.83 M ids are referenced; the rest are abandoned uploads and
  rows since deleted.
- Nothing reads the file directly. The Go API builds
  `delivery.ilovefreegle.org?url=https://uploads.ilovefreegle.org:8080/<id>`; weserv
  fetches the original from the uploads vhost of `frontend-nginx`, which proxies to tusd.
  The 41 GB delivery cache hits 92-99% of the time, so origin fetches are rare.
- ~4,400 uploads a day. Uploads create, write and delete on the NFS directory, and a
  listing of that directory starves them (see `.claude/rules/dev-containers.md`).

## Why not tusd's own S3 backend

tusd's s3store gives every upload the id `<objectId>+<multipartId>` and cannot look up
a plain `<id>` (its `GetUpload` splits on `+` and returns not-found otherwise). That
changes the shape of every `externaluid`, needs escaping of `+` in every place a
delivery URL is built (Go, Nuxt, Laravel mail, the apps), writes `.info` objects with
the member's original filename into a public bucket, and costs four S3 calls per origin
fetch. None of that is needed.

## Design: local spool, push, and a read chain that does not care where a file is

1. **tusd writes to a local disk spool** (`tusd-spool` Docker volume) instead of NFS.
   Ids, the tus protocol, the upload URL and every client stay exactly as they are.
   NFS becomes read-only from the moment this ships, which removes it from the upload
   path.
2. **A pusher** (`images:push-spool`, Laravel, scheduled every minute in `batch-prod`)
   copies each completed upload to the bucket with the right `Content-Type`, verifies the
   size, and deletes it from the spool. Completed means the file is as long as its
   `.info` says and has not changed for a grace period. Uploads that never complete are
   deleted after a day, which also retires the "purge abandoned uploads" item from the
   spend runbook. The `.info` never leaves the host.
3. **frontend-nginx serves a GET for an upload from the first place that has it:**
   the spool (tusd, local stat, sub-millisecond), then the bucket (public read), then
   the legacy NFS share (a second read-only tusd, `tusd-nfs`). Every other method is
   tus protocol and goes to tusd unchanged. Because the read path is location-agnostic,
   the migration has no user-visible state and can take as long as it likes.
4. **A migrator** (`images:migrate-legacy`, scheduled with a time budget and a
   bandwidth cap) walks the eleven tables by id, and for each `freegletusd-` id copies
   the NFS file to the bucket if the bucket does not already have it. It never lists the
   directory and never deletes from NFS. Per-table cursors live in one small table so
   it is resumable and idempotent. `--verify` re-walks and reports anything missing.
5. **When verify reports nothing missing**, a human removes `tusd-nfs` and the NFS hop
   from nginx, unmounts the share, and deletes the file storage volume in the console.
   Files the database does not reference are not copied; they are unreachable today and
   go with the volume.

Rollback at any point before step 5: copy whatever is in the spool onto NFS (ids are
unique, so it is a plain copy), and put the old tusd command and bind back.

## Storage facts that shaped this

- Katapult object storage is S3-compatible with multipart, presigned URLs, lifecycle
  and versioning, but **no bucket or object ACLs and no CORS via the S3 API**; public
  access and CORS are set in the Krystal console. Buckets and keys are console tasks:
  the Core API has no object storage endpoints. £5/month for 250 GB and 1 TB egress,
  then £0.02/GB; the NFS share bills ~£0.09/GB used, so the store drops from ~£100 to
  ~£25 a month once the volume is gone.
- Endpoint hostname is not documented; it is shown in the console and is config here.
- A public bucket that turns out not to be public fails **silently**: nginx falls
  through to the legacy hop and every new image 404s. `images:object-store-check`
  proves an anonymous GET works before the switch is enabled.

## Deliverables

| # | Item | Where |
|---|------|-------|
| 1 | tusd on a local spool; `tusd-nfs` read-only legacy server; `minio` + `minio-init` for the dev `edge` stack; batch containers see the spool | `docker-compose.yml`, `docker-compose.override.edge.yml` |
| 2 | Uploads vhost read chain, bucket URL from env, `.info`/`.part`/`.lock` never served | `frontend-nginx.conf` (now an envsubst template) |
| 3 | `images` (S3), `tusd-spool` and `tusd-legacy` disks; `freegle.images.object_store` config | `iznik-batch/config/*.php`, `composer.json` (`league/flysystem-aws-s3-v3`) |
| 4 | `TusInfo`, `SpoolPusherService`, `LegacyMigrationService`; commands `images:push-spool`, `images:migrate-legacy`, `images:object-store-check` | `iznik-batch/app/Services/ImageStore/*`, `app/Console/Commands/Images/*` |
| 5 | `image_store_migration` cursor table (Laravel migration + idempotent prod SQL) | `iznik-batch/database/migrations/` |
| 6 | Schedule entries, gated on the enable flag | `iznik-batch/routes/console.php` |
| 7 | Tests with `Storage::fake` for all three disks | `iznik-batch/tests/Feature/Images/*` |
| 8 | Runbook (cutover, verify, rollback, completion), production topology update, spend runbook row, architecture profile row, env examples | `docs/ops/**`, `.env.example`, `.env.background.example` |

## Progress

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Compose: spool volume, tusd-nfs, minio, batch mounts, edge override | ✅ | both configs validate |
| 2 | nginx read chain + template | ✅ | renders with the filter, nginx -t ok |
| 3 | Laravel disks, config, dependency | ✅ | flysystem-aws-s3-v3 ^3.0 |
| 4 | Services + commands (TDD) | 🔄 | tests written; red run in progress; drafts in scratchpad |
| 5 | Migration table | ✅ | + prod SQL |
| 6 | Schedule | 🔄 | snippet drafted, applied after the red run |
| 7 | Tests green through the worktree status API | ⬜ | |
| 8 | Local end-to-end on the edge stack with minio | ⬜ | upload -> spool -> bucket -> served via nginx chain |
| 9 | Docs | ✅ | runbook, production, spend, architecture, runbooks index, env examples |
| 10 | PR | ⬜ | |

## Open for Edward

- The Katapult API token in git history is rotated; none is on this machine or the
  prod host. Not needed for the build; the bucket, public access and access key are
  console steps in the runbook.
- Whether the file storage volume can be deleted outright at the end, or must be
  emptied first, is a billing question for the console.
