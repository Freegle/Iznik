#!/usr/bin/env node
// Runs the Laravel (iznik-batch) test suite on PostgreSQL and writes one result per test.
//
//   node tools/pg-ratchet/laravel-suite.mjs --out <dir> --phpunit-xml <file>
//       --exec-json '["docker","exec","-w","{cwd}","-e","DB_CONNECTION=pgsql",...,"freegle-batch"]'
//       [--root /var/www/html] [--chunk 40]
//
// The suite runs in chunks: the test files of one directory (at most --chunk of them) per PHPUnit
// process, each with its own generated configuration. A process that dies leaves no JUnit file, so
// its files are run again one by one, and a file that still leaves none is recorded as CRASH. That
// keeps a fatal error in one test from hiding the rest of the suite.
//
// The database must already hold the schema (scripts/setup-pg-database.sh). tests/bootstrap.php
// checks for it when TEST_DB_ENGINE=pgsql, and does not run migrations.
//
// Output: <out>/laravel-results.txt (PASS, FAIL, CRASH or SKIP, a tab, then "Class::method"), and
// <out>/laravel-failures.log with the first lines of each failure. The exit code is 0 when every
// chunk produced results, and 1 when the suite could not run.

import fs from 'node:fs'
import path from 'node:path'
import { makeExec, capture } from './exec.mjs'
import { parseJunit, formatResults, chunkConfig, groupFiles } from './lib.mjs'

function parseArgs(argv) {
  const o = {}
  for (let i = 0; i < argv.length; i++) if (argv[i].startsWith('--')) o[argv[i].slice(2)] = argv[++i]
  return o
}

const args = parseArgs(process.argv.slice(2))
if (!args.out || !args['phpunit-xml'] || !args['exec-json']) {
  console.error('usage: laravel-suite.mjs --out <dir> --phpunit-xml <file> --exec-json <json> [--root <dir>] [--chunk N]')
  process.exit(2)
}
const OUT = args.out
const ROOT = args.root || '/var/www/html'
const CHUNK = Number(args.chunk || 40)
const build = makeExec(args['exec-json'])
// A file that takes longer than this, alone, is recorded as a crash. A chunk gets 20 seconds a file, and
// at least CHUNK_MIN_S.
const FILE_LIMIT_S = 120
const CHUNK_MIN_S = 240
const DEADLINE = Date.now() + Number(args['deadline-min'] || 45) * 60000

fs.mkdirSync(OUT, { recursive: true })
const resultsFile = path.join(OUT, 'laravel-results.txt')
const failLog = fs.createWriteStream(path.join(OUT, 'laravel-failures.log'))
let failLogBytes = 0
const results = new Map()
let chunkNo = 0

function note(text) {
  if (failLogBytes > 8 * 1024 * 1024) return
  failLogBytes += text.length + 1
  failLog.write(text + '\n')
}

function progress() {
  let pass = 0
  let fail = 0
  for (const k of results.values()) {
    if (k === 'PASS') pass++
    else if (k === 'FAIL' || k === 'CRASH') fail++
  }
  console.log(`PROGRESS laravel passed=${pass} failed=${fail} total=${results.size}`)
}

function flush() {
  const rows = [...results].map(([name, kind]) => ({ kind, name })).sort((a, b) => a.name.localeCompare(b.name))
  fs.writeFileSync(resultsFile, formatResults(rows))
}

const baseXml = fs.readFileSync(args['phpunit-xml'], 'utf8')

/** Runs the files in one PHPUnit process. Returns the parsed JUnit, or null when the process left none. */
async function runFiles(files, label) {
  const n = ++chunkNo
  const cfg = `/tmp/pg-chunk-${n}.xml`
  const junit = `/tmp/pg-chunk-${n}.junit.xml`
  await capture(build(ROOT, ['sh', '-c', `cat > ${cfg}; rm -f ${junit}`], { stdin: true }), { input: chunkConfig(baseXml, files, ROOT), timeoutMs: 60000 })
  // timeout runs inside the container, so a hung PHPUnit is killed there: killing the docker exec client
  // would leave it running, holding its database locks against everything after it.
  const limit = files.length === 1 ? FILE_LIMIT_S : Math.max(CHUNK_MIN_S, files.length * 20)
  const r = await capture(build(ROOT, ['timeout', '-s', 'KILL', String(limit), 'vendor/bin/phpunit', '--configuration', cfg, '--log-junit', junit, '--no-progress', '--colors=never']), { timeoutMs: (limit + 60) * 1000 })
  const x = await capture(build(ROOT, ['sh', '-c', `[ -f ${junit} ] && cat ${junit}`]), { timeoutMs: 60000 })
  await capture(build(ROOT, ['rm', '-f', cfg, junit]), { timeoutMs: 30000 })
  if (x.code !== 0 || !x.out.includes('<testsuite')) {
    note(`--- ${label}: PHPUnit left no JUnit file (exit ${r.code}). Last output:\n${r.out.slice(-2500)}`)
    return null
  }
  return parseJunit(x.out)
}

function take(parsed) {
  for (const t of parsed) {
    results.set(t.name, t.kind)
    if (t.kind === 'FAIL') note(`--- ${t.name}\n${t.message}`)
  }
}

async function runChunk(chunk) {
  if (Date.now() > DEADLINE) {
    for (const f of chunk.files) results.set(`CRASH:${f}`, 'CRASH')
    note(`deadline reached: ${chunk.label} was not run`)
    return
  }
  const parsed = await runFiles(chunk.files, chunk.label)
  if (parsed) {
    take(parsed)
    return
  }
  if (chunk.files.length === 1) {
    results.set(`CRASH:${chunk.files[0]}`, 'CRASH')
    return
  }
  for (const f of chunk.files) await runChunk({ label: f, files: [f] })
}

async function main() {
  const l = await capture(build(ROOT, ['sh', '-c', 'find tests/Unit tests/Feature -name "*Test.php" | sort']), { timeoutMs: 60000 })
  const files = l.out.split('\n').map((x) => x.trim()).filter((x) => x.endsWith('Test.php'))
  if (files.length === 0) throw new Error(`no test files found: ${l.out.slice(-500)}`)
  const chunks = groupFiles(files, CHUNK)
  console.log(`FILES laravel ${files.length} files in ${chunks.length} chunks`)
  for (const c of chunks) {
    await runChunk(c)
    flush()
    progress()
  }
  failLog.end()
  if (results.size === 0) {
    console.log('DRIVER laravel: no results')
    process.exit(1)
  }
  console.log(`DONE laravel ${results.size} results`)
}

main().catch((e) => {
  console.error(e.stack || e)
  flush()
  process.exit(1)
})
