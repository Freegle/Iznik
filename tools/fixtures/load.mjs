#!/usr/bin/env node
// Writes the SQL that loads the test fixtures into one engine. Pipe it to the engine's client:
//
//   node tools/fixtures/load.mjs --engine mysql  | mysql ... iznik
//   node tools/fixtures/load.mjs --engine pgsql  | psql -X -1 -v ON_ERROR_STOP=1 ... iznik
//
// Options:
//   --engine mysql|pgsql   required
//   --dir <path>           the fixtures directory (default scripts/fixtures)
//   --part <p>             all (default), tables, captured (tables and their steps) or extra
//   --tables a,b           only these tables, no steps
//   --batch <n>            rows per INSERT. 1 makes every failing row show up on its own.
//   --list-tables          print every table the fixtures write to, one per line, and stop
//
// The format and how to change it: docs/developers/reference/test-fixtures.md.

import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { FixtureError, allTables, loadSql } from './lib.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))

function usage(msg) {
  if (msg) console.error(msg)
  console.error('usage: load.mjs --engine mysql|pgsql [--dir path] [--part all|tables|captured|extra] [--tables a,b] [--batch n] [--list-tables]')
  process.exit(2)
}

const args = process.argv.slice(2)
const opt = {}
for (let i = 0; i < args.length; i++) {
  const a = args[i]
  if (a === '--list-tables') opt.list = true
  else if (['--engine', '--dir', '--part', '--tables', '--batch'].includes(a)) {
    if (i + 1 >= args.length) usage(`${a} needs a value`)
    opt[a.slice(2)] = args[++i]
  } else usage(`unknown option ${a}`)
}
// The client closing the pipe early (a failed load) is its own error to report, not ours.
process.stdout.on('error', (e) => process.exit(e.code === 'EPIPE' ? 1 : 2))

const dir = path.resolve(opt.dir || path.join(here, '..', '..', 'scripts', 'fixtures'))

try {
  if (opt.list) {
    process.stdout.write(allTables(dir).join('\n') + '\n')
  } else {
    if (!opt.engine) usage('--engine is required')
    if (opt.part && !['all', 'tables', 'captured', 'extra'].includes(opt.part)) usage(`bad --part ${opt.part}`)
    const batch = opt.batch ? Number(opt.batch) : undefined
    if (opt.batch && !(Number.isInteger(batch) && batch > 0)) usage('--batch is a whole number above 0')
    const stats = {}
    const gen = loadSql(opt.engine, dir, {
      part: opt.part,
      tables: opt.tables ? opt.tables.split(',') : undefined,
      batch,
      stats,
    })
    for (const chunk of gen) {
      if (!process.stdout.write(chunk)) await new Promise((r) => process.stdout.once('drain', r))
    }
    const repaired = Object.entries(stats)
    if (repaired.length) {
      console.error('fixtures: repaired for ' + opt.engine + ': ' + repaired.map(([k, n]) => `${k} (${n})`).join(', '))
    }
  }
} catch (e) {
  if (e instanceof FixtureError) {
    console.error(`load failed: ${e.message}`)
    process.exit(1)
  }
  throw e
}
