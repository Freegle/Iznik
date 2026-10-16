'use strict'

/**
 * The live UX idea generator: POST /internal/ux-ideas in server.js, asked daily by the batch's
 * ux:ideas-refresh. It gathers the evidence the batch sends (figures, survey answers, the problems
 * members raised and what they value, reports, what has been tried) with what only this container
 * has (the code as it is now and six months of its history), asks Claude for problems and then
 * ideas that solve them through the same SDK path and subscription token as the support agent, and
 * returns validated problems and ideas. The prompt and the output contract live in ux-ideas-prompt.js, which the
 * backtest shares, so this file is only plumbing: nothing here decides what a good idea is.
 *
 * Who may call, the one-run-at-a-time queue, the route handler and the reader of the model's JSON
 * are in ux-internal.js, which every /internal route shares.
 *
 * docs/developers/reference/ux-ideas.md.
 */

const { execFile: execFileCb } = require('node:child_process')
const { promisify } = require('node:util')
const fs = require('node:fs')
const path = require('node:path')

const { parseModelJson: parseIdeasJson } = require('./ux-internal')
const { FLOW_PATHS, IDEAS_SCHEMA, CATEGORIES, SOURCES, buildIdeasPrompt, clip } = require('./ux-ideas-prompt')
const { deepenHistory } = require('./codebase')
const { driverMode, billableCostUsd } = require('./auth')

const execFile = promisify(execFileCb)

const CODEBASE = process.env.CODEBASE_DIR || '/app/codebase'
// Same unpinned alias the support agent uses, for the same reason: dated snapshots get retired.
const MODEL = process.env.UX_IDEAS_MODEL || process.env.SUPPORT_AI_MODEL || 'opus'
const MAX_FILES = 16
const MAX_FILE_BYTES = 200 * 1024
const HISTORY_MONTHS = 6
const HISTORY_LIMIT = 150
const CODE_EXTENSIONS = new Set(['.vue', '.js', '.mjs', '.ts'])
const CATEGORY_KEYS = new Set(CATEGORIES.map((c) => c[0]))
const IDEA_TYPES = new Set(['fix', 'experiment'])
const SOURCE_KEYS = new Set(SOURCES)
const PROBLEM_REQUIRED = IDEAS_SCHEMA.properties.problems.items.required
const IDEA_REQUIRED = IDEAS_SCHEMA.properties.ideas.items.required
const JOURNEY_REQUIRED = IDEAS_SCHEMA.properties.journey.items.required

/**
 * Turn a request body into the pieces generateIdeas takes, with sizes bounded. Returns an error
 * string for an unknown flow, which the route answers with 400.
 */
function cleanRequest(body) {
  const b = body && typeof body === 'object' ? body : {}

  if (typeof b.flow !== 'string' || !Object.prototype.hasOwnProperty.call(FLOW_PATHS, b.flow)) {
    return { error: 'flow must be one of ' + Object.keys(FLOW_PATHS).join(', ') }
  }

  const text = (v) => (typeof v === 'string' ? v.slice(0, 40000) : '')
  const tried = (Array.isArray(b.tried) ? b.tried : [])
    .filter((t) => t && typeof t === 'object' && typeof t.title === 'string' && t.title.trim())
    .slice(0, 200)
    .map((t) => ({
      title: t.title.slice(0, 200),
      status: typeof t.status === 'string' ? t.status.slice(0, 40) : '',
      decision: typeof t.decision === 'string' ? t.decision.slice(0, 600) : '',
    }))
  const n = Math.round(Number(b.count))
  const count = Number.isFinite(n) && n >= 1 ? Math.min(n, 30) : 12

  return {
    flow: b.flow,
    figures: text(b.figures),
    surveys: text(b.surveys),
    problems: text(b.problems),
    values: text(b.values),
    reports: text(b.reports),
    tried,
    count,
  }
}

// ---------------------------------------------------------------------------
// What the container knows that the batch does not: the code and its history
// ---------------------------------------------------------------------------

