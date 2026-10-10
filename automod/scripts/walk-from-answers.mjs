// Replays the chart's walk from an evaluate.js report's per-question answers, with the
// gating production applies (community rules, is_offer/is_wanted from the post's type, and
// questions that need a fact the offline set lacks skipped), and writes the verdicts back
// into the report so compare.mjs and adjudicate.mjs read production's decision.
// Usage: node scripts/walk-from-answers.mjs report.json posts.jsonl [--check=id,id,...]
import { readFileSync, writeFileSync } from 'node:fs';
import { loadChart } from '../src/walk.js';

const [reportFile, postsFile] = process.argv.slice(2);
const report = JSON.parse(readFileSync(reportFile, 'utf8'));
const posts = new Map(readFileSync(postsFile, 'utf8').trim().split('\n').map((l) => JSON.parse(l)).map((p) => [p.id, p]));
const chart = loadChart();
const textNodes = Object.entries(chart.states).filter(([, s]) => s.check?.kind === 'text');

function walk(post, answers) {
  const rules = typeof post.rules === 'string' ? JSON.parse(post.rules || '{}') || {} : post.rules || {};
  const facts = { is_offer: post.type === 'Offer', is_wanted: post.type === 'Wanted' };
  for (const [id, s] of textNodes) {
    const c = s.check;
    if (c.rule && rules[c.rule] === true) continue;
    if (c.requiresRule && rules[c.requiresRule] !== true) continue;
    if (c.when && facts[c.when] !== true) continue;
    if (answers[id] === 'yes') return { verdict: 'hold', end: `HOLD_${id}` };
  }
  return { verdict: 'approve', end: 'APPROVE' };
}

for (const r of report.perPost) {
  const post = posts.get(r.id);
  if (!post) continue;
  for (const b of report.backends) {
    if (r.answers[b]) r.verdict[b] = walk(post, r.answers[b]);
  }
}
writeFileSync(reportFile, JSON.stringify(report));

const checkArg = process.argv.find((a) => a.startsWith('--check='));
if (checkArg) {
  for (const id of checkArg.slice(8).split(',').map(Number)) {
    const r = report.perPost.find((x) => x.id === id);
    const p = posts.get(id);
    if (!r || !p) continue;
    const yes = Object.entries(r.answers.claude || {}).filter(([, v]) => v === 'yes').map(([k]) => k);
    console.log(`${p.subject} [${p.reason_title || p.outcome}] -> ${r.verdict.claude.end}; yes: ${yes.join(', ') || 'none'}`);
  }
}
console.log('verdicts replayed');
