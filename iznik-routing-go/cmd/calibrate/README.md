# calibrate — fitting the drive-time model against Google ground truth

The routing server's drive-time model (per-class speed factors, junction
penalties, startup overhead — see the calibration comment in `../../graph.go`)
was fitted in August 2026 against ~2,500 Google Routes API journeys sampled
across the whole UK.  This directory holds everything needed to reproduce or
re-run that calibration if road conditions or OSM data shift materially.

## What lives where

- `main.go` — the harness.  Builds a drive-only graph from the pbf that keeps
  per-edge road class, maxspeed-taggedness, signals, crossings, roundabouts
  and way-based junctions; routes origin–destination pairs with A*;
  decomposes each route into per-feature free-flow seconds; fits factors and
  penalties by weighted least squares; re-routes and repeats (route choice
  depends on the parameters being fitted, so it must iterate to a fixed
  point).
- `scripts/sampler.js` — stratified pair sampler (population-weighted,
  area-uniform, city cores, London, sparse rural, estuary crossings), fed by
  a `postcodes.tsv` export of the dev `locations` table.  Deterministic
  (seeded PRNG), 30% holdout marked up front.
- `scripts/pairs-2026-08.json.gz` — the exact 2,542 pairs used for the 2026-08
  calibration, so a future run can compare like-for-like.
- `scripts/collect.js` — Google Routes API collector.  Traffic-aware
  (Tuesday 10:30 departure) plus `staticDuration` in the same call; enforces a
  hard spend cap via a local ledger file.  Google response data must NOT be
  committed to the repo (API terms restrict storage); keep it local.
- `scripts/analyze.js` — joins routed output with the Google ground truth and
  reports error by stratum / density / trip-length / route-divergence, plus
  the most misleading examples.

## Recalibration procedure

1. Export postcodes: `SELECT name,lat,lng FROM locations WHERE
   type='Postcode' AND name LIKE '% %'` → `postcodes.tsv`.
2. `node scripts/sampler.js` → `pairs.json` (or reuse
   `scripts/pairs-2026-08.json.gz` for comparability).
3. `node scripts/collect.js pilot traffic` then `main traffic` — needs a
   Google API key with the Routes API enabled; watch the ledger.
   Cost at 2026 prices: ~$10/1000 traffic-aware calls.
4. Baseline: `go run ./cmd/calibrate -pbf data/uk-latest.osm.pbf -pairs
   pairs.json -google google-results.jsonl -mode route -out baseline.json`
   (default params = the shipped model) and `node scripts/analyze.js
   baseline.json`.
5. Fit: `-mode fit -iters 5 -weightfloor 480 -out fit.json` (the floor stops
   a handful of very short trips dominating the intercept-like coefficients
   via the 1/y^2 relative weighting; holdout metrics are insensitive to it
   but the fitted c0 is much more stable).  Check the per-iteration log:
   factors should settle by iteration 3.  Check per-feature time mass before
   trusting a fitted value (the harness's `speed_secs` decomposition): a
   factor carrying <1% of drive time is noise — pin it rather than fit it.
6. Verify end-to-end: run the real server (`SPATIAL_INTERNAL_PORT=18194
   SPATIAL_PORT=18196 OSM_PBF_PATH=... ./spatial-server`) and evaluate the
   pairs through `/v1/ripple-eval` (`points_only: true`; note the endpoint
   clamps `max_minutes` above 120 back to 30).  The server's numbers should
   match the harness's fit within a percent or two.
7. Transcribe the fitted values into `graph.go` (`driveClassFactors`,
   `drivePenalties`, `driveStartupSecs`), update the calibration comment and
   `drive_factor_test.go`, and update
   `docs/developers/reference/spatial-servers.md` if the accuracy story
   changes.

## Known, deliberate divergences from Google

- Toll crossings (Mersey tunnels, Dartford, Humber, Tyne, M6 Toll) are
  excluded from car routing on purpose — nobody pays a toll to collect a free
  item — so estimates near those crossings assume the long way round.  The
  harness excludes such topology-divergent pairs from FITTING but keeps them
  in reported metrics.
- Ferries are not modelled; islands are unreachable by road, by design.
- `track` ways are drivable in the harness's class grouping but not in the
  production graph; the service/track factor is dominated by `service` mass.
