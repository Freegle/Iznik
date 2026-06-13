'use strict'

// Base URL of the Freegle Go API used to validate sessions. The AI support
// helper has no database access, so it delegates identity + role resolution to
// the main API (the source of truth for sessions and systemroles). Override via
// the FREEGLE_API_URL env var per environment.
const FREEGLE_API_URL = process.env.FREEGLE_API_URL || 'http://apiv2.localhost:8192'

// Freegle systemroles permitted to use the AI support helper. A plain "User"
// (or an unauthenticated caller) is never allowed.
const MODERATOR_ROLES = new Set(['Moderator', 'Support', 'Admin'])

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
 * Verify that the caller is a logged-in Freegle moderator/support/admin by
 * validating their JWT against the Go API session endpoint.
 *
 * @param {object} req - the incoming Express request.
 * @param {function} [fetchImpl] - fetch implementation (injectable for tests).
 * @returns {Promise<string|null>} the caller's systemrole on success, or null
 *   when the caller is unauthenticated or not a moderator.
 */
async function verifyModerator(req, fetchImpl = fetch) {
  const jwt = extractJWT(req)
  if (!jwt) {
    return null
  }

  let resp
  try {
    resp = await fetchImpl(
      `${FREEGLE_API_URL}/api/session?jwt=${encodeURIComponent(jwt)}`,
      { headers: { Accept: 'application/json' } }
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

  const role = data && data.me && data.me.systemrole
  return MODERATOR_ROLES.has(role) ? role : null
}

module.exports = { verifyModerator, extractJWT, FREEGLE_API_URL, MODERATOR_ROLES }
