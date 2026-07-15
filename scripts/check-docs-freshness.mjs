#!/usr/bin/env node
/**
 * Documentation freshness gate.
 *
 * Answers the question "how do docs get updated for changes that are not
 * screenshots?" It does NOT write prose - that is a human judgement. Instead it
 * makes staleness impossible to merge silently: each doc page declares, in front
 * matter, the source paths it covers, and this check fails a pull request that
 * changes a covered path without touching the doc page.
 *
 *   ---
 *   last_reviewed: 2026-07-09
 *   covers:
 *     - iznik-nuxt3/modtools/pages/messages/**
 *     - iznik-nuxt3/modtools/components/ModMessage*.vue
 *   ---
 *
 * If a PR changes one of those paths but not this doc page, the author is told
 * to update the page (or, if nothing needs saying, bump `last_reviewed` - which
 * still counts as touching the page, so the acknowledgement is explicit).
 *
 * Usage:
 *   node scripts/check-docs-freshness.mjs [--base <ref>] [--warn]
 *
 *   --base <ref>  git ref to diff against (default: origin/master, or
 *                 $DOCS_FRESHNESS_BASE).
 *   --warn        print violations but exit 0 (for a soft/advisory CI check).
 *
 * Zero dependencies; pure Node.
 */
import { execSync } from 'node:child_process'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

const args = process.argv.slice(2)
const warnOnly = args.includes('--warn')
// --staged checks staged changes (for a pre-commit hook) instead of base...HEAD.
const staged = args.includes('--staged')
const baseIdx = args.indexOf('--base')
const base =
  (baseIdx !== -1 && args[baseIdx + 1]) ||
  process.env.DOCS_FRESHNESS_BASE ||
  'origin/master'

const REPO = process.cwd()
const DOCS_DIR = join(REPO, 'docs')

function sh(cmd) {
  return execSync(cmd, { cwd: REPO, encoding: 'utf8' }).trim()
}

// --- changed files: staged (pre-commit hook) or this branch vs base -----------
let changed = []
try {
  const out = staged
    ? sh('git diff --cached --name-only')
    : sh(`git diff --name-only ${base}...HEAD`)
  changed = out ? out.split('\n').filter(Boolean) : []
} catch (e) {
  console.error(`Could not compute changed files: ${e.message}`)
  console.error('Pass --base <ref>, set DOCS_FRESHNESS_BASE, or use --staged.')
  process.exit(warnOnly ? 0 : 2)
}
const changedSet = new Set(changed)

// --- collect doc pages with front matter --------------------------------------
function walk(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    const st = statSync(p)
    if (st.isDirectory()) {
      if (name === 'superpowers' || name === 'assets') continue
      out.push(...walk(p))
    } else if (name.endsWith('.md')) {
      out.push(p)
    }
  }
  return out
}

function parseFrontMatter(text) {
  if (!text.startsWith('---')) return {}
  const end = text.indexOf('\n---', 3)
  if (end === -1) return {}
  const block = text.slice(3, end).split('\n')
  const fm = {}
  let key = null
  for (const line of block) {
    const listItem = line.match(/^\s*-\s+(.*\S)\s*$/)
    if (listItem && key) {
      ;(fm[key] = fm[key] || []).push(stripQuotes(listItem[1]))
      continue
    }
    const kv = line.match(/^(\w[\w-]*):\s*(.*)$/)
    if (kv) {
      key = kv[1]
      const val = kv[2].trim()
      fm[key] = val === '' ? [] : stripQuotes(val)
    }
  }
  return fm
}
const stripQuotes = (s) => s.replace(/^["']|["']$/g, '')

// glob -> RegExp: ** matches across dirs, * within a segment, ? one char
function globToRegExp(glob) {
  let re = ''
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i]
    if (c === '*') {
      if (glob[i + 1] === '*') {
        re += '.*'
        i++
        if (glob[i + 1] === '/') i++ // consume the slash after **
      } else {
        re += '[^/]*'
      }
    } else if (c === '?') re += '[^/]'
    else if ('\\.+^$()[]{}|'.includes(c)) re += '\\' + c
    else re += c
  }
  return new RegExp('^' + re + '$')
}

const pages = walk(DOCS_DIR)
const violations = []

for (const page of pages) {
  const rel = relative(REPO, page)
  const fm = parseFrontMatter(readFileSync(page, 'utf8'))
  const covers = Array.isArray(fm.covers) ? fm.covers : []
  if (!covers.length) continue
  if (changedSet.has(rel)) continue // doc page itself was updated -> fine

  const matchers = covers.map(globToRegExp)
  const hits = changed.filter((f) => matchers.some((m) => m.test(f)))
  if (hits.length) {
    violations.push({ page: rel, hits })
  }
}

// --- report -------------------------------------------------------------------
if (!violations.length) {
  const scope = staged ? 'staged changes' : base
  console.log(`docs freshness: OK (checked ${pages.length} pages against ${scope})`)
  process.exit(0)
}

console.error('\nDocumentation may be stale. These pages document code that changed')
console.error('in this branch, but the pages themselves were not updated:\n')
for (const v of violations) {
  console.error(`  ${v.page}`)
  for (const h of v.hits.slice(0, 8)) console.error(`      changed: ${h}`)
  if (v.hits.length > 8) console.error(`      ...and ${v.hits.length - 8} more`)
}
console.error(
  '\nUpdate each page to match the change. If nothing needs saying, bump its\n' +
    '`last_reviewed:` date (that still counts as reviewing it) to acknowledge it.\n'
)
process.exit(warnOnly ? 0 : 1)
