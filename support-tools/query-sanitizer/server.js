/**
 * Query Sanitizer Service (Container 0)
 *
 * Frontend-facing service that:
 * - Receives user queries with known PII (from user selection step)
 * - Extracts any additional PII from the query text
 * - Creates consistent tokens for all PII
 * - Sends mapping directly to Pseudonymizer (Container 3)
 * - Returns pseudonymized query + mapping to frontend for de-tokenization
 *
 * Security: This container sees the original query but does NOT have
 * access to Loki or the ability to translate tokens back to values.
 */

const express = require('express')
const { v4: uuidv4 } = require('uuid')

const app = express()
app.use(express.json())

// CORS - allow browser requests from ModTools dev-live and other .localhost origins
app.use((req, res, next) => {
  const origin = req.headers.origin
  if (origin && (origin.endsWith('.localhost') || origin.includes('localhost'))) {
    res.setHeader('Access-Control-Allow-Origin', origin)
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, sentry-trace, baggage')
  }
  if (req.method === 'OPTIONS') {
    return res.sendStatus(204)
  }
  next()
})

const PSEUDONYMIZER_URL = process.env.PSEUDONYMIZER_URL || 'http://pseudonymizer:8080'

/**
 * Request canonical tokens from the Pseudonymizer (single source of truth).
 * @param {Object} values - { fieldType: value } pairs, e.g. { EMAIL: "user@example.com", USER: "12345" }
 * @param {string} sessionId - Session ID for token registration
 * @param {string|number} userId - Optional user ID for session tracking
 * @returns {Object} Map of realValue -> token
 */
async function requestTokens(values, sessionId, userId) {
  const response = await fetch(`${PSEUDONYMIZER_URL}/tokenize`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId, values, userId }),
  })

  if (!response.ok) {
    throw new Error(`Pseudonymizer tokenize error: ${response.status}`)
  }

  const result = await response.json()
  return result.tokens // { realValue: token }
}

/**
 * Escape special regex characters.
 */
function escapeRegex(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * PII detection patterns for query scanning.
 */
const PII_PATTERNS = {
  // Email addresses
  email: /[\w.-]+@[\w.-]+\.\w+/gi,

  // IP addresses (v4) - negative lookbehind avoids matching version strings like Chrome/134.0.0.0
  ip: /(?<![/\d])\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/g,

  // UK phone numbers
  phone: /\b(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}\b/g,

  // UK postcodes
  postcode: /\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/gi,

  // User IDs (6+ digit numbers, optionally prefixed)
  userId: /\b(?:user\s*(?:id)?[:#]?\s*)?(\d{6,})\b/gi,
}

/**
 * Email trail detection patterns.
 * If detected, we should reject the query to prevent accidental PII exposure.
 */
const MAIL_HEADER_PATTERNS = [
  /^From:.*@/im,
  /^To:.*@/im,
  /^Cc:.*@/im,
  /^Subject:/im,
  /^Date:.*\d{4}/im,
  /On .* wrote:/i,
  /From: .* <.*@.*>/i,
  /-{3,}.*Original Message/i,
  /Sent from my iPhone/i,
  /Sent from my Android/i,
]

/**
 * Check if text contains email trail markers.
 */
function containsEmailTrail(text) {
  return MAIL_HEADER_PATTERNS.some((pattern) => pattern.test(text))
}

/**
 * Scan text for all types of PII.
 */
function scanForPii(text) {
  const detected = []

  // Email addresses
  const emails = text.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    detected.push({ type: 'email', value: email, severity: 'high' })
  }

  // IP addresses
  const ips = text.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    detected.push({ type: 'ip', value: ip, severity: 'medium' })
  }

  // Phone numbers
  const phones = text.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    detected.push({ type: 'phone', value: phone, severity: 'high' })
  }

  // Postcodes
  const postcodes = text.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    detected.push({ type: 'postcode', value: postcode, severity: 'medium' })
  }

  return detected
}

/**
 * Collect all PII values from the query text and known PII.
 * Returns { fieldType: value } pairs for batch tokenization.
 */
function collectPiiValues(text, knownPii = {}) {
  const values = {}

  // Known PII from user selection
  if (knownPii.email) values[`EMAIL:${knownPii.email}`] = { type: 'EMAIL', value: knownPii.email }
  if (knownPii.displayname) values[`NAME:${knownPii.displayname}`] = { type: 'NAME', value: knownPii.displayname }
  if (knownPii.postcode) values[`POSTCODE:${knownPii.postcode}`] = { type: 'POSTCODE', value: knownPii.postcode }
  if (knownPii.location) values[`LOCATION:${knownPii.location}`] = { type: 'LOCATION', value: knownPii.location }
  const knownUserId = knownPii.userid || knownPii.userId
  if (knownUserId) values[`USER:${knownUserId}`] = { type: 'USER', value: knownUserId.toString() }

  // Scan text for additional PII
  const emails = text.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    values[`EMAIL:${email}`] = { type: 'EMAIL', value: email }
  }

  const ips = text.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    values[`IP:${ip}`] = { type: 'IP', value: ip }
  }

  const phones = text.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    values[`PHONE:${phone}`] = { type: 'PHONE', value: phone }
  }

  const postcodes = text.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    values[`POSTCODE:${postcode}`] = { type: 'POSTCODE', value: postcode }
  }

  return values
}

