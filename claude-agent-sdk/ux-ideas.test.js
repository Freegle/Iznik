'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { execFileSync } = require('node:child_process')

const { FLOW_PATHS, IDEAS_SCHEMA } = require('./ux-ideas-prompt')
const {
  cleanRequest,
  areaHistory,
  areaCode,
  validateIdea,
  validateProblem,
  normaliseAnswer,
  generateIdeas,
  readablePath,
  toolRefusal,
  guardToolUse,
} = require('./ux-ideas')
const { internalHandler } = require('./ux-internal')
const { deepenHistory } = require('./codebase')

// ---------------------------------------------------------------------------
// A throwaway checkout: two commits touching a Give file, one touching nothing in any flow.
// Dates are relative to now so the six-month window always holds them.
// ---------------------------------------------------------------------------

function git(dir, args, date) {
  const env = { ...process.env, GIT_AUTHOR_NAME: 't', GIT_AUTHOR_EMAIL: 't@t', GIT_COMMITTER_NAME: 't', GIT_COMMITTER_EMAIL: 't@t' }
  if (date) {
    env.GIT_AUTHOR_DATE = date
    env.GIT_COMMITTER_DATE = date
  }
  execFileSync('git', args, { cwd: dir, env, stdio: 'pipe' })
}

function daysAgo(n) {
  return new Date(Date.now() - n * 86400000).toISOString()
}

function write(dir, rel, content) {
  const abs = path.join(dir, rel)
  fs.mkdirSync(path.dirname(abs), { recursive: true })
  fs.writeFileSync(abs, content)
}

