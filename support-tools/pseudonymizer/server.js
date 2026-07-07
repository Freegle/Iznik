/**
 * Pseudonymizer Service (Container 3)
 *
 * Core service for privacy-preserving log analysis:
 * - Receives mappings from Query Sanitizer (Container 0)
 * - Translates pseudonymized queries to real values for Loki
 * - Pseudonymizes Loki results before returning to MCP Interface
 * - Maintains audit log of all queries
 *
 * Security: This container has access to the key (mapping) and Loki.
 * It is the only container that can see real PII.
 */

const express = require('express')
const { v4: uuidv4 } = require('uuid')
const Database = require('better-sqlite3')
const fs = require('fs')
const path = require('path')

const app = express()
app.use(express.json())

const LOKI_URL = process.env.LOKI_URL || 'http://loki:3100'
const DATA_DIR = process.env.DATA_DIR || '/data'
const AUDIT_LOG_DIR = process.env.AUDIT_LOG_DIR || '/var/log/mcp-audit'

// Ensure directories exist
fs.mkdirSync(DATA_DIR, { recursive: true })
fs.mkdirSync(AUDIT_LOG_DIR, { recursive: true })

// SQLite database for persistent token mappings
const db = new Database(path.join(DATA_DIR, 'mappings.db'))

// Initialize database schema
db.exec(`
  CREATE TABLE IF NOT EXISTS token_mappings (
    token TEXT PRIMARY KEY,
    real_value TEXT NOT NULL,
    field_type TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );

  CREATE INDEX IF NOT EXISTS idx_real_value ON token_mappings(real_value);

  CREATE TABLE IF NOT EXISTS session_mappings (
    session_id TEXT NOT NULL,
    token TEXT NOT NULL,
    user_id INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT,
    PRIMARY KEY (session_id, token)
  );

  CREATE INDEX IF NOT EXISTS idx_session_expires ON session_mappings(expires_at);
`)

// Prepared statements for performance
const stmts = {
  getToken: db.prepare('SELECT token FROM token_mappings WHERE real_value = ? COLLATE NOCASE'),
  insertToken: db.prepare('INSERT OR IGNORE INTO token_mappings (token, real_value, field_type) VALUES (?, ?, ?)'),
  getRealValue: db.prepare('SELECT real_value FROM token_mappings WHERE token = ?'),
  insertSession: db.prepare('INSERT OR REPLACE INTO session_mappings (session_id, token, user_id, expires_at) VALUES (?, ?, ?, ?)'),
  getSessionTokens: db.prepare('SELECT token FROM session_mappings WHERE session_id = ?'),
  cleanExpiredSessions: db.prepare("DELETE FROM session_mappings WHERE expires_at < datetime('now')"),
}

// Clean expired sessions periodically
setInterval(() => {
  stmts.cleanExpiredSessions.run()
}, 60000)

/**
 * Get or create a persistent token for a value.
 * Same value always maps to same token for cross-session correlation.
 */
function getOrCreateToken(value, fieldType) {
  const normalizedValue = value.toLowerCase().trim()

  // Check if token already exists
  const existing = stmts.getToken.get(normalizedValue)
  if (existing) {
    return existing.token
  }

  // Create new token
  const token = `${fieldType}_${uuidv4().slice(0, 8)}`
  stmts.insertToken.run(token, normalizedValue, fieldType)

  return token
}

/**
 * Get real value for a token.
 */
function getRealValue(token) {
  const row = stmts.getRealValue.get(token)
  return row ? row.real_value : null
}

/**
 * Escape special regex characters.
 */