function isoDate(d) {
  return d.toISOString().slice(0, 10)
}

function monthsAgo(months, now = new Date()) {
  const d = new Date(now.getTime())
  d.setUTCMonth(d.getUTCMonth() - months)

  return isoDate(d)
}

/**
 * Commit subjects touching a flow's files over the last six months, newest first, as
 * '2026-06-06 feat(compose): ...' lines (the form the prompt's history section uses).
 *
 * The container's checkout is shallow, so the history is fetched on demand first. git is run with
 * execFile and an argument list, never a shell string. Returns [] for an unknown flow, a missing
 * checkout or any git failure: no history is a thinner brief, not a failed run.
 *
 * @param {string} flow - a key of FLOW_PATHS.
 * @param {object} [opts]
 * @param {string} [opts.since] - YYYY-MM-DD; default six months back.
 * @param {string} [opts.codebaseDir]
 * @param {boolean} [opts.deepen] - fetch more history into a shallow checkout (default true).
 * @param {function} [opts.execFile] - injectable for tests.
 * @returns {Promise<string[]>}
 */
async function areaHistory(flow, opts = {}) {
  const paths = FLOW_PATHS[flow]
  if (!paths) return []

  const dir = opts.codebaseDir || CODEBASE
  const run = opts.execFile || execFile
  const since = /^\d{4}-\d{2}-\d{2}$/.test(opts.since || '') ? opts.since : monthsAgo(HISTORY_MONTHS)

  if (opts.deepen !== false) {
    await deepenHistory({ dir, since, execFile: run })
  }

  try {
    // --no-renames and no diff output: a path-limited log needs only trees, which matters when
    // the checkout was fetched without blobs.
    const { stdout } = await run(
      'git',
      ['log', `--since=${since}`, '--no-renames', '--format=%as %s', `-n${HISTORY_LIMIT}`, '--', ...paths],
      { cwd: dir, timeout: 30000, maxBuffer: 4 * 1024 * 1024 }
    )

    return String(stdout)
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
  } catch (e) {
    return []
  }
}

// Every code file under a directory, in a stable order, without following symlinks out of it.
function walk(absDir, depth = 0) {
  if (depth > 3) return []

  let entries
  try {
    entries = fs.readdirSync(absDir, { withFileTypes: true })
  } catch (e) {
    return []
  }

  entries.sort((a, b) => a.name.localeCompare(b.name))

  const files = []
  for (const e of entries) {
    const full = path.join(absDir, e.name)
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name.startsWith('.')) continue
      files.push(...walk(full, depth + 1))
    } else if (e.isFile() && CODE_EXTENSIONS.has(path.extname(e.name))) {
      files.push(full)
    }
  }

  return files
}

/**
 * The flow's files as they are in the checkout: [{path, content}], path relative to the checkout
 * root, pages first (the screens are what the ideas are about), at most 16 files. A directory in
 * FLOW_PATHS contributes every code file under it. Paths that do not exist are skipped, which is
 * how the old Find names stay harmless.
 */
function areaCode(flow, opts = {}) {
  const paths = FLOW_PATHS[flow]
  if (!paths) return []

  const root = path.resolve(opts.codebaseDir || CODEBASE)
  const pageFirst = [...paths].sort((a, b) => Number(b.includes('/pages/')) - Number(a.includes('/pages/')))
  const seen = new Set()
  const out = []

  for (const rel of pageFirst) {
    const abs = path.resolve(root, rel)
    // FLOW_PATHS is constants, but the guard is cheap and keeps a bad entry inside the checkout.
    if (abs !== root && !abs.startsWith(root + path.sep)) continue

    let stat
    try {
      stat = fs.statSync(abs)
    } catch (e) {
      continue
    }

    const candidates = stat.isDirectory() ? walk(abs) : stat.isFile() ? [abs] : []
    for (const file of candidates) {
      const relPath = path.relative(root, file).split(path.sep).join('/')
      if (seen.has(relPath)) continue

      let content
      try {
        if (fs.statSync(file).size > MAX_FILE_BYTES) continue
        content = fs.readFileSync(file, 'utf8')
      } catch (e) {
        continue
      }

      seen.add(relPath)
      out.push({ path: relPath, content })
      if (out.length >= MAX_FILES) return out
    }
  }

  return out
}

