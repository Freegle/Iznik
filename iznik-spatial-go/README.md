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

The index is rebuilt from MySQL and kept in sync. Six datasets are served:

| Name | Geometry | Source table | Spatial column | Sync |
|------|----------|--------------|----------------|------|
| `locations` | Polygon | `locations` (+ `locations_spatial`) | `geometry` | incremental on `locations.timestamp`; nightly full rebuild. Includes both areas and postcodes (`?type=Postcode` filters) |
| `messages` | Point | `messages_spatial` | `point` | incremental on `messages_spatial.modified`; nightly full rebuild |
| `newsfeed` | Point | `newsfeed` | `position` | incremental on `newsfeed.modified`; nightly full rebuild |
| `userapproxlocs` | Point | `users_approxlocs` | `position` | full rebuild every 15 min (no incremental) |
| `groups` | Polygon | `groups` | `polyindex` | full rebuild every 15 min (no incremental) |
| `jobs` | Polygon | `jobs` | `geometry` | incremental on `jobs.seenat`; nightly full rebuild |

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
| `GET` | `/v1/{dataset}/knn?lat=&lng=&limit=&type=&polygon=` | Nearest records to a point. `limit` 1–1000 (default 1); optional `type` filter; optional WKT `polygon` to restrict results |
| `GET` | `/v1/{dataset}/containing?lng=&lat=` | Reach raster containment: which live reaches cover the point (`reach` dataset only) |
| `POST` | `/v1/{dataset}/within_coords` | Full items **with coordinates** inside a WKT polygon (no centre-distance bias). Polygon in the request body avoids URL-length limits for big isochrone polygons. Body may be raw WKT (`text/plain`) or `polygon=WKT` (`application/x-www-form-urlencoded`) |
| `GET` | `/swagger` | Browsable OpenAPI reference (Redoc). Raw spec at `/swagger/swagger.json` |

### Admin API (`SPATIAL_ADMIN_PORT`, default `8195`)

A separate listener for maintenance operations — keep it off the public network.

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/{dataset}/rebuild` | Trigger an async full rebuild of one dataset from MySQL (HTTP 409 if already rebuilding) |
| `POST` | `/v1/{dataset}/remove` | Incremental hard-delete of specific record IDs. Body: `{"ids":[...]}` |

**Response shape:** all query endpoints return `{"results":[...]}`. The legacy
`{"locationid":N}` shape is **deprecated**; callers should use `?limit=1` and
read `results[0].id`.

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

Sync state is logged at startup and on each rebuild/delta cycle.

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