function escapeRegex(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * PII detection patterns (used for scanning free-text fields).
 */
const PII_PATTERNS = {
  email: /[\w.-]+@[\w.-]+\.\w+/gi,
  ip: /(?<![/\d])\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/g,
  phone: /\b(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}\b/g,
  postcode: /\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/gi,
}

// ============================================================
// Field-by-field pseudonymization schema
// ============================================================

// Fields that are always safe to pass through (never contain PII)
const SAFE_FIELDS = new Set([
  'endpoint', 'duration_ms', 'timestamp', 'client_timestamp',
  'request_id', 'trace_id', 'method', 'status_code',
  'freegle_page', 'freegle_session', 'api_version',
  'level', 'source', 'filename', 'host', 'service_name',
  // Response metadata
  'online', 'supporter', 'spammer', 'newslettersallowed',
  'showmod', 'engagement', 'trustlevel', 'systemrole',
  'deleted', 'forgotten', 'donatedtype',
  // Counts and stats (no PII)
  'offers', 'wanteds', 'taken', 'collected', 'reneged',
  'replies', 'repliesoffer', 'replieswanted', 'replytime',
  'openoffers', 'openwanteds', 'openage', 'expectedreply', 'expectedreplies',
  // Message metadata
  'type', 'subject', 'collection', 'arrival', 'hasoutcome',
  // Group metadata
  'groupname', 'region', 'membercount',
  // UI/client metadata
  'event_type', 'page_name', 'route_name', 'message',
  'is_touch', 'is_standalone', 'cookie_enabled', 'orientation',
  'viewport_height', 'viewport_width', 'screen_height', 'screen_width',
  'device_pixel_ratio', 'connection_type', 'connection_downlink',
  'connection_rtt', 'connection_save_data', 'timezone', 'timezone_offset',
  'platform', 'do_not_track',
])

// Fields that contain user IDs (pseudonymize as USER_xxx)
const USER_ID_FIELDS = new Set([
  'user_id', 'userid', 'fromuser', 'touser',
  'otheruid', 'byuser', 'ljuserid', 'by_user',
  'loggedInAs', // query param containing user ID
])

// 'id' is context-sensitive: user ID in user responses, message ID in message responses.
// We CANNOT blindly pseudonymize this — message IDs need to correlate across log entries.
// Only pseudonymize 'id' when the parent context is known to be a user object.
// This is handled by parentKey checking in pseudonymizeField.

// Fields that contain emails (pseudonymize as EMAIL_xxx)
const EMAIL_FIELDS = new Set([
  'email', 'fromaddr', 'replyto',
  // Laravel email/incoming mail logging
  'recipient', 'envelope_from', 'envelope_to', 'from_address',
])

// Fields that contain IP addresses (pseudonymize as IP_xxx)
const IP_FIELDS = new Set([
  'ip', 'X-Real-Ip', 'X-Forwarded-For',
])

// Fields that contain names (pseudonymize as NAME_xxx)
const NAME_FIELDS = new Set([
  'fullname', 'firstname', 'lastname', 'displayname', 'name',
])

// Fields that contain location data (round or redact)
const LOCATION_FIELDS = new Set([
  'lat', 'lng', 'latitude', 'longitude',
])

// Fields that contain free text which may embed PII (scan with regex)
const FREE_TEXT_FIELDS = new Set([
  'aboutme', 'text', 'suspectreason',
  // Comment fields (user1 through user10 can contain free-text notes)
  'user1', 'user2', 'user3', 'user4', 'user5',
  'user6', 'user7', 'user8', 'user9', 'user10',
])

// Fields that contain chat/message IDs (safe - not PII, but useful context)
const SAFE_ID_FIELDS = new Set([
  'chat_id', 'message_id', 'msg_id', 'group_id', 'groupid',
  'email_tracking_id', 'request_id',
])

// Fields to strip entirely (too much PII risk, not useful for debugging)
const STRIP_FIELDS = new Set([
  'user_agent', 'User-Agent', 'referrer', 'url',
  'Origin', 'Host', 'Accept-Language',
  // Profile image paths can contain user-identifying info
  'path', 'paththumb',
  // Session/auth fields — tokens can be used to impersonate users
  'session_id', 'freegle_session',
  'jwt', 'token', 'series',
  'k', 'rt',  // login token, AMP reply token (query params)
  // X-Freegle-Session header value
  'X-Freegle-Session',
  // Passwords — login request bodies log these (truncated but still visible)
  'password',
  // Email subjects can contain PII
  'subject',
])

// Fields containing location display text (pseudonymize as LOCATION_xxx)
const LOCATION_DISPLAY_FIELDS = new Set([
  'display', 'location', 'publiclocation',
])

/**
 * Pseudonymize a single value based on its field classification.
 * Returns { value, tokens } where tokens is an array of tokens used.
 */
function pseudonymizeField(key, value, parentKey) {
  const tokens = []

  if (value === null || value === undefined) {
    return { value, tokens }
  }

  // Determine the effective field name (use key, but for nested objects consider parent)
  const fieldName = key

  // Strip fields — replace with "[redacted]"
  if (STRIP_FIELDS.has(fieldName)) {
    return { value: '[redacted]', tokens }
  }

  // Safe fields — pass through unchanged
  if (SAFE_FIELDS.has(fieldName)) {
    return { value, tokens }
  }

  // Safe ID fields (chat_id, message_id, etc.) — pass through
  if (SAFE_ID_FIELDS.has(fieldName)) {
    return { value, tokens }
  }

  // User ID fields — handle both numeric and string representations
  if (USER_ID_FIELDS.has(fieldName) && !FREE_TEXT_FIELDS.has(fieldName)) {
    const numVal = typeof value === 'number' ? value : parseInt(value, 10)
    if (numVal > 0 && !isNaN(numVal)) {
      const token = getOrCreateToken(numVal.toString(), 'USER')
      tokens.push(token)
      return { value: token, tokens }
    }
    return { value, tokens }
  }

  // Context-sensitive 'id' field — only pseudonymize when parent indicates a user context.
  // User objects come from response_body for /api/user/ endpoints, or inside 'comments' arrays.
  // Message IDs, group IDs, etc. must NOT be pseudonymized (they correlate across logs).
  if (fieldName === 'id' && typeof value === 'number') {
    // Only pseudonymize if parent is a user-like context
    const userContextParents = new Set(['comments', 'profile', 'ratings'])
    if (parentKey && userContextParents.has(parentKey)) {
      const token = getOrCreateToken(value.toString(), 'USER')
      tokens.push(token)
      return { value: token, tokens }
    }
    // Otherwise pass through (message ID, group ID, etc.)
    return { value, tokens }
  }

  // Email fields
  if (EMAIL_FIELDS.has(fieldName)) {
    if (typeof value === 'string' && value.includes('@')) {
      const token = getOrCreateToken(value, 'EMAIL')
      tokens.push(token)
      return { value: token, tokens }
    }
    return { value, tokens }
  }

  // IP fields
  if (IP_FIELDS.has(fieldName)) {
    if (typeof value === 'string' && /\d+\.\d+\.\d+\.\d+/.test(value)) {
      const token = getOrCreateToken(value, 'IP')
      tokens.push(token)
      return { value: token, tokens }
    }
    return { value, tokens }
  }

  // Name fields
  if (NAME_FIELDS.has(fieldName)) {
    if (typeof value === 'string' && value.length > 0) {
      const token = getOrCreateToken(value, 'NAME')
      tokens.push(token)
      return { value: token, tokens }
    }
    return { value, tokens }
  }

  // Location fields — round to ~1km
  if (LOCATION_FIELDS.has(fieldName)) {
    if (typeof value === 'number') {
      return { value: parseFloat(value.toFixed(2)), tokens }
    }
    return { value, tokens }
  }

  // Location display fields — pseudonymize place names
  if (LOCATION_DISPLAY_FIELDS.has(fieldName)) {
    if (typeof value === 'string' && value.length > 0) {
      const token = getOrCreateToken(value, 'LOCATION')
      tokens.push(token)
      return { value: token, tokens }
    }
    // If it's an object (e.g. publiclocation: {display, location, groupid, groupname}), recurse
    if (typeof value === 'object' && value !== null) {
      const { obj, tokens: childTokens } = pseudonymizeObject(value, fieldName)
      tokens.push(...childTokens)
      return { value: obj, tokens }
    }
    return { value, tokens }
  }

  // Free text fields — scan for embedded PII
  // Note: user1-user10 in comments are free text strings, but user_id fields
  // with the same names are numeric. Handle both cases.
  if (FREE_TEXT_FIELDS.has(fieldName)) {
    if (typeof value === 'string') {
      return scanFreeText(value, tokens)
    }
    // If numeric, these are user IDs (e.g. user8: 12345678)
    if (typeof value === 'number' && value > 0) {
      const token = getOrCreateToken(value.toString(), 'USER')
      tokens.push(token)
      return { value: token, tokens }
    }
    return { value, tokens }
  }

  // Recurse into objects and arrays
  if (Array.isArray(value)) {
    const result = []
    for (const item of value) {
      if (typeof item === 'object' && item !== null) {
        const { obj, tokens: childTokens } = pseudonymizeObject(item, fieldName)
        result.push(obj)
        tokens.push(...childTokens)
      } else {
        result.push(item)
      }
    }
    return { value: result, tokens }
  }

  if (typeof value === 'object') {
    const { obj, tokens: childTokens } = pseudonymizeObject(value, fieldName)
    tokens.push(...childTokens)
    return { value: obj, tokens }
  }

  // Unknown string fields — scan for PII patterns as a safety net.
  // This catches emails, IPs, postcodes, and phone numbers in any field
  // we haven't explicitly categorized — defence in depth for new fields.
  if (typeof value === 'string') {
    return scanFreeText(value, tokens)
  }

  // Numbers and booleans in unknown fields — pass through.
  // We do NOT pseudonymize unknown numbers because they could be message IDs,
  // group IDs, or other non-PII identifiers that need to correlate across logs.
  return { value, tokens }
}

/**
 * Scan free text for embedded PII patterns.
 */
function scanFreeText(text, tokens) {
  let result = text

  // Emails
  const emails = result.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    const token = getOrCreateToken(email, 'EMAIL')
    tokens.push(token)
    result = result.replace(new RegExp(escapeRegex(email), 'gi'), token)
  }

  // IPs
  const ips = result.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    const token = getOrCreateToken(ip, 'IP')
    tokens.push(token)
    result = result.replace(new RegExp(escapeRegex(ip), 'g'), token)
  }

  // Phone numbers
  const phones = result.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    const token = getOrCreateToken(phone, 'PHONE')
    tokens.push(token)
    result = result.replace(new RegExp(escapeRegex(phone), 'g'), token)
  }

  // Postcodes
  const postcodes = result.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    const token = getOrCreateToken(postcode, 'POSTCODE')
    tokens.push(token)
    result = result.replace(new RegExp(escapeRegex(postcode), 'gi'), token)
  }

  return { value: result, tokens }
}

