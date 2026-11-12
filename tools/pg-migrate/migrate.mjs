#!/usr/bin/env node
// Data-migration tooling for the MySQL to PostgreSQL move. See docs/developers/reference/database-engines.md.
//
//   node tools/pg-migrate/migrate.mjs pgloader-config [--out FILE]   write the pgloader load file
//   node tools/pg-migrate/migrate.mjs etl [TABLE...] [--truncate] [--no-replica] [--allow-srid]
//   node tools/pg-migrate/migrate.mjs audit                          data MySQL holds that the baseline would refuse
//   node tools/pg-migrate/migrate.mjs parity counts|geometry|fk|all
//
// Settings come from the environment: MYSQL_CMD, MYSQL_DB, PG_PSQL, PG_DATABASE (see lib.mjs).
// --fixture FILE reads MySQL rows from a fixture file through derived tables instead of from MySQL tables.
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { fixtureSourceMap, loadEnv } from './lib.mjs'
import { runEtl } from './etl.mjs'
import { checkCounts, checkForeignKeys, checkGeometry, formatReport } from './parity.mjs'
import { runAudit, formatAudit } from './audit.mjs'
import { renderPgloaderConfig } from './pgloader.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))

function parseArgs(argv) {
  const flags = {}
  const rest = []
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    if (a.startsWith('--')) {
      const key = a.slice(2)
      const takesValue = ['out', 'fixture', 'json', 'probes', 'auto-probes', 'radius', 'tolerance'].includes(key)
      flags[key] = takesValue ? argv[++i] : true
    } else rest.push(a)
  }
  return { flags, rest }
}

async function main() {
  const { flags, rest } = parseArgs(process.argv.slice(2))
  const [cmd, ...args] = rest
  const env = loadEnv()
  let fixture = null
  let sourceMap
  if (flags.fixture) {
    fixture = JSON.parse(fs.readFileSync(flags.fixture, 'utf8'))
    sourceMap = fixtureSourceMap(fixture)
  }

  if (cmd === 'pgloader-config') {
    const text = await renderPgloaderConfig(env)
    if (flags.out) fs.writeFileSync(flags.out, text, { mode: 0o600 })
    else process.stdout.write(text)
    return 0
  }
  if (cmd === 'etl') {
    const results = await runEtl(env, args, { truncate: !!flags.truncate, replica: !flags['no-replica'], allowSrid: !!flags['allow-srid'], sourceMap })
    console.log(`Loaded ${results.length} tables, ${results.reduce((n, r) => n + r.inserted, 0)} rows.`)
    return 0
  }
  if (cmd === 'audit') {
    const rep = await runAudit(env, { sourceMap })
    console.log(formatAudit(rep))
    return rep.ok ? 0 : 1
  }
  if (cmd === 'parity') {
    const what = args[0] || 'all'
    const reports = []
    const probes = flags.probes ? JSON.parse(fs.readFileSync(flags.probes, 'utf8')).probes : fixture ? fixture.probes : []
    if (what === 'counts' || what === 'all') reports.push(await checkCounts(env, { sourceMap }))
    if (what === 'geometry' || what === 'all') {
      reports.push(await checkGeometry(env, {
        sourceMap, probes,
        autoProbeCount: flags['auto-probes'] !== undefined ? Number(flags['auto-probes']) : 3,
        radius: flags.radius ? Number(flags.radius) : undefined,
        tolerance: flags.tolerance ? Number(flags.tolerance) : undefined,
        allowExtentChange: !!flags['allow-extent-change'],
      }))
    }
    if (what === 'fk' || what === 'all') reports.push(await checkForeignKeys(env))
    if (!reports.length) throw new Error(`unknown parity check ${what}`)
    for (const r of reports) console.log(formatReport(r))
    if (flags.json) fs.writeFileSync(flags.json, JSON.stringify(reports, null, 2))
    return reports.every((r) => r.ok) ? 0 : 1
  }
  console.error(fs.readFileSync(path.join(here, 'migrate.mjs'), 'utf8').split('\n').filter((l) => l.startsWith('//')).map((l) => l.slice(3)).join('\n'))
  return 2
}

main().then((code) => process.exit(code), (e) => {
  console.error(e.message)
  process.exit(1)
})
