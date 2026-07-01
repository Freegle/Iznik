#!/usr/bin/env node
// ni_append.js — add Northern Ireland to uk_lsoa_connectivity.csv.
//
// NI has no DfT connectivity metric, but NIMDM 2017 publishes an "Access to Services" domain
// rank per SOA 2001 (1 = worst-connected .. 890 = best) — the same rank convention DfT/SIMD
// use. We quantile-map that rank onto the E&W DfT distribution (Scotland was mapped onto the
// same reference — see scotland_append.js) so all UK scores share one 0-100 scale (see README
// "Caveats": comparable proxy, not DfT's exact methodology).
//
// Unlike Scotland, NI has no ready-made centroid service, so we download NISRA's official
// SOA2001 boundary GeoJSON (OpenDataNI, Irish Grid / TM65 EPSG:29902, no CRS tag in the file)
// and compute an area-weighted centroid per polygon ourselves (shoelace method — holes
// subtract automatically via signed-area sign), then reproject to WGS84 with proj4.
//
// Sources (both NISRA, verified — see plans/active/scotland-ni-connectivity.md):
//   ranks:    https://www.nisra.gov.uk/files/nisra/publications/NIMDM17_SOAresults.xls
//             sheet "Access to Services", cols SOA2001 + "Access to Services Domain Rank
//             (where 1 is most deprived)". Legacy binary .xls -> needs the `xlsx` package
//             (extract_lsoa.js's hand-rolled XML approach doesn't apply to BIFF).
//   boundary: https://admin.opendatani.gov.uk/dataset/678697e1-ae71-41f3-abba-0ef5f3f352c2/
//             resource/80392e82-8bee-42de-a1e3-82d1cbaa983f/download/soa2001.json
//             (~86MB full-resolution polygons, property SOA_CODE). Downloaded to /tmp, not
//             kept in the worktree.
//
// Deps (scoped to this directory, see package.json / .gitignore — not committed):
//   npm install proj4 xlsx
//
// Usage:  node ni_append.js <ew_reference.csv> <out_ni_rows.csv>
//   ew_reference.csv = uk_lsoa_connectivity.csv. We use only its first 35,672 rows (E&W) as
//                       the quantile reference, matching what Scotland was mapped onto — later
//                       appended nations (Scotland, this NI run) must NOT feed back into the
//                       reference or repeated rebuilds would drift.
//   writes lat,lng,conn rows (NO header) for NI to out.
const fs = require('fs');
const os = require('os');
const path = require('path');
const XLSX = require('xlsx');
const proj4 = require('proj4');

const EW_ROWS = 35672; // committed E&W row count (see README release log) — the quantile reference.
const RANKS_URL = 'https://www.nisra.gov.uk/files/nisra/publications/NIMDM17_SOAresults.xls';
const BOUNDARY_URL = 'https://admin.opendatani.gov.uk/dataset/678697e1-ae71-41f3-abba-0ef5f3f352c2/' +
  'resource/80392e82-8bee-42de-a1e3-82d1cbaa983f/download/soa2001.json';

// EPSG:29902 = TM65 / Irish Grid (OSNI's grid for NI boundary data).
const IRISH_GRID = '+proj=tmerc +lat_0=53.5 +lon_0=-8 +k=1.000035 +x_0=200000 +y_0=250000 ' +
  '+ellps=airy +towgs84=482.5,-130.6,564.6,-1.042,-0.214,-0.631,8.15 +units=m +no_defs';

const refPath = process.argv[2] || '../uk_lsoa_connectivity.csv';
const outPath = process.argv[3] || 'ni_rows.csv';

async function download(url, dest) {
  for (let a = 0; a < 4; a++) {
    try {
      const r = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0' }, signal: AbortSignal.timeout(120000) });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      fs.writeFileSync(dest, Buffer.from(await r.arrayBuffer()));
      return;
    } catch (e) { if (a === 3) throw e; await new Promise((s) => setTimeout(s, 1500 * (a + 1))); }
  }
}

// Ranks: NIMDM17 "Access to Services" sheet -> Map(soaCode -> rank).
function loadRanks(xlsPath) {
  const wb = XLSX.readFile(xlsPath);
  const ws = wb.Sheets['Access to Services'];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1 });
  const header = rows[0];
  const codeIdx = header.indexOf('SOA2001');
  const rankIdx = header.findIndex((h) => typeof h === 'string' && h.startsWith('Access to Services Domain Rank'));
  const ranks = new Map();
  for (let i = 1; i < rows.length; i++) {
    const r = rows[i];
    if (r[codeIdx] && r[rankIdx] != null) ranks.set(r[codeIdx], r[rankIdx]);
  }
  return ranks;
}

