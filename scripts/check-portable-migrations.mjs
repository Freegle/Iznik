#!/usr/bin/env node
// The portable-migration convention: a new Laravel migration is written with Blueprint, which gives
// the same schema on MySQL and PostgreSQL. A raw statement (DB::statement, DB::unprepared, DB::raw,
// DB::select and the like) is MySQL's spelling, so a migration that adds one needs a marker saying why:
//
//     // mysql-only: <reason>
//
// on the same line or the line above. The check looks only at migrations a branch adds, so the
// existing ones, which the PostgreSQL baseline is converted from, are not held to it.
//
//   node scripts/check-portable-migrations.mjs [--base <ref>]     (default origin/master)
//   node scripts/check-portable-migrations.mjs --files a.php b.php  (check these files)
//
// Zero dependencies; pure Node.

import { execSync } from 'node:child_process'
import { readFileSync, realpathSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

export const MIGRATIONS_DIR = 'iznik-batch/database/migrations/'

// What Blueprint does not give: a statement the author wrote in one engine's dialect.
const RAW = /\bDB::(statement|unprepared|raw|select|selectOne|update|delete|insert)\s*\(|->(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|fromRaw)\s*\(|\bgetPdo\s*\(|Schema::getConnection\s*\(/
const MARKER = /mysql-only:\s*\S+/

/** The raw sites in one migration's text that carry no marker: [{line, text}]. */
export function findUnmarked(text) {
  const lines = text.split('\n')
  const out = []
  for (let i = 0; i < lines.length; i++) {
    if (!RAW.test(lines[i])) continue
    const here = MARKER.test(lines[i])
    const above = i > 0 && MARKER.test(lines[i - 1])
    if (!here && !above) out.push({ line: i + 1, text: lines[i].trim() })
  }
  return out
}

function added(base) {
  const out = execSync(`git diff --name-only --diff-filter=A ${base}...HEAD -- ${MIGRATIONS_DIR}`, { encoding: 'utf8' })
  return out.split('\n').filter((f) => f.endsWith('.php'))
}

function main() {
  const args = process.argv.slice(2)
  let files
  if (args[0] === '--files') {
    files = args.slice(1)
  } else {
    const i = args.indexOf('--base')
    const base = i >= 0 ? args[i + 1] : process.env.PORTABLE_MIGRATIONS_BASE || 'origin/master'
    files = added(base)
  }
  let bad = 0
  for (const f of files) {
    for (const s of findUnmarked(readFileSync(f, 'utf8'))) {
      bad++
      console.log(`${f}:${s.line}: raw statement without a "mysql-only: <reason>" marker: ${s.text}`)
    }
  }
  if (bad) {
    console.log(`\nPORTABLE-MIGRATIONS: ${bad} raw statement(s) in new migrations. Use Blueprint, or say why the statement is MySQL only`)
    console.log('with a "// mysql-only: <reason>" comment. See docs/developers/reference/database-engines.md.')
    process.exit(1)
  }
  console.log(`PORTABLE-MIGRATIONS: ok (${files.length} new migration file(s) checked)`)
}

if (import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) main()
