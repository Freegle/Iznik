# Freegle Spatial KNN Index Service (`iznik-spatial-go`)

A small Go HTTP service that keeps fast, queryable spatial indexes over six
Freegle datasets and answers two kinds of question:

- **Nearest-neighbour (KNN):** "what are the N nearest records to this lat/lng?"
- **Within-polygon:** "which records fall inside this area (WKT polygon)?"

It is the **"finder"** half of Freegle's spatial system. The **"travel-time
mapper"** half — isochrones, fairness, the Rippling Explorer — is a separate
service, [`iznik-routing-go`](../iznik-routing-go/README.md), which calls this one
for its nearby-freegler queries.

> **New here / not a developer?** Read the plain-English overview first:
> [`../SPATIAL-SERVERS.md`](../docs/developers/reference/spatial-servers.md).

---

## Datasets

The index is rebuilt from MySQL and kept in sync. Eight datasets are served:

| Name | Geometry | Source table | Spatial column | Sync |
|------|----------|--------------|----------------|------|
| `locations` | Polygon | `locations` (+ `locations_spatial`) | `geometry` | incremental on `locations.timestamp`; nightly full rebuild. Includes both areas and postcodes (`?type=Postcode` filters) |
| `messages` | Point | `messages_spatial` | `point` | incremental on `messages_spatial.modified`; nightly full rebuild |
| `newsfeed` | Point | `newsfeed` | `position` | incremental on `newsfeed.modified`; nightly full rebuild |
| `userapproxlocs` | Point | `users_approxlocs` | `position` | full rebuild every 15 min (no incremental) |
| `groups` | Polygon | `groups` | `polyindex` | full rebuild every 15 min (no incremental) |
| `jobs` | Polygon | `jobs` | `geometry` | incremental on `jobs.seenat`; nightly full rebuild |
| `reach` | Polygon (rasterised) | `rippling_reach` | `polygon` | incremental on `updated_at` every 2 min + reconcile; daily full rebuild. Answers `/containing`, not knn |
| `reachoverflow` | Polygon (rasterised) | `rippling_reach` | `overflow_bounds` JSON, one ring per lane | incremental on `updated_at` every 2 min + reconcile; daily full rebuild. Answers `/containing`, not knn. **Ids are packed**: `msgid << 4 \| lane code`, so one index answers a per-lane question — see `dataset_reachoverflow.go` |

Both rasterised datasets classify a query point from a 2-bit-per-cell grid over
each geometry's bbox, so only the thin boundary band needs the exact geometry.
`reach` uses a 96-cell grid; `reachoverflow` uses 192, because its exact
fallback is dear - a ring is ~37k vertices parsed out of JSON (~6ms), against a
sub-millisecond indexed lookup for a reach - so it is worth spending index size
to make the band fire half as often.

Indexes persist to disk as SQLite files under `SPATIAL_INDEX_DIR`, so a restart
reopens existing indexes instead of rebuilding from MySQL. Pass `-rebuild` to
force a full rebuild on startup.

---

## API endpoints

### Public API (`SPATIAL_PORT`, default `8194`)

No authentication — this port is only exposed inside the Docker network to
trusted callers (`iznik-routing-go`, `apiv2`, batch).

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/health` | `{"status":"ok"}` |
| `GET` | `/v1/datasets` | All datasets with name, record count, readiness |
| `GET` | `/v1/{dataset}/status` | Readiness, row count, last sync time for one dataset |
| `GET` | `/v1/{dataset}/knn?lat=&lng=&limit=&type=&polygon=` | Nearest records to a point. `limit` 1–1000 (default 1); optional `type` filter; optional WKT `polygon` to restrict results |
| `GET` | `/v1/{dataset}/containing?lat=&lng=` | Items whose geometry contains the point, as `{in, partial}`. Only `reach` and `reachoverflow` support it. `in` is definite; `partial` sits in the raster's boundary band and the caller must exact-test it against the source geometry |
| `GET` | `/v1/{dataset}/within?polygon=` | IDs of all records inside a WKT polygon. Max **10 000** IDs (HTTP 413 if exceeded) |
| `GET` | `/v1/{dataset}/within_coords?polygon=` | Like `/within` but returns full items **with coordinates** (no centre-distance bias). Use POST for large polygons |
| `POST` | `/v1/{dataset}/within_coords` | Same as GET, polygon in the request body — avoids URL-length limits for big isochrone polygons. Body may be raw WKT (`text/plain`) or `polygon=WKT` (`application/x-www-form-urlencoded`) |
| `GET` | `/swagger` | Browsable OpenAPI reference (Redoc). Raw spec at `/swagger/swagger.json` |

### Admin API (`SPATIAL_ADMIN_PORT`, default `8195`)

A separate listener for maintenance operations — keep it off the public network.

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/{dataset}/rebuild` | Trigger an async full rebuild of one dataset from MySQL (HTTP 409 if already rebuilding) |
| `POST` | `/v1/rebuild` | Trigger an async full rebuild of **every** dataset |
| `POST` | `/v1/{dataset}/remove` | Incremental hard-delete of specific record IDs. Body: `{"ids":[...]}` |

