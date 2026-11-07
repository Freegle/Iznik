'use strict'

/**
 * The support first pass: one bounded run per referral. Facts come from the member's user dump,
 * built by code (first-pass-facts.js); judgement comes from ONE tool-less model call. Nothing here
 * sends anything to anyone: the result is a brief the batch stores and mails to support.
 *
 * Every outside dependency is injectable (the dump fetch, the SQLite opener, the model's query(), the
 * quota fetch, the clock), and only Node built-ins are required at load, so CI's bare `node --test`
 * can run first-pass.test.js. The SDK and better-sqlite3 are required lazily, when a real run needs them.
 *
 * Request and response shapes: plans/active/2026-11-07-referral-first-pass.md.
 */

const crypto = require('node:crypto')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { validateImages, promptContent, MAX_REFERRAL_IMAGES, MAX_TOTAL_BYTES } = require('./images')
const { driverMode, billableCostUsd } = require('./auth')
const { subscriptionToken, fetchQuota, serialiseTranscript } = require('./run-record')
const { parseModelJson } = require('./ux-internal')
const { unknownSiteLinks } = require('./links')
const { buildFacts, noDumpFacts, FACT_TITLES } = require('./first-pass-facts')
const { CLASSIFICATIONS, CONFIDENCE_LEVELS, FACT_KEYS, ANSWER_SCHEMA, systemPrompt, buildUserPrompt } = require('./first-pass-prompt')

const CHAT_TYPES = ['User2Mod', 'User2User', 'Mod2Mod', 'Group']
const SOURCE_KINDS = ['referral', 'mail']
const FROM_KINDS = ['member', 'moderator', 'other', 'system']
const MAX_MESSAGES = 50
const MAX_TEXT = 2000
const MAX_REASON = 2000
const MAX_MAIL_ENTRIES = 20
const TRANSCRIPT_CAP = 200000
const DEFAULT_DEADLINE_MS = 300000
const DUMP_SINCE = '30d'
// Each dump attempt gets its own budget inside the overall deadline, so a slow dump cannot leave the
// model without time: the full dump, then the database alone, then the chat alone.
const FULL_DUMP_BUDGET_MS = 120000
const DB_DUMP_BUDGET_MS = 60000
// The Go API refuses every download with 409 while the export is held (lockdown.RefuseDownload).
const LOCKDOWN_STATUSES = [409]
// A missing or wrong USERDUMP_INTERNAL_KEY: the dump endpoint answers 401 (no valid key) or 403.
const AUTH_STATUSES = [401, 403]

class FirstPassError extends Error {
  constructor(code, message) {
    super(message)
    this.code = code
  }
}

const REASONS = {
  DEADLINE: 'the first pass took too long',
  CREDIT: 'the AI helper is out of credit',
  MODEL: 'the AI helper failed',
  DUMP_LOCKDOWN: 'the member data could not be read during a lockdown',
  DUMP_AUTH: 'the AI helper is not allowed to read member data on this server',
  DUMP: 'the member data could not be read',
  BAD_OUTPUT: 'the AI helper gave an answer that could not be used',
}
const fail = (code) => new FirstPassError(code, REASONS[code])

// ---------------------------------------------------------------------------
// The request
// ---------------------------------------------------------------------------

const str = (v, max) => (typeof v === 'string' ? v.slice(0, max) : '')
const posInt = (v) => (Number.isInteger(v) && v > 0 ? v : null)

/**
 * Validate and trim the batch's request. Returns {error} or the cleaned request.
 */