/**
 * Pseudonymize all fields in a JSON object according to schema.
 */
function pseudonymizeObject(obj, parentKey) {
  if (!obj || typeof obj !== 'object') {
    return { obj, tokens: [] }
  }

  const result = {}
  const allTokens = []

  for (const [key, value] of Object.entries(obj)) {
    const { value: pseudoValue, tokens } = pseudonymizeField(key, value, parentKey)
    result[key] = pseudoValue
    allTokens.push(...tokens)
  }

  return { obj: result, tokens: allTokens }
}

/**
 * Pseudonymize a log entry (JSON string from Loki).
 * Parses JSON, applies field-level pseudonymization, re-serializes.
 * Falls back to regex scanning if JSON parsing fails.
 */
function pseudonymizeText(text) {
  if (!text || typeof text !== 'string') {
    return { text, tokensUsed: [] }
  }

  try {
    const entry = JSON.parse(text)
    const { obj, tokens } = pseudonymizeObject(entry, null)
    return {
      text: JSON.stringify(obj),
      tokensUsed: [...new Set(tokens)],
    }
  } catch {
    // Not valid JSON — fall back to regex scanning
    const tokens = []
    const { value } = scanFreeText(text, tokens)
    return { text: value, tokensUsed: [...new Set(tokens)] }
  }
}

