# Extending connectivity to Scotland & Northern Ireland

Parent: [connectivity-friction-reach.md](connectivity-friction-reach.md) · Pipeline: `iznik-routing-go/data/build-connectivity/`

## Problem

`uk_lsoa_connectivity.csv` only covers England & Wales (DfT's Transport Connectivity Metric is
E&W-only, LSOA21CD-keyed). Scotland and NI nodes get `Conn=0` and silently fall back to a plain
isochrone (`connectivity.go:63-65` — that's a deliberate, documented gap, not a bug). This plan
adds plausible, real-data-backed scores for both nations on the **same 0-100 scale**, so reach
shaping is UK-wide.

## Recommendation

**Route 1 (real, comparable data) works for both nations** — no need to fall back to a
population-density proxy (route 2). Both Scotland and NI publish an official small-area
deprivation index with a **"Access/Proximity to Services" domain rank**, built the same way DfT's
metric conceptually is (drive/PT time to key services, aggregated to one score per area,
1..N ranked). Neither is DfT's *exact* methodology (see Caveats), but both are apples-to-apples
enough for a friction model that only needs relative geography, not absolute minutes.

| Nation | Source | Geography | N | Rank field |
|---|---|---|---|---|
| Scotland | SIMD 2020v2 | Data Zone 2011 | 6,976 | `SIMD2020_Access_Domain_Rank` |
| N. Ireland | NIMDM 2017 | SOA 2001 | 890 | `Access to Services Domain Rank` |

Both use DfT's convention already: **1 = worst-connected, N = best-connected** (SIMD: "1 is most
deprived"; NIMDM: same). No sign inversion needed anywhere in the pipeline.

## Data sources (all verified live 2026-07-01)

### Scotland

1. **Ranks** — SIMD 2020v2, ranks-and-domain-ranks spreadsheet, Scottish Government (official).
   - Landing page: <https://www.gov.scot/publications/scottish-index-of-multiple-deprivation-2020v2-ranks/>
   - Direct file (513 KB xlsx, confirmed HTTP 200):
     `https://www.gov.scot/binaries/content/documents/govscot/publications/statistics/2020/01/scottish-index-of-multiple-deprivation-2020-ranks-and-domain-ranks/documents/scottish-index-of-multiple-deprivation-2020-ranks-and-domain-ranks/scottish-index-of-multiple-deprivation-2020-ranks-and-domain-ranks/govscot%3Adocument/SIMD%2B2020v2%2B-%2Branks.xlsx`
   - Sheet `SIMD 2020v2 ranks`, columns `Data_Zone` (join key, e.g. `S01006506`) and
     `SIMD2020_Access_Domain_Rank` (1..6976). Verified: header + 6,976 data rows, no ties beyond
     the odd `.5` (standard rank-tie convention), no gaps.
   - Cross-check source (same numbers, official ScotGov ArcGIS, useful for a spot-check but no
     centroid): `https://maps.gov.scot/server/rest/services/ScotGov/PeopleSociety/MapServer/7`,
     field `gaccrank` — queried `datazone=S01006506` and got `gaccrank=4724`, matching the xlsx
     exactly.

2. **Data Zone 2011 centroids** — ArcGIS FeatureServer (verified, `returnGeometry` + `outSR=4326`
   gives lat/lng directly, no reprojection needed, mirrors the existing LSOA pattern):
   `https://services2.arcgis.com/Ne8d9gKn5SJ3eAaw/arcgis/rest/services/SG_DataZoneCent_2011_(1)/FeatureServer/0/query`
   — field `DataZone`, `count`=6,976 (exact match to the rank file). This is a third-party
   ArcGIS Online re-host of NRS's official "Data Zone Centroids 2011" dataset (catalogued at
   <https://www.data.gov.uk/dataset/8aabd120-6e15-41bf-be7c-2536cbc4b2e5/data-zone-centroids-2011>
   and <https://spatialdata.gov.scot/geonetwork/srv/api/records/7d3e8709-98fa-4d71-867c-d5c8293823f2>).
   No official ScotGov-domain centroid *service* exists (`maps.gov.scot/NRS/NRS` has locality/
   settlement centroids but not Data Zone) — if this FeatureServer ever disappears, fall back to
   downloading the official Data Zone 2011 boundary from spatialdata.gov.scot and computing
   centroids locally, same method as NI below.
   - Join key: `DataZone` code, both files use 2011 geography — **6,976/6,976 joined, 0 missing**
     (verified by prototype).

### Northern Ireland

1. **Ranks** — NIMDM 2017, SOA-level results, NISRA (official).
   - Landing page: <https://www.nisra.gov.uk/publications/nimdm17-soa-level-results>
   - Direct file (1.7 MB xls, confirmed HTTP 200):
     `https://www.nisra.gov.uk/files/nisra/publications/NIMDM17_SOAresults.xls`
   - Sheet `Access to Services`, columns `SOA2001` (join key, e.g. `95AA01S1`) and
     `Access to Services Domain Rank (where 1 is most deprived)` (1..890). Verified: 890 data
     rows, no gaps.

2. **SOA 2001 boundaries → centroids** — no ready-made centroid file exists for NI SOAs, so
   centroids must be computed from the official boundary polygons (OpenDataNI, official NISRA
   dataset, verified HTTP 200 via redirect to a signed download):
   `https://admin.opendatani.gov.uk/dataset/678697e1-ae71-41f3-abba-0ef5f3f352c2/resource/80392e82-8bee-42de-a1e3-82d1cbaa983f/download/soa2001.json`
   (GeoJSON, 890 features, property `SOA_CODE`, ~86 MB — well under the "no multi-GB" limit but
   large because it's full-resolution polygons, not simplified).
   - Geometry is in **Irish Grid / TM65 (EPSG:29902)** — no CRS tag in the GeoJSON, but the
     coordinate range (E 188,538–366,410 / N 309,895–453,200) is a positive ID against OSNI's
     standard grid, and reprojecting with EPSG:29902's Helmert parameters places every centroid
     inside NI's known bounding box.
   - Compute an **area-weighted polygon centroid** per feature (shoelace formula per ring, holes
     subtract automatically via signed area, summed across rings/multi-polygon parts), then
     reproject EPSG:29902 → EPSG:4326.
   - Join key: `SOA_CODE` / `SOA2001`, both 2001 geography — **890/890 joined, 0 missing**
     (verified by prototype).

## Normalisation: quantile mapping onto the DfT scale

A naive `percentile × 100` rescale would give Scotland/NI a roughly *uniform* 0-100 distribution,
which doesn't match DfT's actual shape (E&W: min 3, **median 67**, max 99 — skewed toward
well-connected, because most of England is at least moderately served). Route 2's suggested
"regress DfT overall on log(pop density)" is one way to reproduce that shape, but there's a more
direct method now that we have full-population **ranks** (not just a score) for both nations:

```
p = (rank - 0.5) / N                              // rank's percentile within its own nation
conn = DfT_scores_sorted[ round(p × (M-1)) ]       // same percentile in the E&W reference (M=35,672)
```

This is standard **quantile mapping** (histogram matching): it assumes a Scottish/NI zone at the
X-th percentile of *its own* access-rank distribution is comparable to an E&W LSOA at the X-th
percentile of DfT's *actual* score distribution, and copies that DfT value across. By
construction, the output lands on DfT's exact empirical shape — verified by the prototype below.
Implementation: `dftScores` = the sorted `conn` column already sitting in
`uk_lsoa_connectivity.csv` (no separate reference dataset to maintain).

## Prototype (run, verified — not committed)

Wrote and ran the fetch/parse/join/quantile-map chain in Node (matching the existing pipeline's
JS-not-Python convention) against the live sources above:

```
DfT reference: n=35672 min=3 median=67 max=99
Scotland: N=6976 joined=6976 missing_centroid=0
Scotland conn distribution: min=4 median=67 max=98
NI: N=890 joined=890 missing_centroid=0
NI conn distribution: min=6 median=67 max=98
wrote scotland_ni_connectivity.csv rows=7866
```

7,866 rows total (6,976 + 890) vs E&W's 35,672 — proportionate to population (Scotland+NI ≈
7.3M vs E&W ≈ 59M) even though the source zone systems are coarser (SIMD/NIMDM zones average
~700-900 people vs LSOA's ~1,500-3,000), which is fine for nearest-centroid node tagging at the
resolution the friction model needs.

## Plugging into `build-connectivity/`

Add two new scripts alongside `extract_lsoa.js` / `join_centroids.js`, plus one shared step, and
a new `build.sh` stage that **appends** rather than overwrites:

- `extract_scotland_simd.js` — reads the SIMD ranks xlsx and emits `datazone,access_rank` CSV.
  The prototype used the `xlsx` npm package for speed; to keep this directory dependency-free
  (matching `extract_lsoa.js`'s hand-rolled ODS/XML parser), reimplement as
  `unzip -p simd_ranks.xlsx xl/worksheets/sheet2.xml` + `xl/sharedStrings.xml` regex parsing —
  same shape as the existing ODS extractor, just XLSX's zip layout instead of ODS's.
- `fetch_scotland_centroids.js` — pages the ArcGIS FeatureServer above (near-identical to
  `join_centroids.js`'s pagination loop; different base URL/field name) → `datazone,lat,lng`.
- `extract_ni_nimdm.js` — same xlsx-without-a-library treatment for the NISRA `.xls` (legacy
  binary XLS, not XLSX — needs a different parser; simplest is to keep this one on the `xlsx` npm
  package rather than hand-rolling BIFF, and accept a single scoped `package.json` dependency in
  `build-connectivity/` only, since it never ships to the Go binary or the Nuxt app).
- `compute_ni_centroids.js` — downloads the OpenDataNI SOA2001 GeoJSON, computes area-weighted
  centroids (shoelace method, code already prototyped) and reprojects EPSG:29902→EPSG:4326. This
  needs a Helmert/Airy transverse-Mercator transform; take the small, well-audited `proj4` npm
  package (also scoped to `build-connectivity/`) rather than hand-rolling geodesy, since a subtly
  wrong transform silently mis-places every NI centroid with no error to catch it.
- `quantile_map.js` — shared by both nations: loads `../uk_lsoa_connectivity.csv` as the
  reference distribution, takes a `(code, rank, N)` stream + a `(code, lat, lng)` centroid stream,
  emits `lat,lng,conn` rows. Already prototyped and working (see above).
- `build.sh` gets a 4th stage: after installing the E&W CSV, run the Scotland and NI
  extract→fetch→quantile-map chains and **append** their rows to `../uk_lsoa_connectivity.csv`
  (not overwrite — E&W stays DfT-sourced, Scotland/NI rows are clearly quantile-mapped). Update
  the README's coverage note (currently "England & Wales only... that gap is intentional") to
  describe the new UK-wide coverage and its provenance, and add a release-log row.
- `connectivity.go` / `friction.go` need **no changes** — `LoadConnectivity`'s nearest-centroid
  lookup and the `Conn=0` fallback both already treat the CSV as opaque; Scotland/NI rows just
  fill in points that used to have no nearby centroid at all.

## Caveats (must go in the README)

- **Not DfT's methodology.** SIMD/NIMDM's Access domains are built from drive/PT time to a
  *different* basket of services (GP, school, post office, retail, broadband — not DfT's
  multi-modal journey-purpose matrix), on different-vintage geography (Data Zone 2011 / SOA
  2001, not LSOA 2021), from different-year data (SIMD 2020, NIMDM 2017 vs DfT's Q4 2024). These
  are **plausible, comparable** connectivity proxies for reach-shaping, not a re-derivation of
  the DfT metric — do not present Scotland/NI scores as "DfT scores" anywhere user-facing.
  Consider tagging the CSV rows with a source column if the model ever needs to explain itself
  per-nation (out of scope for this plan — current `Node.Conn` is a single scalar).
  NIMDM 2017 is also **stale relative to SIMD 2020** (NI's last published measure); if NISRA
  publishes a NIMDM 2027-ish update before DfT's next E&W release, refresh only that half.
  NI's 890 SOA2001 zones don't perfectly align with the mid-2020s population distribution — same
  caveat SIMD/NIMDM themselves carry; acceptable for reach-shaping, not for policy analysis.
- **Quantile mapping assumes distributional similarity, not identical construction.** It forces
  Scotland/NI onto DfT's exact shape by design — this is a feature (keeps `friction.go`'s
  `REF ≈ national midpoint` constant meaningful UK-wide) but means don't over-interpret small
  Scotland/NI score differences as precisely comparable to E&W ones at the DfT's original
  granularity.
- **Scotland centroid source is a third-party re-host** (see above) — durable so far (matches
  official record counts exactly) but not a ScotGov-domain URL; note the spatialdata.gov.scot
  fallback in the script's header comment, same as `join_centroids.js` already does for its
  ONS layer.
- **NI reprojection correctness** rests on the EPSG:29902 Helmert parameters
  (`towgs84=482.5,-130.6,564.6,-1.042,-0.214,-0.631,8.15`, Airy 1830 ellipsoid). Checked two
  ways: (1) all 890 computed centroids fall inside NI's known lat/lng envelope; (2) forward-
  transforming Belfast City Hall's published WGS84 (54.5966,-5.9301) gives Irish Grid
  E333835/N374015, ~100m from its commonly-published grid ref (J3374 7401 ≈ E333740/N374010) —
  within the precision of a 4-digit grid ref. Good enough for nearest-centroid tagging at the
  friction model's ~5km grid resolution (`connRes` in `connectivity.go`).
