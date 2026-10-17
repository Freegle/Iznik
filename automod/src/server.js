// Minimal HTTP API for automod. No framework: node:http is enough for four
// routes, and it keeps the image's dependencies down to the workflow engine
// and the model runtime.
//
// Every review writes one JSON line to stdout (the container's log, so Loki
// has it): what was decided, how long it took, which model answered, what it
// cost. /health says whether the backend has been answering, and /metrics
// gives the counts since the process started.
import { createServer } from 'node:http';
import { createReviewer } from './walk.js';
import { createBackend } from './backend.js';

const MAX_BODY_BYTES = 1_000_000;

/** How many latencies are kept for the p95 in /metrics. */
const LATENCY_WINDOW = 500;

// A setting from env or options; blank, missing or below the floor means the default.
function num(value, fallback, min = 1) {
  if (value === undefined || value === null || value === '') return fallback;
  const n = Number(value);
  return Number.isFinite(n) && n >= min ? n : fallback;
}

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

/**
 * At most `limit` reviews run at once; up to `queueMax` more wait their turn; beyond that the
 * caller is told to come back later. Each review is a model call or several, and on the CLI
 * backend each call is a process, so an unbounded number of them would exhaust the container's
 * memory rather than its patience.
 */
function createGate(limit, queueMax) {
  let active = 0;
  const waiting = [];
  const release = () => {
    active -= 1;
    const next = waiting.shift();
    if (next) next();
  };
  return {
    get active() {
      return active;
    },
    get waiting() {
      return waiting.length;
    },
    /** A promise of a release function, or null when the queue is full. */
    acquire() {
      if (active < limit) {
        active += 1;
        return Promise.resolve(release);
      }
      if (waiting.length >= queueMax) {
        return null;
      }
      return new Promise((resolve) => {
        waiting.push(() => {
          active += 1;
          resolve(release);
        });
      });
    },
  };
}

function percentile(values, p) {
  if (!values.length) return null;
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
}

/**
 * Build the server without starting it, so tests can point it at a fake
 * backend and listen on an ephemeral port instead of the real one.
 *
 * Settings come from env (AUTOMOD_CONCURRENCY, AUTOMOD_QUEUE_MAX,
 * AUTOMOD_UNHEALTHY_AFTER) or the same names as options, which win.
 */
