// Where automated review and a moderator disagreed, the model reviews the disagreement the
// way a person would reading the results: was the moderator's recorded reason true of the
// post as written, or was the template not about this post? Nobody is ground truth here;
// this makes the reviewing step part of the results instead of something done by eye.
//
// Usage: node scripts/adjudicate.mjs v5-report.json posts.jsonl [--backend=claude] [--model=opus] [--parallel=6] [--out=adjudicated.json]
//   report: src/evaluate.js output with perPost; posts: the JSONL it was run on.
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

function arg(name, fallback) {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
}

const [reportFile, postsFile] = process.argv.slice(2);
const backend = arg('backend', 'claude');
const model = arg('model', 'opus');
const parallel = parseInt(arg('parallel', '6'), 10);
const report = JSON.parse(readFileSync(reportFile, 'utf8'));
const posts = new Map(readFileSync(postsFile, 'utf8').trim().split('\n').map((l) => JSON.parse(l)).map((p) => [p.id, p]));
const cwd = mkdtempSync(join(tmpdir(), 'automod-adjudicate-'));

const VERDICTS = ['model_missed', 'moderator_wrong', 'template_not_about_post', 'local_rule', 'unclear'];

function prompt(post, decision) {
  const rejected = post.outcome === 'rejected';
  const modSaid = rejected
    ? `A moderator rejected this post using the standard message titled "${post.reason_title}".`
    : 'A moderator approved this post.';
  const modelSaid = decision.verdict === 'hold'
    ? `Automated review held it, ending at "${decision.end}".`
    : 'Automated review approved it.';
  return (
    'You check posts on Freegle, a UK site where people give away and ask for unwanted items for free. ' +
    "Everything must be free and legal, posts must be about physical items, and Wanteds must name the items. " +
    'A moderator and an automated review disagreed about the post below. Judge, from the post as written, ' +
    'which of these best describes the disagreement:\n' +
    '- model_missed: the moderator\'s reason is true of this post as written and the review should have caught it (or the review held it for no good reason)\n' +
    '- moderator_wrong: the moderator\'s reason is not true of this post as written\n' +
    '- template_not_about_post: the moderator\'s standard message names a rule this post does not break; another reason may apply\n' +
    '- local_rule: the moderator applied a stricter local rule than Freegle\'s (for example one item per post, or asking for sizes) that the post does not break nationally\n' +
    '- unclear: it cannot be told from the words\n' +
    `Reply with ONLY a JSON object {"verdict":"<one of the five>","why":"one short sentence"}.\n\n${modSaid}\n${modelSaid}\n\n` +
    `<post>\nType: ${post.type}\nSubject: ${post.subject}\nBody: ${post.body}\n</post>`
  );
}

function ask(text) {
  return new Promise((resolve) => {
    execFile('claude', ['-p', text, '--output-format', 'json', '--model', model], { cwd, maxBuffer: 1 << 24, timeout: 240000 }, (err, stdout) => {
      if (err) return resolve({ verdict: 'error', why: String(err.message).slice(0, 120) });
      try {
        const out = JSON.parse(stdout).result || '';
        const j = JSON.parse(out.slice(out.indexOf('{'), out.lastIndexOf('}') + 1));
        resolve(VERDICTS.includes(j.verdict) ? j : { verdict: 'error', why: 'bad verdict' });
      } catch (e) {
        resolve({ verdict: 'error', why: `parse: ${e.message.slice(0, 80)}` });
      }
    });
  });
}

const disagreements = report.perPost
  .map((r) => {
    const post = posts.get(r.id);
    const decision = r.verdict[backend];
    if (!post || !decision) return null;
    const held = decision.verdict === 'hold';
    if ((post.outcome === 'rejected') === held) return null;
    return { post, decision };
  })
  .filter(Boolean);

console.error(`${disagreements.length} disagreements between ${backend} and the moderator`);
const results = [];
let next = 0;
await Promise.all(Array.from({ length: parallel }, async () => {
  while (next < disagreements.length) {
    const d = disagreements[next++];
    const j = await ask(prompt(d.post, d.decision));
    results.push({ id: d.post.id, outcome: d.post.outcome, reason_title: d.post.reason_title, end: d.decision.end, subject: d.post.subject, ...j });
    if (results.length % 20 === 0) console.error(`${results.length}/${disagreements.length}`);
  }
}));

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
const table = {};
for (const r of results) {
  const k = cat(r.reason_title);
  table[k] ||= Object.fromEntries([...VERDICTS, 'error'].map((v) => [v, 0]));
  table[k][r.verdict]++;
}
console.log(`Disagreements adjudicated by ${backend}: who was wrong, by the moderator's reason`);
console.log('group           ' + [...VERDICTS, 'error'].map((v) => v.padStart(24)).join(''));
for (const [k, row] of Object.entries(table)) {
  console.log(k.padEnd(16) + [...VERDICTS, 'error'].map((v) => String(row[v]).padStart(24)).join(''));
}
const out = arg('out', '');
if (out) writeFileSync(out, JSON.stringify(results, null, 2));
