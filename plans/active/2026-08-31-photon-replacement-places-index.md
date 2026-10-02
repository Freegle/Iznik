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
| 1 | Recon: contracts, corpus, photon probes + photon 0.5.0 SOURCE review | ✅ | AddressType ranks, strict->lenient retry, zoom-decay bias all verified in source |
| 2 | Extraction CLI iznik-routing-go cmd/placesextract (TDD vs testdata/bristol.osm.pbf) | ✅ | b27875049; UK run 195,358 entries / 7.2MB / ~2.5min |
| 3 | Serving iznik-spatial-go: /api + dataset (TDD) | ✅ | f31792d42; container 236MB RSS incl. SQLite datasets |
| 4 | Local deploy: extractor on local PBF, rebuild spatial-knn, smoke | ✅ | Kendal/West Midlands answers match photon incl. extents |
| 5 | Parity replay via tunnel + divergence report | ✅ | 5 runs drove 4 fix rounds; definitive: regions 82.4% / towns 86.5% / boxes 85.9% agree; report artifact 1d14d3ca-c733-4c78-a5a3-2555c84d4f39 |
| 6 | Docs: spatial-servers.md, ops pages, geocoder-cutover runbook, READMEs | ✅ | 7c46ef073; freshness OK; orb untouched (suites unchanged) |
| 7 | Full go suites via status API; PR | ✅ | routing 217 / spatial 144; **PR #1460 open**, body current; humans merge; cutover = Edward |
| 8 | Perf/occupancy review (Edward) | ✅ | index 142->127MB despite 1.8x entries; container 251MB; worst query 101->19ms; extractor peak 1.6GB w/ 17M coords, 4 procs |

## Constraints

- Never run tests on the prod host; prod nginx/container changes are operator steps.
- Both repos' suites run via status API (routing + spatial suites).
- Corpus/scratch: /tmp/claude-1000/-home-edward-FreegleDockerWSL/b0d20900-579e-48c3-a651-85f20786e0b6/scratchpad/photon/
