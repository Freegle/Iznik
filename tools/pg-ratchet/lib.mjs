// Shared parts of the PostgreSQL test ratchet. No dependencies, Node only.
//
// A suite run produces a results file: one line per test, "PASS", "FAIL", "CRASH" or "SKIP", a tab,
// then the test's name. CRASH is a test that took its process down (a panic, a timeout, a fatal
// error) and counts as a failure. The ratchet compares the failures with a committed list of the
// tests known to fail on PostgreSQL today.

import fs from 'node:fs'
import path from 'node:path'

export const RESULT_KINDS = ['PASS', 'FAIL', 'CRASH', 'SKIP']

/** Reads a results file into an array of {kind, name}. Lines starting with # are ignored. */
export function parseResults(text) {
  const out = []
  for (const raw of text.split('\n')) {
    const line = raw.replace(/\r$/, '')
    if (!line || line.startsWith('#')) continue
    const tab = line.indexOf('\t')
    if (tab < 0) throw new Error(`results: line without a tab: ${line.slice(0, 80)}`)
    const kind = line.slice(0, tab)
    if (!RESULT_KINDS.includes(kind)) throw new Error(`results: unknown kind ${kind}`)
    out.push({ kind, name: line.slice(tab + 1) })
  }
  return out
}

// A name is one line: a test name can hold a newline or a tab (a data set label that is a query).
export function cleanName(name) {
  return name.replace(/\s+/g, ' ').trim()
}

export function formatResults(results) {
  return results.map((r) => `${r.kind}\t${cleanName(r.name)}`).join('\n') + (results.length ? '\n' : '')
}

/** Reads a known-failures list: one name per line, # comments and blank lines ignored. */
export function parseList(text) {
  const names = new Set()
  for (const raw of text.split('\n')) {
    const line = raw.replace(/\r$/, '')
    if (!line || line.startsWith('#')) continue
    names.add(line)
  }
  return names
}

export function readList(file) {
  return fs.existsSync(file) ? parseList(fs.readFileSync(file, 'utf8')) : new Set()
}

export function formatList(names, header) {
  const lines = [...names].sort()
  return header.map((h) => `# ${h}`).join('\n') + '\n' + lines.join('\n') + (lines.length ? '\n' : '')
}

/** The names of the tests that failed: FAIL and CRASH. */
export function failures(results) {
  return new Set(results.filter((r) => r.kind === 'FAIL' || r.kind === 'CRASH').map((r) => r.name))
}

/**
 * The ratchet. A run passes when every failure is in the known list. It also reports the known
 * failures that passed this time (so the list can shrink) and the known names that did not appear in
 * the run at all (a renamed or removed test, so the entry can go).
 *
 * A run that holds no results is an error, not a pass: it means the suite did not run.
 */
export function compare(results, known) {
  if (results.length === 0) {
    return { ok: false, empty: true, newFailures: [], nowPassing: [], absent: [], counts: counts(results) }
  }
  const failed = failures(results)
  const seen = new Set(results.map((r) => r.name))
  const passed = new Set(results.filter((r) => r.kind === 'PASS').map((r) => r.name))
  const newFailures = [...failed].filter((n) => !known.has(n)).sort()
  const nowPassing = [...known].filter((n) => passed.has(n)).sort()
  const absent = [...known].filter((n) => !seen.has(n)).sort()
  return { ok: newFailures.length === 0, empty: false, newFailures, nowPassing, absent, counts: counts(results) }
}

export function counts(results) {
  const c = { pass: 0, fail: 0, crash: 0, skip: 0, total: results.length }
  for (const r of results) c[r.kind.toLowerCase()]++
  return c
}

// ---- Go -----------------------------------------------------------------------------------------

/**
 * One line of `go test -v` output for a top-level test: {event, name} or null. Subtests (a name with a
 * slash, or a result line indented) are ignored: a failing subtest fails its parent, which is the entry.
 */
export function parseGoLine(line) {
  // Not anchored: GORM's logger writes some lines without a newline, so a marker can follow other text.
  let m = /=== RUN\s+(\S+)/.exec(line)
  if (m) return m[1].includes('/') ? null : { event: 'run', name: m[1] }
  m = /--- (PASS|FAIL|SKIP):\s+(\S+)\s+\(\d/.exec(line)
  if (m) return m[2].includes('/') ? null : { event: m[1].toLowerCase(), name: m[2] }
  return null
}

// ---- PHPUnit ------------------------------------------------------------------------------------

function xmlDecode(s) {
  return s
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, n) => String.fromCodePoint(parseInt(n, 16)))
    .replace(/&amp;/g, '&')
}

function attrs(s) {
  const out = {}
  for (const m of s.matchAll(/([\w:-]+)="([^"]*)"/g)) out[m[1]] = xmlDecode(m[2])
  return out
}

/**
 * Parses PHPUnit's JUnit XML into [{kind, name, message}]. A test case holding a failure or error
 * element is FAIL (a warning is not: the suite does not fail on one), one holding a skipped element is SKIP,
 * anything else PASS. The name is
 * "Class::method", with a data set kept in the method part.
 */
export function parseJunit(xml) {
  const out = []
  const re = /<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g
  for (const m of xml.matchAll(re)) {
    const a = attrs(m[1])
    const body = m[2] || ''
    const cls = a.class || (a.classname || '').replace(/\./g, '\\')
    const name = cls ? `${cls}::${a.name}` : a.name
    let kind = 'PASS'
    let message = ''
    const bad = /<(failure|error)\b([^>]*)>([\s\S]*?)<\/\1>|<(failure|error)\b([^>]*)\/>/.exec(body)
    if (bad) {
      kind = 'FAIL'
      message = xmlDecode((bad[3] || '').trim()).split('\n').slice(0, 6).join('\n')
    } else if (/<skipped\b/.test(body)) {
      kind = 'SKIP'
    }
    out.push({ kind, name, message })
  }
  return out
}

/**
 * The suite's own configuration with its test list replaced by the given files. Paths in a
 * configuration resolve against the file's directory, so the ones kept are made absolute; the
 * coverage source and logging elements are dropped, because the run does not collect coverage and
 * names its own JUnit file.
 */
export function chunkConfig(baseXml, files, root) {
  const suite = `<testsuites>\n<testsuite name="chunk">\n${files.map((f) => `<file>${root}/${f}</file>`).join('\n')}\n</testsuite>\n</testsuites>`
  return baseXml
    .replace(/<testsuites>[\s\S]*?<\/testsuites>/, suite)
    .replace(/<source>[\s\S]*?<\/source>/, '')
    .replace(/<logging>[\s\S]*?<\/logging>/, '')
    .replace(/\sxsi:noNamespaceSchemaLocation="[^"]*"/, '')
    .replace(/\sbootstrap="[^"]*"/, ` bootstrap="${root}/tests/bootstrap.php"`)
}

/** Groups files by directory, and splits a directory's files into chunks of at most n. */
export function groupFiles(files, n) {
  const byDir = new Map()
  for (const f of files) {
    const d = path.posix.dirname(f)
    if (!byDir.has(d)) byDir.set(d, [])
    byDir.get(d).push(f)
  }
  const chunks = []
  for (const d of [...byDir.keys()].sort()) {
    const list = byDir.get(d).sort()
    for (let i = 0; i < list.length; i += n) chunks.push({ label: d + (list.length > n ? `#${i / n + 1}` : ''), files: list.slice(i, i + n) })
  }
  return chunks
}