function cleanFirstPassRequest(body) {
  const b = body && typeof body === 'object' && !Array.isArray(body) ? body : null
  if (!b) return { error: 'the body must be an object' }

  const caseid = posInt(b.caseid)
  if (!caseid) return { error: 'caseid must be a positive integer' }

  let memberid = null
  if (b.memberid !== null && b.memberid !== undefined) {
    memberid = posInt(b.memberid)
    if (!memberid) return { error: 'memberid must be a positive integer or null' }
  }

  const s = b.source
  if (!s || typeof s !== 'object' || !SOURCE_KINDS.includes(s.kind)) return { error: 'source.kind must be referral or mail' }
  const source = {
    kind: s.kind,
    chatid: posInt(s.chatid),
    referrerid: posInt(s.referrerid),
    reason: typeof s.reason === 'string' && s.reason.trim() ? s.reason.trim().slice(0, MAX_REASON) : null,
  }

  const c = b.chat
  if (!c || typeof c !== 'object') return { error: 'chat is required' }
  const chatid = posInt(c.id)
  if (!chatid) return { error: 'chat.id must be a positive integer' }
  if (!CHAT_TYPES.includes(c.type)) return { error: 'chat.type must be User2Mod, User2User, Mod2Mod or Group' }
  if (!Array.isArray(c.messages)) return { error: 'chat.messages must be a list' }
  let group = null
  if (c.group && typeof c.group === 'object') {
    group = { id: posInt(c.group.id), name: str(c.group.name, 120) }
  }
  const messages = c.messages.slice(-MAX_MESSAGES).map((m) => {
    const x = m && typeof m === 'object' ? m : {}
    return {
      id: posInt(x.id) || 0,
      date: str(x.date, 40),
      from: FROM_KINDS.includes(x.from) ? x.from : 'other',
      type: str(x.type, 30),
      text: str(x.text, MAX_TEXT),
    }
  })

  // One bad picture (wrong type, wrong bytes, too big) is dropped and counted, not a reason to refuse
  // the whole request: the brief is still useful without it.
  if (b.images !== undefined && b.images !== null && !Array.isArray(b.images)) return { error: 'Screenshots must be a list.' }
  const rawImages = b.images || []
  if (rawImages.length > MAX_REFERRAL_IMAGES) return { error: `Please attach at most ${MAX_REFERRAL_IMAGES} screenshots.` }
  const images = []
  let imagesDropped = 0
  let imageBytes = 0
  rawImages.forEach((item, i) => {
    const one = validateImages([item], MAX_REFERRAL_IMAGES)
    if (one.error || imageBytes + one.images[0].bytes > MAX_TOTAL_BYTES) {
      imagesDropped++
      return
    }
    const img = one.images[0]
    imageBytes += img.bytes
    // validateImages numbers an unnamed picture from its place in a list of one.
    if (!(item && item.name)) img.name = img.name.replace(/^screenshot-1\./, `screenshot-${i + 1}.`)
    images.push(img)
  })

  const mh = b.mailHistory && typeof b.mailHistory === 'object' ? b.mailHistory : {}
  const mailHistory = {
    available: mh.available === true,
    reason: str(mh.reason, 200),
    entries: Array.isArray(mh.entries)
      ? mh.entries.slice(0, MAX_MAIL_ENTRIES).map((e) => {
          const x = e && typeof e === 'object' ? e : {}
          return {
            date: str(x.date, 40),
            mailbox: x.mailbox === 'info' ? 'info' : 'support',
            direction: x.direction === 'out' ? 'out' : 'in',
            subject: str(x.subject, 150),
            excerpt: str(x.excerpt, 500),
          }
        })
      : [],
  }

  // The batch counts the chat images it could not fetch or that were too big. Without that count,
  // image messages beyond those sent are taken to be the skipped ones.
  const imagesSkipped = Number.isInteger(b.imagesSkipped) && b.imagesSkipped >= 0 ? Math.min(b.imagesSkipped, 1000) + imagesDropped : null

  return { caseid, memberid, source, chat: { id: chatid, type: c.type, group, messages }, images, imagesSkipped, imagesDropped, mailHistory }
}

// ---------------------------------------------------------------------------
// The environment the interactive agent runs in
// ---------------------------------------------------------------------------

const SECRET_ENV = ['USERDUMP_INTERNAL_KEY', 'USERDUMP_API_KEY']

/**
 * A copy of the environment without the dump keys. The interactive support agent starts a CLI
 * process that inherits process.env by default; passing this as the SDK's `env` option keeps the
 * first pass's dump key out of reach of anything the agent can run or be talked into revealing.
 */
function agentEnv(env = process.env) {
  const out = {}
  for (const [k, v] of Object.entries(env)) {
    if (!SECRET_ENV.includes(k) && v !== undefined) out[k] = v
  }
  return out
}

// ---------------------------------------------------------------------------
// The draft's links
// ---------------------------------------------------------------------------

