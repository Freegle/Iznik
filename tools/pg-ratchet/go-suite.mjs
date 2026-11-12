#!/usr/bin/env node
// Runs the Go API test suite on PostgreSQL and writes one result per test.
//
//   node tools/pg-ratchet/go-suite.mjs --root <module dir as the exec environment sees it> --out <dir>
//       [--exec-json '["docker","exec","-w","{cwd}","-e","DB_ENGINE=postgres","freegle-apiv2"]']
//       [--chunk 150] [--deadline-min 45]
//
// The code is not ported yet, so a panic in one test is normal on PostgreSQL, and a panic ends the
// whole package. Two ways of running keep it from hiding the tests after it:
//
//   - The big integration package (test/) is compiled once, and run in chunks of tests. A test that
//     takes its process down is recorded as CRASH, and the tests after it run in a new process.
//   - Every other package runs once through `go test -json`. A crash there is recorded against the
//     tests that started and did not finish.
//
// Output: <out>/go-results.txt (PASS, FAIL, CRASH or SKIP, a tab, then "package:Test"), and
// <out>/go-failures.log with the first lines of each failure. The exit code is 0 when every test
// has a result, whether it passed or not, and 1 when the suite could not run. tools/pg-ratchet/
// ratchet.mjs judges the results.

import fs from 'node:fs'
import path from 'node:path'
import { makeExec, capture, stream } from './exec.mjs'
import { parseGoLine, formatResults } from './lib.mjs'

function parseArgs(argv) {
  const o = {}
  for (let i = 0; i < argv.length; i++) if (argv[i].startsWith('--')) o[argv[i].slice(2)] = argv[++i]
  return o
}

const args = parseArgs(process.argv.slice(2))
if (!args.root || !args.out) {
  console.error('usage: go-suite.mjs --root <dir> --out <dir> [--exec-json <json>] [--chunk N] [--deadline-min N]')
  process.exit(2)
}
const ROOT = args.root
const OUT = args.out
const CHUNK = Number(args.chunk || 150)
const DEADLINE = Date.now() + Number(args['deadline-min'] || 45) * 60000
const build = makeExec(args['exec-json'])
const BIN = args.bin || '/tmp/pg-api.test'
const BIG = 'test'

fs.mkdirSync(OUT, { recursive: true })
const resultsFile = path.join(OUT, 'go-results.txt')
const failLog = fs.createWriteStream(path.join(OUT, 'go-failures.log'))
let failLogBytes = 0
const results = new Map() // name -> kind

function note(text) {
  if (failLogBytes > 8 * 1024 * 1024) return
  failLogBytes += text.length + 1
  failLog.write(text + '\n')
}

function record(name, kind) {
  results.set(name, kind)
  if (results.size % 100 === 0) progress()
}

function progress() {
  let pass = 0
  let fail = 0
  for (const k of results.values()) {
    if (k === 'PASS') pass++
    else if (k === 'FAIL' || k === 'CRASH') fail++
  }
  console.log(`PROGRESS go passed=${pass} failed=${fail} total=${results.size}`)
}

function flush() {
  const rows = [...results].map(([name, kind]) => ({ kind, name })).sort((a, b) => a.name.localeCompare(b.name))
  fs.writeFileSync(resultsFile, formatResults(rows))
}

async function go(cwd, argv, opts) {
  return capture(build(cwd, ['go', ...argv]), opts)
}

async function listPackages() {
  const r = await go(ROOT, ['list', '-f', '{{if or .TestGoFiles .XTestGoFiles}}{{.ImportPath}}{{end}}', './...'])
  if (r.code !== 0) throw new Error(`go list failed: ${r.out.slice(-2000)}`)
  const pkgs = r.out.split('\n').map((l) => l.trim()).filter((l) => l && !l.includes(' '))
  return pkgs
}

function shortName(modulePath, pkg) {
  const s = pkg === modulePath ? '.' : pkg.slice(modulePath.length + 1)
  return s
}

// Every package but the big one, through go test -json.
async function runSmallPackages(pkgs, modulePath) {
  if (pkgs.length === 0) return
  const started = new Map() // "pkg:Test" -> true while unfinished
  const output = new Map() // "pkg:Test" -> last lines
  const pkgFailed = new Set()
  const pkgSawTest = new Set()
  const argv = ['test', '-json', '-count=1', '-p', '1', '-timeout', '20m', ...pkgs]
  await stream(build(ROOT, ['go', ...argv]), (line) => {
    let ev
    try {
      ev = JSON.parse(line)
    } catch {
      return
    }
    const pkg = ev.Package ? shortName(modulePath, ev.Package) : ''
    if (ev.Test && !ev.Test.includes('/')) {
      const key = `${pkg}:${ev.Test}`
      pkgSawTest.add(pkg)
      if (ev.Action === 'run') {
        started.set(key, true)
        output.set(key, [])
      } else if (ev.Action === 'output') {
        const o = output.get(key)
        if (o) {
          o.push(ev.Output.trimEnd())
          if (o.length > 40) o.shift()
        }
      } else if (['pass', 'fail', 'skip'].includes(ev.Action)) {
        started.delete(key)
        record(key, ev.Action.toUpperCase())
        if (ev.Action === 'fail') note(`--- ${key}\n${(output.get(key) || []).slice(-12).join('\n')}`)
      }
    } else if (!ev.Test && ev.Action === 'fail' && pkg) {
      pkgFailed.add(pkg)
    }
  }, { timeoutMs: 40 * 60000 })
  for (const key of started.keys()) {
    record(key, 'CRASH')
    note(`--- ${key} did not finish: ${(output.get(key) || []).slice(-12).join('\n')}`)
  }
  // A package that failed and ran no test at all failed to build or to start.
  for (const pkg of pkgFailed) {
    if (!pkgSawTest.has(pkg)) {
      record(`${pkg}:[package did not run]`, 'CRASH')
    }
  }
}

