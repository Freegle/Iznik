#!/usr/bin/env node
// scotland_append.js — add Scotland to uk_lsoa_connectivity.csv.
//
// Scotland has no DfT connectivity metric, but SIMD 2020v2 publishes a "Geographic Access
// to Services" domain rank per Data Zone 2011 (1 = worst-connected .. 6976 = best) — the same
// rank convention DfT uses. We quantile-map that rank onto the E&W DfT connectivity
// distribution so the scores share one 0-100 scale (see README "Caveats": comparable proxy,
// not DfT's exact methodology).
//
// Sources (both ONS/ScotGov ArcGIS, verified):
//   ranks:     ScotGov PeopleSociety MapServer/7 field `gaccrank` (= SIMD2020 Access rank)
//   centroids: SG_DataZoneCent_2011 FeatureServer (point geometry, outSR=4326)
//
// Usage:  node scotland_append.js <ew_reference.csv> <out_scotland_rows.csv>
//   ew_reference.csv = the E&W uk_lsoa_connectivity.csv (used only as the quantile reference)
//   writes lat,lng,conn rows (NO header) for Scotland to out.
const fs = require('fs');

const refPath = process.argv[2] || '../uk_lsoa_connectivity.csv';
const outPath = process.argv[3] || 'scotland_rows.csv';

const RANKS = 'https://maps.gov.scot/server/rest/services/ScotGov/PeopleSociety/MapServer/7/query';
const CENTS = 'https://services2.arcgis.com/Ne8d9gKn5SJ3eAaw/arcgis/rest/services/SG_DataZoneCent_2011_(1)/FeatureServer/0/query';

async function page(base, extra, offset) {
  const qs = new URLSearchParams({ where: '1=1', outSR: '4326', resultOffset: String(offset),
    resultRecordCount: '2000', f: 'json', ...extra });
  for (let a = 0; a < 4; a++) {
    try {
      const r = await fetch(`${base}?${qs}`, { signal: AbortSignal.timeout(45000) });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return await r.json();
    } catch (e) { if (a === 3) throw e; await new Promise((s) => setTimeout(s, 1500 * (a + 1))); }
  }
}

async function fetchAll(base, extra, take) {
  const map = new Map();
  let offset = 0;
  for (;;) {
    const j = await page(base, extra, offset);
    const feats = j.features || [];
    for (const f of feats) take(f, map);
    console.error(`  ${base.split('/').slice(-3, -1).join('/')} offset ${offset}: +${feats.length} (total ${map.size})`);
    if (!j.exceededTransferLimit || feats.length === 0) break;
    offset += feats.length;
  }
  return map;
}

(async () => {
  // E&W reference distribution (sorted conn values) for quantile mapping.
  const ew = fs.readFileSync(refPath, 'utf8').split('\n').slice(1)
    .map((l) => parseInt((l.split(',')[2] || '').trim(), 10)).filter((x) => !isNaN(x)).sort((a, b) => a - b);
  console.error('E&W reference rows:', ew.length);

  console.error('Fetching Scottish Data Zone access ranks…');
  const ranks = await fetchAll(RANKS, { outFields: 'datazone,gaccrank', returnGeometry: 'false' },
    (f, m) => { const a = f.attributes; if (a && a.datazone && a.gaccrank != null) m.set(a.datazone, a.gaccrank); });

  console.error('Fetching Scottish Data Zone centroids…');
  const cents = await fetchAll(CENTS, { outFields: 'DataZone', returnGeometry: 'true' },
    (f, m) => { const a = f.attributes, g = f.geometry; if (a && a.DataZone && g) m.set(a.DataZone, [g.y, g.x]); });

  const N = ranks.size;
  const out = fs.createWriteStream(outPath);
  let n = 0, miss = 0;
  const vals = [];
  for (const [dz, rank] of ranks) {
    const ll = cents.get(dz);
    if (!ll) { miss++; continue; }
    const p = (rank - 0.5) / N;                       // percentile (rank 1 = worst-connected)
    const idx = Math.max(0, Math.min(ew.length - 1, Math.floor(p * ew.length)));
    const conn = ew[idx];
    out.write(`${ll[0].toFixed(6)},${ll[1].toFixed(6)},${conn}\n`);
    vals.push(conn); n++;
  }
  out.end(() => {
    vals.sort((a, b) => a - b);
    console.error(`Scotland: N=${N} joined=${n} missing_centroid=${miss} → conn min=${vals[0]} median=${vals[vals.length >> 1]} max=${vals[vals.length - 1]}`);
  });
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
