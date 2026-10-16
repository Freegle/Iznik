'use strict'

/**
 * The plumbing shared by the helper's /internal routes, which the batch calls across the docker
 * network: who may call (a private peer, not forwarded, holding the shared secret), one run at a
 * time across every route, the Express handler that wraps a run, and the reader of a model's JSON
 * answer. The routes are built in server.js and each route's own work is in its own file, so
 * nothing here decides what a route does.
 *
 * docs/developers/reference/ai-support-helper.md.
 */

const crypto = require('node:crypto')

// ---------------------------------------------------------------------------
// Who may call the endpoint
// ---------------------------------------------------------------------------

/**
 * Is this a private (non-routable) address: loopback, RFC1918, an IPv6 loopback or unique-local
 * one, or an IPv4-mapped IPv6 form of those (Node reports `::ffff:10.0.0.5` for an IPv4 peer on a
 * dual-stack socket). Anything that is not a string we can parse is not private.
 */
function isPrivateAddress(ip) {
  if (typeof ip !== 'string') return false

  let addr = ip.trim().toLowerCase()
  // A zone id (fe80::1%eth0) is not part of the address.
  addr = addr.replace(/%.*$/, '')

  const mapped = addr.match(/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/)
  if (mapped) addr = mapped[1]

  const v4 = addr.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/)
  if (v4) {
    const o = v4.slice(1).map(Number)
    if (o.some((n) => n > 255)) return false

    return (
      o[0] === 10 ||
      o[0] === 127 ||
      (o[0] === 172 && o[1] >= 16 && o[1] <= 31) ||
      (o[0] === 192 && o[1] === 168)
    )
  }

  if (addr === '::1') return true

  // IPv6 unique-local, fc00::/7: the form a docker network gets when IPv6 is on.
  return /^f[cd][0-9a-f]{2}:/.test(addr)
}

/**
 * Is the presented secret the configured one. Compared as SHA-256 digests with timingSafeEqual,
 * so neither the length nor the first differing byte leaks through timing. A secret that is unset
 * or empty never matches anything: an unconfigured helper refuses everyone rather than admitting
 * every caller.
 */
function secretMatches(presented, expected) {
  if (typeof expected !== 'string' || expected === '') return false
  if (typeof presented !== 'string' || presented === '') return false

  const digest = (v) => crypto.createHash('sha256').update(v).digest()

  return crypto.timingSafeEqual(digest(presented), digest(expected))
}

// Headers a reverse proxy or load balancer adds. These routes are only for the batch, calling
// straight across the docker network. A request that carries one has been forwarded by something,
// so its peer address is the proxy's and says nothing about who really sent it.
const FORWARDING_HEADERS = ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'forwarded']

/**
 * The guard for every /internal route, kept here so it can be tested without importing the heavy
 * server.js. All three must hold:
 *
 *  - the peer is on a private address;
 *  - the request was not forwarded (no X-Forwarded-For, Forwarded or the like), because a proxy
 *    that forwards to this container has a private source address itself, so the peer check alone
 *    passes whatever the real client was;
 *  - the X-Freegle-Internal-Secret header equals the UX_INTERNAL_SECRET environment variable. An
 *    unset variable refuses everyone. Each accepted call spends the subscription on a long run, so
 *    network position is not enough.
 *
 * @param {object} req - an Express request (only socket.remoteAddress and headers are read).
 * @returns {{ok:boolean, error?:string}}
 */