// ---------------------------------------------------------------------------
// Reading the model's answer: parseIdeasJson (ux-internal.js) finds the JSON, and these check it
// ---------------------------------------------------------------------------

const str = (v) => (typeof v === 'string' ? v.trim() : '')

/**
 * At most max characters of text, cut at the end of a word when there is a space to cut at. A title
 * cut in the middle of a word reads as a mistake. A single word longer than max is cut where it falls.
 */
function cutAtWord(text, max) {
  if (text.length <= max) return text
  // The character after the first max is a space, so the first max end at the end of a word.
  if (/\s/.test(text[max])) return text.slice(0, max).trimEnd()
  const head = text.slice(0, max)
  const space = head.search(/\s\S*$/)

  return (space > 0 ? head.slice(0, space) : head).trimEnd()
}

function num(v) {
  if (typeof v === 'number') return Number.isFinite(v) ? v : null
  if (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))) return Number(v)

  return null
}

/**
 * One idea, checked against IDEAS_SCHEMA's required keys and types, or null when it does not
 * pass. Numbers that arrive as strings are accepted; out-of-range scores are clamped rather than
 * rejected, because the model's estimate being 1.2 is not a reason to lose the idea.
 */
function validateIdea(raw) {
  if (!raw || typeof raw !== 'object') return null

  for (const key of IDEA_REQUIRED) {
    if (raw[key] === undefined || raw[key] === null) return null
  }

  const idea = {
    title: cutAtWord(str(raw.title), 80),
    category: str(raw.category),
    change: str(raw.change),
    rationale: str(raw.rationale),
    expected: str(raw.expected),
    risk: str(raw.risk),
  }
  for (const k of Object.keys(idea)) {
    if (!idea[k]) return null
  }
  if (!CATEGORY_KEYS.has(idea.category)) return null

  const points = num(raw.expected_points)
  const confidence = num(raw.confidence)
  const locality = num(raw.locality)
  if (points === null || confidence === null || locality === null) return null
  if (!Array.isArray(raw.files)) return null

  const problemId = num(raw.problem_id)
  const type = str(raw.type)
  if (problemId === null || !IDEA_TYPES.has(type)) return null

  idea.problem_id = Math.round(problemId)
  idea.type = type
  idea.expected_points = points
  idea.confidence = Math.min(1, Math.max(0, confidence))
  idea.locality = Math.min(5, Math.max(1, Math.round(locality)))
  idea.files = raw.files.filter((f) => typeof f === 'string' && f.trim()).map((f) => f.trim())
  idea.like_tried = str(raw.like_tried)

  return idea
}

function validateJourneyEntry(raw) {
  if (!raw || typeof raw !== 'object') return null

  const entry = {}
  for (const key of JOURNEY_REQUIRED) {
    entry[key] = str(raw[key])
    if (!entry[key]) return null
  }

  return entry
}

/**
 * One problem the model found, or null when it does not pass. A problem is something a member
 * would feel, not a solution, so only the fields the schema asks for are kept. The source must be
 * one of the known sources, so a made-up source cannot reach the table.
 */
function validateProblem(raw) {
  if (!raw || typeof raw !== 'object') return null

  for (const key of PROBLEM_REQUIRED) {
    if (raw[key] === undefined || raw[key] === null) return null
  }

  const id = num(raw.id)
  const title = cutAtWord(str(raw.title).replace(/\s+/g, ' '), 100)
  const source = str(raw.source)
  if (id === null || !title || !SOURCE_KEYS.has(source)) return null

  // The number of a problem members raised, when the model says this is one of them. Only a whole
  // positive number; the batch checks it is a real open problem for this funnel before using it.
  const memberProblem = typeof raw.member_problem === 'number' ? raw.member_problem : null

  return {
    id: Math.round(id),
    title,
    where: str(raw.where).slice(0, 300),
    source,
    evidence: str(raw.evidence).slice(0, 1000),
    ...(Number.isInteger(memberProblem) && memberProblem > 0 ? { member_problem: memberProblem } : {}),
  }
}

