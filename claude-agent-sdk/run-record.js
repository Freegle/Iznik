'use strict'

/**
 * Recording each helper run, so the questions, what the agent did, its answers
 * and the volunteers' thumbs up/down can be reviewed in ModTools SysAdmin.
 *
 * The run goes to the Go API (POST /api/supportai/runs) with the asking
 * volunteer's own JWT, because this container's database connection is a
 * read-only grant. Recording is best-effort: a failure is logged and the
 * volunteer still gets their answer.
 *
 * Only Node built-ins are required here, so CI can run run-record.test.js with
 * no node_modules.
 */

const fs = require('fs')
const os = require('os')
const path = require('path')

// The endpoint `claude /usage` reads: the subscription's own five-hour and
// seven-day utilisation, 0-100. Undocumented; found in the Claude Code bundle.
const USAGE_URL = 'https://api.anthropic.com/api/oauth/usage'
const USAGE_TIMEOUT_MS = 5000

// One tool result can be a whole user dump. Keep enough to see what came back.
const TOOL_RESULT_CAP = 4000
// The Go API stops at 2MB; stay under it with room for the rest of the body.
const TRANSCRIPT_CAP = 1500000

/**
 * The subscription OAuth token, if the helper runs on one: the
 * `claude setup-token` token in subscription mode, or the access token in the
 * mounted credential file in session mode. Metered API mode has no
 * subscription quota, so it has no token.
 *
 * @param {'api'|'subscription'|'session'} mode from driverMode()
 */
function subscriptionToken(mode, { env = process.env, readFile = fs.readFileSync, home = os.homedir() } = {}) {
  if (mode === 'subscription') return env.CLAUDE_CODE_OAUTH_TOKEN || null
  if (mode !== 'session') return null
  try {
    const creds = JSON.parse(readFile(path.join(home, '.claude', '.credentials.json'), 'utf8'))
    return (creds && creds.claudeAiOauth && creds.claudeAiOauth.accessToken) || null
  } catch (_) {
    return null
  }
}

// A missing or null utilisation is "unknown", not 0: Number(null) is 0, which
// would read as "nothing used".
function utilisation(window) {
  const u = window && window.utilization
  return typeof u === 'number' && Number.isFinite(u) ? u : null
}

/**
 * The subscription's utilisation now, or null if it cannot be had (no token,
 * rate limited, timed out). Never throws.
 *
 * @returns {Promise<{fiveHour:number|null, sevenDay:number|null}|null>}
 */
async function fetchQuota(token, fetchImpl = fetch) {
  if (!token) return null
  try {
    const signal = typeof AbortSignal !== 'undefined' && AbortSignal.timeout ? AbortSignal.timeout(USAGE_TIMEOUT_MS) : undefined
    const res = await fetchImpl(USAGE_URL, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'anthropic-beta': 'oauth-2025-04-20',
        'User-Agent': 'claude-cli/2.0.0 (external, cli)',
      },
      signal,
    })
    if (!res || !res.ok) return null
    const data = await res.json()
    const quota = { fiveHour: utilisation(data && data.five_hour), sevenDay: utilisation(data && data.seven_day) }
    return quota.fiveHour === null && quota.sevenDay === null ? null : quota
  } catch (_) {
    return null
  }
}

function quotaFields(before, after) {
  return {
    quota_5h_before: before ? before.fiveHour : null,
    quota_5h_after: after ? after.fiveHour : null,
    quota_7d_before: before ? before.sevenDay : null,
    quota_7d_after: after ? after.sevenDay : null,
  }
}

function cap(text, max) {
  const s = String(text == null ? '' : text)
  return s.length > max ? `${s.slice(0, max)}… [${s.length - max} more characters]` : s
}

function toolResultText(content) {
  if (typeof content === 'string') return content
  if (Array.isArray(content)) {
    return content.map((c) => (c && c.type === 'text' ? c.text : c && c.type ? `[${c.type}]` : '')).join('\n')
  }
  return content == null ? '' : JSON.stringify(content)
}

/**
 * The transcript entries in one message from query(): the agent's own text,
 * each tool call with its input, and each tool result (capped).
 */
function transcriptEntries(message) {
  const out = []
  const content = (message && message.message && message.message.content) || []
  if (!Array.isArray(content)) return out
  if (message.type === 'assistant') {
    for (const block of content) {
      if (block.type === 'text' && block.text) {
        out.push({ type: 'text', text: block.text })
      } else if (block.type === 'tool_use') {
        out.push({ type: 'tool', id: block.id, name: String(block.name || '').replace(/^mcp__freegle__/, ''), input: block.input })
      }
    }
  } else if (message.type === 'user') {
    for (const block of content) {
      if (block.type === 'tool_result') {
        out.push({
          type: 'result',
          id: block.tool_use_id,
          error: !!block.is_error,
          text: cap(toolResultText(block.content), TOOL_RESULT_CAP),
        })
      }
    }
  }
  return out
}

/**
 * The transcript as JSON, dropping its oldest entries if it would not fit. The
 * end of an investigation is where it reached its answer, so that is kept.
 */
function serialiseTranscript(entries, max = TRANSCRIPT_CAP) {
  let kept = entries.slice()
  let json = JSON.stringify(kept)
  let dropped = 0
  while (json.length > max && kept.length > 1) {
    const n = Math.max(1, Math.ceil(kept.length / 10))
    kept = kept.slice(n)
    dropped += n
    json = JSON.stringify([{ type: 'text', text: `[${dropped} earlier steps left out to fit]` }, ...kept])
  }
  return json
}

/**
 * Send one run to the Go API. Returns the new run's id, or null if it could
 * not be recorded. Never throws.
 */
async function recordRun({ apiUrl, jwt, run, fetchImpl = fetch }) {
  if (!jwt) return null
  try {
    const signal = typeof AbortSignal !== 'undefined' && AbortSignal.timeout ? AbortSignal.timeout(15000) : undefined
    // The Go API reads the JWT from ?jwt= or a bare Authorization header; it
    // does not strip a "Bearer " prefix. Same form as get_user_dump.
    const res = await fetchImpl(`${apiUrl}/api/supportai/runs?jwt=${encodeURIComponent(jwt)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(run),
      signal,
    })
    if (!res || !res.ok) {
      console.error(`[RunRecord] could not record run: HTTP ${res && res.status}`)
      return null
    }
    const data = await res.json()
    return (data && data.id) || null
  } catch (e) {
    console.error('[RunRecord] could not record run:', e.message)
    return null
  }
}

module.exports = {
  subscriptionToken,
  fetchQuota,
  quotaFields,
  transcriptEntries,
  serialiseTranscript,
  recordRun,
  USAGE_URL,
  TOOL_RESULT_CAP,
}
