# Replace Photon geocoder with a Go places index

2026-08-31. Brief from Edward (session "Photon"): replace the Photon+ES JVMs on the prod FD host
(3.6GB RSS, 6.2GB disk) with a places index folded into the existing Go spatial servers.
Photon-compatible `/api` (forward geocode only, UK only), consumers byte-unchanged.
Parity replay via SSH tunnel against live photon before any cutover. Cutover itself is a human
step after eyeballing the divergence report.

## Measured contract (verified 2026-08-31 against live photon + consumer code)

Request surface (from 5 days of geocode_access.log, 85,573 distinct GETs):
- `q` + `bbox=swlng,swlat,nelng,nelat` always (bbox may contain spaces after commas).
- `layer=city&layer=locality&layer=district` on 67,664 (WhatJobs town lookups).
- `lat`/`lon`/`zoom` on map searches (leaflet-control-geocoder Photon class sends map centre).
- 7,481 queries have ", county" context tails (WhatJobs "Kenwyn, Cornwall" fallback).
- OPTIONS preflights answered by photon itself: `Access-Control-Allow-Origin: *`,
  `Access-Control-Allow-Headers: *`, `Access-Control-Allow-Methods: GET`. Must reproduce.
- Nothing calls /reverse.

Response contract consumed:
- WhatJobsService (iznik-batch, geocodeAddress): iterates features; first `properties.extent`
  wins, order [west, NORTH, east, SOUTH]; else geometry.coordinates [lng,lat] with
  case-insensitive `properties.name` equality gate when exact. Prior prod incident on extent
  order — get this right.
- PlaceAutocomplete.vue: top 5 features; extent same order; display string from
  name/street/suburb/hamlet/town/city props; `osm_id` used as key.
- PostMap/DraggableMap (leaflet Photon class): extent → bbox, else point; same props.
- ModGroupMap.vue: features[0] geometry.coordinates only.

Photon layer mapping OBSERVED (probe 2026-08-31, differs from brief's guess):
- place city/town/village → type "city" (village is NOT locality!)
- suburb/neighbourhood → "district"; hamlet → "district" or "locality" (rank noise)
- locality/isolated_dwelling → "locality"
- place=county → "county", place=state → "state", island → "other"
- boundary=administrative: LADs (adm 8) → "city", unitaries/counties (adm 6) → "county"
- boundary=ceremonial ("Devon"!) and boundary=statistical ("West Midlands", "East of England",
  the top WhatJobs region queries) → "other". These MUST be indexed.
- Photon merges place nodes with same-named boundary relations (Kendal = R8292370 with extent).
  WhatJobs town extents depend on this → implement the merge.

## Design

- Extraction CLI `cmd/placesextract` in iznik-routing-go (owns PBF): 3-pass scan of
  uk-latest.osm.pbf → places.jsonl.gz artifact. place=* nodes/ways/relations +
  boundary=administrative/ceremonial/statistical relations (admin_level 4-10).
  Extents = bbox of member nodes. County/state context via point-in-bbox+area against
  admin 4/5/6 (approximate containment; smallest area wins).
- Serving in iznik-spatial-go (spatial-knn container): new file-backed places dataset +
  GET /api. Loads only if file present (db-node instances skip harmlessly). In-memory
  token-prefix + trigram fuzzy, rank by layer weight/population/exact-name/proximity.
- Parity harness: scripts replay corpus against tunnel (photon) + local Go, compare top
  result name/centroid<2km/extent IoU; report by category.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Recon: contracts, corpus, photon probes | ✅ | above |
| 2 | Extraction CLI iznik-routing-go cmd/placesextract (TDD vs testdata/bristol.osm.pbf) | ⬜ | |
| 3 | Serving iznik-spatial-go: /api + dataset (TDD) | ⬜ | |
| 4 | Local deploy: run extractor on local PBF, rebuild spatial-knn, smoke | ⬜ | |
| 5 | Parity replay via tunnel + divergence report | ⬜ | corpus at scratchpad/photon/corpus-raw.txt |
| 6 | Docs spatial-servers.md + covers entries; orb if suites change | ⬜ | |
| 7 | Full go suites via status API; PR | ⬜ | humans merge; cutover = Edward |

## Constraints

- Never run tests on the prod host; prod nginx/container changes are operator steps.
- Both repos' suites run via status API (routing + spatial suites).
- Corpus/scratch: /tmp/claude-1000/-home-edward-FreegleDockerWSL/b0d20900-579e-48c3-a651-85f20786e0b6/scratchpad/photon/