/**
 * Validate a parsed answer: keeps the well-formed problems, ideas and journey entries, drops the
 * rest, and never throws on one bad entry. Throws only when no idea is left, because an answer
 * with no ideas is a failed run and the caller should see a failure, not an empty success.
 *
 * An idea whose problem_id names no kept problem keeps its place but loses the link (problem_id
 * null): the idea may still be good, and a link to nothing would only fail later in the batch.
 * Problem ids are unique; the first one with an id wins.
 */
function normaliseAnswer(parsed, count = 12) {
  if (!parsed || typeof parsed !== 'object') {
    throw new Error('the model did not return a JSON object')
  }

  const problems = []
  const problemIds = new Set()
  for (const p of (Array.isArray(parsed.problems) ? parsed.problems : []).map(validateProblem)) {
    if (!p || problemIds.has(p.id)) continue
    problemIds.add(p.id)
    problems.push(p)
  }

  const ideas = (Array.isArray(parsed.ideas) ? parsed.ideas : [])
    .map(validateIdea)
    .filter(Boolean)
    .slice(0, count)
    .map((idea) => (problemIds.has(idea.problem_id) ? idea : { ...idea, problem_id: null }))
  if (!ideas.length) {
    throw new Error('the model returned no valid ideas')
  }

  return {
    journey: (Array.isArray(parsed.journey) ? parsed.journey : []).map(validateJourneyEntry).filter(Boolean),
    problems,
    ideas,
  }
}

// ---------------------------------------------------------------------------
// Asking the model
// ---------------------------------------------------------------------------

const SYSTEM_PROMPT = [
  'You are a product designer and front-end engineer helping Freegle, a UK reuse charity, find',
  'changes to its member-facing website that would help more members finish what they start.',
  'The Freegle code is checked out read-only in your working directory; you may read, grep and',
  'glob it to check how a screen behaves, but only the front-end source under iznik-nuxt3/ (components,',
  'composables, stores, pages, layouts, plugins, utils, mixins, api); always give Grep and Glob a path',
  'inside those folders. You cannot change anything and must not try to.',
  'Your final answer is a single JSON object matching the schema in the request, and nothing else.',
  'If you cannot return it as structured output, put it in one ```json fenced block.',
].join(' ')

// ---------------------------------------------------------------------------
// What the model may read
// ---------------------------------------------------------------------------

// The prompt carries text anyone can write (issue titles, forum posts, members' themes), so the
// run's tools are held to the front-end source it is meant to read: not .env files, server
// configuration, other repositories' files, or anything a planted instruction might ask it to
// quote back into an idea. The hook runs even in bypassPermissions mode.
const READABLE_DIRS = ['components', 'composables', 'stores', 'pages', 'layouts', 'plugins', 'utils', 'mixins', 'api']
const READABLE_EXTENSIONS = new Set(['.vue', '.js', '.mjs', '.ts', '.css', '.scss'])

function realOrResolved(p) {
  try {
    return fs.realpathSync(p)
  } catch (e) {
    return path.resolve(p)
  }
}

/**
 * Is this path (absolute, or relative to the checkout) inside the source directories? A file must
 * also have a source extension; a directory is fine, because Grep and Glob search under it.
 */
function readablePath(target, codebaseDir = CODEBASE) {
  if (typeof target !== 'string' || !target || target.includes('\0')) return false

  const root = realOrResolved(codebaseDir)
  const abs = realOrResolved(path.resolve(root, target))
  const under = READABLE_DIRS.map((d) => path.join(root, 'iznik-nuxt3', d))
  const inside = under.some((dir) => abs === dir || abs.startsWith(dir + path.sep))
  if (!inside) return false

  const ext = path.extname(abs)
  return ext === '' || READABLE_EXTENSIONS.has(ext)
}

