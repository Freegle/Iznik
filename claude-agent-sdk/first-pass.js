'use strict'

/**
 * The support first pass: the support helper's own investigation, run on a referral with nobody
 * watching. It reads the moderator's reason and the chat, works out the one question that needs
 * answering, checks only what answers it with the helper's read-only tools (the member's dump, the
 * live database, the logs, the error tracker, the volunteer forum, the code), and stops. Its answer
 * is a brief the batch stores and mails to support: the question, what was found, a draft reply to
 * the moderator, and what was checked. Nothing here sends anything to anyone.
 *
 * The run is bounded twice: by a turn limit (FIRST_PASS_MAX_TURNS) and by one deadline over the whole
 * pass (FIRST_PASS_DEADLINE_MS).
 *
 * Every outside dependency is injectable (the SDK's query(), the tools, the quota fetch, the clock),
 * and only Node built-ins and require-free modules load at the top, so CI's bare `node --test` can run
 * first-pass.test.js. The SDK and the tools are required lazily, when a real run needs them.
 *
 * Request and response shapes: plans/active/2026-11-07-referral-first-pass.md.
 */

const crypto = require('node:crypto')
const fs = require('node:fs')
const path = require('node:path')
const { validateImages, promptContent, MAX_REFERRAL_IMAGES, MAX_TOTAL_BYTES } = require('./images')
const { driverMode, billableCostUsd } = require('./auth')
const { subscriptionToken, fetchQuota, transcriptEntries, serialiseTranscript } = require('./run-record')
const { parseModelJson } = require('./ux-internal')
const { unknownSiteLinks } = require('./links')
const { agentEnv, agentOptions } = require('./agent-options')
const { CLASSIFICATIONS, CONFIDENCE_LEVELS, ANSWER_SCHEMA, systemPrompt, buildUserPrompt } = require('./first-pass-prompt')

const CHAT_TYPES = ['User2Mod', 'User2User', 'Mod2Mod', 'Group']
const SOURCE_KINDS = ['referral', 'mail']
const FROM_KINDS = ['member', 'moderator', 'other', 'system']
const MAX_MESSAGES = 50
const MAX_TEXT = 2000
const MAX_REASON = 2000
const MAX_MAIL_ENTRIES = 20
const MAX_RECENT_CASES = 20
const TRANSCRIPT_CAP = 200000
// An investigation takes longer than one model call: a dump, a few queries, a log search.
const DEFAULT_DEADLINE_MS = 480000
// The prompt asks it to stop after about twelve tool calls; this is the hard limit behind that.
const DEFAULT_MAX_TURNS = 30

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
  DUMP_AUTH: 'the AI helper is not allowed to read member data on this server',
  DUMP_LOCKDOWN: 'the member data could not be read during a lockdown',
  TOO_MANY_STEPS: 'the first pass ran out of steps before it could answer',
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
    // When the moderator referred it, so logs can be searched around then.
    referredAt: str(s.referredAt, 40),
  }

  const c = b.chat
  if (!c || typeof c !== 'object') return { error: 'chat is required' }
  const chatid = posInt(c.id)
  if (!chatid) return { error: 'chat.id must be a positive integer' }
  if (!CHAT_TYPES.includes(c.type)) return { error: 'chat.type must be User2Mod, User2User, Mod2Mod or Group' }
  if (!Array.isArray(c.messages)) return { error: 'chat.messages must be a list' }
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

  // Recent support cases, so a referral that is one of many about the same thing is answered from
  // what support already found rather than investigated again.
  const recentCases = Array.isArray(b.recentCases)
    ? b.recentCases
        .slice(0, MAX_RECENT_CASES)
        .map((r) => (r && typeof r === 'object' ? r : {}))
        .filter((r) => posInt(r.id) && posInt(r.id) !== caseid)
        .map((r) => ({
          id: r.id,
          created: str(r.created, 40),
          classification: str(r.classification, 20),
          question: str(r.question, 400),
          summary: str(r.summary, 400),
          supportReply: str(r.supportReply, 600),
        }))
    : []

  return { caseid, memberid, source, chat: { id: chatid, type: c.type, messages }, images, imagesSkipped, imagesDropped, mailHistory, recentCases }
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

