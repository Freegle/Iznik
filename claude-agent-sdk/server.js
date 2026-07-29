/**
 * Freegle Claude Agent Server
 *
 * HTTP server that bridges ModTools browser with Claude Agent SDK.
 * Uses HTTP polling for compatibility (not WebSocket).
 * The agent has codebase access for system knowledge, but requests
 * fact queries from the browser to get user data (privacy-preserving).
 */

const express = require('express')
const cors = require('cors')
const { v4: uuidv4 } = require('uuid')
const { execSync } = require('child_process')
const fs = require('fs')
const { preferSubscriptionToken } = require('./auth')
// agent.js kept for searchCodebase utility but runAgent/warmupSession no longer used

const app = express()
app.use(express.json())

// CORS: allow ModTools origins only. Dev uses *.localhost (traefik); prod/edge
// lists its ModTools origin in ALLOWED_ORIGINS (comma-separated). Requests with
// no Origin header (non-browser / same-origin) are allowed — the real access
// control is the Support/Admin JWT gate on /api/log-analysis, not CORS.
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
function corsOrigin(origin, cb) {
  if (!origin) return cb(null, true)
  try {
    const host = new URL(origin).hostname
    if (host === 'localhost' || host.endsWith('.localhost')) return cb(null, true)
  } catch (_) {
    /* malformed origin -> fall through to deny */
  }
  return cb(null, ALLOWED_ORIGINS.includes(origin))
}
app.use(cors({
  origin: corsOrigin,
  credentials: true,
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization', 'sentry-trace', 'baggage'],
}))

// Session timeout (5 minutes) - for cleanup
const SESSION_TIMEOUT = 5 * 60 * 1000

// Track auth and codebase status
let authStatus = { valid: false, checked: false, message: 'Not checked yet' }
let lastCodeUpdate = null

/**
 * Check if Anthropic API key is configured.
 */
function checkAuth() {
  if (process.env.ANTHROPIC_API_KEY) {
    authStatus = { valid: true, checked: true, message: 'Anthropic API key configured (api mode).' }
    return
  }
  if (process.env.CLAUDE_CODE_OAUTH_TOKEN) {
    authStatus = { valid: true, checked: true, message: 'Claude subscription token configured (claude setup-token).' }
    return
  }
  authStatus = {
    valid: false,
    checked: true,
    message: 'No ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN set (or mount a ~/.claude session).',
  }
}

/**
 * Update codebase from git.
 */
function updateCodebase() {
  // One monorepo clone (its subdirs are iznik-nuxt3/, iznik-server-go/, ...).
  const repos = ['/app/codebase']

  for (const repo of repos) {
    if (fs.existsSync(repo)) {
      try {
        execSync('git pull --ff-only 2>&1', { cwd: repo, timeout: 30000 })
        console.log(`Updated codebase: ${repo}`)
      } catch (error) {
        console.error(`Failed to update ${repo}:`, error.message)
      }
    }
  }

  lastCodeUpdate = new Date().toISOString()
  console.log(`Codebase update completed at ${lastCodeUpdate}`)
}

// Make the subscription token win over a stray ANTHROPIC_API_KEY, so query() authenticates
// with the Claude subscription (from `claude setup-token`) rather than metered API billing.
// The SDK/CLI bills the API whenever the key is set, so we remove it here (see auth.js).
if (preferSubscriptionToken()) {
  console.log('Claude auth: CLAUDE_CODE_OAUTH_TOKEN present - ignoring ANTHROPIC_API_KEY so the subscription is used.')
}

// Check auth on startup
checkAuth()
console.log(`Auth status: ${authStatus.message}`)

// Update codebase on startup
updateCodebase()

// Update codebase every 30 minutes
setInterval(updateCodebase, 30 * 60 * 1000)

// Health check endpoint with auth status
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'freegle-ai-support-helper',
    auth: authStatus,
    lastCodeUpdate,
  })
})

