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

> Coverage: **England & Wales (DfT), plus Scotland (SIMD)**. Northern Ireland is still absent
> (nodes there get `Conn=0` → plain-isochrone fallback, handled in `connectivity.go`/`friction.go`).
>
> **Scotland** has no DfT metric, so `scotland_append.js` uses the **SIMD 2020v2 "Geographic
> Access to Services" domain rank** (Data Zone 2011, same "1=worst..N=best" convention as DfT),
> **quantile-mapped onto the E&W DfT distribution** so both share one 0-100 scale. This is a
> *comparable proxy, not DfT's methodology* (different service basket, geography vintage and
> year) — do not present Scotland scores as "DfT scores" user-facing. Sources: access rank from
> ScotGov PeopleSociety `MapServer/7` (`gaccrank`); centroids from the `SG_DataZoneCent_2011`
> FeatureServer.
>
> **Northern Ireland** is designed + agent-verified (NIMDM 2017 Access domain, SOA 2001, with a
> proj4 EPSG:29902→WGS84 reprojection) but not yet wired in — see
> `plans/active/scotland-ni-connectivity.md`. It needs a scoped `proj4` dependency in this
> directory, hence deferred.

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

| Built | Source | Areas | conn median |
|-------|--------|-------|-------------|
| 2026-07-01 | DfT connectivity_metrics_2025 (Q4 2024, v1.0.0) — E&W | 35,672 | 67 |
| 2026-07-01 | + Scotland SIMD 2020v2 Access (quantile-mapped) | 6,976 | 67 |
| _pending_ | + NI NIMDM 2017 Access (quantile-mapped) | 890 | — |