/**
 * The reason a Read, Grep or Glob call is refused, or '' when it may go ahead. Grep and Glob must
 * name a directory to search, so a search of the whole checkout is not possible by leaving it out.
 */
function toolRefusal(toolName, input, codebaseDir = CODEBASE) {
  const i = input && typeof input === 'object' ? input : {}
  const hint = ' Only front-end source under iznik-nuxt3/' + READABLE_DIRS.join(', iznik-nuxt3/') + ' may be read.'

  if (toolName === 'Read') {
    return readablePath(i.file_path, codebaseDir) ? '' : 'That file is not readable.' + hint
  }
  if (toolName === 'Grep' || toolName === 'Glob') {
    if (!readablePath(i.path, codebaseDir)) return 'Give a path inside the source directories.' + hint
    if (toolName === 'Glob' && (typeof i.pattern !== 'string' || i.pattern.includes('..') || i.pattern.startsWith('/'))) {
      return 'That pattern is not allowed.' + hint
    }
    return ''
  }
  return 'That tool is not available.'
}

/** The PreToolUse hook that applies toolRefusal. */
function guardToolUse(codebaseDir = CODEBASE) {
  return async (input) => {
    const reason = toolRefusal(input && input.tool_name, input && input.tool_input, codebaseDir)
    if (!reason) return {}

    return { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason } }
  }
}

/**
 * One model call through the Claude Agent SDK, with Read/Grep/Glob confined to the checkout as the
 * support agent does and no other tools. Authentication is whichever credential auth.js finds, in
 * practice the geeks subscription token the helper already holds.
 *
 * @returns {Promise<{answer:object|null, text:string, costUsd:number, model:string}>}
 */