// The big package: compile once, run in chunks, restart after a crash.
async function runBigPackage(modulePath) {
  const pkg = `${modulePath}/${BIG}`
  const dir = path.posix.join(ROOT, BIG)
  const c = await go(ROOT, ['test', '-c', '-o', BIN, pkg], { timeoutMs: 20 * 60000 })
  if (c.code !== 0) {
    record(`${BIG}:[package did not build]`, 'CRASH')
    note(`go test -c failed:\n${c.out.slice(-3000)}`)
    return
  }
  const l = await capture(build(dir, [BIN, '-test.list', '.*']), { timeoutMs: 5 * 60000 })
  const names = l.out.split('\n').map((x) => x.trim()).filter((x) => /^Test\w*$/.test(x))
  if (names.length === 0) {
    record(`${BIG}:[no tests listed]`, 'CRASH')
    note(`the test binary listed no tests (exit ${l.code}):\n${l.out.slice(-3000)}`)
    return
  }
  console.log(`LISTED go ${BIG} ${names.length} tests`)

  let remaining = names.slice()
  let guard = names.length + 50
  while (remaining.length > 0 && guard-- > 0) {
    if (Date.now() > DEADLINE) {
      for (const n of remaining) record(`${BIG}:${n}`, 'CRASH')
      note(`deadline reached: ${remaining.length} tests were not run`)
      return
    }
    const chunk = remaining.slice(0, CHUNK)
    const pattern = `^(${chunk.join('|')})$`
    let current = null
    let afterFail = 0
    let failName = null
    const ring = []
    await stream(build(dir, [BIN, '-test.v', '-test.count=1', '-test.timeout=10m', `-test.run=${pattern}`]), (line) => {
      const e = parseGoLine(line)
      if (!e) {
        ring.push(line)
        if (ring.length > 60) ring.shift()
        if (afterFail > 0 && failName) {
          afterFail--
          note(line)
        }
        return
      }
      if (e.event === 'run') {
        current = e.name
        ring.length = 0
        return
      }
      if (!chunk.includes(e.name)) return
      if (current === e.name) current = null
      record(`${BIG}:${e.name}`, e.event.toUpperCase())
      if (e.event === 'fail') {
        failName = e.name
        afterFail = 10
        note(`--- ${BIG}:${e.name}`)
      }
    }, { timeoutMs: 15 * 60000 })
    if (current) {
      record(`${BIG}:${current}`, 'CRASH')
      note(`--- ${BIG}:${current} did not finish. Last output:\n${ring.slice(-25).join('\n')}`)
    }
    const before = remaining.length
    remaining = remaining.filter((n) => !results.has(`${BIG}:${n}`))
    if (remaining.length === before) {
      // Nothing from this chunk was recorded: the process died before the first test.
      const first = remaining[0]
      record(`${BIG}:${first}`, 'CRASH')
      note(`--- ${BIG}:${first} produced no result. Last output:\n${ring.slice(-25).join('\n')}`)
      remaining = remaining.slice(1)
    }
  }
}

async function main() {
  const modLine = await go(ROOT, ['list', '-m'])
  const modulePath = modLine.out.trim().split('\n').pop().trim()
  if (modLine.code !== 0 || !modulePath) throw new Error(`go list -m failed: ${modLine.out.slice(-500)}`)
  const pkgs = await listPackages()
  const small = pkgs.filter((p) => p !== `${modulePath}/${BIG}`)
  console.log(`PACKAGES go ${pkgs.length} with tests`)

  await runBigPackage(modulePath).catch((e) => {
    record(`${BIG}:[driver error]`, 'CRASH')
    note(`driver error: ${e.stack || e}`)
  })
  flush()
  await runSmallPackages(small, modulePath).catch((e) => {
    record('[driver error]', 'CRASH')
    note(`driver error: ${e.stack || e}`)
  })
  progress()
  flush()
  failLog.end()
  if (results.size === 0) {
    console.log('DRIVER go: no results')
    process.exit(1)
  }
  console.log(`DONE go ${results.size} results`)
}

main().catch((e) => {
  console.error(e.stack || e)
  flush()
  process.exit(1)
})