function makeRepo() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ux-ideas-test-'))
  git(dir, ['init', '-q'])

  write(dir, 'iznik-nuxt3/pages/give/index.vue', '<template>give one</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'feat(give): first version of the give page'], daysAgo(60))

  write(dir, 'iznik-nuxt3/pages/give/index.vue', '<template>give two</template>')
  write(dir, 'iznik-nuxt3/components/PostPhoto.vue', '<template>photo</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'fix(give): keep the photo when the upload stalls'], daysAgo(10))

  write(dir, 'iznik-nuxt3/pages/settings/index.vue', '<template>settings</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'chore(settings): unrelated to give'], daysAgo(5))

  return dir
}

const repo = makeRepo()
test.after(() => fs.rmSync(repo, { recursive: true, force: true }))

// ---------------------------------------------------------------------------

test('cleanRequest validates the flow and bounds the rest', () => {
  assert.match(cleanRequest({ flow: 'mystery' }).error, /flow must be one of/)
  assert.match(cleanRequest(null).error, /flow must be one of/)
  assert.match(cleanRequest({ flow: '__proto__' }).error, /flow must be one of/)
  assert.match(cleanRequest({ flow: 'toString' }).error, /flow must be one of/)

  const ok = cleanRequest({
    flow: 'give',
    figures: 'a',
    surveys: 7,
    tried: [{ title: 'T', status: 'open', decision: 'd' }, { nope: 1 }, null, { title: '  ' }],
    count: 500,
  })
  assert.equal(ok.flow, 'give')
  assert.equal(ok.surveys, '')
  assert.deepEqual(ok.tried, [{ title: 'T', status: 'open', decision: 'd' }])
  assert.equal(ok.count, 30)
  assert.equal(cleanRequest({ flow: 'ask' }).count, 12)

  // The three sections that carry the members' problems, what they value and the reports.
  const sections = cleanRequest({ flow: 'give', problems: 'Photos are slow (14 mentioned it)', values: 'It is free', reports: 'x'.repeat(50000), extra: 'ignored' })
  assert.equal(sections.problems, 'Photos are slow (14 mentioned it)')
  assert.equal(sections.values, 'It is free')
  assert.equal(sections.reports.length, 40000)
  assert.equal(sections.extra, undefined)
  const none = cleanRequest({ flow: 'give', problems: 7, values: null })
  assert.deepEqual([none.problems, none.values, none.reports], ['', '', ''])
})

test('areaHistory returns the flow\'s commits, newest first, and nothing from other areas', async () => {
  const history = await areaHistory('give', { codebaseDir: repo })

  assert.equal(history.length, 2)
  assert.match(history[0], /^\d{4}-\d{2}-\d{2} fix\(give\): keep the photo when the upload stalls$/)
  assert.match(history[1], /first version of the give page$/)
  assert.ok(!history.some((h) => h.includes('unrelated')))
})

test('areaHistory honours since, and is empty for an unknown flow or a missing checkout', async () => {
  const recent = await areaHistory('give', { codebaseDir: repo, since: daysAgo(30).slice(0, 10) })
  assert.equal(recent.length, 1)
  assert.match(recent[0], /upload stalls/)

  assert.deepEqual(await areaHistory('mystery', { codebaseDir: repo }), [])
  assert.deepEqual(await areaHistory('give', { codebaseDir: path.join(repo, 'nope') }), [])
})

test('areaHistory passes git an argument list, never a shell string, and a bad since cannot become an option', async () => {
  const calls = []
  const fake = async (file, args, opts) => {
    calls.push({ file, args, opts })
    return { stdout: '2026-01-02 something\n\n' }
  }

  const out = await areaHistory('give', { codebaseDir: '/x', since: '--output=/tmp/evil', execFile: fake, deepen: false })
  assert.deepEqual(out, ['2026-01-02 something'])
  assert.equal(calls.length, 1)
  assert.equal(calls[0].file, 'git')
  assert.ok(Array.isArray(calls[0].args))
  assert.match(calls[0].args.find((a) => a.startsWith('--since=')), /^--since=\d{4}-\d{2}-\d{2}$/)
  const dashdash = calls[0].args.indexOf('--')
  assert.deepEqual(calls[0].args.slice(dashdash + 1), FLOW_PATHS.give)
})

test('deepenHistory fetches only into a shallow checkout, and never throws', async () => {
  const seen = []
  const shallow = async (file, args) => {
    seen.push(args.join(' '))
    return { stdout: args[0] === 'rev-parse' ? 'true\n' : '' }
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: shallow }), true)
  assert.match(seen[1], /^fetch --quiet --filter=blob:none --shallow-since=2026-04-01 origin$/)

  seen.length = 0
  const full = async (file, args) => {
    seen.push(args[0])
    return { stdout: 'false\n' }
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: full }), false)
  assert.deepEqual(seen, ['rev-parse'])

  const broken = async () => {
    throw new Error('no network')
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: broken }), false)
})

test('areaCode returns the flow\'s existing files, pages first, as checkout-relative paths', () => {
  const code = areaCode('give', { codebaseDir: repo })
  const paths = code.map((f) => f.path)

  // PostItem.vue and the rest of the list do not exist in the throwaway repo and are skipped.
  assert.deepEqual(paths, ['iznik-nuxt3/pages/give/index.vue', 'iznik-nuxt3/components/PostPhoto.vue'])
  assert.equal(code[0].content, '<template>give two</template>')
})

test('areaCode is empty for an unknown flow or missing checkout, and capped at 16 files', () => {
  assert.deepEqual(areaCode('mystery', { codebaseDir: repo }), [])
  assert.deepEqual(areaCode('give', { codebaseDir: path.join(repo, 'nope') }), [])

  const big = fs.mkdtempSync(path.join(os.tmpdir(), 'ux-ideas-big-'))
  try {
    for (let i = 0; i < 30; i++) write(big, `iznik-nuxt3/pages/give/p${String(i).padStart(2, '0')}.vue`, 'x')
    write(big, 'iznik-nuxt3/pages/give/node_modules/skip.js', 'x')
    write(big, 'iznik-nuxt3/pages/give/notes.md', 'not code')
    const code = areaCode('give', { codebaseDir: big })
    assert.equal(code.length, 16)
    assert.equal(code[0].path, 'iznik-nuxt3/pages/give/p00.vue')
    assert.ok(code.every((f) => f.path.endsWith('.vue')))
  } finally {
    fs.rmSync(big, { recursive: true, force: true })
  }
})

