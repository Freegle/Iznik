// Writes internal/dbtest/pg_schema.sql: the PostgreSQL tables the routing tests read and write,
// copied unchanged from the generated baseline (iznik-batch/database/postgres/baseline.sql), with
// their indexes and without foreign keys or triggers.
//
//   node scripts/pg-test-schema.mjs           rewrite the file
//   node scripts/pg-test-schema.mjs --check   exit 1 if the file is not what the baseline gives
//
// The tests build a scratch database from this file, so the routing module needs nothing outside
// its own directory at test time. --check keeps it from drifting when the baseline is regenerated.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const baselinePath = join(here, '..', '..', 'iznik-batch', 'database', 'postgres', 'baseline.sql')
const outPath = join(here, '..', 'internal', 'dbtest', 'pg_schema.sql')

// Every table a routing statement touches.
export const TABLES = [
  'chat_messages',
  'config',
  'groups',
  'locations',
  'memberships',
  'messages',
  'messages_attachments',
  'messages_groups',
  'messages_likes',
  'messages_outcomes',
  'messages_spatial',
  'rippling_reach',
  'rippling_reach_leaves',
  'sessions',
  'transport_postcode_classification',
  'users',
  'users_approxlocs',
]

export function build(baseline) {
  const parts = [
    '-- The tables the routing tests use, extracted from iznik-batch/database/postgres/baseline.sql',
    '-- by scripts/pg-test-schema.mjs. Do not edit by hand.',
    '',
    'CREATE EXTENSION IF NOT EXISTS postgis;',
    'CREATE EXTENSION IF NOT EXISTS citext;',
    '',
  ]
  for (const t of TABLES) {
    const m = baseline.match(new RegExp(`^CREATE TABLE ${t} \\(\\n[\\s\\S]*?\\n\\);\\n`, 'm'))
    if (!m) throw new Error(`table ${t} is not in the baseline`)
    parts.push(m[0])
  }
  for (const t of TABLES) {
    const re = new RegExp(`^CREATE (?:UNIQUE )?INDEX \\S+ ON ${t} \\(.*|^CREATE (?:UNIQUE )?INDEX \\S+ ON ${t} USING .*`, 'gm')
    for (const m of baseline.matchAll(re)) parts.push(m[0])
  }
  return parts.join('\n') + '\n'
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const want = build(readFileSync(baselinePath, 'utf8'))
  if (process.argv.includes('--check')) {
    let have = ''
    try {
      have = readFileSync(outPath, 'utf8')
    } catch {}
    if (have !== want) {
      console.error('internal/dbtest/pg_schema.sql is out of date: run node scripts/pg-test-schema.mjs')
      process.exit(1)
    }
    console.log('pg_schema.sql matches the baseline')
  } else {
    writeFileSync(outPath, want)
    console.log(`wrote ${outPath}`)
  }
}