const URL_PATTERN = /(?:https?:\/\/|www\.)[^\s)\]>"'`]+|\bilovefreegle\.org(?:\/[^\s)\]>"'`]*)?/gi
const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/gi
const MAILTO_PATTERN = /mailto:[^\s)\]>"'`]+/gi
const COMMON_TLDS = 'com|org|net|uk|co|io|info|biz|edu|gov|app|dev|xyz|online|site|shop|club|top|ru|cn|de|fr|ie|eu|tk|ml|ga|cf|gq|ly|cc|me|tv|us'
const BARE_DOMAIN_PATTERN = new RegExp(`\\b(?:[a-z0-9-]+\\.)+(?:${COMMON_TLDS})\\b(?:\\/[^\\s)\\]>"'\`]*)?`, 'gi')
// Pages that exist on the member site. Used only when there is no codebase checkout to look in.
const KNOWN_TOP_PAGES = ['', 'give', 'find', 'browse', 'myposts', 'chats', 'settings', 'donate', 'help', 'communityevents', 'volunteerings', 'stories', 'about', 'terms', 'privacy']

/**
 * Every link in a draft that is not to a known Freegle page, each with why. A link the checker cannot
 * place is listed rather than trusted, with or without the codebase checkout to look in.
 *
 * @param {string} text
 * @param {string} pagesDir - iznik-nuxt3/pages in the checkout (it may not exist).
 */
