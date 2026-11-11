/**
 * Corpse control for UX experiments (docs/developers/reference/ux-experiments.md).
 *
 * Every `useExperiment('<name>')` and `flowArm('<flow>', '<name>')` switch in the source must be
 * listed in ux-experiments.manifest.json with the day it was opened, and every listed switch must
 * still exist in the source. The name must be a string literal, so that this check can see it.
 *
 * Age has two limits. A switch older than WARN_AFTER_DAYS (14) is only warned about: the cleanup
 * is a step of a loop that can be down, and a missed cleanup must be loud, not a reason to turn
 * every other change on master red and stop the deploy. A switch older than max_age_days (30,
 * unless its keep_until is still ahead) does fail the build, so it cannot be forgotten for ever.
 */
import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..'
)
const MANIFEST = path.join(root, 'ux-experiments.manifest.json')
// The files that define the helpers, which call useExperiment with the caller's name.
const OWNERS = new Set([
  path.join('composables', 'useExperiment.js'),
  path.join('composables', 'useFlowTelemetry.js'),
])
const SKIP_DIRS = new Set([
  'node_modules',
  '.nuxt',
  '.output',
  '.git',
  'dist',
  'coverage',
  'tests',
  'ios',
  'android',
  'public',
  'docs',
])
const EXTENSIONS = new Set(['.js', '.mjs', '.ts', '.vue'])
const DAY_MS = 24 * 60 * 60 * 1000
export const WARN_AFTER_DAYS = 14
export const HARD_FAIL_DAYS = 30

