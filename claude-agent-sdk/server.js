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
// agent.js kept for searchCodebase utility but runAgent/warmupSession no longer used

const app = express()
app.use(express.json())
app.use(cors({
  origin: true,
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
  if (!process.env.ANTHROPIC_API_KEY) {
    authStatus = {
      valid: false,
      checked: true,
      message: 'ANTHROPIC_API_KEY not set. Add it to your .env file.',
    }
    return
  }

  authStatus = {
    valid: true,
    checked: true,
    message: 'Anthropic API key configured.',
  }
}

/**
 * Update codebase from git.
 */
function updateCodebase() {
  const repos = [
    '/app/codebase/iznik-nuxt3',
    '/app/codebase/iznik-server',
    '/app/codebase/iznik-server-go',
  ]

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
const { runLogAnalysis } = require('./log-analysis')
const { verifyModerator } = require('./auth')

app.post('/api/log-analysis', async (req, res) => {
  // Authenticate the caller as a Freegle moderator/support/admin. This endpoint
  // analyses production logs and incurs Anthropic API cost, so it must not be
  // open to unauthenticated callers — having ANTHROPIC_API_KEY set on the
  // server is not authorisation. Identity + role are resolved by the Go API.
  const role = await verifyModerator(req)
  if (!role) {
    return res.status(403).json({
      error: 'FORBIDDEN',
      message: 'Moderator authentication required.',
    })
  }

  checkAuth()

  if (!authStatus.valid) {
    return res.status(503).json({
      error: 'AUTH_NOT_CONFIGURED',
      message: authStatus.message,
    })
  }

  const { query, claudeSessionId, sanitizerSessionId } = req.body

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
      const result = await runLogAnalysis(query, sanitizerSessionId, claudeSessionId,
        (type, message) => {
          res.write(`data: ${JSON.stringify({ type, message })}\n\n`)
        }
      )
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
    const result = await runLogAnalysis(query, sanitizerSessionId, claudeSessionId)
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

const PORT = process.env.PORT || 3000
app.listen(PORT, () => {
  console.log(`Freegle AI Support Helper listening on port ${PORT}`)
  console.log(`Health: http://localhost:${PORT}/health`)
  console.log(`API: POST /api/log-analysis`)
})
