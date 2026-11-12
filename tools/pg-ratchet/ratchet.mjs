#!/usr/bin/env node
// The PostgreSQL test ratchet.
//
//   node tools/pg-ratchet/ratchet.mjs check  --suite go --results <file> [--list <file>]
//   node tools/pg-ratchet/ratchet.mjs update --suite go --results <file> [--list <file>]
//
// check   compares a results file with the committed list of known failures. Exit 1 when a test
//         outside the list failed, or when the results are empty. It also prints the listed tests
//         that passed this time, so the list can shrink.
// update  rewrites the list from the results. Use it on the results of a real CI run: see
//         docs/developers/reference/database-engines.md.
//
// The default list is tools/pg-ratchet/known-failures/<suite>.txt.

import fs, { realpathSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { parseResults, compare, failures, formatList, readList } from './lib.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))

function parseArgs(argv) {
  const o = { _: [] }
  for (let i = 0; i < argv.length; i++) {
    if (argv[i].startsWith('--')) o[argv[i].slice(2)] = argv[++i]
    else o._.push(argv[i])
  }
  return o
}

const HEADER = (suite) => [
  `Tests of the ${suite} suite known to fail on PostgreSQL. Generated, one name per line.`,
  'The PostgreSQL run fails when a test outside this list fails. A test that passes can be removed:',
  'the run lists them. Regenerate from a CI run with tools/pg-ratchet/ratchet.mjs update; see',
  'docs/developers/reference/database-engines.md.',
]

function readFloor(file) {
  if (!fs.existsSync(file)) return 0
  const m = /^# total-results: (\d+)/m.exec(fs.readFileSync(file, 'utf8'))
  return m ? Number(m[1]) : 0
}

function main() {
  const args = parseArgs(process.argv.slice(2))
  const cmd = args._[0]
  const suite = args.suite
  if (!['check', 'update'].includes(cmd) || !suite || !args.results) {
    console.error('usage: ratchet.mjs check|update --suite <go|laravel> --results <file> [--list <file>]')
    process.exit(2)
  }
  const listFile = args.list || path.join(here, 'known-failures', `${suite}.txt`)
  const results = parseResults(fs.readFileSync(args.results, 'utf8'))

  if (cmd === 'update') {
    const names = failures(results)
    fs.mkdirSync(path.dirname(listFile), { recursive: true })
    fs.writeFileSync(listFile, formatList(names, [...HEADER(suite), `total-results: ${results.length}`]))
    console.log(`RATCHET ${suite}: wrote ${names.size} known failures to ${listFile}`)
    return
  }

  const known = readList(listFile)
  const floor = readFloor(listFile)
  const r = compare(results, known)
  const c = r.counts
  console.log(`RATCHET ${suite}: ${c.pass} passed, ${c.fail + c.crash} failed (${c.crash} crashed), ${c.skip} skipped, ${known.size} known failures`)
  if (r.empty) {
    console.log(`RATCHET ${suite}: FAILED. The run produced no results, so the suite did not run.`)
    process.exit(1)
  }
  // The list records how many results the run it came from had. A run far short of that did not cover the
  // suite, and a test that never ran cannot fail.
  if (floor && c.total < Math.floor(floor * 0.95)) {
    console.log(`RATCHET ${suite}: FAILED. The run has ${c.total} results and the list was made from a run of ${floor}, so part of the suite did not run.`)
    process.exit(1)
  }
  if (r.nowPassing.length) {
    console.log(`RATCHET ${suite}: ${r.nowPassing.length} known failures now pass. Remove them from ${listFile}:`)
    for (const n of r.nowPassing.slice(0, 200)) console.log(`  now passes: ${n}`)
    if (r.nowPassing.length > 200) console.log(`  ... and ${r.nowPassing.length - 200} more`)
  }
  if (r.absent.length) {
    console.log(`RATCHET ${suite}: ${r.absent.length} listed names were not in this run (renamed or removed). They can be removed.`)
  }
  if (!r.ok) {
    console.log(`RATCHET ${suite}: FAILED. ${r.newFailures.length} tests failed that are not in ${listFile}:`)
    for (const n of r.newFailures.slice(0, 300)) console.log(`  new failure: ${n}`)
    if (r.newFailures.length > 300) console.log(`  ... and ${r.newFailures.length - 300} more`)
    process.exit(1)
  }
  console.log(`RATCHET ${suite}: ok`)
}

if (import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) main()
