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
   the legacy NFS share, bound read-only into nginx and served as static files (not a
   second tusd: v2.4.0 creates a lock file even on GET, found 2026-09-28). Every other
   method is tus protocol and goes to tusd unchanged. Because the read path is
   location-agnostic, the migration has no user-visible state and can take as long as
   it likes.
4. **A migrator** (`images:migrate-legacy`, scheduled with a time budget and a
   bandwidth cap) walks the eleven tables by id, and for each `freegletusd-` id copies
   the NFS file to the bucket if the bucket does not already have it. It never lists the
   directory and never deletes from NFS. Per-table cursors live in one small table so
   it is resumable and idempotent. `--verify` re-walks and reports anything missing.
5. **When verify reports nothing missing**, a human removes the NFS hop from nginx and
   the binds, unmounts the share, and deletes the file storage volume in the console.
   Files the database does not reference are not copied; they are unreachable today and
   go with the volume.

Rollback at any point before step 5: copy whatever is in the spool onto NFS (ids are
unique, so it is a plain copy), and put the old tusd command and bind back.

## Storage facts that shaped this

- Katapult object storage is S3-compatible with multipart, presigned URLs, lifecycle
  and versioning, but **no bucket or object ACLs and no CORS via the S3 API**. Public
  read, CORS and access keys are set through the Core API (`object_storage` scope;
  region `uk-lon-1`) or the console. Checked 2026-09-28: the organisation has no
  object storage account yet. £5/month for 250 GB and 1 TB egress, then £0.02/GB.
- The file storage volume `images` bills on use: 1,034 GB at £0.09 = £93.15/month
  (console, 2026-09-28). The bucket holding the same is about £21, so about £72/month
  saved once the volume is deleted.
- Endpoint hostname is not documented; the bucket's `public_url` gives the public base.
- A public bucket that turns out not to be public fails **silently**: nginx falls
  through to the legacy hop and every new image 404s. `images:object-store-check`
  proves an anonymous GET works before the switch is enabled.

## Deliverables

| # | Item | Where |
|---|------|-------|
| 1 | tusd on a local spool (mounted over its own directory so uid 1000 can write); the legacy share read-only in nginx; `objectstore` (RustFS) + `objectstore-init` for the dev `edge` stack; batch containers see the spool | `docker-compose.yml`, `docker-compose.override.edge.yml` |
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
| 1 | Compose: spool volume, legacy bind, objectstore, batch mounts, edge override | ✅ | both configs validate; tusd-nfs dropped after the lock-on-GET finding |
| 2 | nginx read chain + template | ✅ | renders with the filter, nginx -t ok |
| 3 | Laravel disks, config, dependency | ✅ | flysystem-aws-s3-v3 ^3.0 |
| 4 | Services + commands (TDD) | ✅ | 41 tests: red 41/159, then green 159/159 |
| 5 | Migration table | ✅ | + prod SQL |
| 6 | Schedule | ✅ | gated with ->when() on the two switches |
| 7 | Tests green through the worktree status API | ✅ | full suite 7011/7011; image tests 159/159 on final code |
| 8 | Local end-to-end on the edge stack with RustFS | ✅ | tus create/patch via nginx; served from spool; pushed (bucket serves image/jpeg, immutable cache header); served from bucket with tusd logging the spool miss; legacy id only on the share served static and resized by delivery; migrate copied it; verify clean; .info 404; unknown id 404 |
| 9 | Docs | ✅ | runbook, production, spend, architecture, runbooks index, env examples |
| 10 | PR | ✅ | #1630 |

## Open for Edward

- Done 2026-09-28 on Edward's go-ahead: object storage enabled in uk-lon-1, bucket created
  with public read on and listing off, and one access key that can read and write only that
  bucket. Proven: write, size, anonymous read, 404 for a missing key, listing refused,
  delete. The key's secret is in a private file on Edward's machine, not in the repo.
- Left for the rollout: put the key and bucket URL on the prod host, apply the SQL, cut over.
- Whether the file storage volume can be deleted outright at the end, or must be
  emptied first, is a billing question for the console.