// ---------------------------------------------------------------------------

const goodIdea = {
  problem_id: 1,
  type: 'experiment',
  title: 'Show Add photos later as a link on the mobile photos step',
  category: 'disclosure',
  change: 'The photos step leads with the item name.',
  rationale: 'The photos step loses 31% of visitors.',
  expected: 'Give completion up about 2 points.',
  expected_points: 2,
  confidence: 0.4,
  locality: 4,
  risk: 'Fewer posts have photos.',
  files: ['iznik-nuxt3/components/PostPhoto.vue'],
}

test('validateIdea keeps good ideas, clamps scores and rejects malformed ones', () => {
  assert.deepEqual(validateIdea(goodIdea), { ...goodIdea, like_tried: '' })

  const clamped = validateIdea({ ...goodIdea, confidence: 1.4, locality: 9, expected_points: '3.5', title: 'x'.repeat(200) })
  assert.equal(clamped.confidence, 1)
  assert.equal(clamped.locality, 5)
  assert.equal(clamped.expected_points, 3.5)
  assert.equal(clamped.title.length, 80)

  for (const key of IDEAS_SCHEMA.properties.ideas.items.required) {
    const missing = { ...goodIdea }
    delete missing[key]
    assert.equal(validateIdea(missing), null, 'missing ' + key)
  }
  assert.equal(validateIdea({ ...goodIdea, category: 'sparkle' }), null, 'category outside the enum')
  assert.equal(validateIdea({ ...goodIdea, type: 'rewrite' }), null, 'type outside fix and experiment')
  assert.equal(validateIdea({ ...goodIdea, problem_id: 'one' }), null)
  assert.equal(validateIdea({ ...goodIdea, type: 'fix' }).type, 'fix')
  assert.equal(validateIdea({ ...goodIdea, problem_id: '3' }).problem_id, 3)
  assert.equal(validateIdea({ ...goodIdea, confidence: 'high' }), null)
  assert.equal(validateIdea({ ...goodIdea, files: 'one.vue' }), null)
  assert.equal(validateIdea({ ...goodIdea, title: '   ' }), null)
  assert.equal(validateIdea(null), null)
  assert.equal(validateIdea('idea'), null)
  assert.deepEqual(validateIdea({ ...goodIdea, files: ['a.vue', 3, ' '] }).files, ['a.vue'])
})

test('validateIdea cuts a title that is too long at the end of a word, not in the middle of one', () => {
  // 90 characters: the 80th falls inside "slow".
  const long = 'Add a plain skip link above the camera button on the photos step for people on slow phones'
  assert.equal(long.length, 90)
  assert.equal(validateIdea({ ...goodIdea, title: long }).title, 'Add a plain skip link above the camera button on the photos step for people on')

  // Exactly 80 is kept whole, and a space just after the 80th character ends the title there.
  const eighty = 'word '.repeat(15) + 'words'
  assert.equal(eighty.length, 80)
  assert.equal(validateIdea({ ...goodIdea, title: eighty }).title, eighty)
  assert.equal(validateIdea({ ...goodIdea, title: eighty + ' more' }).title, eighty)
  assert.equal(validateIdea({ ...goodIdea, title: eighty + 'x' }).title, 'word '.repeat(15).trim(), 'a word that runs past 80 is left out whole')

  // One word longer than the limit has no end of a word to cut at, so it is cut where it falls.
  assert.equal(validateIdea({ ...goodIdea, title: 'x'.repeat(200) }).title.length, 80)
})

const goodProblem = { id: 1, title: 'People cannot tell the photo step can be skipped', where: 'Photos step', source: 'members', evidence: '14 members said so' }