// A model told not to use em dashes sometimes does anyway; the brief never carries one.
const DASHES = new RegExp('\\s*[' + String.fromCharCode(0x2014, 0x2013) + ']\\s*', 'g')
// Markdown images and raw img tags would make the brief load a remote picture the model was steered to
// name; the alt text stays.
const IMG_MD = /!\[([^\]]*)\]\([^)]*\)/g
const IMG_TAG = /<img\b[^>]*>/gi
const stripImages = (t) =>
  t.replace(IMG_MD, '$1').replace(IMG_TAG, (m) => {
    const alt = /\balt\s*=\s*(?:"([^"]*)"|'([^']*)')/i.exec(m)
    return alt ? alt[1] || alt[2] || '' : ''
  })
const plain = (v, max) =>
  stripImages(String(v == null ? '' : v))
    .replace(DASHES, ', ')
    .trim()
    .slice(0, max)

const list = (v, max, itemMax) => (Array.isArray(v) ? v.map((x) => plain(x, itemMax)).filter(Boolean).slice(0, max) : [])


/**
 * Check the model's answer and turn it into the brief's fields. A related case that is not one of the
 * recent cases it was given is dropped. Anything unusable is BAD_OUTPUT.
 *
 * @param {object} answer
 * @param {number[]} recentIds - the ids of the recent cases in the request.
 */
function validateAnswer(answer, recentIds = []) {
  if (!answer || typeof answer !== 'object') throw fail('BAD_OUTPUT')
  const question = plain(answer.question, 400)
  const found = plain(answer.answer, 4000)
  const draftText = plain(answer.draft && answer.draft.text, 4000)
  const confidence = answer.confidence || {}
  if (!question || !found || !draftText) throw fail('BAD_OUTPUT')
  if (!CLASSIFICATIONS.includes(answer.classification)) throw fail('BAD_OUTPUT')
  if (!CONFIDENCE_LEVELS.includes(confidence.level)) throw fail('BAD_OUTPUT')

  const related = Array.isArray(answer.related_cases) ? answer.related_cases.filter((id) => recentIds.includes(id)) : []
  return {
    question,
    answer: found,
    classification: answer.classification,
    confidence: { level: confidence.level, reason: plain(confidence.reason, 300) },
    checks: list(answer.checks, 12, 200),
    open_questions: list(answer.open_questions, 3, 300),
    related_cases: [...new Set(related)].slice(0, 10),
    draft: draftText,
  }
}

const CREDIT_PATTERN = /credit|quota|usage limit|limit reached|billing|insufficient|rate.?limit|overloaded|out of (?:usage|extra)/i

function usageOf(message) {
  const u = message.usage || {}
  return {
    inputTokens: u.input_tokens || 0,
    outputTokens: u.output_tokens || 0,
    cacheCreation: u.cache_creation_input_tokens || 0,
    cacheRead: u.cache_read_input_tokens || 0,
    durationMs: message.duration_ms || 0,
  }
}

/**
 * The investigation: the support agent with its read-only tools, a turn limit and the answer forced
 * into ANSWER_SCHEMA. Throws a FirstPassError: DEADLINE when the signal aborted, CREDIT or MODEL
 * otherwise.
 *
 * @returns {Promise<{answer, raw, usage, cost, model, entries}>}
 */
