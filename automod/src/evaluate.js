// Compares backends node by node on real posts, so a local model can earn a node by
// matching the frontier model on the same set (plans/active/automod-flowchart.md,
// "Choosing models: top down").
//
// Input: JSONL, one post per line, as written by `php artisan automod:evaluate --export`:
//   {id, subject, body, type, facts, rules, outcome, labels?}
// outcome is what the moderator did ("approved" or "rejected"); labels is an optional
// per-node gold answer, e.g. {"LOAN": "yes"}.
//
// Usage: node src/evaluate.js posts.jsonl [--backends=claude,nli] [--limit=N] [--out=report.json]
import { readFileSync, writeFileSync } from 'node:fs';
import { createBackend } from './backend.js';
import { createReviewer, loadChart } from './walk.js';

function arg(name, fallback) {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
}

function postText(post) {
  return [
    post.type ? `Type: ${post.type}` : null,
    post.subject ? `Subject: ${post.subject}` : null,
    post.body ? `Body: ${post.body}` : null,
  ]
    .filter(Boolean)
    .join('\n');
}

function pct(n, d) {
  return d ? `${((100 * n) / d).toFixed(1)}%` : '-';
}

export async function evaluate(posts, { backends, router, chartPath } = {}) {
  const chart = loadChart(chartPath);
  const textNodes = Object.entries(chart.states).filter(([, s]) => s.check?.kind === 'text');
  const nodes = {};
  const verdicts = {};

  for (const name of backends) {
    verdicts[name] = { held: 0, heldRejected: 0, approved: 0, approvedRejected: 0, rejected: 0 };
  }

  const reviewer = createReviewer({ backend: router, chartPath });

  for (const post of posts) {
    const text = postText(post);

    for (const [nodeId, state] of textNodes) {
      const node = (nodes[nodeId] ||= { question: state.check.question, answers: {}, agree: 0, asked: 0, labelled: 0, correct: {} });
      const answers = {};
      for (const name of backends) {
        try {
          const { p } = await router.ask(state.check.question, text, name);
          answers[name] = p >= state.check.threshold ? 'yes' : 'no';
        } catch {
          answers[name] = 'error';
        }
        node.answers[name] ||= { yes: 0, no: 0, error: 0 };
        node.answers[name][answers[name]]++;
      }
      node.asked++;
      if (new Set(Object.values(answers)).size === 1) {
        node.agree++;
      }
      const gold = post.labels?.[nodeId];
      if (gold) {
        node.labelled++;
        for (const name of backends) {
          node.correct[name] = (node.correct[name] || 0) + (answers[name] === gold ? 1 : 0);
        }
      }
    }

    for (const name of backends) {
      const result = await reviewer.review({ ...post, msgid: post.id, groupid: 1, backend: name });
      const v = verdicts[name];
      const rejected = post.outcome === 'rejected';
      v.rejected += rejected ? 1 : 0;
      if (result.verdict === 'hold') {
        v.held++;
        v.heldRejected += rejected ? 1 : 0;
      } else {
        v.approved++;
        v.approvedRejected += rejected ? 1 : 0;
      }
    }
  }

  return { posts: posts.length, backends, nodes, verdicts };
}

export function formatReport(report) {
  const lines = [`${report.posts} posts, backends: ${report.backends.join(', ')}`, ''];
  lines.push('Per node (answers yes/no/error per backend, agreement, accuracy on labelled posts):');
  for (const [nodeId, n] of Object.entries(report.nodes)) {
    const per = report.backends
      .map((b) => {
        const a = n.answers[b] || {};
        const acc = n.labelled ? ` acc ${pct(n.correct[b] || 0, n.labelled)}` : '';
        return `${b} ${a.yes || 0}/${a.no || 0}/${a.error || 0}${acc}`;
      })
      .join(' | ');
    lines.push(`  ${nodeId.padEnd(16)} agree ${pct(n.agree, n.asked).padStart(6)}  ${per}`);
  }
  lines.push('', 'Whole chart against what the moderator did:');
  for (const b of report.backends) {
    const v = report.verdicts[b];
    lines.push(
      `  ${b}: held ${v.held} (of which moderator rejected ${v.heldRejected}), ` +
        `approved ${v.approved} (of which moderator rejected ${v.approvedRejected}), ` +
        `rejections caught ${pct(v.heldRejected, v.rejected)}`,
    );
  }
  return lines.join('\n');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const file = process.argv[2];
  if (!file) {
    console.error('usage: node src/evaluate.js posts.jsonl [--backends=claude,nli] [--limit=N] [--out=report.json]');
    process.exit(2);
  }
  const limit = parseInt(arg('limit', '0'), 10);
  let posts = readFileSync(file, 'utf8')
    .split('\n')
    .filter((l) => l.trim())
    .map((l) => JSON.parse(l));
  if (limit > 0) {
    posts = posts.slice(0, limit);
  }
  const backends = arg('backends', 'claude,nli').split(',');
  const report = await evaluate(posts, { backends, router: createBackend() });
  console.log(formatReport(report));
  const out = arg('out', '');
  if (out) {
    writeFileSync(out, JSON.stringify(report, null, 2));
  }
}