/**
 * Log analysis endpoint - uses Claude Agent SDK with pseudonymized queries.
 * POST /api/log-analysis
 * Body: { query: string, userId?: number, claudeSessionId?: string, sanitizerSessionId?: string }
 * Returns: { analysis: string, costUsd: number, usage: object, claudeSessionId: string, isNewSession: boolean }
 */
const { runSupportQuery, driverMode } = require('./support-agent')
const { verifyModerator } = require('./auth')

app.post('/api/log-analysis', async (req, res) => {
  // Authenticate the caller as a Freegle moderator/support/admin. This endpoint
  // analyses production logs and incurs Anthropic API cost, so it must not be
  // open to unauthenticated callers — having ANTHROPIC_API_KEY set on the
  // server is not authorisation. Identity + role are resolved by the Go API.
  const mod = await verifyModerator(req)
  if (!mod) {
    return res.status(403).json({
      error: 'FORBIDDEN',
      message: 'Support or Admin authentication required.',
    })
  }

  checkAuth()

  // Auth for Claude: 'api' mode needs ANTHROPIC_API_KEY; 'session' mode uses a
  // mounted ~/.claude (Max subscription) and needs no key.
  if (driverMode() === 'api' && !authStatus.valid) {
    return res.status(503).json({
      error: 'AUTH_NOT_CONFIGURED',
      message: authStatus.message,
    })
  }

  const { query, userId, claudeSessionId } = req.body
  // The caller's JWT is used for get_user_dump against the Go API. Header only —
  // never accept it as a URL query param (which would leak into access logs).
  const jwt = (req.headers.authorization || '').replace(/^Bearer\s+/i, '').trim()

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  console.log(`[LogAnalysis] ${claudeSessionId ? 'Continuing' : 'New'}: ${query.substring(0, 80)}`)

  // If client accepts SSE, stream progress events
  const wantsStream = req.headers.accept?.includes('text/event-stream')

  if (wantsStream) {
    res.setHeader('Content-Type', 'text/event-stream')
    res.setHeader('Cache-Control', 'no-cache')
    res.setHeader('Connection', 'keep-alive')
    res.flushHeaders()

    try {
      const result = await runSupportQuery({
        query,
        userId: userId || 0,
        jwt,
        agentSessionId: claudeSessionId,
        modId: mod.id,
        modEmail: mod.email,
        onProgress: (type, message) => {
          res.write(`data: ${JSON.stringify({ type, message })}\n\n`)
        },
      })
      res.write(`data: ${JSON.stringify({ type: 'result', ...result })}\n\n`)
      res.end()
    } catch (error) {
      console.error('[LogAnalysis] Stream error:', error)
      res.write(`data: ${JSON.stringify({ type: 'error', message: error.message })}\n\n`)
      res.end()
    }
    return
  }

  // Non-streaming fallback
  try {
    const result = await runSupportQuery({ query, userId: userId || 0, jwt, agentSessionId: claudeSessionId, modId: mod.id, modEmail: mod.email })
    res.json(result)
  } catch (error) {
    console.error('[LogAnalysis] Error:', error)

    if (error.status === 401 || error.message?.includes('authentication')) {
      return res.status(401).json({
        error: 'AUTH_EXPIRED',
        message: 'Claude API authentication failed',
      })
    }

    return res.status(500).json({
      error: 'ANALYSIS_FAILED',
      message: error.message || 'Unknown error',
    })
  }
})

// ---------------------------------------------------------------------------
// Device summary — a deterministic (no-AI) view of the member's recent devices,
// shown as soon as a user is selected. Reads `session_start` events (viewport/
// screen/DPR/user-agent) straight from PRODUCTION Loki over the tunnel (see
// SUPPORT_LOKI_URL). user_id is an indexed Loki label, so this is one cheap,
// targeted query — NOT the full user dump, whose email/session passes do
// full-text scans that time out against prod. No Anthropic cost; Support/Admin-
// gated and audited.
// ---------------------------------------------------------------------------
const { lokiQuery, audit } = require('./tools')
const { parseSessionStart, buildDeviceSummary } = require('./device-summary')