test('validateProblem keeps the number of a problem members raised, and only a whole positive one', () => {
  assert.equal(validateProblem({ ...goodProblem, member_problem: 12 }).member_problem, 12)
  for (const bad of [0, -3, 2.5, 'twelve', null]) {
    assert.equal(validateProblem({ ...goodProblem, member_problem: bad }).member_problem, undefined, String(bad))
  }
  assert.equal(validateProblem(goodProblem).member_problem, undefined)
})

test('validateProblem keeps a good problem and rejects ones that are malformed or from an unknown source', () => {
  assert.deepEqual(validateProblem(goodProblem), goodProblem)

  const long = validateProblem({ ...goodProblem, title: '  ' + 'word '.repeat(60) + '  ', id: '4' })
  assert.equal(long.id, 4)
  assert.ok(long.title.length <= 100)
  assert.ok(!/\s{2}/.test(long.title))

  for (const key of ['id', 'title', 'where', 'source', 'evidence']) {
    const missing = { ...goodProblem }
    delete missing[key]
    assert.equal(validateProblem(missing), null, 'missing ' + key)
  }
  assert.equal(validateProblem({ ...goodProblem, source: 'rumour' }), null)
  assert.equal(validateProblem({ ...goodProblem, title: '   ' }), null)
  assert.equal(validateProblem({ ...goodProblem, id: 'x' }), null)
  assert.equal(validateProblem(null), null)
  assert.equal(validateProblem('problem'), null)
})

test('validateProblem cuts a title that is too long at the end of a word too', () => {
  const long = 'Members using a very slow phone connection often wait a long time for the photo upload to finish before they can carry on with their post'
  const cut = validateProblem({ ...goodProblem, title: long }).title
  assert.equal(cut, 'Members using a very slow phone connection often wait a long time for the photo upload to finish')
  assert.ok(cut.length <= 100)
  assert.equal(validateProblem({ ...goodProblem, title: 'y'.repeat(300) }).title.length, 100)
})

test('normaliseAnswer drops bad entries without throwing, caps at count, links ideas to problems, and fails only when no idea is left', () => {
  const answer = normaliseAnswer(
    {
      problems: [goodProblem, { id: 1, title: 'A second with the same id', where: 'x', source: 'code', evidence: 'e' }, { title: 'broken' }, null, { ...goodProblem, id: 2, title: 'Second problem', source: 'figures' }],
      journey: [{ screen: 'Photos', asks: 'Add a photo', friction: 'Upload stalls' }, { screen: 'No asks' }, 7],
      ideas: [goodIdea, { title: 'broken' }, null, { ...goodIdea, title: 'Second', problem_id: 2, type: 'fix' }, { ...goodIdea, title: 'Third' }],
    },
    2
  )

  assert.deepEqual(answer.problems.map((p) => [p.id, p.title]), [[1, goodProblem.title], [2, 'Second problem']], 'duplicate ids and broken problems dropped')
  assert.equal(answer.journey.length, 1)
  assert.deepEqual(answer.ideas.map((i) => i.title), [goodIdea.title, 'Second'])
  assert.deepEqual(answer.ideas.map((i) => [i.problem_id, i.type]), [[1, 'experiment'], [2, 'fix']])
  assert.equal(answer.problem, undefined, 'the old single problem field is gone')

  // An idea that names a problem the model did not list keeps its place but loses the link.
  const dangling = normaliseAnswer({ problems: [goodProblem], journey: [], ideas: [{ ...goodIdea, problem_id: 99 }] })
  assert.equal(dangling.ideas[0].problem_id, null)
  const noProblems = normaliseAnswer({ journey: [], ideas: [goodIdea] })
  assert.deepEqual(noProblems.problems, [])
  assert.equal(noProblems.ideas[0].problem_id, null)

  assert.throws(() => normaliseAnswer(null), /JSON object/)
  assert.throws(() => normaliseAnswer({ problems: [goodProblem], journey: [], ideas: [{ title: 'x' }] }), /no valid ideas/)
  assert.throws(() => normaliseAnswer({ problems: [goodProblem] }), /no valid ideas/)
})

