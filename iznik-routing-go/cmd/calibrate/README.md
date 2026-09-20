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

## Measured and rejected

Candidate features tested against the same ground truth and NOT shipped, so
they are not re-litigated from scratch (all tested 2026-08 on the 2,530-journey
sample with a paired per-route holdout comparison):

- **lit=yes as an urban proxy for unsigned local roads** (fitted 0.78 vs the
  0.81 residential class factor - nearly identical) and **way sinuosity >=
  1.35 derating for unsigned rural roads** (fitted 1.30 vs 1.42-1.68 class
  factors, correctly signed): combined mass only 1.4% of drive time, and on
  the routes actually carrying that mass the paired holdout comparison was
  exactly 34 better / 34 worse - a coin flip.  The aggregate median gain came
  from coefficient reshuffling, not from the features.
- **Splitting the signal penalty into isolated vs coordinated** (another
  signal within 300m, a green-wave proxy): the fit finds a strikingly
  plausible split - isolated signals ~23s, coordinated corridor signals ~8s,
  where the shipped blanket value is 8.7s - but the paired holdout comparison
  is 257 better / 267 worse overall and only 96/81 on signal-heavy routes,
  below any shipping bar.  Worth revisiting with a bigger sample or once a
  learned correction layer exists; the coefficient split itself is the most
  promising unshipped signal found this round.
- **A class-relative single-track factor** (one factor multiplying each way's
  own class base): rejected in favour of the shipped fixed-base design - one
  factor across bases from 4.2 to 26.8 m/s meant 8 km/h on service roads,
  which corrupted route choice (a Kyle of Lochalsh pair went from 10 to 29
  minutes against Google's 6.5).

## Known, deliberate divergences from Google

- Toll crossings (Mersey tunnels, Dartford, Humber, Tyne, M6 Toll) are
  excluded from car routing on purpose — nobody pays a toll to collect a free
  item — so estimates near those crossings assume the long way round.  The
  harness excludes such topology-divergent pairs from FITTING but keeps them
  in reported metrics.
- Ferries are not modelled; islands are unreachable by road, by design.
- `track` ways are drivable in the harness's class grouping but not in the
  production graph; the service/track factor is dominated by `service` mass.
