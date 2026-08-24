# Port `users_approxlocs` maintenance from V1 PHP to Laravel

## Why

`users_approxlocs` is a privacy-blurred point cloud of active members. It has **readers but no
writer**:

| Reader | Use |
|---|---|
| `iznik-routing-go/reachable_groups.go` | drives the *entire* "which groups are reachable" query for rippling (STRAIGHT_JOIN leads from its SPATIAL index) |
| `iznik-spatial-go/dataset_userapproxlocs.go` | point dataset served to the spatial API |
| `iznik-routing-go/cmd/rippleextract` | ripple simulator extract |
| `iznik-server-go/userdump/collect_db.go` | GDPR user dump |

The writer was V1 `Nearby::updateLocations()` (`iznik-server/include/user/Nearby.php`), called from
`scripts/cron/nearby.php`. V1 was deleted in `c14a7125b` (2026-07-09) and that method was never
ported.

**Live evidence (prod, 2026-08-10):**

```
rows_total = 171,799   min_ts = 2025-12-12   max_ts = 2026-06-11
```

`timestamp` holds `users.lastaccess`, so `max_ts` dates the last run: **2026-06-11, ~2 months
stale**. The blind spot:

```
active members (Approved membership, lastaccess >= 90d) = 112,548
  with a users_approxlocs row                          =  74,223
  MISSING                                              =  38,325  (34%)
```

So a third of active freeglers are currently invisible to rippling reach, and the share grows daily.

## V1 behaviour to preserve

`Nearby::updateLocations()`:

1. `cutoff = date('Y-m-d', now - Engage::USER_INACTIVE + 1 day)`, where
   `USER_INACTIVE = 365*24*60*60/2` (182.5 days) → cutoff is **date-granular, ~181.5 days ago**.
2. `SELECT DISTINCT users.id, users.lastaccess FROM users INNER JOIN memberships ON ... WHERE
   users.lastaccess >= cutoff` — any membership row, no `collection` filter, no `deleted` filter.
3. Per user, `getLatLng(usedef=FALSE, usegroup=FALSE, BLUR_USER)`, i.e. resolution order:
   - `settings.mylocation.lat/lng`
   - else `users.lastlocation` → `locations.lat/lng`
   - else **nothing** (no last-message fallback, no group fallback, no Dunsop Bridge default)
4. `Utils::blur($lat, $lng, 400)`: deterministic direction `($lat*1000 + $lng*1000) % 360`,
   `GreatCircle::getPositionByDistance(400, dir, lat, lng)`, then `round(.., 4)`.
5. Upsert `users_approxlocs (userid, lat, lng, position, timestamp)` with
   `position = ST_GeomFromText('POINT(lng lat)', 3857)` and **`timestamp = users.lastaccess`**
   (explicitly, so the column's `ON UPDATE CURRENT_TIMESTAMP` doesn't hijack it).
6. `DELETE FROM users_approxlocs WHERE timestamp < cutoff`.

## Deliberate divergences

| V1 | Here | Why |
|---|---|---|
| per-user PHP loop, one `getLatLng` + one upsert each | resolution in one SQL projection, chunked reads, bulk upserts | 112k users; the loop was the reason V1's run was slow enough to be dropped. Measured: 5,000 members in ~1.1s |
| `mylocation.lat` used even when `mylocation.lng` is NULL (would insert NULL into a NOT NULL column) | both coords required before `mylocation` wins | matches `UnifiedDigestService::resolveUserLatLng`, the house pattern; V1's path was a latent insert failure |

Two traps found while building, both now pinned by tests:

- `CAST(JSON_EXTRACT(settings, '$.mylocation.lat') AS DECIMAL)` yields **0.000000** for a JSON
  null, not NULL — the obvious SQL-side resolution would have silently moved members to Null
  Island. mylocation is read as raw JSON text and validated in PHP instead.
- V1's `if ($lat || $lng)` guard is load-bearing: 1,629 `locations` rows on live sit at 0,0. The
  guard needs *both* coordinates falsy, because the Greenwich meridian runs through east London.

Everything else is kept, including the absent `collection`/`deleted` filters (readers do their own
filtering — `reachable_groups` requires `collection='Approved'` and `lastaccess >= 90d`).

## Shape

- `app/Services/UserApproxLocService.php` — `updateLocations(bool $dryRun, ?int $limit): array`
- `app/Console/Commands/User/UpdateApproxLocsCommand.php` — `users:update-approx-locs`
- `routes/console.php` — `dailyAt('04:45')` (free slot; V1 cadence class was daily)
- `iznik-server-go/housekeeper/housekeeper.go` — registry entry so it shows in SysAdmin → Cron Jobs

## Test plan (TDD, RED first)

`tests/Unit/Services/UserApproxLocServiceTest.php` — 20 tests: resolution order (mylocation,
lastlocation, one-coord fall-through, 0,0 rejected on both paths, Greenwich accepted), membership
and cutoff filtering, `timestamp` = `lastaccess`, upsert not duplicate, SRID 3857 / `POINT(lng lat)`,
blur distance + determinism + 4-dp rounding, prune, and dry-run for both write and prune.

`tests/Feature/User/UpdateApproxLocsCommandTest.php` — 4 tests: writes, reports, `--dry-run`,
`--limit`.

`iznik-server-go/housekeeper/housekeeper_test.go` — registry contains
`users:update-approx-locs`, active, daily, under User Management.

`test-freegle-worktree-data.sh` — 6 tests for the `freegle` CLI change below.

## Side fix: `freegle worktree create` and the OSM extract

A fresh worktree's `spatial` / `spatial-live` containers exit(1) because
`iznik-routing-go/data/uk-latest.osm.pbf` (2.5GB, gitignored) only exists in the main checkout.
`_share_gitignored_data` now hardlinks it in before starting containers — one inode, so N
worktrees cost no extra disk, and it survives removal of the main checkout.

## Verified end to end

Seeded 5,000 synthetic members in the worktree dev DB (half via `settings.mylocation`, half via
`lastlocation`), ran the command, then ran the **real** `reachable_groups.go` query against the
result: 4,500 in-bbox member rows made the group reachable (the 500 excluded were seeded 90+ days
stale, exactly as its `lastaccess >= NOW() - 90 DAY` filter requires). Both resolution paths landed
in their own seeded coordinate boxes; SRID uniformly 3857 with `ST_X` = lng. Aging 100 members to
300 days back dropped them from the refresh and pruned their rows.
