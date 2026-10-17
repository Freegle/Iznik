// Exercises createApp() from server.js directly (no listen(), no real port)
// by driving Node's http server with real HTTP requests over a loopback
// connection. Uses FakeBackend so these run fast and without a model.
import { test, describe, before, after, mock } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createApp } from '../src/server.js';
import { FakeBackend } from '../src/backend.js';
import { VERSION } from '../scripts/build-chart.mjs';
import { completeFacts } from './helpers.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CHART_PATH = path.join(__dirname, '..', 'chart.json');

let server;
let baseUrl;

// Starts an app on an ephemeral port; the caller closes it.
async function listen(options) {
  const app = createApp({ chartPath: CHART_PATH, env: {}, ...options });
  await new Promise((resolve) => app.listen(0, '127.0.0.1', resolve));
  return { app, url: `http://127.0.0.1:${app.address().port}` };
}

function review(url, body) {
  return fetch(`${url}/review`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
}

// A backend that takes a while, so two reviews overlap.
function slowBackend(ms) {
  return {
    setQuestions() {},
    async ask() {
      await new Promise((resolve) => setTimeout(resolve, ms));
      return { p: 0.05, answer: 'no', model: 'slow' };
    },
  };
}

before(async () => {
  ({ app: server, url: baseUrl } = await listen({ backend: new FakeBackend() }));
});

after(async () => {
  await new Promise((resolve) => server.close(resolve));
});

describe('GET /health', () => {
  test('returns ok with the chart id and version, and says the backend has not failed', async () => {
    const res = await fetch(`${baseUrl}/health`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.status, 'ok');
    assert.equal(body.chart, 'freegle-automod');
    assert.equal(body.version, VERSION);
    assert.equal(body.backend, 'claude');
    assert.equal(body.consecutive_failures, 0);
    assert.equal(body.last_error, null);
    assert.equal(body.in_flight, 0);
  });
});

describe('GET /chart', () => {
  test('returns the full chart definition', async () => {
    const res = await fetch(`${baseUrl}/chart`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.id, 'freegle-automod');
    assert.equal(body.version, VERSION);
    assert.ok(body.states.MOD_NOTE);
  });
});

describe('GET /nonsense', () => {
  test('returns 404', async () => {
    const res = await fetch(`${baseUrl}/nonsense`);
    assert.equal(res.status, 404);
    const body = await res.json();
    assert.deepEqual(body, { error: 'Not found' });
  });
});

describe('POST /review', () => {
  test('reviews a clean post and approves it, and logs one line for it', async () => {
    const log = mock.method(console, 'log', () => {});
    try {
      const res = await review(baseUrl, {
        msgid: 1,
        groupid: 1,
        subject: 'Free sofa',
        body: 'Free two-seater sofa in good condition, must collect from the house.',
        type: 'Offer',
        facts: completeFacts(),
      });
      assert.equal(res.status, 200);
      const body = await res.json();
      assert.equal(body.verdict, 'approve');
      assert.equal(body.end, 'APPROVE');
      assert.equal(typeof body.ms, 'number');
      const line = JSON.parse(log.mock.calls.at(-1).arguments[0]);
      assert.equal(line.event, 'review');
      assert.equal(line.msgid, 1);
      assert.equal(line.groupid, 1);
      assert.equal(line.verdict, 'approve');
      assert.equal(line.end, 'APPROVE');
      assert.equal(line.backend, 'claude');
      assert.equal(typeof line.ms, 'number');
      assert.equal(line.calls, 0);
      assert.equal(line.cache_hit, false);
      assert.ok(!('over_budget' in line));
    } finally {
      log.mock.restore();
    }
  });

  test('holds a post that matches a keyword', async () => {
    const res = await review(baseUrl, {
      msgid: 2,
      groupid: 1,
      body: 'Can I borrow your ladder for the weekend please?',
      type: 'Offer',
      facts: completeFacts(),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.verdict, 'hold');
    assert.equal(body.end, 'HOLD_LOAN');
  });

  test('400s on malformed JSON', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{not json',
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'Request body must be valid JSON' });
  });

  test('400s when the body is valid JSON but not an object', async () => {
    const res = fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify([1, 2, 3]),
    });
    assert.equal((await res).status, 400);
    assert.deepEqual(await (await res).json(), { error: 'Request body must be a JSON object' });
  });

  test('400s when msgid is missing', async () => {
    const res = await review(baseUrl, { groupid: 1 });
    assert.equal(res.status, 400);
    assert.deepEqual(await res.json(), { error: 'msgid and groupid are required' });
  });

  test('400s when groupid is missing', async () => {
    const res = await review(baseUrl, { msgid: 1 });
    assert.equal(res.status, 400);
    assert.deepEqual(await res.json(), { error: 'msgid and groupid are required' });
  });

  test('400s when facts is not an object', async () => {
    const res = await review(baseUrl, { msgid: 1, groupid: 1, facts: 'nope' });
    assert.equal(res.status, 400);
    assert.deepEqual(await res.json(), { error: 'facts must be an object' });
  });

  test('400s when rules is not an object', async () => {
    const res = await review(baseUrl, { msgid: 1, groupid: 1, rules: ['nope'] });
    assert.equal(res.status, 400);
    assert.deepEqual(await res.json(), { error: 'rules must be an object' });
  });

  test('400s when the body is not a string, or the deadline is not a positive number', async () => {
    let res = await review(baseUrl, { msgid: 1, groupid: 1, body: { nested: true } });
    assert.equal(res.status, 400);
    assert.deepEqual(await res.json(), { error: 'body must be a string' });

    res = await review(baseUrl, { msgid: 1, groupid: 1, body: 'Sofa', deadline_ms: '20' });
    assert.equal(res.status, 400);
    assert.match((await res.json()).error, /deadline_ms/);
  });

  test('400s on an oversized body', async () => {
    const hugeBody = 'x'.repeat(1_000_001);
    const res = await review(baseUrl, { msgid: 1, groupid: 1, body: hugeBody });
    assert.equal(res.status, 400);
  });
});

