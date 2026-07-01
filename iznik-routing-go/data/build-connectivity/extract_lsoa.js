#!/usr/bin/env node
// extract_lsoa.js — pull (LSOA21CD, Overall connectivity) from the DfT Transport
// Connectivity Metric ODS. Reads content.xml on STDIN (pipe `unzip -p file content.xml`)
// and streams the LSOA sheet, draining complete <table:table-row> chunks from a small
// sliding buffer so it never holds the ~170MB sheet in memory (O(n)).
//
// Usage:  unzip -p connectivity_metrics_2025.ods content.xml | node extract_lsoa.js out.csv
// Output: CSV `lsoa21cd,overall` (Overall = the grand connectivity score, last column).
//
// The DfT sheet layout (verified 2025 release): the "LSOA" table has a 2-row title,
// a header row starting "LSOA21CD", then one row per LSOA whose LAST cell is "Overall".
const fs = require('fs');

const outPath = process.argv[2] || 'lsoa_conn_codes.csv';
const MARK = 'table:name="LSOA"';
const ROW_OPEN = '<table:table-row';
const ROW_CLOSE = '</table:table-row>';
const TBL_CLOSE = '</table:table>';

let phase = 'search';
let buf = '';
let n = 0;
let finished = false;
const out = fs.createWriteStream(outPath);
out.write('lsoa21cd,overall\n');

function cells(rowXml) {
  const res = [];
  const cr = /<table:table-cell\b([^>]*?)(\/>|>([\s\S]*?)<\/table:table-cell>)/g;
  let m;
  while ((m = cr.exec(rowXml))) {
    let rep = 1;
    const rm = m[1].match(/number-columns-repeated="(\d+)"/);
    if (rm) rep = +rm[1];
    if (rep > 100) rep = 1; // ignore huge trailing pads
    const vm = m[1].match(/office:value="([^"]*)"/);
    const inner = (m[3] || '').replace(/<[^>]*>/g, '').trim();
    const v = vm ? vm[1] : inner;
    for (let i = 0; i < rep; i++) res.push(v);
  }
  while (res.length && res[res.length - 1] === '') res.pop();
  return res;
}

function finish() {
  if (finished) return;
  finished = true;
  phase = 'done';
  out.end(() => { console.error('wrote ' + outPath + ' rows=' + n); process.exit(0); });
}

function drain() {
  for (;;) {
    const close = buf.indexOf(ROW_CLOSE);
    if (close === -1) {
      if (buf.indexOf(TBL_CLOSE) !== -1) { finish(); return; }
      if (buf.length > 1_000_000) buf = buf.slice(-500_000);
      return;
    }
    const open = buf.lastIndexOf(ROW_OPEN, close);
    if (open === -1) { buf = buf.slice(close + ROW_CLOSE.length); continue; }
    const tbl = buf.indexOf(TBL_CLOSE);
    if (tbl !== -1 && tbl < open) { finish(); return; }
    const rowXml = buf.slice(open, close + ROW_CLOSE.length);
    buf = buf.slice(close + ROW_CLOSE.length);
    const c = cells(rowXml);
    if (/^[EW]\d{8}$/.test(c[0])) {
      const f = parseFloat(c[c.length - 1]);
      if (!isNaN(f)) { out.write(c[0] + ',' + Math.round(f) + '\n'); n++; }
    }
  }
}

process.stdin.on('data', (chunk) => {
  if (phase === 'done') return;
  const s = chunk.toString('latin1');
  if (phase === 'search') {
    buf += s;
    const idx = buf.indexOf(MARK);
    if (idx === -1) { buf = buf.slice(-200); return; }
    buf = buf.slice(idx);
    phase = 'stream';
    drain();
    return;
  }
  buf += s;
  drain();
});
process.stdin.on('end', () => { if (!finished) finish(); });
