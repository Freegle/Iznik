// Frontier labels for an evaluation set, using the logged-in `claude` CLI on a developer
// machine (no API key needed). One call per post answers every text node's question, which
// keeps a few hundred posts to minutes rather than hours. Output feeds src/evaluate.js via
// the `labels` field, so the local model is measured against the frontier model.
//
// Usage: node scripts/claude-cli-label.mjs posts.jsonl out.jsonl [--model=opus] [--parallel=6]
// Only the post's type, subject and body are sent.
import { readFileSync, writeFileSync, appendFileSync, existsSync, mkdtempSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const chart = JSON.parse(readFileSync(join(here, '..', 'chart.json'), 'utf8'));
const questions = Object.fromEntries(
  Object.entries(chart.states)
    .filter(([, s]) => s.check?.kind === 'text')
    .map(([id, s]) => [id, s.check.question]),
);

function arg(name, fallback) {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
}

const [input, output] = process.argv.slice(2);
const model = arg('model', 'opus');
const parallel = parseInt(arg('parallel', '6'), 10);
const cwd = mkdtempSync(join(tmpdir(), 'automod-label-'));

function prompt(post) {
  return (
    'You check posts on Freegle, a UK site where people give away and ask for unwanted items for free. ' +
    'Answer each yes/no question about the post below, about this post as written. Reply with ONLY a JSON ' +
    'object mapping each question id to {"answer":"yes"|"no","confidence":0..1 (how sure you are the answer is yes),' +
    '"evidence":"short quote or empty"}. No other text.\n\nQuestions:\n' +
    Object.entries(questions).map(([id, q]) => `${id}: ${q}`).join('\n') +
    `\n\nPost:\nType: ${post.type}\nSubject: ${post.subject}\nBody: ${post.body}`
  );
}

function ask(post) {
  return new Promise((resolve) => {
    execFile('claude', ['-p', prompt(post), '--output-format', 'json', '--model', model], { cwd, maxBuffer: 1 << 24, timeout: 240000 }, (err, stdout) => {
      if (err) return resolve({ error: String(err.message).slice(0, 200) });
      try {
        const text = JSON.parse(stdout).result;
        const json = text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1);
        resolve({ answers: JSON.parse(json) });
      } catch (e) {
        resolve({ error: `parse: ${String(e.message).slice(0, 100)}` });
      }
    });
  });
}

const posts = readFileSync(input, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
const done = new Set(
  existsSync(output) ? readFileSync(output, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l).id) : [],
);
const todo = posts.filter((p) => !done.has(p.id));
let next = 0;
let finished = 0;

async function worker() {
  while (next < todo.length) {
    const post = todo[next++];
    const res = await ask(post);
    const labels = {};
    for (const [id, a] of Object.entries(res.answers || {})) {
      if (a && (a.answer === 'yes' || a.answer === 'no')) labels[id] = a.answer;
    }
    appendFileSync(output, JSON.stringify({ ...post, labels, claude: res.answers || null, error: res.error }) + '\n');
    finished++;
    if (finished % 10 === 0) console.log(`${finished}/${todo.length}`);
  }
}

await Promise.all(Array.from({ length: parallel }, worker));
console.log(`done ${finished}`);