// Area-weighted polygon centroid (shoelace per ring; holes cancel via signed-area sign).
function ringSignedArea(ring) {
  let a = 0;
  for (let i = 0; i < ring.length - 1; i++) {
    const [x1, y1] = ring[i], [x2, y2] = ring[i + 1];
    a += x1 * y2 - x2 * y1;
  }
  return a / 2;
}
function ringCentroid(ring) {
  const a = ringSignedArea(ring);
  if (a === 0) return { cx: ring[0][0], cy: ring[0][1], a: 0 };
  let cx = 0, cy = 0;
  for (let i = 0; i < ring.length - 1; i++) {
    const [x1, y1] = ring[i], [x2, y2] = ring[i + 1];
    const cross = x1 * y2 - x2 * y1;
    cx += (x1 + x2) * cross;
    cy += (y1 + y2) * cross;
  }
  return { cx: cx / (6 * a), cy: cy / (6 * a), a };
}
function featureCentroid(geom) {
  const polys = geom.type === 'Polygon' ? [geom.coordinates] : geom.coordinates;
  let sumCx = 0, sumCy = 0, sumA = 0;
  for (const rings of polys) {
    for (const ring of rings) {
      const { cx, cy, a } = ringCentroid(ring);
      sumCx += cx * Math.abs(a);
      sumCy += cy * Math.abs(a);
      sumA += Math.abs(a);
    }
  }
  return [sumCx / sumA, sumCy / sumA];
}

// Boundary GeoJSON -> Map(soaCode -> [lat,lng]), reprojected EPSG:29902 -> WGS84.
function loadCentroids(geojsonPath) {
  const j = JSON.parse(fs.readFileSync(geojsonPath, 'utf8'));
  const centroids = new Map();
  for (const f of j.features) {
    const code = f.properties && f.properties.SOA_CODE;
    if (!code) continue;
    const [x, y] = featureCentroid(f.geometry);
    const [lng, lat] = proj4(IRISH_GRID, proj4.WGS84, [x, y]);
    centroids.set(code, [lat, lng]);
  }
  return centroids;
}

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ni-conn-'));
  const xlsPath = path.join(tmp, 'NIMDM17_SOAresults.xls');
  const geojsonPath = path.join(tmp, 'soa2001.json');

  console.error('Downloading NIMDM 2017 SOA results…');
  await download(RANKS_URL, xlsPath);
  console.error('Downloading OpenDataNI SOA2001 boundary (~86MB) to', geojsonPath, '…');
  await download(BOUNDARY_URL, geojsonPath);

  // E&W-only reference distribution (first EW_ROWS data rows) for quantile mapping — must NOT
  // include Scotland's already-appended rows, or repeated rebuilds would drift the reference.
  const ew = fs.readFileSync(refPath, 'utf8').split('\n').slice(1, 1 + EW_ROWS)
    .map((l) => parseInt((l.split(',')[2] || '').trim(), 10)).filter((x) => !isNaN(x)).sort((a, b) => a - b);
  console.error('E&W reference rows:', ew.length);
  if (ew.length !== EW_ROWS) {
    console.error(`WARNING: expected ${EW_ROWS} E&W reference rows, got ${ew.length} — check EW_ROWS is still correct.`);
  }

  console.error('Parsing NIMDM Access to Services ranks…');
  const ranks = loadRanks(xlsPath);
  console.error('Computing SOA2001 centroids (area-weighted, EPSG:29902 -> WGS84)…');
  const centroids = loadCentroids(geojsonPath);

  const N = ranks.size;
  const out = fs.createWriteStream(outPath);
  let n = 0, miss = 0;
  const vals = [];
  for (const [soa, rank] of ranks) {
    const ll = centroids.get(soa);
    if (!ll) { miss++; continue; }
    const p = (rank - 0.5) / N; // percentile (rank 1 = worst-connected)
    const idx = Math.max(0, Math.min(ew.length - 1, Math.floor(p * ew.length)));
    const conn = ew[idx];
    out.write(`${ll[0].toFixed(6)},${ll[1].toFixed(6)},${conn}\n`);
    vals.push(conn); n++;
  }
  out.end(() => {
    vals.sort((a, b) => a - b);
    console.error(`NI: N=${N} joined=${n} missing_centroid=${miss} → conn min=${vals[0]} median=${vals[vals.length >> 1]} max=${vals[vals.length - 1]}`);
    fs.rmSync(tmp, { recursive: true, force: true });
  });
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