export function createApp({ backend, chartPath, env = process.env, concurrency, queueMax, unhealthyAfter } = {}) {
  const reviewer = createReviewer({ backend: backend || createBackend(env), chartPath });
  const gate = createGate(num(concurrency ?? env.AUTOMOD_CONCURRENCY, 2), num(queueMax ?? env.AUTOMOD_QUEUE_MAX, 10, 0));
  const unhealthyAt = num(unhealthyAfter ?? env.AUTOMOD_UNHEALTHY_AFTER, 20);

  const state = {
    started: new Date().toISOString(),
    backend: env.AUTOMOD_BACKEND || 'claude',
    lastSuccessAt: null,
    lastFailureAt: null,
    lastError: null,
    consecutiveFailures: 0,
    reviews: 0,
    unavailable: 0,
    errors: 0,
    cacheHits: 0,
    byEnd: {},
    byVerdict: {},
    latencies: [],
  };

  function recordLatency(ms) {
    state.latencies.push(ms);
    if (state.latencies.length > LATENCY_WINDOW) state.latencies.shift();
  }

  // A review that could not get an answer counts as a failure of the backend; any decided
  // review, hold or approve, means it is answering again.
  function recordResult(result) {
    state.reviews += 1;
    state.byEnd[result.end] = (state.byEnd[result.end] || 0) + 1;
    state.byVerdict[result.verdict] = (state.byVerdict[result.verdict] || 0) + 1;
    if (result.cache_hit) state.cacheHits += 1;
    recordLatency(result.ms);
    if (result.end === 'UNAVAILABLE') {
      const failed = result.path[result.path.length - 1];
      recordFailure(failed?.error || 'backend unavailable');
      state.unavailable += 1;
    } else {
      state.consecutiveFailures = 0;
      state.lastSuccessAt = new Date().toISOString();
    }
  }

  function recordFailure(error) {
    state.consecutiveFailures += 1;
    state.lastFailureAt = new Date().toISOString();
    state.lastError = error;
  }

  function logReview(payload, fields) {
    console.log(JSON.stringify({
      ts: new Date().toISOString(),
      event: 'review',
      msgid: payload.msgid,
      groupid: payload.groupid,
      backend: payload.backend || state.backend,
      ...fields,
    }));
  }

  async function handleReview(req, res) {
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

    const { msgid, groupid, subject, body, type, backend: backendName, deadline_ms } = payload;
    // An empty PHP array arrives as []; treat it as no facts or rules.
    const emptyList = (v) => Array.isArray(v) && v.length === 0;
    const facts = emptyList(payload.facts) ? {} : payload.facts;
    const rules = emptyList(payload.rules) ? {} : payload.rules;
    if (!msgid || !groupid) {
      sendJson(res, 400, { error: 'msgid and groupid are required' });
      return;
    }
    if (facts !== undefined && !isPlainObject(facts)) {
      sendJson(res, 400, { error: 'facts must be an object' });
      return;
    }
    if (backendName !== undefined && !['claude', 'jev', 'nli', 'fake'].includes(backendName)) {
      sendJson(res, 400, { error: 'backend must be claude, jev, nli or fake' });
      return;
    }
    if (rules !== undefined && !isPlainObject(rules)) {
      sendJson(res, 400, { error: 'rules must be an object' });
      return;
    }
    for (const [name, value] of Object.entries({ subject, body, type })) {
      if (value !== undefined && value !== null && typeof value !== 'string') {
        sendJson(res, 400, { error: `${name} must be a string` });
        return;
      }
    }
    if (deadline_ms !== undefined && (typeof deadline_ms !== 'number' || !(deadline_ms > 0))) {
      sendJson(res, 400, { error: 'deadline_ms must be a positive number of milliseconds' });
      return;
    }

    const ticket = gate.acquire();
    if (!ticket) {
      logReview(payload, { outcome: 'busy', in_flight: gate.active, queued: gate.waiting });
      sendJson(res, 503, { error: 'Automated review is busy; try again later', in_flight: gate.active, queued: gate.waiting });
      return;
    }
    const release = await ticket;

    const started = Date.now();
    try {
      const result = await reviewer.review({
        msgid,
        groupid,
        subject,
        body,
        type,
        facts: facts || {},
        rules: rules || {},
        other_posts: payload.other_posts,
        backend: backendName,
        deadline_ms,
      });
      recordResult(result);
      const failed = result.end === 'UNAVAILABLE' ? result.path[result.path.length - 1] : null;
      logReview(payload, {
        verdict: result.verdict,
        end: result.end,
        ms: result.ms,
        model: result.model,
        calls: result.calls,
        cache_hit: result.cache_hit,
        tokens: result.tokens,
        cost_usd: result.cost_usd,
        ...(failed ? { error: failed.error, node: failed.node } : {}),
        ...(deadline_ms && result.ms > deadline_ms ? { over_budget: true, deadline_ms } : {}),
      });
      sendJson(res, 200, result);
    } catch (err) {
      const ms = Date.now() - started;
      state.errors += 1;
      recordFailure(String(err.message).slice(0, 200));
      recordLatency(ms);
      logReview(payload, { outcome: 'error', ms, error: String(err.message).slice(0, 200) });
      sendJson(res, 500, { error: err.message });
    } finally {
      release();
    }
  }

  function health() {
    const failing = state.consecutiveFailures >= unhealthyAt;
    return {
      status: failing ? 'failing' : 'ok',
      chart: reviewer.chart.id,
      version: reviewer.chart.version,
      backend: state.backend,
      started: state.started,
      last_success_at: state.lastSuccessAt,
      last_failure_at: state.lastFailureAt,
      last_error: state.lastError,
      consecutive_failures: state.consecutiveFailures,
      unhealthy_after: unhealthyAt,
      in_flight: gate.active,
      queued: gate.waiting,
    };
  }

  function metrics() {
    const n = state.latencies.length;
    return {
      since: state.started,
      reviews: state.reviews,
      by_end: state.byEnd,
      by_verdict: state.byVerdict,
      unavailable: state.unavailable,
      errors: state.errors,
      cache_hits: state.cacheHits,
      cache_hit_rate: state.reviews ? state.cacheHits / state.reviews : null,
      latency_ms: {
        n,
        avg: n ? Math.round(state.latencies.reduce((s, x) => s + x, 0) / n) : null,
        p95: percentile(state.latencies, 0.95),
      },
      in_flight: gate.active,
      queued: gate.waiting,
      consecutive_failures: state.consecutiveFailures,
    };
  }

  return createServer((req, res) => {
    const url = new URL(req.url, 'http://automod');

    if (req.method === 'GET' && url.pathname === '/health') {
      const body = health();
      sendJson(res, body.status === 'ok' ? 200 : 503, body);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/metrics') {
      sendJson(res, 200, metrics());
      return;
    }
    if (req.method === 'GET' && url.pathname === '/chart') {
      sendJson(res, 200, reviewer.chart);
      return;
    }
    if (req.method === 'POST' && url.pathname === '/review') {
      handleReview(req, res);
      return;
    }
    sendJson(res, 404, { error: 'Not found' });
  });
}

const isMain = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;
if (isMain) {
  const port = Number(process.env.PORT) || 8090;
  createApp().listen(port, () => {
    console.log(JSON.stringify({ ts: new Date().toISOString(), event: 'listening', port }));
  });
}
