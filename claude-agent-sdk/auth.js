'use strict'

// Base URL of the Freegle Go API used to validate sessions. The AI support
// helper delegates identity + role resolution to the main API (the source of
// truth for sessions and systemroles). Override via FREEGLE_API_URL per env.
const FREEGLE_API_URL = process.env.FREEGLE_API_URL || 'http://apiv2.localhost:8192'

// Freegle systemroles permitted to use the AI support helper. Support and Admin
// are the trusted roles that may already access confidential member data; a
// plain "Moderator" (or "User", or an unauthenticated caller) is NOT allowed to
// drive the de-anonymised support tooling. This is the guard against other mods
// obtaining support-level access.
const SUPPORT_ROLES = new Set(['Support', 'Admin'])

// How long to wait for the session-verification call before failing closed.
const VERIFY_TIMEOUT_MS = 5000

/**
 * Extract a bearer JWT from a request's Authorization header.
 * @returns {string} the token, or '' if absent/malformed.
 */
function extractJWT(req) {
  const header = (req && req.headers && req.headers.authorization) || ''
  if (header.startsWith('Bearer ')) {
    return header.slice(7).trim()
  }
  return ''
}

/**
 * Verify that the caller is a logged-in Freegle Support or Admin user by
 * validating their JWT against the Go API session endpoint.
 *
 * @param {object} req - the incoming Express request.
 * @param {function} [fetchImpl] - fetch implementation (injectable for tests).
 * @returns {Promise<{role:string,id:number,email:string}|null>} the caller's
 *   identity on success, or null when unauthenticated / not Support-or-Admin.
 */
async function verifyModerator(req, fetchImpl = fetch) {
  const jwt = extractJWT(req)
  if (!jwt) {
    return null
  }

  let resp
  try {
    // Fail closed if the API is slow/unreachable rather than hanging the request.
    const signal =
      typeof AbortSignal !== 'undefined' && AbortSignal.timeout
        ? AbortSignal.timeout(VERIFY_TIMEOUT_MS)
        : undefined
    resp = await fetchImpl(
      `${FREEGLE_API_URL}/api/session?jwt=${encodeURIComponent(jwt)}`,
      { headers: { Accept: 'application/json' }, signal }
    )
  } catch (e) {
    console.error('[Auth] session verification request failed:', e.message)
    return null
  }

  if (!resp || !resp.ok) {
    return null
  }

  let data
  try {
    data = await resp.json()
  } catch (e) {
    return null
  }

  const me = (data && data.me) || null
  const role = me && me.systemrole
  if (!SUPPORT_ROLES.has(role)) {
    return null
  }
  return { role, id: Number(me.id) || 0, email: me.email || '' }
}

/**
 * Which credential the Claude Agent SDK's query() will use:
 *   - 'subscription' - CLAUDE_CODE_OAUTH_TOKEN set (a token from `claude setup-token`;
 *                      Max/Pro subscription billing, and HEADLESS - no interactive
 *                      login and no mounted ~/.claude, so the helper can be driven by
 *                      an automation/subagent).
 *   - 'api'          - only ANTHROPIC_API_KEY set (metered API billing; production/edge).
 *   - 'session'      - neither; a mounted ~/.claude login session (interactive/testing).
 *
 * CLAUDE_CODE_OAUTH_TOKEN WINS when both are set - these are headless background jobs and
 * the whole point of a `claude setup-token` is to run them on the subscription rather than
 * metered API spend (kept consistent with Community News' CommunityNewsResearchService).
 * Preferring the token in this reporting function is not enough on its own: the SDK/CLI
 * bills the API whenever ANTHROPIC_API_KEY is present (the monitor-fsm's run-loop.sh unsets
 * it for exactly this reason), so preferSubscriptionToken() removes the key from the
 * environment at startup so query() actually authenticates with the subscription.
 *
 * @returns {'api'|'subscription'|'session'}
 */
function driverMode() {
  if (process.env.CLAUDE_CODE_OAUTH_TOKEN) return 'subscription'
  if (process.env.ANTHROPIC_API_KEY) return 'api'
  return 'session'
}

/**
 * Make the subscription token win. When a `claude setup-token` OAuth token is present,
 * remove ANTHROPIC_API_KEY from the environment so the Claude Agent SDK / CLI authenticates
 * with the subscription instead of silently billing the metered API (which it does whenever
 * the key is set - the same reason monitor-fsm/run-loop.sh unsets it). No-op when no OAuth
 * token is set. Returns true if it removed a key. Call once at startup, before query() runs.
 */
function preferSubscriptionToken() {
  if (process.env.CLAUDE_CODE_OAUTH_TOKEN && process.env.ANTHROPIC_API_KEY) {
    delete process.env.ANTHROPIC_API_KEY
    return true
  }
  return false
}

module.exports = { verifyModerator, extractJWT, driverMode, preferSubscriptionToken, FREEGLE_API_URL, SUPPORT_ROLES }
