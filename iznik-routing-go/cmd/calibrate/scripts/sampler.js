#!/usr/bin/env node
// Stratified UK origin-destination pair sampler for travel-time calibration.
// Frame: postcodes.tsv (name, lat, lng) - public postcode centroids.
// Output: pairs.json - [{id, stratum, holdout, pilot, o:{pc,lat,lng}, d:{pc,lat,lng}, crow_km, density}]
'use strict';
const fs = require('fs');

// ---------- deterministic PRNG ----------
function mulberry32(a) {
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rand = mulberry32(20260814);

// ---------- load frame ----------
const rows = fs.readFileSync('postcodes.tsv', 'utf8').split('\n');
const pcs = [];
for (const line of rows) {
  if (!line) continue;
  const [name, lat, lng] = line.split('\t');
  const la = +lat, lo = +lng;
  if (!isFinite(la) || !isFinite(lo) || la === 0) continue;
  pcs.push({ pc: name, lat: la, lng: lo });
}
console.error(`loaded ${pcs.length} postcodes`);

// ---------- spatial indexes ----------
// fine grid 0.01 deg for nearest lookup; mid grid 0.05 for density; coarse 0.4x0.6 for area-uniform
const fine = new Map(), mid = new Map(), coarse = new Map();
const fk = (la, lo) => `${Math.floor(la / 0.01)}:${Math.floor(lo / 0.01)}`;
const mk = (la, lo) => `${Math.floor(la / 0.05)}:${Math.floor(lo / 0.05)}`;
const ck = (la, lo) => `${Math.floor(la / 0.4)}:${Math.floor(lo / 0.6)}`;
for (const p of pcs) {
  for (const [m, key] of [[fine, fk(p.lat, p.lng)], [mid, mk(p.lat, p.lng)], [coarse, ck(p.lat, p.lng)]]) {
    let a = m.get(key); if (!a) { a = []; m.set(key, a); } a.push(p);
  }
}

const R = 6371;
function hav(aLat, aLng, bLat, bLng) {
  const dLa = (bLat - aLat) * Math.PI / 180, dLo = (bLng - aLng) * Math.PI / 180;
  const s = Math.sin(dLa / 2) ** 2 + Math.cos(aLat * Math.PI / 180) * Math.cos(bLat * Math.PI / 180) * Math.sin(dLo / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(s));
}

// nearest postcode to a point within maxKm (searches expanding fine-grid rings)
function nearest(la, lo, maxKm) {
  const ci = Math.floor(la / 0.01), cj = Math.floor(lo / 0.01);
  const cellsRadius = Math.ceil(maxKm / 1.1 / 1) + 1; // 0.01 deg lat ~1.1km
  let best = null, bestD = maxKm;
  for (let r = 0; r <= cellsRadius; r++) {
    if (best && r > Math.ceil(bestD / 1.1) + 1) break;
    for (let i = ci - r; i <= ci + r; i++) {
      for (let j = cj - r; j <= cj + r; j++) {
        if (Math.max(Math.abs(i - ci), Math.abs(j - cj)) !== r) continue;
        const a = fine.get(`${i}:${j}`); if (!a) continue;
        for (const p of a) {
          const d = hav(la, lo, p.lat, p.lng);
          if (d < bestD) { bestD = d; best = p; }
        }
      }
    }
  }
  return best;
}

// density = postcodes within the 3x3 mid-grid neighborhood (~15x11km) -> band
function densityAt(la, lo) {
  const i = Math.floor(la / 0.05), j = Math.floor(lo / 0.05);
  let n = 0;
  for (let a = i - 1; a <= i + 1; a++) for (let b = j - 1; b <= j + 1; b++) {
    const c = mid.get(`${a}:${b}`); if (c) n += c.length;
  }
  return n;
}
function densityBand(n) { return n >= 8000 ? 'dense' : n >= 1500 ? 'medium' : 'sparse'; }

function destAt(o, dKm) {
  for (let t = 0; t < 15; t++) {
    const brg = rand() * 2 * Math.PI;
    const dLat = (dKm / 111.2) * Math.cos(brg);
    const dLng = (dKm / (111.2 * Math.cos(o.lat * Math.PI / 180))) * Math.sin(brg);
    const tol = Math.min(Math.max(0.15 * dKm, 0.6), 3.0);
    const p = nearest(o.lat + dLat, o.lng + dLng, tol);
    if (p && p.pc !== o.pc) {
      const d = hav(o.lat, o.lng, p.lat, p.lng);
      if (d >= 0.8) return p;
    }
  }
  return null;
}

// distance bands (km) + weights: matched to Freegle collection trips (median taker drive ~14min)
const BANDS = [[1, 2.5, 0.10], [2.5, 5, 0.20], [5, 9, 0.24], [9, 15, 0.20], [15, 25, 0.16], [25, 45, 0.10]];
function drawDist() {
  let u = rand();
  for (const [lo, hi, w] of BANDS) { if (u < w) return lo + rand() * (hi - lo); u -= w; }
  return 5 + rand() * 4;
}

const pairs = [];
let idc = 0;
function addPair(stratum, o, d) {
  if (!o || !d) return false;
  const crow = hav(o.lat, o.lng, d.lat, d.lng);
  if (crow < 0.8 || crow > 80) return false;
  pairs.push({
    id: ++idc, stratum,
    o: { pc: o.pc, lat: o.lat, lng: o.lng },
    d: { pc: d.pc, lat: d.lat, lng: d.lng },
    crow_km: Math.round(crow * 100) / 100,
    density: densityBand(densityAt(o.lat, o.lng)),
  });
  return true;
}

// ---- stratum: main_pop (population-proportional: uniform over postcodes) ----
let made = 0, tries = 0;
while (made < 1400 && tries < 40000) {
  tries++;
  const o = pcs[Math.floor(rand() * pcs.length)];
  if (addPair('main_pop', o, destAt(o, drawDist()))) made++;
}
console.error(`main_pop: ${made}`);

// ---- stratum: main_area (uniform over coarse cells) ----
const cells = [...coarse.values()].filter(c => c.length >= 5);
made = 0; tries = 0;
while (made < 500 && tries < 30000) {
  tries++;
  const cell = cells[Math.floor(rand() * cells.length)];
  const o = cell[Math.floor(rand() * cell.length)];
  if (addPair('main_area', o, destAt(o, drawDist()))) made++;
}
console.error(`main_area: ${made}`);

// ---- stratum: city_core ----
const CITIES = [
  [52.480, -1.902], [53.480, -2.242], [53.796, -1.548], [55.861, -4.250], [55.953, -3.188],
  [51.454, -2.588], [53.408, -2.980], [54.978, -1.617], [53.381, -1.470], [51.481, -3.179],
  [52.954, -1.150], [52.636, -1.133], [54.597, -5.930], [50.905, -1.404], [57.149, -2.094],
  [50.376, -4.143], [52.628, 1.297],
];
made = 0; tries = 0;
while (made < 250 && tries < 15000) {
  tries++;
  const c = CITIES[Math.floor(rand() * CITIES.length)];
  const o = nearest(c[0] + (rand() - 0.5) * 0.05, c[1] + (rand() - 0.5) * 0.08, 3);
  if (o && addPair('city_core', o, destAt(o, 2 + rand() * 8))) made++;
}
console.error(`city_core: ${made}`);

// ---- stratum: london ----
made = 0; tries = 0;
while (made < 150 && tries < 10000) {
  tries++;
  const la = 51.35 + rand() * 0.30, lo = -0.45 + rand() * 0.60;
  const o = nearest(la, lo, 2.5);
  if (o && addPair('london', o, destAt(o, 2 + rand() * 16))) made++;
}
console.error(`london: ${made}`);

// ---- stratum: rural_sparse (coarse cells with few postcodes) ----
const sparseCells = [...coarse.values()].filter(c => c.length >= 5 && c.length <= 900);
made = 0; tries = 0;
while (made < 200 && tries < 25000) {
  tries++;
  const cell = sparseCells[Math.floor(rand() * sparseCells.length)];
  const o = cell[Math.floor(rand() * cell.length)];
  if (addPair('rural_sparse', o, destAt(o, 8 + rand() * 37))) made++;
}
console.error(`rural_sparse: ${made} (from ${sparseCells.length} sparse cells)`);

// ---- stratum: estuary (hand-picked crossings; snap ends to postcodes) ----
const EST = [
  ['mersey', 53.408, -2.992, 53.393, -3.014], ['dartford', 51.446, 0.219, 51.481, 0.237],
  ['gravesend-tilbury', 51.442, 0.368, 51.462, 0.358], ['severn', 51.641, -2.675, 51.560, -2.635],
  ['humber', 53.744, -0.333, 53.689, -0.439], ['forth', 55.990, -3.398, 56.009, -3.396],
  ['tay', 56.462, -2.970, 56.441, -2.938], ['tyne', 55.010, -1.447, 55.004, -1.432],
  ['soton-hythe', 50.883, -1.394, 50.869, -1.399], ['stour-orwell', 51.961, 1.351, 51.945, 1.288],
  ['cardiff-penarth', 51.465, -3.166, 51.437, -3.173], ['wyre', 53.925, -3.011, 53.928, -2.996],
  ['exe', 50.620, -3.413, 50.628, -3.446], ['portsmouth-gosport', 50.796, -1.106, 50.795, -1.129],
];
made = 0;
for (const [name, aLa, aLo, bLa, bLo] of EST) {
  for (let v = 0; v < 3; v++) {
    const j = () => (rand() - 0.5) * (v === 0 ? 0 : 0.02);
    const o = nearest(aLa + j(), aLo + j(), 2.5);
    const d = nearest(bLa + j(), bLo + j(), 2.5);
    if (o && d && o.pc !== d.pc) {
      const crow = hav(o.lat, o.lng, d.lat, d.lng);
      if (crow >= 0.6 && crow <= 80) {
        pairs.push({
          id: ++idc, stratum: 'estuary:' + name,
          o: { pc: o.pc, lat: o.lat, lng: o.lng }, d: { pc: d.pc, lat: d.lat, lng: d.lng },
          crow_km: Math.round(crow * 100) / 100, density: densityBand(densityAt(o.lat, o.lng)),
        });
        made++;
      }
    }
  }
}
console.error(`estuary: ${made}`);

// ---- pilot + holdout flags (deterministic) ----
// shuffle deterministically, take stratified ~400 for pilot
const byStratum = new Map();
for (const p of pairs) {
  const key = p.stratum.startsWith('estuary') ? 'estuary' : p.stratum;
  let a = byStratum.get(key); if (!a) { a = []; byStratum.set(key, a); } a.push(p);
}
const PILOT_FRAC = 400 / pairs.length;
for (const [, arr] of byStratum) {
  for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(rand() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]]; }
  const n = Math.max(1, Math.round(arr.length * PILOT_FRAC));
  arr.forEach((p, i) => { p.pilot = i < n; });
}
// holdout: 30%, never used for fitting (pilot rows can be holdout too - pilot is about
// WHEN they're collected, holdout about what they're used for)
for (const p of pairs) p.holdout = (rand() < 0.30);

const nPilot = pairs.filter(p => p.pilot).length, nHold = pairs.filter(p => p.holdout).length;
console.error(`total ${pairs.length} pairs, pilot ${nPilot}, holdout ${nHold}`);
const summary = {};
for (const p of pairs) {
  const key = p.stratum.startsWith('estuary') ? 'estuary' : p.stratum;
  summary[key] = (summary[key] || 0) + 1;
}
console.error(JSON.stringify(summary));
fs.writeFileSync('pairs.json', JSON.stringify(pairs));
console.error('wrote pairs.json');