function internalGuard(req) {
  const peer = req && req.socket && req.socket.remoteAddress

  if (!isPrivateAddress(peer)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  const headers = (req && req.headers) || {}
  if (FORWARDING_HEADERS.some((h) => headers[h] !== undefined)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  if (!secretMatches(headers['x-freegle-internal-secret'], process.env.UX_INTERNAL_SECRET)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  return { ok: true }
}

// ---------------------------------------------------------------------------
// Reading the model's answer
// ---------------------------------------------------------------------------

/**
 * Pull a JSON object out of a model reply. With the SDK's structured output this is not needed,
 * but if that option is ignored or fails the reply is text, and models wrap JSON in prose and
 * fences. Tries, in order: the whole text, each fenced block (last first, since the answer comes
 * after any thinking out loud), then the outermost braces. Returns null if nothing parses to an
 * object.
 */
function parseModelJson(text) {
  if (typeof text !== 'string') return null

  const tryParse = (s) => {
    try {
      const v = JSON.parse(s)

      return v && typeof v === 'object' && !Array.isArray(v) ? v : null
    } catch (e) {
      return null
    }
  }

  const whole = text.replace(/^﻿/, '').trim()
  let v = tryParse(whole)
  if (v) return v

  const fences = [...whole.matchAll(/```(?:json|JSON)?\s*\n?([\s\S]*?)```/g)].map((m) => m[1].trim())
  for (const f of fences.reverse()) {
    v = tryParse(f)
    if (v) return v
  }

  const first = whole.indexOf('{')
  const last = whole.lastIndexOf('}')
  if (first !== -1 && last > first) {
    v = tryParse(whole.slice(first, last + 1))
    if (v) return v
  }

  return null
}

// ---------------------------------------------------------------------------
// One run at a time, and the route handler
// ---------------------------------------------------------------------------

// One run at a time across every route. Each is a long model run on the shared subscription
// token, which the support helper also uses; running them side by side would only make each
// slower and trip the rate limit sooner.
//
// maxPending bounds the queue: with a job already running or waiting, a new one is refused with
// an error whose code is BUSY instead of joining the line. The routes use 1, so a caller can never
// stack up work behind a long run, and the batch's next request is told to come back rather than
// spend its own timeout waiting.
let chain = Promise.resolve()
let pending = 0
function serialise(fn, opts = {}) {
  const max = opts.maxPending === undefined ? Infinity : opts.maxPending
  if (pending >= max) {
    const busy = new Error('another run is in progress')
    busy.code = 'BUSY'

    return Promise.reject(busy)
  }

  pending++
  const next = chain.then(() => fn())
  const done = () => {
    pending--
  }
  next.then(done, done)
  chain = next.catch(() => {})

  return next
}

/**
 * An Express handler for one of the /internal routes: guard, clean the body, run one job at a
 * time, and stop the job if the caller disconnects. server.js builds every route from this, and it
 * is separate from server.js so a test can drive it with plain objects.
 *
 * @param {object} o
 * @param {string} o.tag - the log prefix, e.g. 'UxThemes'.
 * @param {function} o.clean - body => {error} | the arguments for run.
 * @param {function} o.run - (cleaned, {signal}) => Promise<result>.
 * @param {function} [o.summary] - result => extra text for the log line.
 */
function internalHandler({ tag, clean, run, summary }) {
  return async (req, res) => {
    const guard = internalGuard(req)
    if (!guard.ok) {
      return res.status(403).json({ error: guard.error })
    }

    const request = clean(req.body)
    if (request.error) {
      return res.status(400).json({ error: request.error })
    }

    // Aborted if the connection closes before the answer is sent, so a caller that timed out does
    // not leave a run going.
    const controller = new AbortController()
    const onClose = () => {
      if (!res.writableFinished) controller.abort()
    }
    res.on('close', onClose)

    try {
      const result = await serialise(() => run(request, { signal: controller.signal }), { maxPending: 1 })
      console.log(`[${tag}] ok ${summary ? summary(result, request) : ''} cost=$${(result.costUsd || 0).toFixed(4)} model=${result.model}`)
      res.json(result)
    } catch (error) {
      if (error && error.code === 'BUSY') {
        return res.status(429).json({ error: 'BUSY', message: error.message })
      }
      console.error(`[${tag}] FAILED:`, error.message)
      res.status(502).json({ error: error.message })
    }
  }
}

module.exports = {
  isPrivateAddress,
  secretMatches,
  FORWARDING_HEADERS,
  internalGuard,
  parseModelJson,
  serialise,
  internalHandler,
}
