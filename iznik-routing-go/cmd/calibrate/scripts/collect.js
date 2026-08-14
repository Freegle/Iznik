#!/usr/bin/env node
// Collect Google Routes API ground truth for sampled O-D pairs.
// Usage: node collect.js <pilot|main|all> <traffic|static> [maxCalls]
// - Appends to google-results.jsonl (resume-safe: skips ids already collected for that flavour).
// - Enforces a HARD combined spend cap via google-spend.json.
//   Pricing worst case (no free tier): Essentials (static) $5/1000, Pro (traffic-aware) $10/1000.
//   Cap: projected total <= $37 (~GBP 28.5), refuses to start a call beyond it.
'use strict';
const fs = require('fs');

const MODE = process.argv[2] || 'pilot';
const FLAVOUR = process.argv[3] || 'traffic';
const MAX_CALLS = +(process.argv[4] || 1e9);
const CAP_USD = 37.0;
const DEPARTURE = '2026-08-18T09:30:00Z'; // Tuesday 10:30 UK (BST)

const envText = fs.readFileSync('/home/edward/FreegleDockerWSL/.env', 'utf8');
const KEY = (envText.match(/^GOOGLE_PUSH_KEY=(.*)$/m) || [])[1];
if (!KEY) { console.error('no key'); process.exit(1); }

const pairs = JSON.parse(fs.readFileSync('pairs.json', 'utf8'));
let targets = pairs;
if (MODE === 'pilot') targets = pairs.filter(p => p.pilot);
if (MODE === 'main') targets = pairs.filter(p => !p.pilot);

const RESULTS = 'google-results.jsonl';
const done = new Set();
if (fs.existsSync(RESULTS)) {
  for (const line of fs.readFileSync(RESULTS, 'utf8').split('\n')) {
    if (!line) continue;
    try { const r = JSON.parse(line); done.add(r.id + ':' + r.flavour); } catch {}
  }
}
targets = targets.filter(p => !done.has(p.id + ':' + FLAVOUR)).slice(0, MAX_CALLS);
console.error(`${targets.length} pairs to collect (${MODE}, ${FLAVOUR}); ${done.size} already done`);

const LEDGER = 'google-spend.json';
const ledger = fs.existsSync(LEDGER) ? JSON.parse(fs.readFileSync(LEDGER, 'utf8')) : { essentials: 0, pro: 0 };
function spentUSD(l) { return l.pro * 0.01 + l.essentials * 0.005; }
const projected = spentUSD(ledger) + targets.length * (FLAVOUR === 'traffic' ? 0.01 : 0.005);
if (projected > CAP_USD) {
  console.error(`REFUSED: projected spend $${projected.toFixed(2)} > cap $${CAP_USD}`);
  process.exit(2);
}

let inFlightCalls = 0, ok = 0, failed = 0;
function bumpLedger() {
  if (spentUSD(ledger) + 0.01 > CAP_USD) { // hard stop even mid-run (retries etc.)
    console.error(`HARD CAP HIT at $${spentUSD(ledger).toFixed(2)} - aborting`);
    process.exit(3);
  }
  if (FLAVOUR === 'traffic') ledger.pro++; else ledger.essentials++;
  fs.writeFileSync(LEDGER, JSON.stringify(ledger));
}

async function one(p) {
  const body = {
    origin: { location: { latLng: { latitude: p.o.lat, longitude: p.o.lng } } },
    destination: { location: { latLng: { latitude: p.d.lat, longitude: p.d.lng } } },
    travelMode: 'DRIVE',
  };
  if (FLAVOUR === 'traffic') {
    body.routingPreference = 'TRAFFIC_AWARE';
    body.departureTime = DEPARTURE;
  }
  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      bumpLedger(); // count BEFORE the call - a failed billed call still bills
      const res = await fetch('https://routes.googleapis.com/directions/v2:computeRoutes', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Goog-Api-Key': KEY,
          'X-Goog-FieldMask': 'routes.duration,routes.staticDuration,routes.distanceMeters,routes.polyline.encodedPolyline',
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(30000),
      });
      if (res.status === 429 || res.status >= 500) {
        await new Promise(r => setTimeout(r, 1500 * (attempt + 1) ** 2));
        continue;
      }
      const j = await res.json();
      const rt = j.routes && j.routes[0];
      const rec = {
        id: p.id, flavour: FLAVOUR,
        ok: !!rt,
        dur_s: rt ? parseInt(rt.duration) : null,
        static_s: rt && rt.staticDuration ? parseInt(rt.staticDuration) : null,
        dist_m: rt ? rt.distanceMeters : null,
        poly: rt && rt.polyline ? rt.polyline.encodedPolyline : null,
        err: rt ? null : JSON.stringify(j).slice(0, 200),
      };
      fs.appendFileSync(RESULTS, JSON.stringify(rec) + '\n');
      if (rec.ok) ok++; else failed++;
      return;
    } catch (e) {
      await new Promise(r => setTimeout(r, 1500 * (attempt + 1) ** 2));
    }
  }
  fs.appendFileSync(RESULTS, JSON.stringify({ id: p.id, flavour: FLAVOUR, ok: false, err: 'exhausted retries' }) + '\n');
  failed++;
}

(async () => {
  const CONC = 6;
  let i = 0;
  async function worker() {
    while (i < targets.length) {
      const p = targets[i++];
      await one(p);
      if ((ok + failed) % 100 === 0) console.error(`${ok + failed}/${targets.length} ok=${ok} failed=${failed} spent=$${spentUSD(ledger).toFixed(2)}`);
    }
  }
  await Promise.all(Array.from({ length: CONC }, worker));
  console.error(`DONE ok=${ok} failed=${failed} total spent=$${spentUSD(ledger).toFixed(2)}`);
})();