/**
 * Translate a pseudonymized query to real values for Loki.
 */
function translateQuery(query, sessionId) {
  if (!query) return query

  // Find all tokens in the query
  const tokenPattern = /(?:EMAIL|IP|PHONE|POSTCODE|NAME|USER)_[a-f0-9]{8}/g
  const tokens = query.match(tokenPattern) || []

  let result = query
  for (const token of tokens) {
    const realValue = getRealValue(token)
    if (realValue) {
      result = result.replace(new RegExp(token, 'g'), realValue)
    }
  }

  return result
}

/**
 * Write audit log entry.
 */
function writeAuditLog(entry) {
  const dateStr = new Date().toISOString().slice(0, 10)
  const logFile = path.join(AUDIT_LOG_DIR, `${dateStr}.jsonl`)

  const logEntry = {
    timestamp: new Date().toISOString(),
    ...entry,
  }

  fs.appendFileSync(logFile, JSON.stringify(logEntry) + '\n')
}

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'pseudonymizer',
    lokiUrl: LOKI_URL,
  })
})

/**
 * Register mapping from Query Sanitizer (Container 0).
 * POST /register-mapping
 * Body: { sessionId, mapping, userId }
 */
/**
 * Create tokens for PII values. Single source of truth for all token creation.
 * POST /tokenize
 * Body: { sessionId, values: { fieldType: value, ... }, userId? }
 * Returns: { tokens: { realValue: token, ... } }
 *
 * The sanitizer sends raw PII values here. The pseudonymizer creates or retrieves
 * canonical tokens. No other service creates tokens.
 */
