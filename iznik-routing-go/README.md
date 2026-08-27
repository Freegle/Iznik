# Freegle Spatial Server (`iznik-routing-go`)

A single Go HTTP server providing geographic intelligence for Freegle. It exposes **two listeners** with identical endpoints but different authentication:

| Port | Auth | Purpose |
|------|------|---------|
| **8194** (internal) | None | Trusted backend services (apiv2, batch) within the Docker network |
| **8196** (external) | JWT (moderators only) | Browser clients — powers the modtools Rippling Explorer |

This means the same spatial capabilities (isochrones, nearby freeglers, group boundaries) are available to both internal services and authenticated moderators, without duplicating code or deploying two containers.

> This is the **"travel-time mapper"** half of Freegle's spatial system. The
> **"finder"** half — the nearest-neighbour / within-area index it calls for
> nearby-freegler queries — is a separate service,
> [`iznik-spatial-go`](../iznik-spatial-go/README.md).
>
> **New here / not a developer?** Read the plain-English overview first:
> [`../SPATIAL-SERVERS.md`](../docs/developers/reference/spatial-servers.md).

---

## API endpoints

All endpoints are available on both ports. On the external port (8196) every `/v1/*` endpoint requires a valid JWT passed as `?jwt=<token>` or an `Authorization` header; `/health` and `/demo` are always open.

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Node count, deprivation status — `{"status":"ok","nodes":N,"deprivation":"loaded"}` |
| `GET /demo` | Interactive Leaflet demo UI |
| `GET /v1/isochrone?lat=&lng=&minutes=&mode=` | Walk/cycle/drive isochrone polygon (GeoJSON) |
| `GET /v1/fairness?lat=&lng=&minutes=&mode=&fairness=` | Fairness-adjusted isochrone (see below) |
| `GET /v1/nearby-freeglers?lat=&lng=` | Approximate freegler locations within the isochrone around a point (avoids the centre-distance bias of a plain KNN query) |
| `GET /v1/groups/nearby?lat=&lng=` | Freegle group boundaries near a point (GeoJSON FeatureCollection) |
| `GET /v1/ripple-schedule?lat=&lng=&mode=&ticks=&max_minutes=` | The "rippling out" schedule: the expanding sequence of reachable areas over time used to introduce a post gradually. Steps are smaller in dense regions and larger across empty voids. Powers the Rippling Explorer |
| `POST /v1/ripple-eval` | Evaluate a rippling scenario for a post (JSON body) — who would be reached at each tick |
| `GET /v1/reach-labels?lat=&lng=&minutes=` | Reach engine: compute a post's per-region reach labels (returns the stored-form bytes, base64). 503 until `STAGE2_DIR` is configured |
| `POST /v1/reach-arrival` | Reach engine: exact arrival seconds + in-reach flag for up to 1000 points, evaluated from label bytes (JSON body `{labels, points[]}`) |
| `POST /v1/drive-metrics` | Reach engine: road drive minutes AND road miles from one origin to up to 1000 targets in a single call (one labeling query + table lookups). Powers the site's road-distance display via apiv2 `/drivedistance` |
| `GET /v1/blur?lat=&lng=&metres=` | Road-aware location blur: a deterministic pseudo-random road node within [R/2, 3R/2] ROAD metres of the input — never jumps an unbridged river the way circular blur can. Engine-independent (works without `STAGE2_DIR`) |
| `GET /v1/posts-for-member?lat=&lng=&date=&max_minutes=` | The posts a member at this location would be shown (`date` defaults to today, `max_minutes` to 30) |
| `GET /v1/digest-simulator?lat=&lng=&max_minutes=&w_closeness=&w_freshness=&w_budget=&w_anchor=&cap=&group_by_poster=` | Simulate a member's ranked digest with tunable scoring weights and a result cap |
| `GET /swagger` | Browsable OpenAPI reference (Redoc). Raw spec at `/swagger/swagger.json` |

See [the rippling algorithm reference](../docs/developers/reference/rippling-algorithm.md) for the thinking behind the ripple / digest endpoints.

---

## Reach engine

Reach (the growing drive-time area of a post) can be computed once and stored as
a few KB of per-region labels instead of being re-searched on every expansion
tick. Every arrival the engine computes carries road METRES alongside seconds
(verified UK-wide: 5.1M checks), which is what lets the site show "miles by
road" instead of crow-flies. The road graph is contracted to junctions, cut into regions along its
natural narrow seams by a maximum-flow partitioner, and membership is answered
exactly from labels + per-region tables. Plain-English walkthrough, measurements
and verification: [REACH-ENGINE.md](REACH-ENGINE.md). Offline tooling:
`go run . stage2 <build|partition|matrices|query|parity|sweep>`.

---

## Fairness algorithm

`fairness` is a weight W ∈ [0, 1]:
- W = 0: identical to the standard isochrone
- W = 1: Q1 (most deprived) nodes get 2× the base time budget; Q5 (least deprived) get 1×