**Response shape:** all query endpoints return `{"results":[...]}` (KNN /
within_coords) or `{"ids":[...]}` (within). The legacy `{"locationid":N}` shape is
**deprecated**; callers should use `?limit=1` and read `results[0].id`.

**Polygon limits:** WKT polygons are capped at 100 KB and 10 000 vertices (HTTP
400 if exceeded).

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SPATIAL_PORT` | `8194` | Public (unauthenticated) query API port |
| `SPATIAL_ADMIN_PORT` | `8195` | Admin API port (rebuild / remove) |
| `SPATIAL_INDEX_DIR` | `/data` | Directory holding the persisted SQLite index files |
| `MYSQL_HOST` | `localhost` | MySQL host |
| `MYSQL_PORT` | `3306` | MySQL port |
| `MYSQL_USER` | `iznik` | MySQL username |
| `MYSQL_PASSWORD` | `iz` | MySQL password |
| `MYSQL_DBNAME` | `iznik` | Database name |

CLI flag: `-rebuild` forces a full rebuild from MySQL on startup (otherwise
existing on-disk indexes are reopened).

---

## Background sync (scheduler)

On startup the service opens (or builds) every index, then runs two background
schedules:

- **Nightly full rebuild** of all datasets at **03:00 UTC**.
- **Per-dataset delta sync** on each dataset's own interval — incremental for
  datasets with a "modified"/timestamp trigger; a periodic full rebuild (every
  **15 min**) for the rebuild-only datasets (`userapproxlocs`, `groups`).

`last_sync` for each dataset is visible via `GET /v1/{dataset}/status`.

---

## Who calls it

| Caller | Uses |
|--------|------|
| `iznik-routing-go` | `within_coords` to find freeglers inside an isochrone (`SPATIAL_KNN_URL`) |
| `iznik-batch` (PHP) | `PostcodeRemapService` resolves postcodes via `/v1/locations/knn?limit=1` |
| `apiv2` | nearest-location lookups |

---

## Docker Compose

Defined as `spatial-knn` (local dev) and `spatial-knn-live` (production DB via the
`prod-live` profile):

```yaml
spatial-knn:
  build: ./iznik-spatial-go
  environment:
    - SPATIAL_PORT=8194
    - SPATIAL_ADMIN_PORT=8195
    - SPATIAL_INDEX_DIR=/data
    - MYSQL_HOST=percona
  healthcheck:
    test: ["CMD-SHELL", "curl -f http://localhost:8194/health || exit 1"]
```

Traefik routes `spatial-knn.localhost` to the service. `iznik-routing-go` reaches
it at `http://spatial-knn:8194` via `SPATIAL_KNN_URL`.

---

## OpenAPI / Swagger

The spec is generated from annotations in [`doc.go`](doc.go) (go-swagger
`swagger:meta` / `swagger:route`), exactly as the v2 Go API does:

```bash
./generate-swagger.sh      # regenerates swagger/swagger.json from doc.go
```

The result is served at `/swagger` (Redoc UI) and `/swagger/swagger.json` (raw
spec). `swaggerdocs_test.go` checks the committed spec stays in sync with the
code.

---

## Tests

```bash
go test ./...
```

Covers the KNN index (`knn_test.go`, `index_test.go`), each dataset loader
(`dataset_test.go`), the HTTP handlers (`server_test.go`), the within-polygon cap
(`within_cap_test.go`), and the OpenAPI spec (`swaggerdocs_test.go`).