function checkDraftLinks(text, pagesDir) {
  const warnings = []
  const seen = new Set()
  for (const raw of String(text || '').match(URL_PATTERN) || []) {
    const link = raw.replace(/[.,;:!?]+$/, '')
    if (seen.has(link)) continue
    seen.add(link)

    let url
    try {
      url = new URL(/^https?:\/\//i.test(link) ? link : `https://${link}`)
    } catch (_) {
      warnings.push(`${link} (not a valid link)`)
      continue
    }
    const host = url.hostname.toLowerCase()
    if (host !== 'ilovefreegle.org' && host !== 'www.ilovefreegle.org') {
      warnings.push(`${link} (not a Freegle page)`)
      continue
    }
    if (pagesDir && fs.existsSync(pagesDir)) {
      if (unknownSiteLinks(`https://www.ilovefreegle.org${url.pathname}`, pagesDir).length) warnings.push(`${link} (no such page on the site)`)
    } else if (!KNOWN_TOP_PAGES.includes(url.pathname.replace(/^\/+|\/+$/g, '').toLowerCase())) {
      warnings.push(`${link} (could not be checked against the site's pages)`)
    }
  }

  // Addresses: only @ilovefreegle.org is ours.
  const body = String(text || '')
  const addresses = new Set()
  for (const m of body.match(EMAIL_PATTERN) || []) addresses.add(m.toLowerCase())
  for (const m of body.match(MAILTO_PATTERN) || []) {
    const addr = m.replace(/^mailto:/i, '').split('?')[0].toLowerCase()
    if (addr) addresses.add(addr)
  }
  for (const addr of addresses) {
    if (!/@ilovefreegle\.org$/.test(addr)) warnings.push(`${addr} (not a Freegle address)`)
  }

  // Domains written with no scheme, such as evil.example/login: checked on what is left once the
  // links and addresses above are taken out.
  const rest = body.replace(EMAIL_PATTERN, ' ').replace(MAILTO_PATTERN, ' ').replace(URL_PATTERN, ' ')
  for (const raw of rest.match(BARE_DOMAIN_PATTERN) || []) {
    const link = raw.replace(/[.,;:!?]+$/, '')
    if (seen.has(link)) continue
    seen.add(link)
    const host = link.split('/')[0].toLowerCase()
    if (host === 'ilovefreegle.org' || host.endsWith('.ilovefreegle.org')) continue
    warnings.push(`${link} (not a Freegle page)`)
  }
  return warnings
}

// ---------------------------------------------------------------------------
// The model's answer
// ---------------------------------------------------------------------------

// A model told not to use em dashes sometimes does anyway; the brief never carries one.
const DASHES = new RegExp('\\s*[' + String.fromCharCode(0x2014, 0x2013) + ']\\s*', 'g')
const plain = (v, max) =>
  String(v == null ? '' : v)
    .replace(DASHES, ', ')
    .trim()
    .slice(0, max)

const list = (v, max, itemMax) => (Array.isArray(v) ? v.map((x) => plain(x, itemMax)).filter(Boolean).slice(0, max) : [])

/**
 * Check the model's answer and turn it into the brief's judgement fields. Fact keys that are not one
 * of the six are dropped. Anything unusable is BAD_OUTPUT.
 */
function validateAnswer(answer) {
  if (!answer || typeof answer !== 'object') throw fail('BAD_OUTPUT')
  const summary = plain(answer.summary, 1200)
  const draftText = plain(answer.draft && answer.draft.text, 4000)
  const confidence = answer.confidence || {}
  if (!summary || !draftText) throw fail('BAD_OUTPUT')
  if (!CLASSIFICATIONS.includes(answer.classification)) throw fail('BAD_OUTPUT')
  if (!CONFIDENCE_LEVELS.includes(confidence.level)) throw fail('BAD_OUTPUT')

  const used = Array.isArray(answer.draft.facts_used) ? answer.draft.facts_used : []
  return {
    summary,
    classification: answer.classification,
    confidence: { level: confidence.level, reason: plain(confidence.reason, 300) },
    unusual: list(answer.unusual, 10, 300),
    could_not_find: list(answer.could_not_find, 10, 300),
    draft: { text: draftText, facts_used: FACT_KEYS.filter((k) => used.includes(k)) },
  }
}

const CREDIT_PATTERN = /credit|quota|usage limit|limit reached|billing|insufficient|rate.?limit|overloaded|out of (?:usage|extra)/i

/**
 * One tool-less model call, forced into ANSWER_SCHEMA, with the screenshots attached. Throws a
 * FirstPassError: DEADLINE when the signal aborted, CREDIT or MODEL otherwise.
 */
async function callModel({ prompt, images, model, signal, queryImpl, abort }) {
  const query = queryImpl || require('@anthropic-ai/claude-agent-sdk').query
  const options = {
    model,
    systemPrompt: systemPrompt(),
    tools: [],
    allowedTools: [],
    disallowedTools: ['Read', 'Grep', 'Glob', 'Write', 'Edit', 'Bash', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Task'],
    permissionMode: 'default',
    cwd: os.tmpdir(),
    maxTurns: 4,
    persistSession: false,
    abortController: abort,
    // The keys have no business in a process that reads member text.
    env: agentEnv(),
    outputFormat: { type: 'json_schema', schema: ANSWER_SCHEMA },
  }
  const content = promptContent(prompt, images)
  const input = images.length
    ? (async function* () {
        yield { type: 'user', message: { role: 'user', content }, parent_tool_use_id: null, session_id: '' }
      })()
    : prompt

  let structured = null
  let text = ''
  let usage = { inputTokens: 0, outputTokens: 0, cacheCreation: 0, cacheRead: 0, durationMs: 0 }
  let cost = 0
  let model2 = model
  let failure = ''
  try {
    for await (const message of query({ prompt: input, options })) {
      if (message.type === 'system' && message.subtype === 'init' && message.model) model2 = message.model
      if (message.type !== 'result') continue
      if (message.subtype === 'success' && message.is_error) {
        // A usage-limit or credit error can arrive as a success result flagged is_error, with the
        // error in the result text.
        failure = String(message.result || '') || 'error'
      } else if (message.subtype === 'success') {
        text = message.result || ''
        structured = message.structured_output && typeof message.structured_output === 'object' ? message.structured_output : null
        usage = {
          inputTokens: (message.usage && message.usage.input_tokens) || 0,
          outputTokens: (message.usage && message.usage.output_tokens) || 0,
          cacheCreation: (message.usage && message.usage.cache_creation_input_tokens) || 0,
          cacheRead: (message.usage && message.usage.cache_read_input_tokens) || 0,
          durationMs: message.duration_ms || 0,
        }
        cost = message.total_cost_usd || 0
      } else {
        failure = (message.errors || []).map((e) => (e && e.message) || String(e)).join('; ') || String(message.subtype || '')
      }
    }
  } catch (e) {
    if (signal.aborted) throw fail('DEADLINE')
    // The upstream text is only used to tell credit from other failures; it is never stored or returned.
    throw fail(CREDIT_PATTERN.test((e && e.message) || '') ? 'CREDIT' : 'MODEL')
  }
  if (signal.aborted) throw fail('DEADLINE')
  if (failure) throw fail(CREDIT_PATTERN.test(failure) ? 'CREDIT' : 'MODEL')

  return { answer: structured || parseModelJson(text), raw: structured ? JSON.stringify(structured) : text, usage, cost, model: model2 }
}

// ---------------------------------------------------------------------------
// The whole pass
// ---------------------------------------------------------------------------

function defaultOpenDb(file) {
  const Database = require('better-sqlite3')
  return new Database(file, { readonly: true })
}

// A signal that aborts when any of the given ones does (AbortSignal.any needs Node 20.3).
function anySignal(signals) {
  if (typeof AbortSignal.any === 'function') return AbortSignal.any(signals)
  const c = new AbortController()
  for (const s of signals) {
    if (s.aborted) c.abort(s.reason)
    else s.addEventListener('abort', () => c.abort(s.reason), { once: true })
  }
  return c.signal
}

/**
 * Get the dump for the member: everything first (at most 120s), then the database alone (at most
 * 60s) if that fails, and no dump at all if that fails too; a note says when an attempt ran out of time. A held export (lockdown) stops the pass instead: a chat-only brief
 * would read as a complete one. A deadline stops it too.
 *
 * @returns {Promise<{file:string|null, notes:string[]}>}
 */
async function getDump({ request, key, signal, fetchDump, budgets = [FULL_DUMP_BUDGET_MS, DB_DUMP_BUDGET_MS] }) {
  if (!request.memberid) return { file: null, notes: ['No single member to look up, so only the chat and the reason were available.'] }
  // A member to look up but no key is a server set up wrongly, not a reason to pass on a thinner brief.
  if (!key) throw fail('DUMP_AUTH')

  const attempts = [
    { include: 'db,loki,sentry', budget: budgets[0], slow: 'Logs and the error tracker took too long to collect, so only the database was read.', failed: 'Logs and the error tracker could not be collected, so only the database was read.' },
    { include: 'db', budget: budgets[1] },
  ]
  let timedOut = false
  for (let i = 0; i < attempts.length; i++) {
    const a = attempts[i]
    const attemptSignal = anySignal([signal, AbortSignal.timeout(a.budget)])
    try {
      const file = await fetchDump({ userId: request.memberid, since: DUMP_SINCE, include: a.include, key, caseId: request.caseid, signal: attemptSignal })
      return { file, notes: i === 0 ? [] : [timedOut ? attempts[0].slow : attempts[0].failed] }
    } catch (e) {
      if (signal.aborted) throw fail('DEADLINE')
      if (e && LOCKDOWN_STATUSES.includes(e.status)) throw fail('DUMP_LOCKDOWN')
      if (e && AUTH_STATUSES.includes(e.status)) throw fail('DUMP_AUTH')
      // Only this attempt's own budget ran out: the next attempt, and the model, still have time.
      if (attemptSignal.aborted) timedOut = true
    }
  }
  if (!request.chat.messages.length) throw fail('DUMP')
  return {
    file: null,
    notes: [timedOut ? 'The member data took too long to read, so only the chat and the reason were available.' : 'The member data could not be read, so only the chat and the reason were available.'],
  }
}

/**
 * Run one first pass.
 *
 * @param {object} request - cleaned by cleanFirstPassRequest.
 * @param {object} [o]
 * @param {AbortSignal} [o.signal] - the caller going away.
 * @param {object} [o.deps] - fetchDump, openDb, queryImpl, fetchQuotaImpl, audit, unlink, now, env, pagesDir, nonce.
 */
async function runFirstPass(request, { signal: callerSignal, deps = {} } = {}) {
  const env = deps.env || process.env
  const now = deps.now || Date.now
  const started = now()
  const deadlineMs = Number(env.FIRST_PASS_DEADLINE_MS) > 0 ? Number(env.FIRST_PASS_DEADLINE_MS) : DEFAULT_DEADLINE_MS
  const model = env.FIRST_PASS_MODEL || env.SUPPORT_AI_MODEL || 'opus'
  const unlink = deps.unlink || ((f) => fs.unlinkSync(f))
  const auditFn = deps.audit || (() => {})
  const pagesDir = deps.pagesDir !== undefined ? deps.pagesDir : path.join(env.CODEBASE_DIR || '/app/codebase', 'iznik-nuxt3', 'pages')

  auditFn({ tool: 'first_pass', caseid: request.caseid, target: request.memberid || 0, images: request.images.length })

  // One deadline covers the dump and the model together.
  const abort = new AbortController()
  const timer = setTimeout(() => abort.abort(), deadlineMs)
  const onCallerGone = () => abort.abort()
  if (callerSignal) {
    if (callerSignal.aborted) abort.abort()
    else callerSignal.addEventListener('abort', onCallerGone, { once: true })
  }
  const signal = abort.signal

  let file = null
  let db = null
  try {
    const mode = deps.driver || driverMode()
    const token = subscriptionToken(mode, { env })
    const quotaFn = deps.fetchQuotaImpl || fetchQuota
    const quotaBeforeP = quotaFn(token)

    const fetchDump = deps.fetchDump || ((a) => require('./tools').fetchUserDump(a))
    const budgetOf = (name, dflt) => (Number(env[name]) > 0 ? Number(env[name]) : dflt)
    const dump = await getDump({ request, key: env.USERDUMP_INTERNAL_KEY, signal, fetchDump, budgets: [budgetOf('FIRST_PASS_FULL_DUMP_MS', FULL_DUMP_BUDGET_MS), budgetOf('FIRST_PASS_DB_DUMP_MS', DB_DUMP_BUDGET_MS)] })
    file = dump.file
    const notes = [...dump.notes]

    let facts
    if (file) {
      try {
        db = (deps.openDb || defaultOpenDb)(file)
        facts = buildFacts({ db, memberid: request.memberid, mailHistory: request.mailHistory, nowMs: now() })
      } catch (_) {
        facts = noDumpFacts('the member data could not be opened', request.mailHistory)
        notes.push('The member data was fetched but could not be read.')
      }
    } else {
      facts = noDumpFacts(notes[0] ? notes[0].replace(/\.$/, '').toLowerCase() : 'not available', request.mailHistory)
      if (!request.memberid) facts[0] = { key: 'member', title: FACT_TITLES[0][1], lines: [], note: 'No single member to look up' }
    }
    if (!request.mailHistory.available) notes.push(`Past mail with support and info: not available (${request.mailHistory.reason || 'not set up'}).`)

    const nonce = deps.nonce || crypto.randomBytes(8).toString('hex')
    const prompt = buildUserPrompt({ request, facts, notes, nonce })
    const call = await callModel({ prompt, images: request.images, model, signal, queryImpl: deps.queryImpl, abort })
    const judged = validateAnswer(call.answer)

    const couldNotFind = [...judged.could_not_find]
    if (!request.memberid) couldNotFind.unshift('No single member to look up')
    else if (!file) couldNotFind.unshift('The member data could not be read, so the facts are missing')

    const chatImages = request.chat.messages.filter((m) => m.type === 'Image').length
    const brief = {
      version: 1,
      summary: judged.summary,
      classification: judged.classification,
      confidence: judged.confidence,
      facts,
      unusual: judged.unusual,
      could_not_find: couldNotFind.slice(0, 12),
      draft: { to: 'referrer', ...judged.draft, link_warnings: checkDraftLinks(judged.draft.text, pagesDir) },
      images: {
        sent: request.images.length,
        skipped: request.imagesSkipped ?? Math.max(request.imagesDropped || 0, chatImages - request.images.length),
      },
    }

    const quota = await quotaFn(token)
    const entries = [
      { type: 'prompt', text: prompt.length > TRANSCRIPT_CAP / 2 ? `${prompt.slice(0, TRANSCRIPT_CAP / 2)}... [prompt cut to fit]` : prompt },
      { type: 'images', count: request.images.length, names: request.images.map((i) => i.name) },
      { type: 'text', text: call.raw },
    ]
    return {
      brief,
      usage: { ...call.usage, durationMs: call.usage.durationMs || now() - started },
      costUsd: billableCostUsd(mode, call.cost),
      model: call.model,
      driver: mode,
      quota,
      quotaBefore: await quotaBeforeP,
      transcript: serialiseTranscript(entries, TRANSCRIPT_CAP),
    }
  } catch (e) {
    if (e instanceof FirstPassError) throw e
    if (signal.aborted) throw fail('DEADLINE')
    throw fail('MODEL')
  } finally {
    clearTimeout(timer)
    if (callerSignal) callerSignal.removeEventListener('abort', onCallerGone)
    try {
      if (db) db.close()
    } catch (_) {}
    if (file) {
      try {
        unlink(file)
      } catch (_) {}
    }
  }
}

module.exports = {
  FirstPassError,
  REASONS,
  cleanFirstPassRequest,
  agentEnv,
  checkDraftLinks,
  validateAnswer,
  callModel,
  getDump,
  runFirstPass,
}
