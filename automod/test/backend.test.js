// The backend router and the per-node comparison, with the deterministic fake backend so
// no model or network is involved.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BackendRouter, FakeBackend } from '../src/backend.js';
import { createReviewer } from '../src/walk.js';
import { evaluate, formatReport } from '../src/evaluate.js';

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

  const viaDefault = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer' });
  assert.equal(viaDefault.verdict, 'hold');

  const viaOverride = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', backend: 'fake' });
  assert.equal(viaOverride.verdict, 'approve');
  assert.ok(never.calls > 0);
});

test('backend evidence is carried into the path', async () => {
  const quoting = { ask: async () => ({ p: 0.95, model: 'claude:test', evidence: 'lend me a ladder' }) };
  const reviewer = createReviewer({ backend: quoting });
  const result = await reviewer.review({ msgid: 1, groupid: 1, subject: 'WANTED: ladder', body: 'Can anyone lend me a ladder', type: 'Wanted' });
  const last = result.path[result.path.length - 1];
  assert.equal(last.evidence, 'lend me a ladder');
});

test('evaluate compares backends node by node and against the moderator outcome', async () => {
  const router = routerWith({ claude: new FakeBackend(), nli: new FakeBackend() });
  const posts = [
    { id: 1, subject: 'OFFER: Sofa', body: 'Brown sofa, collect from Leeds', type: 'Offer', facts: {}, rules: {}, outcome: 'approved' },
    { id: 2, subject: 'OFFER: Bike', body: 'Selling my bike, money please', type: 'Offer', facts: {}, rules: {}, outcome: 'rejected', labels: { SELLING: 'yes' } },
  ];
  const report = await evaluate(posts, { backends: ['claude', 'nli'], router });

  assert.equal(report.posts, 2);
  assert.equal(report.nodes.SELLING.asked, 2);
  assert.equal(report.nodes.SELLING.agree, 2, 'two copies of the same backend always agree');
  assert.equal(report.nodes.SELLING.labelled, 1);
  assert.equal(report.verdicts.claude.rejected, 1);
  assert.match(formatReport(report), /SELLING/);
});

test('claude answers every chart question in one call per post', async () => {
  const { ClaudeBackend } = await import('../src/backend.js');
  const claude = new ClaudeBackend({ apiKey: 'test' });
  let calls = 0;
  claude.requestAll = async () => {
    calls++;
    return new Map(claude.questions.map((q) => [q, { answer: 'no', confidence: 0.1, evidence: '' }]));
  };
  const router = new BackendRouter({ AUTOMOD_BACKEND: 'claude' });
  router.instances.claude = claude;
  const reviewer = createReviewer({ backend: router });

  const result = await reviewer.review({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer' });
  assert.equal(result.verdict, 'approve');
  assert.equal(calls, 1);
  assert.ok(result.path.filter((s) => s.model.startsWith('claude:')).length > 5);
});