async function runIdeasQuery({ prompt, codebaseDir = CODEBASE, queryImpl, timeoutMs, signal }) {
  // Required here, not at the top, so this module loads (and its tests run) where the SDK is not
  // installed, as in the bare `node --test` CI step.
  const query = queryImpl || require('@anthropic-ai/claude-agent-sdk').query

  const abort = new AbortController()
  const limit = timeoutMs || Number(process.env.UX_IDEAS_TIMEOUT_MS || 15 * 60 * 1000)
  const timer = setTimeout(() => abort.abort(), limit)
  // Stop when the caller goes away. Without this a batch that gave up after its own timeout would
  // leave the run going, spending the subscription on an answer nobody will read while the next
  // request queues behind it.
  const onCallerGone = () => abort.abort()
  if (signal) {
    if (signal.aborted) abort.abort()
    else signal.addEventListener('abort', onCallerGone, { once: true })
  }

  const options = {
    model: MODEL,
    systemPrompt: SYSTEM_PROMPT,
    // The built-in set is cut to three read-only tools, and the write tools are refused as well in
    // case a future SDK default widens it. No MCP servers: this run needs no member data at all.
    tools: ['Read', 'Grep', 'Glob'],
    allowedTools: ['Read', 'Grep', 'Glob'],
    disallowedTools: ['Write', 'Edit', 'Bash', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Task'],
    permissionMode: 'bypassPermissions',
    hooks: { PreToolUse: [{ matcher: 'Read|Grep|Glob', hooks: [guardToolUse(codebaseDir)] }] },
    additionalDirectories: [codebaseDir],
    cwd: codebaseDir,
    // Reading a flow and following its imports takes many lookups; 30 ran out before an answer.
    // The time limit above is what bounds a run.
    maxTurns: Number(process.env.UX_IDEAS_MAX_TURNS || 150),
    // Do not write a session file for an hourly job nobody will resume.
    persistSession: false,
    abortController: abort,
    outputFormat: { type: 'json_schema', schema: IDEAS_SCHEMA },
  }

  let text = ''
  let structured = null
  let costUsd = 0
  let model = MODEL
  let failure = ''

  try {
    for await (const message of query({ prompt, options })) {
      if (message.type === 'system' && message.subtype === 'init' && message.model) {
        model = message.model
      } else if (message.type === 'result') {
        if (message.subtype === 'success') {
          text = message.result || ''
          structured = message.structured_output && typeof message.structured_output === 'object' ? message.structured_output : null
          costUsd = billableCostUsd(driverMode(), message.total_cost_usd)
        } else {
          failure = (message.errors || []).map((e) => (e && e.message) || String(e)).join('; ') || message.subtype
        }
      }
    }
  } catch (e) {
    if (signal && signal.aborted) throw new Error('the caller went away, so the model run was stopped')
    throw new Error(abort.signal.aborted ? `the model run timed out after ${limit} ms` : e.message || String(e))
  } finally {
    clearTimeout(timer)
    if (signal) signal.removeEventListener('abort', onCallerGone)
  }

  if (failure) throw new Error('the model run failed: ' + failure)
  if (signal && signal.aborted) throw new Error('the caller went away, so the model run was stopped')

  return { answer: structured || parseIdeasJson(text), text, costUsd, model }
}

/**
 * Generate ideas for one funnel.
 *
 * @param {object} o
 * @param {string} o.flow - a key of FLOW_PATHS.
 * @param {string} [o.figures] - plain-text rollup lines.
 * @param {string} [o.surveys] - plain-text survey and awareness lines.
 * @param {string} [o.problems] - plain-text lines: problems members raised, with mentions and rank.
 * @param {string} [o.values] - plain-text lines: what members value.
 * @param {string} [o.reports] - plain-text lines: reports and open issues.
 * @param {AbortSignal} [o.signal] - aborts the run (the caller disconnected).
 * @param {Array} [o.tried] - [{title, status, decision}] already run or rejected.
 * @param {number} [o.count] - ideas wanted (default 12).
 * @param {string} [o.codebaseDir]
 * @param {function} [o.queryImpl] - the SDK's query(), injectable for tests.
 * @param {function} [o.now] - () => Date, injectable for tests.
 * @returns {Promise<{journey:Array, problems:Array, ideas:Array, costUsd:number, model:string}>}
 */
async function generateIdeas({ flow, figures, surveys, problems, values, reports, tried, count, codebaseDir, queryImpl, now, signal }) {
  if (!Object.prototype.hasOwnProperty.call(FLOW_PATHS, flow)) {
    throw new Error('unknown flow: ' + flow)
  }

  const dir = codebaseDir || CODEBASE
  const wanted = count || 12
  if (!areaCode(flow, { codebaseDir: dir }).length) {
    // Ideas with no code to ground them are generic; failing says the checkout is missing.
    throw new Error('the codebase checkout has none of this flow\'s files: ' + dir)
  }

  const today = isoDate(now ? now() : new Date())
  const prompt = buildIdeasPrompt({
    flow,
    today,
    count: wanted,
    figures: clip(figures, 40000),
    surveys: clip(surveys, 40000),
    problems: clip(problems, 40000),
    values: clip(values, 40000),
    reports: clip(reports, 40000),
    tried: tried || [],
    recentCommits: await areaHistory(flow, { codebaseDir: dir }),
    // The model reads the checkout from the flow's files outwards, following imports, as in the
    // backtest that measured it. A clipped copy of a few files inlined here was never measured,
    // and the model went and read the files anyway.
    codeDir: dir,
  })

  const run = await runIdeasQuery({ prompt, codebaseDir: dir, queryImpl, signal })
  const result = normaliseAnswer(run.answer, wanted)

  return { ...result, costUsd: run.costUsd, model: run.model }
}

module.exports = {
  cleanRequest,
  areaHistory,
  areaCode,
  validateIdea,
  validateProblem,
  normaliseAnswer,
  runIdeasQuery,
  readablePath,
  toolRefusal,
  guardToolUse,
  generateIdeas,
}
