# Building `uk_lsoa_connectivity.csv`

The connectivity-friction reach model tags each graph node with a DfT transport-connectivity
score (0-100). That score comes from `../uk_lsoa_connectivity.csv` (`lat,lng,conn`), one row
per England-&-Wales LSOA. This directory holds the reproducible pipeline that builds it, so we
can refresh it when DfT republishes and never lose *how* it was constructed.

## Sources

1. **DfT Transport Connectivity Metric** (the scores).
   - Landing page: <https://www.gov.uk/government/publications/transport-connectivity-metric>
   - Pinned 2025 release (experimental, Q4 2024 data, England & Wales):
     `connectivity_metrics_2025.ods`
     <https://assets.publishing.service.gov.uk/media/68c966fc07d9e92bc5517b80/connectivity_metrics_2025.ods>
   - It is a ~68 MB ODS whose `content.xml` decompresses to ~1 GB. We only read the `LSOA`
     sheet (`LSOA21CD` + 35 score columns); the **last column, "Overall"**, is the grand
     connectivity score we use. (OA/LSOA/LAD/RGN sheets all exist; LSOA is the sweet spot of
     granularity vs size.)

2. **ONS LSOA 2021 boundaries → centroids** (to place each LSOA on the map).
   - ONS Open Geography Portal, `LSOA_2021_EW_BSC_V4_RUC` FeatureServer.
   - We query with `returnCentroid=true&outSR=4326`, so ArcGIS returns each LSOA's centroid in
     WGS84 directly — no boundary download or projection. Geometric (not population-weighted)
     centroids are fine for nearest-centroid node tagging. Override the layer with
     `LSOA_ARCGIS_URL` if ONS renames it.

> Coverage: **England & Wales only.** Scotland/NI LSOAs are absent; nodes there get `Conn=0`
> and the model cleanly falls back to the plain isochrone. That gap is intentional and handled
> in code (`connectivity.go` / `friction.go`).

## Rebuild

```bash
cd iznik-routing-go/data/build-connectivity
./build.sh                       # downloads the pinned ODS, extracts, joins, installs
# or, with a manually downloaded file:
./build.sh /path/to/connectivity_metrics_2026.ods
```

Steps (what `build.sh` runs):
1. `unzip -p <ods> content.xml | node extract_lsoa.js lsoa_conn_codes.csv`
   → streams the LSOA sheet (O(n), never holds the 1 GB XML) → `lsoa21cd,overall`.
2. `node join_centroids.js lsoa_conn_codes.csv uk_lsoa_connectivity.csv`
   → paginates ONS centroids, joins → `lat,lng,conn`.
3. Copies the result to `../uk_lsoa_connectivity.csv` (committed, loaded via `CONNECTIVITY_CSV`).

## Updating for a new release

1. Find the new ODS on the DfT landing page above; note its date/version here.
2. Run `./build.sh /path/to/new.ods` (or update `ODS_URL` and run bare).
3. Sanity-check the distribution before committing (2025: n=35,672, min 3, median 67, max 99).
4. Commit the regenerated `../uk_lsoa_connectivity.csv` and update the reference date below.

## Release log

| Built | DfT release | LSOAs | conn median |
|-------|-------------|-------|-------------|
| 2026-07-01 | connectivity_metrics_2025 (Q4 2024, v1.0.0) | 35,672 | 67 |
