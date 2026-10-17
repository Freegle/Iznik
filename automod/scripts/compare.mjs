// Holds by the moderator's reason, per backend, from an evaluate.js report with perPost.
// Usage: node scripts/compare.mjs report.json [--baseline=labelled.jsonl]
//   baseline: an older claude-cli-label.mjs file (answer=yes on any text question, with the
//   community's rules applied) to set beside the report's whole-chart verdicts.
import { readFileSync } from 'node:fs';

const report = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const baseArg = process.argv.find((a) => a.startsWith('--baseline='));

const cat = (t) => {
  t = (t || '').toLowerCase();
  if (!t) return 'approved';
  if (/vague|anything|various/.test(t)) return 'vague';
  if (/borrow|swap|loan/.test(t)) return 'borrow/swap';
  if (/sell|money|donation/.test(t)) return 'money';
  if (/medic/.test(t)) return 'medicine';
  if (/pet|animal/.test(t)) return 'animals';
  if (/landfill/.test(t)) return 'not an item';
  return 'other';
};

const rows = {};
for (const r of report.perPost) {
  const k = cat(r.reason_title);
  const row = (rows[k] ||= { n: 0, held: Object.fromEntries(report.backends.map((b) => [b, 0])) });
  row.n++;
  for (const b of report.backends) if (r.verdict[b]?.verdict === 'hold') row.held[b]++;
}

let baseline = null;
if (baseArg) {
  baseline = {};
  for (const line of readFileSync(baseArg.slice(11), 'utf8').trim().split('\n')) {
    const p = JSON.parse(line);
    const k = cat(p.reason_title);
    const row = (baseline[k] ||= { n: 0, held: 0 });
    row.n++;
    if (Object.values(p.labels || {}).includes('yes')) row.held++;
  }
}

const pct = (a, b) => (b ? `${Math.round((100 * a) / b)}%` : '-');
const cols = [...(baseline ? ['baseline'] : []), ...report.backends];
console.log('reason'.padEnd(14) + 'posts'.padStart(6) + cols.map((c) => c.padStart(14)).join(''));
for (const [k, row] of Object.entries(rows)) {
  const cells = [];
  if (baseline) cells.push(baseline[k] ? `${baseline[k].held} (${pct(baseline[k].held, baseline[k].n)})` : '-');
  for (const b of report.backends) cells.push(`${row.held[b]} (${pct(row.held[b], row.n)})`);
  console.log(k.padEnd(14) + String(row.n).padStart(6) + cells.map((c) => c.padStart(14)).join(''));
}

// How much the review pass was used, and what it changed.
const reviewed = report.perPost.filter((r) => Object.values(r.answers.claude || {}).length).length;
console.log(`\n${reviewed} posts with per-question answers recorded.`);
