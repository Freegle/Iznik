// The backend router and the per-node comparison, with the deterministic fake backend so
// no model or network is involved.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BackendRouter, FakeBackend, ClaudeBackend, ClaudeCliBackend, checkAnswerIds, newAccount, remainingMs } from '../src/backend.js';
import { createReviewer } from '../src/walk.js';
import { evaluate, formatReport } from '../src/evaluate.js';
import { completeFacts } from './helpers.js';

class NamedBackend {
  constructor(p) {
    this.p = p;
    this.calls = 0;
  }
  async ask() {
    this.calls++;
    return { p: this.p, model: `named:${this.p}` };
  }
}

function routerWith(backends, defaultName = 'claude') {
  const router = new BackendRouter({ AUTOMOD_BACKEND: defaultName });
  for (const [name, backend] of Object.entries(backends)) {
    router.instances[name] = backend;
    router.factories[name] = () => backend;
  }
  return router;
}

// A batched reply with every answer "no", as requestAll returns it.
function allNo(claude, usage = { model: 'claude:test-model', input: 50, output: 20 }) {
  return {
    byQuestion: new Map(claude.questions.map((q) => [q.question, { answer: 'no', confidence: 0.1, evidence: '' }])),
    usage,
    features: { items: ['sofa'], item_named: true },
    prompt_sha: 'p'.repeat(64),
    reply_sha: 'r'.repeat(64),
  };
}

test('the router defaults to claude', () => {
  assert.equal(new BackendRouter({}).defaultName, 'claude');
});

test('the router rejects an unknown backend', () => {
  assert.throws(() => new BackendRouter({}).get('nope'), /unknown backend/);
});

test('a request backend overrides the default for every text node', async () => {
  const always = new NamedBackend(0.99);
  const never = new NamedBackend(0.01);
  const router = routerWith({ claude: always, fake: never });
  const reviewer = createReviewer({ backend: router });

  const viaDefault = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() });
  assert.equal(viaDefault.verdict, 'hold');

  const viaOverride = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts(), backend: 'fake' });
  assert.equal(viaOverride.verdict, 'approve');
  assert.ok(never.calls > 0);
});

test('backend evidence is carried into the path', async () => {
  const quoting = { ask: async () => ({ p: 0.95, model: 'claude:test', evidence: 'lend me a ladder' }) };
  const reviewer = createReviewer({ backend: quoting });
  const result = await reviewer.review({ msgid: 1, groupid: 1, subject: 'WANTED: ladder', body: 'Can anyone lend me a ladder', type: 'Wanted', facts: completeFacts() });
  const last = result.path[result.path.length - 1];
  assert.equal(last.evidence, 'lend me a ladder');
});

test('evaluate compares backends node by node and against the moderator outcome', async () => {
  const router = routerWith({ claude: new FakeBackend(), nli: new FakeBackend() });
  const posts = [
    { id: 1, subject: 'OFFER: Sofa', body: 'Brown sofa, collect from Leeds', type: 'Offer', facts: {}, rules: {}, outcome: 'approved' },
    { id: 2, subject: 'OFFER: Bike', body: 'Selling my bike, money please', type: 'Offer', facts: {}, rules: {}, outcome: 'rejected', labels: { MONEY: 'yes' } },
  ];
  const report = await evaluate(posts, { backends: ['claude', 'nli'], router });

  assert.equal(report.posts, 2);
  assert.equal(report.nodes.MONEY.asked, 2);
  assert.equal(report.nodes.MONEY.agree, 2, 'two copies of the same backend always agree');
  assert.equal(report.nodes.MONEY.labelled, 1);
  assert.equal(report.verdicts.claude.rejected, 1);
  assert.match(formatReport(report), /MONEY/);
});

