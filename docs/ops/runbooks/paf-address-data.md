---
last_reviewed: 2026-11-10
owner: Freegle dev team
covers:
  - paf-extractor/server.mjs
  - paf-extractor/extract.sh
  - paf-extractor/Dockerfile
  - iznik-batch/app/Console/Commands/Paf/ImportRunCommand.php
  - iznik-batch/app/Services/PafService.php
  - iznik-batch/app/Models/PafImport.php
  - iznik-server-go/paf/paf.go
  - iznik-nuxt3/modtools/components/ModSysAdminPaf.vue
  - iznik-nuxt3/modtools/components/ModSysAdminPafModal.vue
  - iznik-batch/app/Console/Commands/Paf/CheckLatestCommand.php
  - iznik-server-go/test/paf_test.go
  - scripts/deploy-prod.sh
---

# Royal Mail address data (PAF)

The address picker (`paf_addresses` and its ten `paf_*` lookup tables) comes from Royal
Mail's Postcode Address File. We get it from AFD, who publish a new release every three months.

## Applying a new release

1. AFD email the board (as "PAF address data <month>") when a release is ready. The email
   has an unlock key, written as eight groups of three, e.g. `AB1 / CD2 / EF3 / ...`.
2. In ModTools, open SysAdmin, then **Housekeeping**, and press **Open** on the **PAF Database
   Update** row (Admins only). A link to `?tab=paf` (or the old `?tab=addresses`) opens it too.
3. It shows the latest release on AFD's site and when it was published, and says if that one has
   already been applied. Paste the key and press **Download and apply**. The button stays off
   until the key has 24 letters and numbers.

It then runs on the server with nobody watching, and marks the housekeeping task done when it
finishes. The pop-up shows each step and its progress:

| Step | What happens | Time |
|---|---|---|
| Download | `SetupRM.exe` (~420 MB) from AFD's SFTP server | ~2 minutes |
| Unlock | The extractor is run with the key and writes `CSV PAF.csv` (~2.8 GB, 32M lines) | ~6 minutes |
| Compare | Sorted, and compared with the last release applied | ~2 minutes |
| Fetch | The new or changed lines are copied to batch | minutes |
| Apply | Those lines are inserted or updated in `paf_addresses`, 1,000 at a time | about an hour per 10M lines |

A quarter's changes are a small share of the file. The **first** run has nothing to compare
with, so it applies all 32M lines and takes several hours.

Each key unlocks one release only, and the pop-up always takes the latest, at the top level of
AFD's site. A key that does not match fails at the Unlock step with "The key was not accepted for
this release". An older release, under `Archive/` on the site, can still be asked for through the
API: `POST /sysadmin/paf` with `edition` set to its folder, such as `Y26M08`.

**Which release is the latest.** `paf:check-latest` (hourly) asks the sidecar (`GET /latest`,
one SFTP directory listing) and keeps the date AFD published `SetupRM.exe`, and its size, in the
config row `paf_latest`. The Go API reads that row (`GET /sysadmin/paf/latest`), as it cannot
reach the sidecar. An import of the latest records the date it took in
`paf_imports.release_published`, which the list shows.

## How it works

- **Go** (`iznik-server-go/paf`): `POST /sysadmin/paf` stores the request in `paf_imports`
  with the key. `GET /sysadmin/paf` lists the latest ten, without the key. Only one runs at a
  time; one that has not written progress for 30 minutes is taken as dead and replaced.
- **Batch** (`paf:import-run`, every minute): picks up the request and drives the sidecar,
  copying its progress into the row. It then fetches the changed lines and applies them with
  `PafService::applyLines`, which matches on UDPRN. A run stopped part way, by a deploy or
  a restart, carries on from `lines_applied` the next minute.
- **Sidecar** (`paf-extractor/`, its own container): AFD ship the data only inside a
  Windows GUI program, so this runs it under Wine on a virtual display and types the key into
  it (`extract.sh`). It keeps the last applied release on its `paf-data` volume (~3 GB) and
  hands batch only the lines that differ. The key is cleared from the row once unlocked.

Lines whose postcode is not yet in `locations` are not applied. They show as **Waiting**,
and are offered again with the next release, by which time the postcode refresh has usually
added them.

Addresses that have left PAF are not deleted: user addresses point at `paf_addresses` rows.

## Setup on the live server

- `.env.background` holds `PAF_SFTP_HOST`, `PAF_SFTP_USER` and `PAF_SFTP_PASSWORD`, AFD's SFTP
  login. They go to the sidecar only.
- The sidecar is in the `production` profile. `scripts/deploy-prod.sh` creates it when it has no
  container, and rebuilds it when `paf-extractor/` has changed (`--only paf` forces that). By
  hand: `docker compose build paf-extractor && docker compose up -d --no-deps paf-extractor`.
  The boot unit runs `compose up -d --no-build`, which starts it after a reboot but cannot create
  it the first time.
- AFD's SFTP host key is pinned in `paf-extractor/known_hosts`. If AFD change it, the
  download fails; check the new key with them before replacing it.
- Optional batch settings: `PAF_BATCH_SIZE` (lines per batch, default 1000) and
  `PAF_PAUSE_MS` (pause between batches, default 0) if the database needs room.

## When it goes wrong

- **"Could not resolve host: paf-extractor"**: the sidecar has no container. Create it as above.
  This happened on live on 20 October 2026, because the deploy did not create new services.
- The failed row's error is shown on the tab. A screenshot of the extractor's screen at the
  moment of failure is left at `/paf-data/work/out/failure.png` in the sidecar.
- `docker exec freegledocker-paf-extractor curl -s localhost:8080/jobs/current` shows the
  sidecar's own view.
- The batch side logs to `storage/logs/cron/paf_import-run.log`.
- If AFD redesign the extractor, the screen positions at the top of `extract.sh` are what
  change. Work them out by running it by hand on a virtual display and taking screenshots.

## Testing locally

The sidecar is not in a dev profile, as the image is ~1 GB of Wine. Put the SFTP login in
the checkout's `.env.background` (git-ignored) and start it by name:
`docker-compose --profile paf up -d paf-extractor`.