async function investigate({ request, prompt, model, maxTurns, codebase, key, signal, abort, deps }) {
  const sdk = deps.queryImpl ? null : require('@anthropic-ai/claude-agent-sdk')
  const query = deps.queryImpl || sdk.query
  const createServer = deps.createMcpServer || ((o) => (sdk || require('@anthropic-ai/claude-agent-sdk')).createSdkMcpServer(o))
  const makeTools = deps.buildTools || ((ctx) => require('./tools').buildTools(ctx))

  // A refused dump key or a held export stops the run: working round it with other tools would give a
  // brief that reads as complete, or read data a lockdown is holding back.
  let refused = null
  const { tools, names, cleanup } = makeTools({
    jwt: null,
    dumpKey: key,
    caseId: request.caseid,
    signal,
    userId: request.memberid || 0,
    modId: 'first-pass',
    progress: () => {},
    onDumpRefused: (status) => {
      refused = status === 409 ? 'DUMP_LOCKDOWN' : 'DUMP_AUTH'
      abort.abort()
    },
  })
  const options = agentOptions({
    model,
    systemPrompt: systemPrompt(request.memberid, codebase),
    toolNames: names,
    mcpServer: createServer({ name: 'freegle', version: '2.0.0', tools }),
    codebase,
    maxTurns,
    alsoDisallow: ['WebFetch', 'WebSearch', 'Task'],
    extra: {
      persistSession: false,
      abortController: abort,
      outputFormat: { type: 'json_schema', schema: ANSWER_SCHEMA },
    },
  })
  const input = request.images.length
    ? (async function* () {
        yield { type: 'user', message: { role: 'user', content: promptContent(prompt, request.images) }, parent_tool_use_id: null, session_id: '' }
      })()
    : prompt

  const entries = []
  let structured = null
  let text = ''
  let usage = usageOf({})
  let cost = 0
  let model2 = model
  let failure = ''
  let endSubtype = ''
  try {
    for await (const message of query({ prompt: input, options })) {
      entries.push(...transcriptEntries(message))
      if (message.type === 'system' && message.subtype === 'init' && message.model) model2 = message.model
      if (message.type !== 'result') continue
      if (message.subtype === 'success' && message.is_error) {
        // A usage-limit or credit error can arrive as a success result flagged is_error, with the
        // error in the result text.
        failure = String(message.result || '') || 'error'
      } else if (message.subtype === 'success') {
        text = message.result || ''
        structured = message.structured_output && typeof message.structured_output === 'object' ? message.structured_output : null
        usage = usageOf(message)
        cost = message.total_cost_usd || 0
      } else {
        // error_max_turns and the like: the run ended without an answer.
        usage = usageOf(message)
        endSubtype = String(message.subtype || '')
        // The SDK's own end-of-run type (error_max_turns and so on) and the turn count say why, and
        // carry no member text.
        ;(deps.log || console.log)(`[SupportFirstPass] run ended without an answer: ${String(message.subtype || 'unknown').replace(/[^a-z_]/gi, '')} after ${Number(message.num_turns) || 0} turns`)
        failure = (message.errors || []).map((e) => (e && e.message) || String(e)).join('; ') || String(message.subtype || '')
      }
    }
  } catch (e) {
    if (refused) throw fail(refused)
    if (signal.aborted) throw fail('DEADLINE')
    // The upstream text is only used to tell credit from other failures; it is never stored or returned.
    throw fail(CREDIT_PATTERN.test((e && e.message) || '') ? 'CREDIT' : 'MODEL')
  } finally {
    try {
      cleanup()
    } catch (_) {}
  }
  if (refused) throw fail(refused)
  if (signal.aborted) throw fail('DEADLINE')
  if (endSubtype === 'error_max_turns') throw fail('TOO_MANY_STEPS')
  if (failure) throw fail(CREDIT_PATTERN.test(failure) ? 'CREDIT' : 'MODEL')
  if (!structured) (deps.log || console.log)(`[SupportFirstPass] no structured answer; ${text ? 'parsing the text' : 'the run returned no text'}`)

  return { answer: structured || parseModelJson(text), raw: structured ? JSON.stringify(structured) : text, usage, cost, model: model2, entries }
}

// ---------------------------------------------------------------------------
// The whole pass
// ---------------------------------------------------------------------------

