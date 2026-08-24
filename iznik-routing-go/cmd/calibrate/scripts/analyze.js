#!/usr/bin/env node
// Join routed results with Google ground truth; report error by segment,
// traffic-vs-static gap, route divergence, and the most misleading examples.
// Usage: node analyze.js <routed.json> [--target dur_s|static_s] [--json out.json]
'use strict';
const fs = require('fs');

const routedPath = process.argv[2] || 'baseline-routed.json';
const target = process.argv.includes('--target') ? process.argv[process.argv.indexOf('--target') + 1] : 'dur_s';
const pairs = new Map(JSON.parse(fs.readFileSync('pairs.json', 'utf8')).map(p => [p.id, p]));
const goog = new Map();
for (const line of fs.readFileSync('google-results.jsonl', 'utf8').split('\n')) {
  if (!line) continue;
  const r = JSON.parse(line);
  if (r.ok && r.flavour) {
    const cur = goog.get(r.id) || {};
    cur[r.flavour] = r;
    goog.set(r.id, cur);
  }
}
const routedDoc = JSON.parse(fs.readFileSync(routedPath, 'utf8'));
const routed = routedDoc.routed || routedDoc;

function pct(x) { return (100 * x).toFixed(1) + '%'; }
function quantile(a, q) { if (!a.length) return NaN; const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(q * s.length))]; }
function stats(errs) {
  if (!errs.length) return null;
  const apes = errs.map(Math.abs);
  return {
    n: errs.length,
    mape: apes.reduce((a, b) => a + b, 0) / apes.length,
    med_ape: quantile(apes, 0.5),
    p90_ape: quantile(apes, 0.9),
    bias: errs.reduce((a, b) => a + b, 0) / errs.length,
    med_bias: quantile(errs, 0.5),
  };
}
function fmtStats(s) { return s ? `n=${s.n} MAPE=${pct(s.mape)} med=${pct(s.med_ape)} p90=${pct(s.p90_ape)} bias=${pct(s.bias)} medbias=${pct(s.med_bias)}` : 'n=0'; }

const rows = [];
let unreachableOurs = 0, missingGoog = 0;
for (const r of routed) {
  const p = pairs.get(r.id);
  const g = goog.get(r.id);
  const gt = g && g.traffic;
  if (!gt) { missingGoog++; continue; }
  const y = target === 'static_s' ? gt.static_s : gt.dur_s;
  if (!y) continue;
  if (!r.ok) { unreachableOurs++; rows.push({ id: r.id, p, unreachable: true, y }); continue; }
  const rel = (r.secs - y) / y;
  rows.push({
    id: r.id, p, y, ours: r.secs, rel,
    distRatio: gt.dist_m ? r.dist_m / gt.dist_m : null,
    static_s: gt.static_s, dur_s: gt.dur_s, feat: r.feat,
  });
}
const valid = rows.filter(r => !r.unreachable);
console.log(`=== ${routedPath} vs Google ${target} ===`);
console.log(`pairs with google: ${rows.length + missingGoog - missingGoog}, ours-unreachable: ${unreachableOurs}, no-google: ${missingGoog}`);
console.log(`OVERALL: ${fmtStats(stats(valid.map(r => r.rel)))}`);

// traffic vs static gap (on all pilot rows that have both)
const gaps = [];
for (const r of valid) if (r.static_s && r.dur_s) gaps.push(r.dur_s / r.static_s);
if (gaps.length) {
  console.log(`\nTRAFFIC/STATIC ratio: med=${quantile(gaps, 0.5).toFixed(3)} p10=${quantile(gaps, 0.1).toFixed(3)} p90=${quantile(gaps, 0.9).toFixed(3)}`);
}

function seg(label, keyFn) {
  const m = new Map();
  for (const r of valid) {
    const k = keyFn(r);
    if (k == null) continue;
    if (!m.has(k)) m.set(k, []);
    m.get(k).push(r.rel);
  }
  console.log(`\n--- by ${label} ---`);
  for (const [k, errs] of [...m.entries()].sort()) console.log(`${String(k).padEnd(22)} ${fmtStats(stats(errs))}`);
}
seg('stratum', r => r.p.stratum.startsWith('estuary') ? 'estuary' : r.p.stratum);
seg('density', r => r.p.density);
seg('google min band', r => {
  const min = r.y / 60;
  return min < 10 ? 'a:<10min' : min < 20 ? 'b:10-20' : min < 30 ? 'c:20-30' : min < 45 ? 'd:30-45' : 'e:>45';
});
seg('crow km band', r => {
  const d = r.p.crow_km;
  return d < 2.5 ? 'a:<2.5' : d < 5 ? 'b:2.5-5' : d < 9 ? 'c:5-9' : d < 15 ? 'd:9-15' : d < 25 ? 'e:15-25' : 'f:25+';
});
seg('dist ratio (route divergence)', r => {
  if (r.distRatio == null) return null;
  return r.distRatio < 0.85 ? 'a:ours<0.85x' : r.distRatio < 0.95 ? 'b:0.85-0.95' : r.distRatio < 1.05 ? 'c:~same' : r.distRatio < 1.2 ? 'd:1.05-1.2' : 'e:ours>1.2x';
});

// misleading examples
const sorted = [...valid].sort((a, b) => Math.abs(b.rel) - Math.abs(a.rel));
console.log('\n--- 15 most misleading (ours vs google, minutes) ---');
for (const r of sorted.slice(0, 15)) {
  console.log(`#${r.id} ${r.p.stratum} ${r.p.o.pc}->${r.p.d.pc} crow=${r.p.crow_km}km: ours=${(r.ours / 60).toFixed(1)} goog=${(r.y / 60).toFixed(1)} rel=${pct(r.rel)} distRatio=${r.distRatio ? r.distRatio.toFixed(2) : '?'}`);
}
console.log('\n--- unreachable for us, google fine ---');
for (const r of rows.filter(r => r.unreachable).slice(0, 10)) {
  console.log(`#${r.id} ${r.p.stratum} ${r.p.o.pc}->${r.p.d.pc} crow=${r.p.crow_km}km goog=${(r.y / 60).toFixed(1)}min`);
}

const outIdx = process.argv.indexOf('--json');
if (outIdx > 0) {
  fs.writeFileSync(process.argv[outIdx + 1], JSON.stringify({ rows: rows.map(({ p, feat, ...rest }) => rest) }));
}
