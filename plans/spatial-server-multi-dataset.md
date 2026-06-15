# Freegle Spatial Server — Multi-Dataset Plan

## Status: IN PROGRESS
Branch: `feature/spatial-improvements`
Worktree: `/home/edward/FreegleDocker-spatial-improvements`

---

## Decisions locked in

| Question | Decision |
|----------|----------|
| Postcodes | Loaded in `locations` dataset alongside areas; `?type=Postcode` filters at query time. Loader must NOT exclude postcodes — both types loaded. |
| Messages extra fields | Return `msgtype`, `groupid`, `promised` in `extra` |
| Backwards compat | Legacy `{"locationid":N}` shape is **deprecated**. All KNN returns `{"results":[...]}`. `PostcodeRemapService.php` updated to use `?limit=1` and parse `results[0].id`. |
| Newsfeed | 4th dataset — point, `newsfeed.position` column |
| userapproxlocs | 5th dataset — point, `users_approxlocs.position`; rebuild-only (full rebuild every 15 min) |
| Groups | 6th dataset — polygon, `groups.polyindex` column; rebuild-only (small number of groups, no incremental needed) |
| Jobs | Polygon dataset — `jobs.geometry` catchment area; query semantics: `geom.Intersects()` (point's expanding buffer touches polygon) |
| Authority stats | Leave existing `GetStatsByAuthority` function as-is; address separately later |
| Isochrone creation | Out of scope |

---

## Datasets

| # | Name | Type | Source table | Spatial column | Incremental trigger | Rebuild interval |
|---|------|------|-------------|----------------|---------------------|-----------------|
| 1 | `locations` | Polygon | `locations` | `ST_AsWKB(geometry)` from `locations_spatial` | `locations.timestamp` (auto-update) | Nightly 03:00 |
| 2 | `messages` | Point | `messages_spatial` | `point` | `messages_spatial.modified` | Nightly 03:00 |
| 3 | `newsfeed` | Point | `newsfeed` | `position` | `newsfeed.modified` | Nightly 03:00 |
| 4 | `userapproxlocs` | Point | `users_approxlocs` | `position` | — (rebuild only) | Every 15 min |
| 5 | `groups` | Polygon | `groups` | `polyindex` | — (rebuild only) | Every 15 min |
| 6 | `jobs` | Polygon | `jobs` | `geometry` | `jobs.seenat` | Nightly 03:00 |

---

## Endpoints

### Public port (`SPATIAL_PORT=8194`)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/health` | Health check |
| `GET` | `/v1/datasets` | List datasets + status |
| `GET` | `/v1/:dataset/knn` | K-nearest results; `limit` required |
| `GET` | `/v1/:dataset/within` | All items intersecting a polygon; max 10 000 IDs per response (HTTP 413 if exceeded) |
| `GET` | `/v1/:dataset/status` | Dataset row count, last sync time |
| `GET` | `/openapi.yaml` | OpenAPI spec |

### Admin port (`SPATIAL_ADMIN_PORT=8195`)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/:dataset/rebuild` | Trigger full rebuild of one dataset |
| `POST` | `/v1/rebuild` | Rebuild all datasets |

### Query parameters

| Param | Applies to | Description |
|-------|-----------|-------------|
| `lng` | knn, within | Longitude of query point |
| `lat` | knn, within | Latitude of query point |
| `limit` | knn | Max results (required) |
| `type` | knn, within (locations only) | `Postcode` to restrict to postcode rows; omit for areas only |
| `polygon` | knn, within | WKT polygon; restricts results to items whose geometry intersects this polygon. Max 100 KB; max 10 000 vertices. |

### Response shapes

All KNN responses (unified):
```json
{"results": [{"id": 42, "distance": 0.012, "extra": {"msgtype": "Offer", "groupid": 7, "promised": 0}}]}
```

`/v1/:dataset/within`:
```json
{"ids": [42, 107, 993]}
```

*(The old `{"locationid":N}` shape is removed. `PostcodeRemapService.php` uses `?limit=1` and reads `results[0].id`.)*

---

## Phase 1: Restructure into multi-dataset server ⬜

### 1.1 `dataset.go` — interface + shared types
- `Dataset` interface: `Name()`, `Load()`, `ApplyDelta()`, `Query()`, `Within()`, `IndexSchema()`
- `QueryParams`: lng, lat, limit, polygon (*geom.Geometry), typeFilter string
- `QueryResult`: id int64, distance float64, extra map[string]any
- `ErrNotSupported` sentinel for datasets without incremental

### 1.2 `index.go` — generic R-tree, two schema variants
- Polygon variant (locations, groups, jobs): stores WKB, bbox from envelope
- Point variant (messages, newsfeed, userapproxlocs): stores lat/lng as degenerate bbox (min==max)
- `DeleteByExtID(extid int64)` for incremental removes
- `CountRows()` for status endpoint
- `QueryWithin(polygon *geom.Geometry) []int64` — returns all IDs whose geometry intersects polygon; caller must cap at 10 000

### 1.3 `knn.go` — query algorithms
- `FindNearestPolygon` (existing buffer expansion) — for polygon datasets
- `FindNearestPoints(limit int, polygon *geom.Geometry)` — expanding square, rank by Euclidean distance, intersect with polygon if supplied
- `FindWithin(polygon *geom.Geometry) []int64` — no distance; returns all matching IDs up to the 10 000 cap

### 1.4 `datasets/locations.go` — polygon dataset
- Full load: non-excluded, 2D polygon, `ST_Area BETWEEN 0.00001 AND 0.15`
- **Loads both areas AND postcodes** — no `type != 'Postcode'` filter
- In-memory `type` field stored per row; `?type=Postcode` filters results at query time
- Incremental: `WHERE timestamp > :last_sync` for new/modified rows
- Deletion detection: re-query `locations_excluded` on every incremental — any ID now in that table is removed from the index, regardless of whether `locations.timestamp` changed
- Extra fields: `name`, `type`

### 1.5 `datasets/messages.go` — point dataset
- Full load:
  ```sql
  SELECT ms.msgid, ST_X(ms.point) AS lng, ST_Y(ms.point) AS lat,
         ms.msgtype, ms.groupid,
         CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END AS promised
  FROM messages_spatial ms
  LEFT JOIN messages_promises mp ON mp.msgid = ms.msgid
  WHERE ms.successful = 0
  ```
- Incremental (every 2 min): `WHERE ms.modified > :last_sync`; remove rows where `successful=1`
- Extra fields: `msgtype`, `groupid`, `promised`

### 1.6 `datasets/newsfeed.go` — point dataset
- Full load:
  ```sql
  SELECT id, ST_X(position) AS lng, ST_Y(position) AS lat, type, userid
  FROM newsfeed
  WHERE deleted IS NULL AND replyto IS NULL
  ```
- Incremental (every 2 min): `WHERE modified > :last_sync`; remove rows where `deleted IS NOT NULL`
- Extra fields: `type`, `userid`

### 1.7 `datasets/userapproxlocs.go` — point dataset (rebuild-only)
- Full load:
  ```sql
  SELECT userid, lng, lat FROM users_approxlocs
  ```
- No incremental — full rebuild every 15 min
- No extra fields

### 1.8 `datasets/groups.go` — polygon dataset (rebuild-only)
- Full load:
  ```sql
  SELECT id, ST_AsWKB(polyindex) AS wkb, nameshort
  FROM `groups`
  WHERE publish = 1 AND listable = 1
  ```
- No incremental — full rebuild every 15 min
- Extra fields: `nameshort`

### 1.9 `datasets/jobs.go` — polygon dataset
- Full load:
  ```sql
  SELECT id, ST_AsWKB(geometry) AS wkb, title, city, cpc
  FROM jobs
  WHERE visible = 1 AND cpc >= 0.10
  ```
- Incremental (every 5 min): `WHERE seenat > :last_sync`; remove `visible=0` rows
- Extra fields: `title`, `city`, `cpc`

### 1.10 `server.go` — dataset registry + scheduler

**Index swap model** (critical for correctness):
- Each incremental tick builds a **new** `*Index` in a background goroutine, then atomically swaps it under the write lock. Deltas are never applied in-place to a live index.
- Sequence: `Load() → buildNewIndex → mu.Lock() → state.idx = newIdx → mu.Unlock()`
- Reads always hold the old index until the swap completes; no partial-state reads possible.
- For datasets with large incremental sets (messages), the new index is built by cloning the existing index and applying adds/removes, then swapping. For small datasets (groups, userapproxlocs), the new index is built from scratch.

Other details:
- `map[string]*datasetState` — one state per dataset
- Startup: load all datasets in parallel
- Nightly rebuild at 03:00 UTC: all datasets
- Per-dataset incremental ticker per table above
- `datasetState`: mu RWMutex, idx *Index, lastSync time.Time, rebuilding atomic.Bool

### 1.11 `main.go` — HTTP routing and input validation

**WKT polygon validation** (applied before any dataset query):
- Reject if `polygon` param > 100 KB
- Reject if vertex count > 10 000 (count commas in coordinate pairs as proxy)
- Wrap `geom.UnmarshalWKT()` in `recover()` — return HTTP 400 on panic
- Apply to both `/knn?polygon=` and `/within?polygon=`

**`/within` response cap:**
- If `QueryWithin()` returns > 10 000 IDs, return HTTP 413 with `{"error":"polygon matches too many results; narrow the polygon"}` — caller must subdivide

Routes:
- Public port: health, datasets, knn, within, status, openapi.yaml
- Admin port: per-dataset rebuild, all-rebuild

### 1.12 `openapi.yaml`
- Full OpenAPI 3.0 spec covering all endpoints, params, response shapes
- Documents the 10 000-ID `/within` cap and WKT constraints
- Served at `GET /openapi.yaml` on public port

---

## Phase 2: Migrate callers ⬜

### 2.1 `location.ClosestPostcode` (Go) + `Location.closestPostcode` (PHP)
- `GET /v1/locations/knn?type=Postcode&limit=1&lng=X&lat=Y` → parse `results[0].id`
- Go: `iznik-server-go/location/location.go`
- PHP: `iznik-batch/app/Models/Location.php`

### 2.2 `PostcodeRemapService.php`
- Update to use `?limit=1` + parse `results[0].id` (removes legacy `{"locationid":N}` dependency)
- File: `iznik-batch/app/Services/PostcodeRemapService.php`

### 2.3 `ClosestGroups` (Go)
- Replace parallel expanding `MBRIntersects(polyindex, ...)` + `ST_distance(..., polyindex)` MySQL queries with `GET /v1/groups/knn?lng=X&lat=Y&limit=N`
- File: `iznik-server-go/location/location.go:ClosestGroups`

### 2.4 `newsfeed.go GetNearbyDistance`
- Replace 6-level MBR expansion + parallel MySQL queries on `newsfeed.position` with `GET /v1/newsfeed/knn?lng=X&lat=Y&limit=N`
- File: `iznik-server-go/newsfeed/newsfeed.go`

### 2.5 `job.go GetJobs`
- Replace parallel expanding `ST_Within` + `ST_Distance` queries with `GET /v1/jobs/knn?lng=X&lat=Y&limit=N`
- File: `iznik-server-go/job/job.go`

### 2.6 `NearbyOffersService.php`
- Replace lat/lng bounding box with `GET /v1/messages/knn?lng=X&lat=Y&limit=50`
- File: `iznik-batch/app/Services/NearbyOffersService.php`

### 2.7 `isochrone/message.go`
- Replace `ST_Contains(isochrones.polygon, point)` MySQL join with `GET /v1/messages/knn?lng=X&lat=Y&polygon=<WKT>`
- File: `iznik-server-go/isochrone/message.go`

### 2.8 `Nearby.php` — users near message
- Replace `ST_Contains(box, users_approxlocs.position)` MySQL query with `GET /v1/userapproxlocs/knn?lng=X&lat=Y&limit=N`
- File: `iznik-server/include/user/Nearby.php` (or Go equivalent when migrated)

### 2.9 Rippling Out — user selection per isochrone expansion
- Each expansion step currently runs `ST_Contains(isochrone_polygon, users_approxlocs.position)` in MySQL
- Replace with `GET /v1/userapproxlocs/within?polygon=<isochrone_WKT>` → returns all user IDs within the polygon (up to 10 000 per call; typical isochrone ≪ 10 000 users)
- Caller applies group-membership and already-notified filters in Go (not spatial)
- Isochrone WKT comes from ORS/Valhalla unchanged — spatial server accepts any polygon
- Simulation optimisation scripts get same benefit: replace MySQL ST_Contains loops with spatial server calls
- Files: `iznik-server/include/user/Isochrone.php`, simulation scripts

---

## Phase 3: Polygon/isochrone query path ⬜

The `polygon` parameter on `/v1/{dataset}/knn` and the `/within` endpoint together enable isochrone-based workflows:

**Browse use case** (`/knn?polygon=<WKT>`): find nearest N messages within a travel-time area.

**Notification use case** (`/within?polygon=<WKT>`): find ALL users within an isochrone (Rippling Out expansion step).

Both cases: caller generates isochrone WKT from ORS/Valhalla, passes to spatial server.

---

## Incremental sync strategy

| Dataset | Poll interval | Detection | Removal detection |
|---------|--------------|-----------|-------------------|
| locations | 15 min | `locations.timestamp > last_sync` | Re-query full `locations_excluded` table every tick; remove any IDs now present |
| messages | 2 min | `messages_spatial.modified > last_sync` | `successful=1` rows removed |
| newsfeed | 2 min | `newsfeed.modified > last_sync` | `deleted IS NOT NULL` rows removed |
| userapproxlocs | — (rebuild) | — | Full rebuild every 15 min |
| groups | — (rebuild) | — | Full rebuild every 15 min |
| jobs | 5 min | `jobs.seenat > last_sync` | `visible=0` rows removed |

Full rebuild at 03:00 UTC catches anything incremental misses. All incremental ticks build a new index and swap atomically — never mutate the live index in place.

---

## DB migrations needed

| Migration | Status |
|-----------|--------|
| `messages_spatial.modified` TIMESTAMP ON UPDATE | ✅ Applied on live; recording migration written |
| `newsfeed.modified` TIMESTAMP ON UPDATE | ✅ Applied on live; recording migration written |
| `users_approxlocs.modified` | ⬜ Deferred (rebuild-only for now) |

---

## Test plan

- Unit tests per dataset: load, query, delta (in-memory SQLite)
- Integration: full rebuild from test DB, query returns expected IDs
- Polygon query — explicit predicates tested:
  - Point inside polygon → match
  - Point on polygon boundary → match (Intersects semantics)
  - Point outside polygon → no match
  - Polygon dataset item that partially overlaps query polygon → match
- Within query: verify 10 001-result polygon returns HTTP 413
- WKT validation: >100 KB input returns HTTP 400; malformed WKT returns HTTP 400
- Incremental swap: verify in-flight query sees consistent index (no partial state)
- locations_excluded: insert exclusion row mid-tick, verify next tick removes ID from index
- Legacy shape removal: verify `GET /v1/locations/knn?limit=1` returns `{"results":[...]}` not `{"locationid":N}`