app.post('/tokenize', (req, res) => {
  const { sessionId, values, userId } = req.body

  if (!sessionId || !values) {
    return res.status(400).json({ error: 'sessionId and values required' })
  }

  const expiresAt = new Date(Date.now() + 60 * 60 * 1000).toISOString()
  const tokens = {}

  for (const [key, value] of Object.entries(values)) {
    if (!value) continue
    // Key format: "TYPE:value" — extract the field type
    const fieldType = key.split(':')[0]
    const token = getOrCreateToken(value.toString(), fieldType)
    tokens[value.toString()] = token

    // Register session mapping
    stmts.insertSession.run(sessionId, token, userId || null, expiresAt)
  }

  console.log(`[${sessionId}] Tokenized ${Object.keys(tokens).length} values`)
  res.json({ tokens })
})

/**
 * Legacy register-mapping endpoint (kept for compatibility).
 * POST /register-mapping
 */
app.post('/register-mapping', (req, res) => {
  const { sessionId, mapping, userId } = req.body

  if (!sessionId || !mapping) {
    return res.status(400).json({ error: 'sessionId and mapping required' })
  }

  const expiresAt = new Date(Date.now() + 60 * 60 * 1000).toISOString()
  const canonicalMapping = {}

  for (const [sanitizerToken, realValue] of Object.entries(mapping)) {
    const normalizedValue = realValue.toLowerCase().trim()
    const fieldType = sanitizerToken.split('_')[0]

    // Use canonical token (may differ from sanitizer's token)
    const canonicalToken = getOrCreateToken(normalizedValue, fieldType)
    canonicalMapping[canonicalToken] = realValue

    stmts.insertSession.run(sessionId, canonicalToken, userId || null, expiresAt)
  }

  console.log(`[${sessionId}] Registered ${Object.keys(mapping).length} mappings`)
  res.json({ status: 'ok', canonicalMapping })
})

/**
 * Query Loki and pseudonymize results.
 * POST /query
 * Body: { sessionId, query, start, end, limit }
 */