// useExperiment('name') / useExperiment("name") / useExperiment(`name`)
const CALL = /\buseExperiment\(\s*['"`]([A-Za-z0-9_-]{1,64})['"`]\s*[,)]/g
// flowArm(FlowName.GIVE, 'name'): the second argument is the switch.
const FLOW_CALL =
  /\bflowArm\(\s*[^,()]+,\s*['"`]([A-Za-z0-9_-]{1,64})['"`]\s*[,)]/g
const ANY_CALL = /\b(useExperiment|flowArm)\(/g

// Comments are not switches: an example in a comment must not count, or be flagged.
function withoutComments(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

export function switchesIn(source) {
  const code = withoutComments(source)
  const names = new Set()
  for (const m of code.matchAll(CALL)) names.add(m[1])
  for (const m of code.matchAll(FLOW_CALL)) names.add(m[1])
  return [...names]
}

/**
 * Calls whose switch name is not a string literal, such as useExperiment(NAME). The scan cannot
 * see those, so they would sit outside corpse control altogether; they are not allowed.
 */
export function nonLiteralCalls(source) {
  const code = withoutComments(source)
  const bad = []
  for (const m of code.matchAll(ANY_CALL)) {
    const rest = code.slice(m.index)
    const ok =
      m[1] === 'useExperiment'
        ? /^useExperiment\(\s*['"`][A-Za-z0-9_-]{1,64}['"`]\s*[,)]/.test(rest)
        : /^flowArm\(\s*[^,()]+,\s*['"`][A-Za-z0-9_-]{1,64}['"`]\s*[,)]/.test(
            rest
          )
    if (!ok) bad.push(rest.split('\n')[0].slice(0, 80))
  }
  return bad
}

function ageDays(entry, today) {
  return Math.floor((today - new Date(entry.opened + 'T00:00:00Z')) / DAY_MS)
}

function isKept(entry, today) {
  return (
    typeof entry.keep_until === 'string' &&
    new Date(entry.keep_until + 'T00:00:00Z') >= today
  )
}

/**
 * Switches past the soft limit, which only warn: opened more than 14 days ago, no keep_until still
 * ahead. They become hard failures at max_age_days.
 */
export function manifestWarnings(manifest, today = new Date()) {
  const out = []
  for (const e of manifest.experiments || []) {
    if (!e || typeof e.name !== 'string') continue
    if (!/^\d{4}-\d{2}-\d{2}$/.test(e.opened || '')) continue
    const age = ageDays(e, today)
    if (age > WARN_AFTER_DAYS && !isKept(e, today)) {
      out.push(
        `${e.name}: opened ${e.opened}, ${age} days ago. Clean it up (it fails the build at ${Number(manifest.max_age_days) || HARD_FAIL_DAYS} days) or set keep_until with a reason.`
      )
    }
  }
  return out
}

export function manifestProblems(manifest, switches, today = new Date()) {
  const problems = []
  const maxAge = Number(manifest.max_age_days) || HARD_FAIL_DAYS
  const listed = new Map()
  for (const e of manifest.experiments || []) {
    if (!e || typeof e.name !== 'string') {
      problems.push('a manifest entry has no name')
      continue
    }
    listed.set(e.name, e)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(e.opened || '')) {
      problems.push(`${e.name}: opened must be YYYY-MM-DD`)
      continue
    }
    const age = ageDays(e, today)
    if (age > maxAge && !isKept(e, today)) {
      problems.push(
        `${e.name}: opened ${e.opened}, ${age} days ago, past ${maxAge}; clean it up or set keep_until with a reason`
      )
    }
  }
  const inCode = new Set(switches.map((s) => s.name))
  for (const s of switches) {
    if (!listed.has(s.name)) {
      problems.push(
        `${s.name}: used in ${s.file} but not in ux-experiments.manifest.json`
      )
    }
  }
  for (const name of listed.keys()) {
    if (!inCode.has(name)) {
      problems.push(
        `${name}: in the manifest but no useExperiment('${name}') left in the code; remove the entry`
      )
    }
  }
  return problems
}

function* walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) yield* walk(path.join(dir, entry.name))
    } else if (EXTENSIONS.has(path.extname(entry.name))) {
      yield path.join(dir, entry.name)
    }
  }
}

function scanTree() {
  const switches = []
  const nonLiteral = []
  for (const file of walk(root)) {
    const rel = path.relative(root, file)
    if (OWNERS.has(rel)) continue
    const source = fs.readFileSync(file, 'utf8')
    if (!source.includes('useExperiment(') && !source.includes('flowArm(')) {
      continue
    }
    for (const name of switchesIn(source)) switches.push({ name, file: rel })
    for (const call of nonLiteralCalls(source)) {
      nonLiteral.push(
        `${rel}: ${call} - the switch name must be a string literal so ux-experiments.manifest.json can track it`
      )
    }
  }
  return { switches, nonLiteral }
}

describe('UX experiment switch manifest', () => {
  describe('the checker', () => {
    const today = new Date('2026-10-14T12:00:00Z')

    it('finds switches in their spellings', () => {
      expect(
        switchesIn("const arm = useExperiment('give-photos-skip')")
      ).toEqual(['give-photos-skip'])
      expect(switchesIn('useExperiment("a_b") useExperiment(`c`)')).toEqual([
        'a_b',
        'c',
      ])
      expect(switchesIn('useExperiment(name)')).toEqual([])
    })

    it('finds a flowArm switch by its second argument', () => {
      expect(switchesIn("flowArm(FlowName.GIVE, 'give-photos-skip')")).toEqual([
        'give-photos-skip',
      ])
      expect(switchesIn('flowArm(\'give\', "a_b")')).toEqual(['a_b'])
    })

    it('ignores a switch that only appears in a comment', () => {
      expect(
        switchesIn(
          "/* useExperiment('doc-example') */\n// useExperiment('other')\n<!-- useExperiment('html') -->"
        )
      ).toEqual([])
    })

    it('flags a call whose name is not a literal', () => {
      expect(nonLiteralCalls('const a = useExperiment(NAME)')).toHaveLength(1)
      expect(nonLiteralCalls("useExperiment('a' + b)")).toHaveLength(1)
      expect(nonLiteralCalls('flowArm(FlowName.GIVE, name)')).toHaveLength(1)
      expect(nonLiteralCalls("useExperiment('ok')")).toEqual([])
      expect(nonLiteralCalls("flowArm(FlowName.GIVE, 'ok')")).toEqual([])
      expect(nonLiteralCalls('// useExperiment(NAME)')).toEqual([])
    })

    it('passes a fresh, listed switch', () => {
      const m = {
        max_age_days: 14,
        experiments: [
          {
            name: 'x',
            opened: '2026-10-10',
            pr: 'https://github.com/Freegle/Iznik/pull/1',
          },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'x', file: 'a.vue' }], today)
      ).toEqual([])
    })

    it('fails a switch missing from the manifest', () => {
      expect(
        manifestProblems(
          { experiments: [] },
          [{ name: 'x', file: 'a.vue' }],
          today
        )
      ).toEqual(['x: used in a.vue but not in ux-experiments.manifest.json'])
    })

    it('fails a manifest entry with no switch left', () => {
      const m = { experiments: [{ name: 'gone', opened: '2026-10-10' }] }
      expect(manifestProblems(m, [], today)[0]).toContain('no useExperiment')
    })

    it('fails a switch older than the hard limit', () => {
      const m = {
        max_age_days: 30,
        experiments: [{ name: 'old', opened: '2026-09-01' }],
      }
      const p = manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      expect(p).toHaveLength(1)
      expect(p[0]).toContain('past 30')
    })

    it('does not fail a switch between 14 and 30 days old, but warns', () => {
      const m = {
        max_age_days: 30,
        experiments: [{ name: 'late', opened: '2026-09-25' }],
      }
      expect(
        manifestProblems(m, [{ name: 'late', file: 'a.vue' }], today)
      ).toEqual([])
      const w = manifestWarnings(m, today)
      expect(w).toHaveLength(1)
      expect(w[0]).toContain('late')
      expect(w[0]).toContain('19 days')
    })

    it('does not warn about a fresh switch or one kept on purpose', () => {
      const m = {
        max_age_days: 30,
        experiments: [
          { name: 'new', opened: '2026-10-10' },
          { name: 'kept', opened: '2026-09-01', keep_until: '2026-11-01' },
        ],
      }
      expect(manifestWarnings(m, today)).toEqual([])
    })

    it('defaults the hard limit to 30 days', () => {
      const m = { experiments: [{ name: 'x', opened: '2026-09-01' }] }
      expect(
        manifestProblems(m, [{ name: 'x', file: 'a.vue' }], today)[0]
      ).toContain('past 30')
    })

    it('allows an old switch kept deliberately until a future date', () => {
      const m = {
        max_age_days: 30,
        experiments: [
          {
            name: 'old',
            opened: '2026-09-01',
            keep_until: '2026-11-01',
            reason: 'holdout',
          },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      ).toEqual([])
    })

    it('does not allow keep_until in the past', () => {
      const m = {
        max_age_days: 30,
        experiments: [
          { name: 'old', opened: '2026-09-01', keep_until: '2026-10-01' },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      ).toHaveLength(1)
    })

    it('fails a bad opened date', () => {
      const m = { experiments: [{ name: 'x', opened: 'last week' }] }
      expect(
        manifestProblems(m, [{ name: 'x', file: 'a.vue' }], today)
      ).toEqual(['x: opened must be YYYY-MM-DD'])
    })
  })

  it('every switch in the code is listed, none is dead, none is too old, and every name is a literal', () => {
    const manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'))
    const { switches, nonLiteral } = scanTree()
    expect(nonLiteral).toEqual([])
    expect(manifestProblems(manifest, switches)).toEqual([])
  })

  it('says so, without failing, about a switch left past 14 days', () => {
    const manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'))
    for (const w of manifestWarnings(manifest)) {
      console.warn(`UX experiment cleanup overdue: ${w}`)
    }
    expect(true).toBe(true)
  })
})
