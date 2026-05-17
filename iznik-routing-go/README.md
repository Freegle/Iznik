# iznik-routing-go

Go HTTP service that builds a walk/cycle/drive routing graph from an OSM PBF file and serves isochrone polygons with optional deprivation-fairness adjustment.

## What it does

1. **Loads an OSM PBF file** into a compact in-memory graph (CSR edge storage, uint32 node IDs, float32 coordinates).
2. **Runs Dijkstra** from a query point to find all nodes reachable within a time budget.
3. **Rasterises** the reachable nodes onto a grid and traces polygon boundaries.
4. **Fairness adjustment** (optional): extends the time budget for nodes in deprived LSOA quintiles so that offers can reach more deprived communities even when they are further away.
5. **Serves results** as GeoJSON over HTTP.

## API

| Endpoint | Description |
|---|---|
| `GET /health` | Liveness check — returns node count and deprivation status |
| `GET /v1/isochrone?lat=&lng=&minutes=` | Walk/cycle/drive isochrone polygons |
| `GET /v1/fairness?lat=&lng=&minutes=&mode=&fairness=` | Fairness-adjusted isochrone |
| `GET /demo` | Interactive map UI |

### Fairness algorithm

`fairness` is a weight W ∈ [0, 1]:
- W = 0: identical to the standard isochrone
- W = 1: Q1 (most deprived) nodes get 2× the base time budget; Q5 (least deprived) get 1×

The multiplier scales linearly: `multiplier(q, W) = 1 + W × (5 − q) / 4`

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `OSM_PBF_PATH` | *(required)* | Path to the OSM PBF file |
| `DEPRIVATION_CSV` | *(empty)* | Path to LSOA centroid CSV (`lat,lng,quintile`) |
| `ROUTING_PORT` | `8196` | HTTP listen port |

## Memory usage

| Dataset | Nodes | Edges | Approx. RSS |
|---|---|---|---|
| Bristol (test) | ~50k | ~120k | ~50 MB |
| Great Britain | ~57M | ~116M | ~4.5 GB |

The compact representation uses:
- Sequential uint32 node IDs (vs int64 OSM IDs)
- float32 lat/lng (11m precision — adequate for routing)
- CSR edge storage (flat array + offset array vs per-node slice headers)

## Deprivation data

The `DEPRIVATION_CSV` file must have a header row and three columns: `lat,lng,quintile`.

To generate `data/england_lsoa_quintile.csv` for England:

1. Download LSOA centroids from the ONS ArcGIS API (paginated):
   ```python
   import requests, pandas as pd
   
   url = "https://services1.arcgis.com/ESMARspQHYMw9BZ9/arcgis/rest/services/LSOA_Dec_2011_PWC_in_England_and_Wales_2022/FeatureServer/0/query"
   rows, offset = [], 0
   while True:
       r = requests.get(url, params={"where":"1=1","outFields":"LSOA11CD,X,Y","outSR":"4326","f":"json","resultOffset":offset,"resultRecordCount":2000}).json()
       rows += [(f["attributes"]["LSOA11CD"], f["attributes"]["Y"], f["attributes"]["X"]) for f in r["features"]]
       if not r.get("exceededTransferLimit"): break
       offset += 2000
   centroids = pd.DataFrame(rows, columns=["lsoa11cd","lat","lng"])
   ```

2. Join with mySociety UK_IMD quintiles:
   ```python
   imd = pd.read_csv("UK_IMD_E.csv")[["Code","E_expanded_decile"]]
   imd["quintile"] = ((imd.E_expanded_decile - 1) // 2 + 1).clip(1, 5)
   df = centroids.merge(imd, left_on="lsoa11cd", right_on="Code")
   df[["lat","lng","quintile"]].to_csv("data/england_lsoa_quintile.csv", index=False)
   ```

## Docker

```yaml
routing-server:
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