test('generateIdeas assembles the brief and returns validated ideas from structured output', async () => {
  let seen
  const queryImpl = async function* (arg) {
    seen = arg
    yield { type: 'system', subtype: 'init', model: 'claude-test-1' }
    yield {
      type: 'result',
      subtype: 'success',
      result: '',
      total_cost_usd: 1.23,
      structured_output: { problems: [goodProblem], journey: [], ideas: [goodIdea, { broken: true }] },
    }
  }

  const out = await generateIdeas({
    flow: 'give',
    figures: 'give: 822 started',
    surveys: 'login worst',
    problems: 'Photos are slow (14 said so)',
    values: 'It is free (30 said so)',
    reports: 'Discourse: photo upload hangs',
    tried: [{ title: 'Shorter first screen', status: 'open' }],
    count: 5,
    codebaseDir: repo,
    queryImpl,
    now: () => new Date('2026-10-14T12:00:00Z'),
  })

  assert.deepEqual(out.problems, [goodProblem])
  assert.equal(out.ideas.length, 1)
  assert.equal(out.ideas[0].problem_id, 1)
  assert.equal(out.model, 'claude-test-1')
  assert.ok(Object.keys(out).includes('costUsd'))

  assert.match(seen.prompt, /Today is 2026-10-14/)
  assert.match(seen.prompt, /give: 822 started/)
  assert.match(seen.prompt, /Photos are slow \(14 said so\)/)
  assert.match(seen.prompt, /It is free \(30 said so\)/)
  assert.match(seen.prompt, /Discourse: photo upload hangs/)
  assert.match(seen.prompt, /Shorter first screen \[open\]/)
  assert.match(seen.prompt, /keep the photo when the upload stalls/)
  assert.match(seen.prompt, /return the best 5/)
  // The way the backtest scored: the model reads the checkout from the flow's files outwards,
  // rather than getting a clipped copy of a few of them.
  assert.ok(seen.prompt.includes('checked out under ' + repo), 'points at the checkout')
  assert.match(seen.prompt, /^- iznik-nuxt3\/pages\/give/m)
  assert.doesNotMatch(seen.prompt, /### iznik-nuxt3\/pages\/give\/index.vue/)
  // Reading a flow outwards takes many lookups; the run's time limit is what bounds it.
  assert.ok(seen.options.maxTurns >= 100, `maxTurns ${seen.options.maxTurns}`)

  // Read-only tools only, confined to the checkout, with the schema as the output format.
  assert.deepEqual(seen.options.tools, ['Read', 'Grep', 'Glob'])
  assert.deepEqual(seen.options.allowedTools, ['Read', 'Grep', 'Glob'])
  for (const t of ['Write', 'Edit', 'Bash']) assert.ok(seen.options.disallowedTools.includes(t), t)
  assert.equal(seen.options.cwd, repo)
  assert.deepEqual(seen.options.additionalDirectories, [repo])
  assert.equal(seen.options.mcpServers, undefined)
  assert.equal(seen.options.hooks.PreToolUse[0].matcher, 'Read|Grep|Glob')
  assert.equal(seen.options.hooks.PreToolUse[0].hooks.length, 1)
  assert.equal(seen.options.outputFormat.type, 'json_schema')
  assert.equal(seen.options.outputFormat.schema, IDEAS_SCHEMA)
})

test('generateIdeas falls back to parsing a fenced block when there is no structured output', async () => {
  const text = 'Thinking aloud.\n```json\n' + JSON.stringify({ problems: [goodProblem], journey: [], ideas: [goodIdea] }) + '\n```'
  const queryImpl = async function* () {
    yield { type: 'result', subtype: 'success', result: text, total_cost_usd: 0 }
  }

  const out = await generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl })
  assert.equal(out.ideas.length, 1)
})