/**
 * Run one first pass.
 *
 * @param {object} request - cleaned by cleanFirstPassRequest.
 * @param {object} [o]
 * @param {AbortSignal} [o.signal] - the caller going away.
 * @param {object} [o.deps] - queryImpl, buildTools, createMcpServer, fetchQuotaImpl, audit, now, env, pagesDir, nonce, driver.
 */
async function runFirstPass(request, { signal: callerSignal, deps = {} } = {}) {
  const env = deps.env || process.env
  const now = deps.now || Date.now
  const started = now()
  const deadlineMs = Number(env.FIRST_PASS_DEADLINE_MS) > 0 ? Number(env.FIRST_PASS_DEADLINE_MS) : DEFAULT_DEADLINE_MS
  const maxTurns = Number(env.FIRST_PASS_MAX_TURNS) > 0 ? Number(env.FIRST_PASS_MAX_TURNS) : DEFAULT_MAX_TURNS
  const model = env.FIRST_PASS_MODEL || env.SUPPORT_AI_MODEL || 'opus'
  const codebase = env.CODEBASE_DIR || '/app/codebase'
  const auditFn = deps.audit || (() => {})
  const pagesDir = deps.pagesDir !== undefined ? deps.pagesDir : path.join(codebase, 'iznik-nuxt3', 'pages')

  // A member to look up but no key is a server set up wrongly, not a reason to pass on a thinner brief.
  if (request.memberid && !env.USERDUMP_INTERNAL_KEY) throw fail('DUMP_AUTH')

  auditFn({ tool: 'first_pass', caseid: request.caseid, target: request.memberid || 0, images: request.images.length })

  const abort = new AbortController()
  const timer = setTimeout(() => abort.abort(), deadlineMs)
  const onCallerGone = () => abort.abort()
  if (callerSignal) {
    if (callerSignal.aborted) abort.abort()
    else callerSignal.addEventListener('abort', onCallerGone, { once: true })
  }
  const signal = abort.signal

  try {
    const mode = deps.driver || driverMode()
    const token = subscriptionToken(mode, { env })
    const quotaFn = deps.fetchQuotaImpl || fetchQuota
    const quotaBeforeP = quotaFn(token)

    const nonce = deps.nonce || crypto.randomBytes(8).toString('hex')
    const prompt = buildUserPrompt({ request, nonce })
    const run = await investigate({ request, prompt, model, maxTurns, codebase, key: env.USERDUMP_INTERNAL_KEY, signal, abort, deps })
    const judged = validateAnswer(run.answer, request.recentCases.map((c) => c.id))

    const chatImages = request.chat.messages.filter((m) => m.type === 'Image').length
    const brief = {
      version: 2,
      question: judged.question,
      answer: judged.answer,
      classification: judged.classification,
      confidence: judged.confidence,
      checks: judged.checks,
      open_questions: judged.open_questions,
      related_cases: judged.related_cases,
      draft: { to: 'referrer', text: judged.draft, link_warnings: checkDraftLinks(judged.draft, pagesDir) },
      images: {
        sent: request.images.length,
        skipped: request.imagesSkipped ?? Math.max(request.imagesDropped || 0, chatImages - request.images.length),
      },
    }

    const quota = await quotaFn(token)
    const entries = [
      { type: 'prompt', text: prompt.length > TRANSCRIPT_CAP / 2 ? `${prompt.slice(0, TRANSCRIPT_CAP / 2)}... [prompt cut to fit]` : prompt },
      { type: 'images', count: request.images.length, names: request.images.map((i) => i.name) },
      ...run.entries,
    ]
    return {
      brief,
      usage: { ...run.usage, durationMs: run.usage.durationMs || now() - started },
      costUsd: billableCostUsd(mode, run.cost),
      model: run.model,
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
  }
}

module.exports = {
  FirstPassError,
  REASONS,
  cleanFirstPassRequest,
  agentEnv,
  checkDraftLinks,
  validateAnswer,
  investigate,
  runFirstPass,
}