test('evaluate decides a post from an export that carries no facts on its text, not as unavailable', async () => {
  const router = routerWith({ claude: new FakeBackend() });
  const posts = [
    { id: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', outcome: 'approved' },
    { id: 2, subject: 'OFFER: Bike', body: 'Selling my bike, money please', type: 'Offer', facts: { mod_note: true }, outcome: 'rejected' },
  ];
  const report = await evaluate(posts, { backends: ['claude'], router });

  assert.equal(report.perPost[0].verdict.claude.end, 'APPROVE', 'what the export does not say is taken as false');
  assert.equal(report.perPost[1].verdict.claude.end, 'HOLD_MOD_NOTE', 'what it does say still counts');
});

test('claude answers every chart question in one call per post, and the review says what it cost', async () => {
  const claude = new ClaudeBackend({ apiKey: 'test' });
  let calls = 0;
  claude.requestAll = async () => {
    calls++;
    return allNo(claude);
  };
  const router = new BackendRouter({ AUTOMOD_BACKEND: 'claude' });
  router.instances.claude = claude;
  const reviewer = createReviewer({ backend: router });

  const result = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() });
  assert.equal(result.verdict, 'approve');
  assert.equal(calls, 1);
  assert.ok(result.path.filter((s) => s.model.startsWith('claude:')).length > 5);
  assert.equal(result.calls, 1);
  assert.equal(result.cache_hit, false);
  assert.equal(result.model, 'claude:test-model');
  assert.deepEqual(result.tokens, { input: 50, output: 20 });
  assert.equal(result.cost_usd, null, 'the API does not say what it cost');
  assert.deepEqual(result.features, { items: ['sofa'], item_named: true });
  assert.equal(result.prompt_sha, 'p'.repeat(64));
  assert.equal(result.reply_sha, 'r'.repeat(64));

  // The same text again is a cache hit: no call, nothing charged, the entry's features still given.
  const again = await reviewer.review({ msgid: 2, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() });
  assert.equal(calls, 1);
  assert.equal(again.calls, 0);
  assert.equal(again.cache_hit, true);
  assert.equal(again.model, 'claude:test-model');
  assert.deepEqual(again.tokens, { input: 0, output: 0 });
  assert.deepEqual(again.features, { items: ['sofa'], item_named: true });
});

