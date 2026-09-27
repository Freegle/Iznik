// Minimal HTTP API for automod. No framework: node:http is enough for three
// routes, and it keeps the image's dependencies down to the workflow engine
// and the model runtime.
import { createServer } from 'node:http';
import { createReviewer } from './walk.js';
import { createBackend } from './backend.js';

const MAX_BODY_BYTES = 1_000_000;

function sendJson(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(data),
  });
  res.end(data);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    let tooLarge = false;
    req.on('data', (chunk) => {
      // Once oversized, drop further chunks instead of destroying the
      // socket: destroying it mid-request aborts the connection before the
      // client can read the 400 response, so it sees a network error rather
      // than a clean rejection.
      if (tooLarge) return;
      data += chunk;
      if (data.length > MAX_BODY_BYTES) {
        tooLarge = true;
        reject(new Error('Request body too large'));
      }
    });
    req.on('end', () => {
      if (!tooLarge) resolve(data);
    });
    req.on('error', reject);
  });
}

function isPlainObject(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

async function handleReview(req, res, reviewer) {
  let payload;
  try {
    const raw = await readBody(req);
    payload = raw ? JSON.parse(raw) : {};
  } catch {
    sendJson(res, 400, { error: 'Request body must be valid JSON' });
    return;
  }

  if (!isPlainObject(payload)) {
    sendJson(res, 400, { error: 'Request body must be a JSON object' });
    return;
  }

  const { msgid, groupid, subject, body, type, facts, rules, backend } = payload;
  if (!msgid || !groupid) {
    sendJson(res, 400, { error: 'msgid and groupid are required' });
    return;
  }
  if (facts !== undefined && !isPlainObject(facts)) {
    sendJson(res, 400, { error: 'facts must be an object' });
    return;
  }
  if (backend !== undefined && !["claude", "nli", "fake"].includes(backend)) {
    sendJson(res, 400, { error: "backend must be claude, nli or fake" });
    return;
  }
  if (rules !== undefined && !isPlainObject(rules)) {
    sendJson(res, 400, { error: 'rules must be an object' });
    return;
  }

  try {
    const result = await reviewer.review({
      msgid,
      groupid,
      subject,
      body,
      type,
      facts: facts || {},
      rules: rules || {},
      backend,
    });
    sendJson(res, 200, result);
  } catch (err) {
    sendJson(res, 500, { error: err.message });
  }
}

/**
 * Build the server without starting it, so tests can point it at a fake
 * backend and listen on an ephemeral port instead of the real one.
 */
export function createApp({ backend, chartPath } = {}) {
  const reviewer = createReviewer({ backend: backend || createBackend(), chartPath });

  return createServer((req, res) => {
    const url = new URL(req.url, 'http://automod');

    if (req.method === 'GET' && url.pathname === '/health') {
      sendJson(res, 200, { status: 'ok', chart: reviewer.chart.id, version: reviewer.chart.version });
      return;
    }
    if (req.method === 'GET' && url.pathname === '/chart') {
      sendJson(res, 200, reviewer.chart);
      return;
    }
    if (req.method === 'POST' && url.pathname === '/review') {
      handleReview(req, res, reviewer);
      return;
    }
    sendJson(res, 404, { error: 'Not found' });
  });
}

const isMain = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;
if (isMain) {
  const port = Number(process.env.PORT) || 8090;
  createApp().listen(port, () => {
    console.log(`automod listening on ${port}`);
  });
}