test('generateIdeas fails loudly on an unknown flow, no code, an unparseable answer or a failed run', async () => {
  const ok = async function* () {
    yield { type: 'result', subtype: 'success', result: 'I could not do it.' }
  }
  await assert.rejects(generateIdeas({ flow: 'mystery', codebaseDir: repo, queryImpl: ok }), /unknown flow/)
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: path.join(repo, 'nope'), queryImpl: ok }), /none of this flow/)
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: ok }), /JSON object/)

  const failed = async function* () {
    yield { type: 'result', subtype: 'error_max_turns', errors: [] }
  }
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: failed }), /error_max_turns/)

  const thrown = async function* () {
    throw new Error('rate limited')
    yield null
  }
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: thrown }), /rate limited/)
})

test('generateIdeas stops when its signal aborts, before or during the run', async () => {
  const early = new AbortController()
  early.abort()
  const never = async function* () {
    yield { type: 'result', subtype: 'success', result: JSON.stringify({ problems: [], journey: [], ideas: [goodIdea] }) }
  }
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: never, signal: early.signal }), /caller went away/)

  const late = new AbortController()
  const hanging = async function* (arg) {
    // The SDK ends the iteration when its abortController fires.
    await new Promise((resolve, reject) => arg.options.abortController.signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true }))
    yield null
  }
  const run = generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: hanging, signal: late.signal })
  setTimeout(() => late.abort(), 20)
  await assert.rejects(run, /caller went away/)
})

test('the model may read front-end source and nothing else in the checkout', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'uxguard-'))
  fs.mkdirSync(path.join(root, 'iznik-nuxt3', 'components'), { recursive: true })
  fs.writeFileSync(path.join(root, 'iznik-nuxt3', 'components', 'A.vue'), '<template/>')
  fs.writeFileSync(path.join(root, 'iznik-nuxt3', 'components', 'secret.env'), 'KEY=1')
  fs.writeFileSync(path.join(root, '.env'), 'KEY=1')
  fs.writeFileSync(path.join(root, 'iznik-nuxt3', 'nuxt.config.js'), 'export default {}')
  fs.symlinkSync(path.join(root, '.env'), path.join(root, 'iznik-nuxt3', 'components', 'link.vue'))

  assert.ok(readablePath('iznik-nuxt3/components/A.vue', root))
  assert.ok(readablePath(path.join(root, 'iznik-nuxt3', 'components', 'A.vue'), root))
  assert.ok(readablePath('iznik-nuxt3/components', root), 'a directory to search under')
  for (const bad of ['.env', 'iznik-nuxt3/nuxt.config.js', 'iznik-nuxt3/components/secret.env', '../etc/passwd',
    'iznik-nuxt3/components/../../.env', 'iznik-nuxt3/components/link.vue', '/etc/passwd', '', undefined]) {
    assert.equal(readablePath(bad, root), false, String(bad))
  }

  assert.equal(toolRefusal('Read', { file_path: 'iznik-nuxt3/components/A.vue' }, root), '')
  assert.match(toolRefusal('Read', { file_path: '.env' }, root), /not readable/)
  assert.equal(toolRefusal('Grep', { pattern: 'x', path: 'iznik-nuxt3/components' }, root), '')
  assert.match(toolRefusal('Grep', { pattern: 'KEY' }, root), /Give a path/, 'a search of the whole checkout is refused')
  assert.match(toolRefusal('Grep', { pattern: 'KEY', path: '.' }, root), /Give a path/)
  assert.equal(toolRefusal('Glob', { pattern: '**/*.vue', path: 'iznik-nuxt3/components' }, root), '')
  assert.match(toolRefusal('Glob', { pattern: '../../**', path: 'iznik-nuxt3/components' }, root), /pattern/)
  assert.match(toolRefusal('Glob', { pattern: '/etc/*', path: 'iznik-nuxt3/components' }, root), /pattern/)
  assert.match(toolRefusal('Bash', { command: 'cat .env' }, root), /not available/)

  const hook = guardToolUse(root)
  assert.deepEqual(await hook({ tool_name: 'Read', tool_input: { file_path: 'iznik-nuxt3/components/A.vue' } }), {})
  const denied = await hook({ tool_name: 'Read', tool_input: { file_path: '.env' } })
  assert.equal(denied.hookSpecificOutput.permissionDecision, 'deny')
  assert.equal(denied.hookSpecificOutput.hookEventName, 'PreToolUse')
})