// These reviews are unavailable by design, so they run on an app of their own: the shared one's
// metrics (below) count what happened on it.
describe('POST /review - the request must carry the facts', () => {
  test('a request with no facts is held as unavailable, never approved', async () => {
    const { app, url } = await listen({ backend: new FakeBackend() });
    const err = mock.method(console, 'error', () => {});
    const log = mock.method(console, 'log', () => {});
    try {
      const res = await review(url, { msgid: 1, groupid: 1, subject: 'Free sofa', body: 'Free sofa, collect.', type: 'Offer' });
      assert.equal(res.status, 200);
      const body = await res.json();
      assert.equal(body.verdict, 'hold');
      assert.equal(body.end, 'UNAVAILABLE');

      // It counts against the backend's health, so a batch that has stopped sending a fact shows.
      const health = await (await fetch(`${url}/health`)).json();
      assert.equal(health.consecutive_failures, 1);
      assert.match(health.last_error, /mod_note/);
    } finally {
      err.mock.restore();
      log.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });
});

describe('POST /review - PHP-encoded empty arrays', () => {
  test('[] rules are an empty object; [] facts are accepted as the right type but are no facts', async () => {
    const withFacts = await review(baseUrl, { msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts(), rules: [] });
    assert.equal(withFacts.status, 200);
    assert.equal((await withFacts.json()).end, 'APPROVE');

    const { app, url } = await listen({ backend: new FakeBackend() });
    const err = mock.method(console, 'error', () => {});
    const log = mock.method(console, 'log', () => {});
    try {
      const noFacts = await review(url, { msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: [], rules: [] });
      assert.equal(noFacts.status, 200, 'an empty PHP array is not rejected as the wrong type');
      assert.equal((await noFacts.json()).end, 'UNAVAILABLE', 'and an empty set of facts is not a set of answers of no');
    } finally {
      err.mock.restore();
      log.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });
});

describe('GET /metrics', () => {
  test('counts the reviews so far by end node, with latencies', async () => {
    const res = await fetch(`${baseUrl}/metrics`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.reviews >= 3);
    assert.ok(body.by_end.APPROVE >= 2);
    assert.equal(body.by_end.HOLD_LOAN, 1);
    assert.equal(body.by_verdict.hold, 1);
    assert.equal(body.unavailable, 0);
    assert.equal(body.errors, 0);
    assert.equal(body.latency_ms.n, body.reviews);
    assert.equal(typeof body.latency_ms.avg, 'number');
    assert.equal(typeof body.latency_ms.p95, 'number');
    assert.equal(body.in_flight, 0);
  });
});

describe('concurrency', () => {
  test('a review beyond the limit and the queue is told to come back later', async () => {
    const { app, url } = await listen({ backend: slowBackend(150), concurrency: 1, queueMax: 0 });
    const log = mock.method(console, 'log', () => {});
    try {
      const post = { msgid: 5, groupid: 1, subject: 'Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() };
      const [a, b] = await Promise.all([review(url, post), review(url, { ...post, msgid: 6 })]);
      assert.deepEqual([a.status, b.status].sort(), [200, 503]);
      const busy = a.status === 503 ? a : b;
      const body = await busy.json();
      assert.match(body.error, /busy/);
      assert.ok(log.mock.calls.some((c) => JSON.parse(c.arguments[0]).outcome === 'busy'));
    } finally {
      log.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });

  test('a review within the queue waits its turn and is answered', async () => {
    const { app, url } = await listen({ backend: slowBackend(50), concurrency: 1, queueMax: 5 });
    try {
      const post = { msgid: 7, groupid: 1, subject: 'Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() };
      const results = await Promise.all([7, 8, 9].map((msgid) => review(url, { ...post, msgid })));
      assert.deepEqual(results.map((r) => r.status), [200, 200, 200]);
    } finally {
      await new Promise((resolve) => app.close(resolve));
    }
  });
});

describe('deadline', () => {
  test('a review that overran the caller\'s deadline is logged as over budget', async () => {
    const { app, url } = await listen({ backend: slowBackend(30) });
    const log = mock.method(console, 'log', () => {});
    try {
      const res = await review(url, { msgid: 10, groupid: 1, subject: 'Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts(), deadline_ms: 1 });
      assert.equal(res.status, 200);
      const line = JSON.parse(log.mock.calls.at(-1).arguments[0]);
      assert.equal(line.over_budget, true);
      assert.equal(line.deadline_ms, 1);
    } finally {
      log.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });
});

describe('readiness', () => {
  test('/health fails after the backend could not answer N reviews in a row, and says why', async () => {
    const throwing = { setQuestions() {}, async ask() { throw new Error('token lapsed'); } };
    const { app, url } = await listen({ backend: throwing, unhealthyAfter: 2 });
    const log = mock.method(console, 'log', () => {});
    const err = mock.method(console, 'error', () => {});
    try {
      const post = { msgid: 20, groupid: 1, subject: 'Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() };
      const first = await review(url, post);
      assert.equal(first.status, 200);
      assert.equal((await first.json()).end, 'UNAVAILABLE');
      let health = await fetch(`${url}/health`);
      assert.equal(health.status, 200, 'one failure is not yet unhealthy');

      await review(url, { ...post, msgid: 21 });
      health = await fetch(`${url}/health`);
      assert.equal(health.status, 503);
      const body = await health.json();
      assert.equal(body.status, 'failing');
      assert.equal(body.consecutive_failures, 2);
      assert.match(body.last_error, /token lapsed/);
      assert.equal(body.last_success_at, null);

      const line = JSON.parse(log.mock.calls.at(-1).arguments[0]);
      assert.equal(line.end, 'UNAVAILABLE');
      assert.match(line.error, /token lapsed/);
      assert.equal(line.node, 'VAGUE');
      assert.ok(err.mock.calls.length >= 2, 'the cause went to stderr');

      const metrics = await (await fetch(`${url}/metrics`)).json();
      assert.equal(metrics.unavailable, 2);
    } finally {
      log.mock.restore();
      err.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });

  test('a decided review clears the failure count', async () => {
    let fail = true;
    const flaky = { setQuestions() {}, async ask() { if (fail) throw new Error('blip'); return { p: 0.05, answer: 'no', model: 'ok' }; } };
    const { app, url } = await listen({ backend: flaky, unhealthyAfter: 1 });
    const log = mock.method(console, 'log', () => {});
    const err = mock.method(console, 'error', () => {});
    try {
      const post = { msgid: 30, groupid: 1, subject: 'Sofa', body: 'Brown sofa', type: 'Offer', facts: completeFacts() };
      await review(url, post);
      assert.equal((await fetch(`${url}/health`)).status, 503);
      fail = false;
      await review(url, { ...post, msgid: 31 });
      const health = await fetch(`${url}/health`);
      assert.equal(health.status, 200);
      const body = await health.json();
      assert.equal(body.consecutive_failures, 0);
      assert.ok(body.last_success_at);
      assert.match(body.last_error, /blip/, 'the last error is still shown');
    } finally {
      log.mock.restore();
      err.mock.restore();
      await new Promise((resolve) => app.close(resolve));
    }
  });
});
