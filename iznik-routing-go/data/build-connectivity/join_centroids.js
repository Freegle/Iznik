#!/usr/bin/env node
// join_centroids.js — join DfT LSOA connectivity (lsoa21cd,overall) with ONS LSOA 2021
// centroids (from the ONS Open Geography ArcGIS FeatureServer, via returnCentroid) to
// produce the routing server's connectivity CSV: `lat,lng,conn`.
//
// Usage:  node join_centroids.js lsoa_conn_codes.csv uk_lsoa_connectivity.csv
//
// Centroids come from LSOA_2021_EW_BSC (boundary super-generalised clipped); we request
// returnCentroid=true so ArcGIS returns each LSOA's centroid in WGS84 — no boundary
// geometry download or projection needed. Geometric (not population-weighted) centroids
// are plenty accurate for the friction model's ~nearest-centroid node tagging.
const fs = require('fs');

const inPath = process.argv[2] || 'lsoa_conn_codes.csv';
const outPath = process.argv[3] || 'uk_lsoa_connectivity.csv';

// ONS Open Geography Portal — LSOA (2021) EW boundaries. If ONS retires/renames this
// layer, find the current "LSOA 2021 EW" boundary FeatureServer on the portal and update.
const BASE = process.env.LSOA_ARCGIS_URL ||
  'https://services1.arcgis.com/ESMARspQHYMw9BZ9/ArcGIS/rest/services/' +
  'LSOA_2021_EW_BSC_V4_RUC/FeatureServer/0/query';

const conn = new Map();
for (const line of fs.readFileSync(inPath, 'utf8').split('\n').slice(1)) {
  const [code, ov] = line.split(',');
  if (code && ov) conn.set(code.trim(), parseInt(ov, 10));
}
console.error('connectivity codes:', conn.size);

async function fetchPage(offset) {
  const qs = new URLSearchParams({
    where: '1=1', outFields: 'LSOA21CD', returnCentroid: 'true', returnGeometry: 'false',
    outSR: '4326', resultOffset: String(offset), resultRecordCount: '2000', f: 'json',
  });
  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      const r = await fetch(`${BASE}?${qs}`, { signal: AbortSignal.timeout(45000) });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return await r.json();
    } catch (e) {
      if (attempt === 3) throw e;
      await new Promise((res) => setTimeout(res, 1500 * (attempt + 1)));
    }
  }
}

(async () => {
  const centroids = new Map();
  let offset = 0;
  for (;;) {
    const j = await fetchPage(offset);
    const feats = j.features || [];
    for (const f of feats) {
      const code = f.attributes && f.attributes.LSOA21CD;
      if (code && f.centroid) centroids.set(code, [f.centroid.y, f.centroid.x]); // lat,lng
    }
    console.error(`offset ${offset}: +${feats.length} (total ${centroids.size})`);
    if (!j.exceededTransferLimit || feats.length === 0) break;
    offset += feats.length;
  }

  const out = fs.createWriteStream(outPath);
  out.write('lat,lng,conn\n');
  let joined = 0, missing = 0;
  for (const [code, cv] of conn) {
    const ll = centroids.get(code);
    if (!ll) { missing++; continue; }
    out.write(`${ll[0].toFixed(6)},${ll[1].toFixed(6)},${cv}\n`);
    joined++;
  }
  out.end(() => console.error(`joined=${joined} missing_centroid=${missing} centroids=${centroids.size}`));
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