// ---------------------------------------------------------------------------
// The route. The guard, the queue and the handler are tested in ux-internal.test.js, which this route
// shares with the member-answer coder; this checks the route as server.js builds it.
// ---------------------------------------------------------------------------

test('the ideas route refuses a call without the secret, and runs one with it', async () => {
  const before = process.env.UX_INTERNAL_SECRET
  process.env.UX_INTERNAL_SECRET = 'ideas-secret'
  try {
    let ran = 0
    const handler = internalHandler({
      tag: 'UxIdeas',
      clean: cleanRequest,
      run: async () => {
        ran++
        return { journey: [], problems: [], ideas: [goodIdea], costUsd: 0, model: 'm' }
      },
      summary: (result, request) => `flow=${request.flow} ideas=${result.ideas.length} problems=${result.problems.length}`,
    })
    const mk = (headers, body) => {
      const res = { statusCode: 200, status(c) { this.statusCode = c; return this }, json(b) { this.body = b; this.writableFinished = true; return this }, on() {} }
      return [{ socket: { remoteAddress: '172.18.0.4' }, headers, body }, res]
    }
    const good = { flow: 'give', figures: 'f', count: 3 }

    let [req, res] = mk({}, good)
    await handler(req, res)
    assert.equal(res.statusCode, 403, 'no secret')

    ;[req, res] = mk({ 'x-freegle-internal-secret': 'wrong' }, good)
    await handler(req, res)
    assert.equal(res.statusCode, 403, 'wrong secret')

    ;[req, res] = mk({ 'x-freegle-internal-secret': 'ideas-secret', 'x-forwarded-for': '203.0.113.9' }, good)
    await handler(req, res)
    assert.equal(res.statusCode, 403, 'forwarded')

    ;[req, res] = mk({ 'x-freegle-internal-secret': 'ideas-secret' }, { flow: 'mystery' })
    await handler(req, res)
    assert.equal(res.statusCode, 400, 'a flow that is not a funnel')
    assert.equal(ran, 0, 'nothing ran for a refused call')

    ;[req, res] = mk({ 'x-freegle-internal-secret': 'ideas-secret' }, good)
    await handler(req, res)
    assert.equal(res.statusCode, 200)
    assert.equal(ran, 1)
    assert.equal(res.body.ideas.length, 1)
  } finally {
    if (before === undefined) delete process.env.UX_INTERNAL_SECRET
    else process.env.UX_INTERNAL_SECRET = before
  }
})

test('server.js builds the ideas route behind the gate and the 2 MB parser, from the shared handler', () => {
  const source = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8')

  assert.match(source, /require\('\.\/ux-internal'\)/)
  assert.match(source, /require\('\.\/ux-ideas'\)/)
  // The gate refuses before the body is read, so a caller without the secret cannot make it parse 2 MB.
  assert.match(source, /'\/internal\/ux-ideas',\s*internalGate,\s*internalJson,\s*internalHandler\(/)
  assert.match(source, /express\.json\(\{ limit: '2mb' \}\)/)
  // The default 100 kb parser is skipped for every /internal path, or it would answer 413 first.
  assert.match(source, /req\.path\.startsWith\('\/internal\/'\)/)
  assert.doesNotMatch(source, /app\.use\(express\.json\(\)\)/)
})