/**
 * Extract and pseudonymize PII from query text.
 * Tokens are created by the Pseudonymizer (single source of truth).
 */
async function extractAndPseudonymize(text, knownPii, sessionId, userId) {
  // Step 1: Collect all PII values
  const piiValues = collectPiiValues(text, knownPii || {})

  if (Object.keys(piiValues).length === 0) {
    return { pseudonymizedText: text, mapping: {} }
  }

  // Step 2: Request canonical tokens from the Pseudonymizer
  // Build { fieldType: value } for the /tokenize endpoint
  const tokenizeRequest = {}
  for (const { type, value } of Object.values(piiValues)) {
    // Use "TYPE:value" as key to handle multiple values of same type
    tokenizeRequest[`${type}:${value}`] = value
  }

  const tokenMap = await requestTokens(tokenizeRequest, sessionId, userId)

  // Step 3: Replace PII values in text with canonical tokens
  let result = text
  const mapping = {} // token -> realValue (for frontend de-tokenization)

  // Sort by value length descending so longer values are replaced first
  // (prevents partial replacement of e.g. "edward@ehibbert.org.uk" by "edward")
  const sortedPii = Object.values(piiValues).sort((a, b) => b.value.length - a.value.length)

  for (const { type, value } of sortedPii) {
    const token = tokenMap[value]
    if (!token) continue

    mapping[token] = value

    if (type === 'USER') {
      // User IDs: word boundary match
      result = result.replace(new RegExp(`\\b${escapeRegex(value)}\\b`, 'g'), token)
    } else if (type === 'IP') {
      result = result.replace(new RegExp(escapeRegex(value), 'g'), token)
    } else {
      result = result.replace(new RegExp(escapeRegex(value), 'gi'), token)
    }
  }

  return { pseudonymizedText: result, mapping }
}

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'query-sanitizer',
    pseudonymizerUrl: PSEUDONYMIZER_URL,
  })
})

/**
 * Sanitize a user query.
 * POST /sanitize
 * Body: { query, knownPii, userId }
 * Returns: { pseudonymizedQuery, sessionId, localMapping, detectedPii }
 */
app.post('/sanitize', async (req, res) => {
  const { query, knownPii, userId } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  // Check for email trail content
  if (containsEmailTrail(query)) {
    return res.status(400).json({
      error: 'EMAIL_TRAIL_DETECTED',
      message: 'Your query appears to contain copy-pasted email content. Please describe the issue in your own words to protect user privacy.',
    })
  }

  const sessionId = `sess_${uuidv4().slice(0, 8)}`

  // Scan for PII before pseudonymization (for UI warning)
  const detectedPii = scanForPii(query)

  // Extract PII and get canonical tokens from the Pseudonymizer (single source of truth).
  // This replaces the old two-step approach of local tokenization + register-mapping.
  let pseudonymizedText, mapping
  try {
    const result = await extractAndPseudonymize(query, knownPii || {}, sessionId, userId)
    pseudonymizedText = result.pseudonymizedText
    mapping = result.mapping
  } catch (error) {
    console.error('Failed to tokenize with pseudonymizer:', error.message)
    return res.status(503).json({
      error: 'PSEUDONYMIZER_UNAVAILABLE',
      message: 'Unable to connect to pseudonymizer service',
    })
  }

  console.log(`[${sessionId}] Sanitized query: ${pseudonymizedText.substring(0, 50)}...`)
  console.log(`[${sessionId}] Tokens created: ${Object.keys(mapping).length}`)

  res.json({
    pseudonymizedQuery: pseudonymizedText,
    sessionId,
    localMapping: mapping, // For frontend de-tokenization
    detectedPii: detectedPii.length > 0 ? detectedPii : null,
  })
})

/**
 * Scan query for PII without sanitizing (for preview/warning).
 * POST /scan
 * Body: { query, knownPii }
 * Returns: { detectedPii, containsEmailTrail }
 */
app.post('/scan', (req, res) => {
  const { query, knownPii } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  const detectedPii = scanForPii(query)
  const hasEmailTrail = containsEmailTrail(query)

  // Add known PII to detected list if present in query
  if (knownPii) {
    const lowerQuery = query.toLowerCase()

    if (knownPii.email && lowerQuery.includes(knownPii.email.toLowerCase())) {
      detectedPii.unshift({ type: 'email', value: knownPii.email, severity: 'high', source: 'selected_user' })
    }

    if (knownPii.displayname && lowerQuery.includes(knownPii.displayname.toLowerCase())) {
      detectedPii.unshift({ type: 'name', value: knownPii.displayname, severity: 'medium', source: 'selected_user' })
    }
  }

  res.json({
    detectedPii,
    containsEmailTrail: hasEmailTrail,
    piiCount: detectedPii.length,
  })
})

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`Query Sanitizer service listening on port ${PORT}`)
  console.log(`Pseudonymizer URL: ${PSEUDONYMIZER_URL}`)
})

module.exports = { app, extractAndPseudonymize, scanForPii, containsEmailTrail }
