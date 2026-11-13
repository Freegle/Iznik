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
//   - Every other package runs through `go test -json`, again after each crash with the crashed test
//     skipped.
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
// --only <regexp>, or the same text in /tmp/pg-ratchet-only, runs just the big-package tests whose names match: for
// debugging one failure through the status container, which cannot take the option from its API.
if (!args.only && fs.existsSync('/tmp/pg-ratchet-only')) args.only = fs.readFileSync('/tmp/pg-ratchet-only', 'utf8').trim()
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

// Every package but the big one, through go test -json, one process per package. A test that takes the
// process down is recorded as CRASH, and the package runs again without it (go test -skip), so the tests
// after it still get a result. A package that fails with every test passing (a TestMain that exits
// non-zero, a build error) is recorded as CRASH too, so it cannot pass unseen.
async function runPackage(pkg, modulePath) {
  const short = shortName(modulePath, pkg)
  const skip = []
  let badTest = false
  let pkgFailed = false
  for (let attempt = 0; attempt < 40; attempt++) {
    const started = new Map() // test -> last output lines, while unfinished
    pkgFailed = false
    const argv = ['test', '-json', '-count=1', '-timeout', '20m']
    if (skip.length) argv.push('-skip', `^(${skip.join('|')})$`)
    argv.push(pkg)
    await stream(build(ROOT, ['go', ...argv]), (line) => {
      let ev
      try {
        ev = JSON.parse(line)
      } catch {
        return
      }
      if (ev.Test && !ev.Test.includes('/')) {
        const key = `${short}:${ev.Test}`
        if (ev.Action === 'run') {
          started.set(ev.Test, [])
        } else if (ev.Action === 'output') {
          const o = started.get(ev.Test)
          if (o) {
            o.push(ev.Output.trimEnd())
            if (o.length > 40) o.shift()
          }
        } else if (['pass', 'fail', 'skip'].includes(ev.Action)) {
          const o = started.get(ev.Test) || []
          started.delete(ev.Test)
          record(key, ev.Action.toUpperCase())
          if (ev.Action === 'fail') {
            badTest = true
            note(`--- ${key}\n${o.slice(-12).join('\n')}`)
          }
        }
      } else if (!ev.Test && ev.Action === 'fail') {
        pkgFailed = true
      }
    }, { timeoutMs: 25 * 60000 })
    if (started.size === 0) break
    for (const [t, o] of started) {
      record(`${short}:${t}`, 'CRASH')
      badTest = true
      skip.push(t)
      note(`--- ${short}:${t} did not finish: ${o.slice(-12).join('\n')}`)
    }
  }
  if (pkgFailed && !badTest) record(`${short}:[package failed]`, 'CRASH')
}

async function runSmallPackages(pkgs, modulePath) {
  if (!args.only) for (const pkg of pkgs) await runPackage(pkg, modulePath)
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
  const only = args.only ? new RegExp(args.only) : null
  const names = l.out.split('\n').map((x) => x.trim()).filter((x) => /^Test\w*$/.test(x) && (!only || only.test(x)))
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
    const ring = []
    await stream(build(dir, [BIN, '-test.v', '-test.count=1', '-test.timeout=10m', `-test.run=${pattern}`]), (line) => {
      const e = parseGoLine(line)
      if (!e) {
        ring.push(line)
        if (ring.length > 200) ring.shift()
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
        // A test's own output comes before its FAIL line, so the lines kept since it started are its failure.
        note(`--- ${BIG}:${e.name}\n${ring.join('\n')}`)
        ring.length = 0
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
