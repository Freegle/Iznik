# Freegle Spatial Services

Two Go HTTP microservices that provide geographic intelligence for Freegle:

| Service | Directory | Port | Purpose |
|---------|-----------|------|---------|
| **KNN server** | `iznik-server-go/knn-server/` | 8194 | Find nearest Freegle location area for a lat/lng |
| **Isochrone server** | `iznik-routing-go/` | 8196 | Walk/cycle/drive isochrones with deprivation-fairness adjustment |

---

## Multi-Dataset Spatial Server (`iznik-spatial-go`)

A general spatial lookup service. Given a lat/lng (and optionally an isochrone polygon), returns the nearest items from any of six datasets — all backed by R-tree indexes built from MySQL.

### Datasets

| Dataset | Type | Sync | Content |
|---------|------|------|---------|
| `locations` | Polygon | 15 min rebuild | Freegle location areas — returns smallest enclosing area ID |
| `groups` | Polygon | 15 min rebuild | Freegle groups with their geographic boundaries |
| `messages` | Point | 2 min delta | Active offer/wanted posts from `messages_spatial`; extra: `msgtype`, `groupid`, `promised` |
| `newsfeed` | Point | 2 min delta | Newsfeed items with location |
| `jobs` | Point | 5 min delta | Nearby jobs; extra: `title`, `city`, `cpc` |
| `userapproxlocs` | Point | 15 min rebuild | Approximate user locations for nearby-user queries |

### API (public port 8194)

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Liveness check |
| `GET /v1/datasets` | List all datasets with last-sync status |
| `GET /v1/:dataset/knn?lat=&lng=&limit=` | Nearest N items; returns `{"results":[{"id":N,"extra":{...}}]}` |
| `GET /v1/:dataset/within?polygon=<WKT>` | All items within a polygon (e.g. an isochrone) |
| `GET /v1/:dataset/status` | Per-dataset index size and sync times |
| `GET /openapi.yaml` | OpenAPI 3.0 specification |

**Backwards-compatible:** `GET /knn?lat=&lng=` (no version) returns `{"locationid":N}` using the `locations` dataset.

### API (admin port 8195, not exposed externally)

| Endpoint | Description |
|----------|-------------|
| `POST /v1/:dataset/rebuild` | Force rebuild of one dataset |
| `POST /v1/rebuild` | Force rebuild of all datasets |
| `POST /v1/:dataset/remove` | Remove a specific item from the index |

### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `MYSQL_HOST` | `localhost` | MySQL host |
| `MYSQL_PORT` | `3306` | MySQL port |
| `MYSQL_USER` | `iznik` | MySQL username |
| `MYSQL_PASSWORD` | `iz` | MySQL password |
| `MYSQL_DBNAME` | `iznik` | Database name |
| `SPATIAL_INDEX_DIR` | `/data` | Directory for per-dataset SQLite index files |
| `SPATIAL_PORT` | `8194` | Public API port |
| `SPATIAL_ADMIN_PORT` | `8195` | Admin port (not exposed externally) |

### Docker

```yaml
spatial-server:
  build: ./iznik-spatial-go
  environment:
    MYSQL_HOST: db
    MYSQL_USER: iznik
    MYSQL_PASSWORD: iz
    MYSQL_DBNAME: iznik
    SPATIAL_INDEX_DIR: /data
  volumes:
    - spatial_data:/data
  ports:
    - "8194:8194"
```

---

## Isochrone / Fairness Server

Answers the question: *"Where can people walk/cycle/drive from this location within N minutes, and how does deprivation affect that reach?"*

Loads an OSM PBF file into a compact in-memory routing graph (CSR format, uint32 node IDs, float32 coordinates). Runs Dijkstra from a query point to find reachable nodes, then traces polygon boundaries. Optionally extends time budgets for deprived LSOA quintiles to improve access for disadvantaged communities.

### API

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Node count and deprivation status |
| `GET /v1/isochrone?lat=&lng=&minutes=` | Walk/cycle/drive isochrone polygons |
| `GET /v1/fairness?lat=&lng=&minutes=&mode=&fairness=` | Fairness-adjusted isochrone |
| `GET /demo` | Interactive map UI |

### Fairness algorithm

`fairness` is a weight W ∈ [0, 1]:
- W = 0: identical to the standard isochrone
- W = 1: Q1 (most deprived) nodes get 2× the base time budget; Q5 (least deprived) get 1×

Multiplier: `1 + W × (5 − q) / 4` where q is the IMD quintile (1 = most deprived).

### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OSM_PBF_PATH` | *(required)* | Path to the OSM PBF file |
| `DEPRIVATION_CSV` | *(empty)* | Path to LSOA centroid CSV (`lat,lng,quintile`) |
| `ROUTING_PORT` | `8196` | HTTP listen port |

### Memory usage

| Dataset | Nodes | Edges | Approx. RSS |
|---------|-------|-------|-------------|
| Bristol (test) | ~160k | ~330k | ~50 MB |
| Great Britain | ~57M | ~116M | ~4.5 GB |
| UK (inc. NI) | ~60M | ~120M | ~5 GB |

The server only starts accepting HTTP requests after the graph is fully loaded. Loading the full UK dataset takes 3–5 minutes.

The compact representation uses:
- Sequential uint32 node IDs (vs int64 OSM IDs)
- float32 lat/lng (11m precision — adequate for routing)
- CSR edge storage (flat array + offset array vs per-node slice headers)

### Demo

Open `http://localhost:8196/demo` for an interactive Leaflet map. Search any UK postcode or click the map to draw isochrones. Adjust the time budget and fairness weight sliders. Deprivation quintile colours use the standard RdYlGn scale (red = most deprived, green = least deprived).

Note: the demo deprivation data supplied in `testdata/bristol_lsoa.csv` covers Bristol only. Areas outside Bristol will show the standard isochrone only with a note that no deprivation index is available. For full UK coverage, generate `data/england_lsoa_quintile.csv` — see below.

### Generating deprivation data

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

### Docker

```yaml
spatial-server:
  build: ./iznik-routing-go
  environment:
    OSM_PBF_PATH: /data/great-britain-latest.osm.pbf
    DEPRIVATION_CSV: /data/england_lsoa_quintile.csv
    ROUTING_PORT: "8196"
  volumes:
    - ./data:/data:ro
  ports:
    - "8196:8196"
```
