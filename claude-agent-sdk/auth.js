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

module.exports = { verifyModerator, extractJWT, FREEGLE_API_URL, SUPPORT_ROLES }