// The deployed web/app version to compare a member's loaded version against, so
// we can flag "needs a refresh". Read from the cloned frontend config (kept
// current by updateCodebase). Best-effort — null just leaves freshness unknown.
function currentMobileVersion() {
  try {
    const cfg = fs.readFileSync('/app/codebase/iznik-nuxt3/config.js', 'utf8')
    const m = /MOBILE_VERSION:\s*['"]([^'"]+)['"]/.exec(cfg)
    return m ? m[1] : null
  } catch (_) {
    return null
  }
}

app.get('/api/device-summary', async (req, res) => {
  const mod = await verifyModerator(req)
  if (!mod) {
    return res.status(403).json({ error: 'FORBIDDEN', message: 'Support or Admin authentication required.' })
  }
  const userId = parseInt(req.query.userId, 10)
  if (!userId || userId < 1) {
    return res.status(400).json({ error: 'BAD_REQUEST', message: 'userId is required' })
  }
  // Accessing a member's device/usage data — record who looked at whom.
  audit({ mod: mod.id, modEmail: mod.email, target: userId, tool: 'device_summary' })
  const window = '7d'
  try {
    // Client session_start events for this member, straight from prod Loki.
    // user_id is an indexed label, so this is a cheap targeted lookup. userId is
    // a validated positive integer, so it is safe to interpolate into the label
    // selector (no injection surface).
    const rows = await lokiQuery({
      query: `{app="freegle", source="client", user_id="${userId}"} |= "session_start"`,
      start: window,
      limit: 500,
    })

    const seen = new Set()
    const records = []
    for (const r of rows) {
      const d = parseSessionStart(r.line)
      if (!d) continue
      if (d.sessionId && seen.has(d.sessionId)) continue
      if (d.sessionId) seen.add(d.sessionId)
      records.push(d)
    }
    const currentVersion = currentMobileVersion()
    const devices = buildDeviceSummary(records, currentVersion)

    // A member can be fully active with NO client telemetry at all: ad/tracker
    // blockers eat the unauthenticated /clientlog POST, and app builds from
    // before client logging never send it. Fall back to their newest api-source
    // line (also a cheap indexed-label lookup) so "no sessions" can't read as
    // "not active" when the member was on the site hours ago.
    let lastApiActivity = null
    if (!devices.length) {
      try {
        const apiRows = await lokiQuery({
          query: `{app="freegle", source="api", user_id="${userId}"}`,
          start: window,
          limit: 1,
        })
        if (apiRows.length) {
          lastApiActivity = new Date(Number(BigInt(apiRows[0].ts) / 1000000n)).toISOString()
        }
      } catch (e) {
        console.error('[DeviceSummary] api-activity fallback failed:', e.message)
      }
    }

    res.json({
      userId,
      window,
      generatedAt: new Date().toISOString(),
      // Newest device sighting doubles as "last active on a device"; if the
      // device never reports, fall back to server-side API activity.
      lastaccess: devices[0]?.lastSeen || lastApiActivity,
      lastApiActivity,
      currentVersion,
      deviceCount: devices.length,
      devices,
      // freshness needs app_version (app) / build_date (web) in session_start,
      // which the frontend only just started logging — so sessions from before
      // that deploy show freshness 'unknown' (no up-to-date badge) until revisit.
      note: 'freshness comes from app_version/build_date in session_start going forward; older sessions show unknown.',
    })
  } catch (error) {
    console.error('[DeviceSummary] Error:', error)
    res.status(500).json({ error: 'DEVICE_SUMMARY_FAILED', message: error.message })
  }
})

const PORT = process.env.PORT || 3000
app.listen(PORT, () => {
  console.log(`Freegle AI Support Helper listening on port ${PORT}`)
  console.log(`Health: http://localhost:${PORT}/health`)
  console.log(`API: POST /api/log-analysis`)
})