test('jev asks every chart question in one request and reads each probability of yes', async () => {
  const { JevBackend } = await import('../src/backend.js');
  const jev = new JevBackend({ apiKey: 'test', baseUrl: 'http://jev.invalid' });
  jev.setQuestions(['Is it selling?', 'Is it a loan?']);
  const seen = [];
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    seen.push({ url, body: JSON.parse(init.body) });
    return { ok: true, json: async () => ({ answers: { q0: { noul: 0.1 }, q1: { noul: 0.93 } } }) };
  };
  try {
    const acct = newAccount();
    const a = await jev.ask('Is it a loan?', 'Subject: WANTED: ladder', { acct });
    const b = await jev.ask('Is it selling?', 'Subject: WANTED: ladder', { acct });
    assert.equal(a.p, 0.93);
    assert.equal(b.p, 0.1);
    assert.equal(a.model, 'jev:jev-latest');
    assert.equal(seen.length, 1, 'one request per post');
    assert.equal(seen[0].url, 'http://jev.invalid/v1/systemone');
    assert.equal(seen[0].body.questions.q1.type, 'noul');
    assert.equal(acct.calls, 1);
    assert.equal(acct.model, 'jev:jev-latest');
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('the review pass asks again, on its own, when a flagged word met a no', async () => {
  const claude = new ClaudeBackend({ apiKey: 'test' });
  const calls = [];
  claude.requestAll = async () => allNo(claude);
  claude.askOne = async (question, text, opts) => {
    calls.push(opts);
    return { p: 0.9, answer: 'yes', model: 'claude:test', evidence: 'Buyer must remove' };
  };
  claude.setQuestions([{ question: 'Money?', flags: ['money'] }, { question: 'Loan?', flags: ['loan'] }]);

  const money = await claude.ask('Money?', 'Buyer must remove it', { flags: ['money'], flagged: ['buyer'] });
  const loan = await claude.ask('Loan?', 'Buyer must remove it', { flags: ['loan'], flagged: [] });

  assert.equal(money.answer, 'yes');
  assert.equal(money.model, 'claude:test+review');
  assert.deepEqual(calls[0].flagged, ['buyer']);
  assert.equal(loan.answer, 'no', 'no flagged word, no second look');
  assert.equal(calls.length, 1);
});

test('a batched reply is only used when its ids are exactly 0..n-1, once each', () => {
  const ok = [{ id: 0 }, { id: 2 }, { id: 1 }];
  assert.doesNotThrow(() => checkAnswerIds(ok, 3));
  assert.throws(() => checkAnswerIds([{ id: 1 }, { id: 2 }, { id: 3 }], 3), /numbered its answers wrongly/, 'one-based');
  assert.throws(() => checkAnswerIds([{ id: 0 }, { id: 0 }, { id: 1 }], 3), /numbered its answers wrongly/, 'a repeat');
  assert.throws(() => checkAnswerIds([{ id: 0 }, { id: 1 }], 3), /answered 2 of 3/, 'one short');
  assert.throws(() => checkAnswerIds([{ id: 0 }, { id: 1 }, { id: 2 }, { id: 3 }], 3), /answered 4 of 3/, 'one over');
  assert.throws(() => checkAnswerIds([{ id: 0 }, { id: 1.5 }, { id: 2 }], 3), /numbered its answers wrongly/, 'not an integer');
});

test('a shifted batched reply from the API holds rather than answering the wrong questions', async () => {
  const claude = new ClaudeBackend({ apiKey: 'test' });
  claude.setQuestions(['A?', 'B?']);
  const parsed = { features: {}, answers: [{ id: 1, answer: 'no', confidence: 0.1, evidence: '' }, { id: 2, answer: 'yes', confidence: 0.9, evidence: '' }] };
  assert.throws(() => claude.entryFor('prompt', parsed, {}), /numbered its answers wrongly/);
});

test('the deadline bounds every call and refuses one that would start after it', () => {
  assert.equal(remainingMs(undefined, 1000), 1000);
  assert.ok(remainingMs(Date.now() + 500, 1000) <= 500);
  assert.equal(remainingMs(Date.now() + 5000, 1000), 1000);
  assert.throws(() => remainingMs(Date.now() - 1, 1000), /deadline passed/);
});

test('keyword flags match whole words and symbols', async () => {
  const { keywordFlags } = await import('../src/prompt.js');
  const f = keywordFlags('Selling my bike, £50 ono. Buyer collects.');
  assert.ok(f.money.includes('£'));
  assert.ok(f.money.includes('ono'));
  assert.ok(f.listing.includes('buyer'));
  assert.equal(keywordFlags('A rattan chair').animals, undefined, '"rat" inside "rattan" is not a flag');
});

// A stand-in for the claude CLI: reads the prompt from stdin like the real one, and replies in
// the real one's JSON shape, putting what it was given into the evidence so the test can see it.
// FAKE_CLI_MODE picks the reply: a single answer, a batch (numbered from FAKE_CLI_FIRST_ID, so a
// wrongly numbered batch can be tried), or an API error.
function fakeCli() {
  const dir = mkdtempSync(join(tmpdir(), 'automod-fake-cli-'));
  const bin = join(dir, 'claude');
  writeFileSync(
    bin,
    `#!/usr/bin/env node
let input = '';
process.stdin.on('data', (c) => { input += c; });
process.stdin.on('end', () => {
  const argv = process.argv.slice(2);
  const mode = process.env.FAKE_CLI_MODE || 'single';
  const usage = { input_tokens: 3, cache_creation_input_tokens: 7, cache_read_input_tokens: 0, output_tokens: 5 };
  const modelUsage = { 'claude-opus-5-9': { costUSD: 0.0123 } };
  let out;
  if (mode === 'api_error') {
    out = { type: 'result', subtype: 'success', is_error: true, terminal_reason: 'api_error', result: 'Invalid API key - fix external API key', total_cost_usd: 0, usage: {}, modelUsage: {} };
  } else if (mode === 'batch') {
    const first = Number(process.env.FAKE_CLI_FIRST_ID || 0);
    const qs = (input.split('\\nQuestions:\\n')[1] || '').split('\\n\\n<post>')[0].split('\\n').filter(Boolean);
    const answers = qs.map((_, i) => ({ id: first + i, answer: 'no', confidence: 0.1, evidence: '' }));
    const features = { items: ['ladder'], item_named: true, money: '', sale_listing: '', borrowing: '', exchange: '', animals: '', medicines: '', substances: '', links_or_codes: '', readings: '' };
    out = { type: 'result', subtype: 'success', is_error: false, terminal_reason: 'completed', result: JSON.stringify({ features, answers }), total_cost_usd: 0.0123, duration_ms: 7, usage, modelUsage };
  } else {
    const answer = { answer: 'yes', confidence: 0.9, evidence: JSON.stringify({ argv, inputLength: input.length, hasQuestion: input.includes('Question:') }) };
    out = { type: 'result', subtype: 'success', is_error: false, terminal_reason: 'completed', result: JSON.stringify(answer), total_cost_usd: 0.0123, duration_ms: 7, usage, modelUsage };
  }
  process.stdout.write(JSON.stringify(out));
});
`,
  );
  chmodSync(bin, 0o755);
  return bin;
}

test('the cli backend sends the prompt on stdin with no tools and one turn, and records what the cli reports', async () => {
  const backend = new ClaudeCliBackend({ token: 'test-token', bin: fakeCli() });
  const acct = newAccount();
  const r = await backend.askOne('Is it a loan?', 'Subject: WANTED: ladder', { acct });
  assert.equal(r.answer, 'yes');
  const seen = JSON.parse(r.evidence);
  assert.ok(!seen.argv.some((a) => a.includes('Is it a loan?')), 'the prompt is not on the command line');
  assert.equal(seen.argv[seen.argv.indexOf('--tools') + 1], '', 'no tools');
  assert.equal(seen.argv[seen.argv.indexOf('--max-turns') + 1], '1');
  assert.ok(seen.argv.includes('--no-session-persistence'), 'the cli must not save the prompt, which holds a member\'s text, to disk');
  assert.equal(seen.argv[seen.argv.indexOf('--model') + 1], 'opus');
  assert.ok(seen.hasQuestion, 'the prompt arrived on stdin');
  assert.equal(acct.calls, 1);
  assert.equal(acct.model, 'cli:claude-opus-5-9', 'the model the cli reports, not the alias asked for');
  assert.deepEqual(acct.tokens, { input: 10, output: 5 });
  assert.equal(acct.cost_usd, 0.0123);
});

test('the cli backend answers every question from one batched run, and refuses a wrongly numbered one', async () => {
  const backend = new ClaudeCliBackend({ token: 'test-token', bin: fakeCli() });
  backend.setQuestions(['Is it a loan?', 'Is it selling?']);
  process.env.FAKE_CLI_MODE = 'batch';
  try {
    const acct = newAccount();
    const a = await backend.ask('Is it selling?', 'Subject: WANTED: ladder', { acct });
    assert.equal(a.answer, 'no');
    assert.equal(acct.calls, 1);
    assert.equal(acct.cost_usd, 0.0123);
    assert.deepEqual(acct.features.items, ['ladder']);
    assert.match(acct.prompt_sha, /^[0-9a-f]{64}$/);
    assert.match(acct.reply_sha, /^[0-9a-f]{64}$/);

    process.env.FAKE_CLI_FIRST_ID = '1';
    await assert.rejects(backend.askAll('Subject: WANTED: bike', [], {}), /numbered its answers wrongly/);
  } finally {
    delete process.env.FAKE_CLI_MODE;
    delete process.env.FAKE_CLI_FIRST_ID;
  }
});

test('the cli backend fails with the cause when the cli reports an error', async () => {
  const backend = new ClaudeCliBackend({ token: 'test-token', bin: fakeCli() });
  process.env.FAKE_CLI_MODE = 'api_error';
  try {
    await assert.rejects(backend.askOne('Is it a loan?', 'Subject: ladder', {}), /claude cli api_error: Invalid API key/);
  } finally {
    delete process.env.FAKE_CLI_MODE;
  }
});

test('the cli backend does not start a call after the deadline', async () => {
  const backend = new ClaudeCliBackend({ token: 'test-token', bin: fakeCli() });
  await assert.rejects(backend.askOne('Is it a loan?', 'Subject: ladder', { deadline: Date.now() - 1 }), /deadline passed/);
});

test('the answer cache is keyed on the text AND the findings the model is told', async () => {
  // A post to two communities is two reviews of the same text. If one community's content check
  // found a concern word and the other's did not, the second must not be answered from a call
  // that never saw the finding.
  const claude = new ClaudeBackend({ apiKey: 'test' });
  claude.setQuestions([{ question: 'A?', flags: [] }]);
  const told = [];
  claude.requestAll = async (text, hints) => {
    told.push(hints);
    return allNo(claude);
  };

  await claude.askAll('Subject: Sofa', [], {});
  await claude.askAll('Subject: Sofa', [], {});
  assert.equal(told.length, 1, 'same text, same findings: cached');

  await claude.askAll('Subject: Sofa', ['A concern word matched (knife)'], {});
  assert.equal(told.length, 2, 'same text, a finding the first call did not have: asked again');
  assert.deepEqual(told[1], ['A concern word matched (knife)']);

  await claude.askAll('Subject: Sofa', ['A concern word matched (knife)'], {});
  assert.equal(told.length, 2, 'and that is cached in turn');

  await claude.askAll('Subject: Sofa', undefined, {});
  assert.equal(told.length, 2, 'no findings is the same as an empty list');
});

test('the default model is a valid id', () => {
  const saved = process.env.AUTOMOD_CLAUDE_MODEL;
  delete process.env.AUTOMOD_CLAUDE_MODEL;
  try {
    assert.equal(new ClaudeBackend({ apiKey: 'test' }).model, 'claude-opus-5-5');
  } finally {
    if (saved !== undefined) process.env.AUTOMOD_CLAUDE_MODEL = saved;
  }
});