app.post('/query', async (req, res) => {
  const { sessionId, query, start = '1h', end, limit = 100 } = req.body

  if (!sessionId || !query) {
    return res.status(400).json({ error: 'sessionId and query required' })
  }

  const startTime = Date.now()

  try {
    // Translate pseudonymized query to real values
    const realQuery = translateQuery(query, sessionId)

    // Build Loki query parameters
    const params = new URLSearchParams({
      query: realQuery,
      limit: limit.toString(),
    })

    // Handle time range
    if (start) {
      // Relative time like "1h" or ISO timestamp
      if (/^\d+[smhdw]$/.test(start)) {
        const now = Date.now()
        const duration = parseDuration(start)
        params.set('start', ((now - duration) * 1000000).toString()) // nanoseconds
      } else {
        params.set('start', (new Date(start).getTime() * 1000000).toString())
      }
    }

    if (end) {
      params.set('end', (new Date(end).getTime() * 1000000).toString())
    }

    // Query Loki
    const lokiResponse = await fetch(`${LOKI_URL}/loki/api/v1/query_range?${params}`)

    if (!lokiResponse.ok) {
      throw new Error(`Loki error: ${lokiResponse.status} ${await lokiResponse.text()}`)
    }

    const data = await lokiResponse.json()
    const allTokensUsed = new Set()

    // Pseudonymize results
    if (data.data?.result) {
      for (const stream of data.data.result) {
        if (stream.values) {
          stream.values = stream.values.map(([ts, line]) => {
            const { text, tokensUsed } = pseudonymizeText(line)
            tokensUsed.forEach((t) => allTokensUsed.add(t))
            return [ts, text]
          })
        }
      }
    }

    const durationMs = Date.now() - startTime

    // Audit log
    writeAuditLog({
      sessionId,
      operation: 'loki_query',
      request: { query, start, end, limit },
      lokiQuery: {
        query: realQuery,
        resultCount: data.data?.result?.length || 0,
      },
      response: {
        pseudonymizedEntries: data.data?.result?.reduce((sum, s) => sum + (s.values?.length || 0), 0) || 0,
        tokensUsed: [...allTokensUsed],
      },
      durationMs,
    })

    console.log(`[${sessionId}] Query completed in ${durationMs}ms, ${allTokensUsed.size} tokens used`)

    res.json(data)
  } catch (error) {
    console.error(`[${sessionId}] Query error:`, error.message)

    writeAuditLog({
      sessionId,
      operation: 'loki_query',
      request: { query, start, end, limit },
      error: error.message,
      durationMs: Date.now() - startTime,
    })

    res.status(500).json({ error: error.message })
  }
})

/**
 * Get the reverse mapping for frontend de-tokenization.
 * GET /mapping/:sessionId
 */
app.get('/mapping/:sessionId', (req, res) => {
  const { sessionId } = req.params

  const tokens = stmts.getSessionTokens.all(sessionId)
  const mapping = {}

  for (const { token } of tokens) {
    const realValue = getRealValue(token)
    if (realValue) {
      mapping[token] = realValue
    }
  }

  res.json({ mapping })
})

/**
 * Parse duration string to milliseconds.
 */
function parseDuration(str) {
  const match = str.match(/^(\d+)([smhdw])$/)
  if (!match) return 0

  const value = parseInt(match[1], 10)
  const unit = match[2]

  const multipliers = {
    s: 1000,
    m: 60 * 1000,
    h: 60 * 60 * 1000,
    d: 24 * 60 * 60 * 1000,
    w: 7 * 24 * 60 * 60 * 1000,
  }

  return value * (multipliers[unit] || 0)
}

// Mount database query routes
const { mountDbRoutes } = require('./db-query')
mountDbRoutes(app, getOrCreateToken, translateQuery, writeAuditLog)

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`Pseudonymizer service listening on port ${PORT}`)
  console.log(`Loki URL: ${LOKI_URL}`)
  console.log(`Endpoints: /query (Loki), /tokenize, /api/db/query, /api/db/schema`)
})

module.exports = { app, getOrCreateToken, getRealValue, pseudonymizeText, translateQuery }