Multiplier: `1 + W × (5 − q) / 4` where q is the IMD quintile (1 = most deprived).

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OSM_PBF_PATH` | *(required)* | Path to the OSM PBF file |
| `DEPRIVATION_CSV` | *(empty)* | Path to LSOA centroid CSV (`lat,lng,quintile`) |
| `SPATIAL_PORT` | `8196` | External (authenticated) port |
| `SPATIAL_INTERNAL_PORT` | `8194` | Internal (unauthenticated) port |
| `SPATIAL_KNN_URL` | `http://localhost:8194` | URL of the `iznik-spatial-go` KNN index service |
| `STAGE2_DIR` | *(empty)* | Reach-engine artifact directory (`graph.snap` + `partition.snap` + `matrices.snap`). When set, the server boots from artifacts in seconds instead of rebuilding from the PBF, and the reach endpoints go live; missing partition/matrices are derived at boot (~3min UK) and saved back. See [REACH-ENGINE.md](REACH-ENGINE.md) |
| `ROUTING_DRIVE_SPEED_FACTOR` | `1.0` | Global multiplier on drive speeds, applied at graph **build** time (e.g. `0.7` ≈ OSRM, `0.6` ≈ Google with UK traffic). Changing it takes effect on the next graph build/restart |
| `JWT_SECRET` | *(required for external port)* | HMAC secret for JWT validation |
| `MYSQL_HOST` | `localhost` | MySQL host (for group boundaries and session validation) |
| `MYSQL_PORT` | `3306` | MySQL port |
| `MYSQL_USER` | `root` | MySQL username |
| `MYSQL_PASSWORD` | *(empty)* | MySQL password |
| `MYSQL_DBNAME` | `iznik` | Database name |

---

## Memory usage

| Dataset | Nodes | Edges | Approx. RSS |
|---------|-------|-------|-------------|
| Bristol (test) | ~160k | ~330k | ~50 MB |
| Great Britain | ~57M | ~116M | ~4.5 GB |
| England + Wales | ~45M | ~90M | ~4 GB |

The server starts accepting HTTP on both ports only after the graph is fully loaded (~2–5 minutes for England).

---

## Docker Compose

In `docker-compose.yml`, two variants are defined:

```yaml
# Local dev — uses local percona DB
spatial:
  build: ./iznik-routing-go
  environment:
    SPATIAL_PORT: "8196"
    SPATIAL_INTERNAL_PORT: "8194"
    SPATIAL_KNN_URL: http://spatial-knn:8194
    OSM_PBF_PATH: /data/england-latest.osm.pbf
    DEPRIVATION_CSV: /data/uk_lsoa_quintile.csv
    MYSQL_HOST: percona
    JWT_SECRET: secret
  volumes:
    - ./iznik-routing-go/data:/data:ro

# Live — connects to production DB via SSH tunnel
spatial-live:
  build: ./iznik-routing-go
  profiles: [prod-live]
  extra_hosts:
    - "db-live:host-gateway"
  environment:
    SPATIAL_PORT: "8196"
    SPATIAL_INTERNAL_PORT: "8194"
    MYSQL_HOST: db-live
    MYSQL_PORT: "${LIVE_DB_PORT}"
    MYSQL_USER: "${LIVE_DB_USER}"
    MYSQL_PASSWORD: "${LIVE_DB_PASSWORD}"
    JWT_SECRET: "${JWT_SECRET}"
```

Traefik routes `spatial.localhost` and `spatial-live.localhost` to port 8196 (external/authenticated). Internal services call `spatial:8194` directly (no auth).

---

## Related: KNN index service (`iznik-spatial-go`)

The `iznik-spatial-go` service (`spatial-knn` in Docker Compose) maintains KNN / within-area indexes over six Freegle datasets (locations, groups, messages, newsfeed, jobs, userapproxlocs). This server calls it via `SPATIAL_KNN_URL` for nearby-freegler queries. It runs on port 8194 internally with no authentication. See [`iznik-spatial-go/README.md`](../iznik-spatial-go/README.md).

---

## Generating deprivation data

The `DEPRIVATION_CSV` file needs a header row and columns `lat,lng,quintile`. To generate for England:

**Step 1 — LSOA centroids** (ONS ArcGIS API):
```python
import requests, pandas as pd

url = "https://services1.arcgis.com/ESMARspQHYMw9BZ9/arcgis/rest/services/LSOA_Dec_2011_PWC_in_England_and_Wales_2022/FeatureServer/0/query"
rows, offset = [], 0
while True:
    r = requests.get(url, params={
        "where": "1=1", "outFields": "LSOA11CD,X,Y",
        "outSR": "4326", "f": "json",
        "resultOffset": offset, "resultRecordCount": 2000
    }).json()
    rows += [(f["attributes"]["LSOA11CD"], f["attributes"]["Y"], f["attributes"]["X"])
             for f in r["features"]]
    if not r.get("exceededTransferLimit"):
        break
    offset += 2000
centroids = pd.DataFrame(rows, columns=["lsoa11cd", "lat", "lng"])
```

**Step 2 — Join with mySociety UK IMD quintiles**:
```python
imd = pd.read_csv("UK_IMD_E.csv")[["Code", "E_expanded_decile"]]
imd["quintile"] = ((imd.E_expanded_decile - 1) // 2 + 1).clip(1, 5)
df = centroids.merge(imd, left_on="lsoa11cd", right_on="Code")
df[["lat", "lng", "quintile"]].to_csv("data/england_lsoa_quintile.csv", index=False)
```

Download `UK_IMD_E.csv` from [mySociety UK IMD](https://github.com/mysociety/composite_uk_imd).
