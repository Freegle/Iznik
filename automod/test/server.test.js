// Exercises createApp() from server.js directly (no listen(), no real port)
// by driving Node's http server with real HTTP requests over a loopback
// connection. Uses FakeBackend so these run fast and without a model.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createApp } from '../src/server.js';
import { FakeBackend } from '../src/backend.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CHART_PATH = path.join(__dirname, '..', 'chart.json');

let server;
let baseUrl;

before(async () => {
  server = createApp({ backend: new FakeBackend(), chartPath: CHART_PATH });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  baseUrl = `http://127.0.0.1:${port}`;
});

after(async () => {
  await new Promise((resolve) => server.close(resolve));
});

describe('GET /health', () => {
  test('returns ok with the chart id and version', async () => {
    const res = await fetch(`${baseUrl}/health`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.deepEqual(body, { status: 'ok', chart: 'freegle-automod', version: '3' });
  });
});

describe('GET /chart', () => {
  test('returns the full chart definition', async () => {
    const res = await fetch(`${baseUrl}/chart`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.id, 'freegle-automod');
    assert.equal(body.version, '3');
    assert.ok(body.states.VETO);
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
  test('reviews a clean post and approves it', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        msgid: 1,
        groupid: 1,
        subject: 'Free sofa',
        body: 'Free two-seater sofa in good condition, must collect from the house.',
        type: 'Offer',
      }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.verdict, 'approve');
    assert.equal(body.end, 'APPROVE');
  });

  test('holds a post that matches a keyword', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        msgid: 2,
        groupid: 1,
        body: 'Can I borrow your ladder for the weekend please?',
        type: 'Offer',
      }),
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
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify([1, 2, 3]),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'Request body must be a JSON object' });
  });

  test('400s when msgid is missing', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ groupid: 1 }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'msgid and groupid are required' });
  });

  test('400s when groupid is missing', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgid: 1 }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'msgid and groupid are required' });
  });

  test('400s when facts is not an object', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgid: 1, groupid: 1, facts: 'nope' }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'facts must be an object' });
  });

  test('400s when rules is not an object', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgid: 1, groupid: 1, rules: ['nope'] }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.deepEqual(body, { error: 'rules must be an object' });
  });

  test('400s on an oversized body', async () => {
    const hugeBody = 'x'.repeat(1_000_001);
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgid: 1, groupid: 1, body: hugeBody }),
    });
    assert.equal(res.status, 400);
  });
});

describe('POST /review - PHP-encoded empty arrays', () => {
  test('treats [] facts and rules as empty objects', async () => {
    const res = await fetch(`${baseUrl}/review`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgid: 1, groupid: 1, subject: 'OFFER: Sofa', body: 'Brown sofa', type: 'Offer', facts: [], rules: [] }),
    });
    assert.equal(res.status, 200);
  });
});
