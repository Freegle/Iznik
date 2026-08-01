#!/usr/bin/env node
// burndown.mjs - burn-down report for the raw-SQL-to-ORM migration inventory
// (plan 7.2/7.3, plans/database-migration-evaluation-2026-07.md).
//
// Reads tools/orm-migration/manifest.json (the extractor's output) and prints
// counts by status, wave, complexity and kind, plus the modules with the most
// remaining raw work - "the burn-down (manifest status counts over time) is a
// dashboard, so progress and any stall are visible, not anecdotal" (plan 7.3).
//
// No dependencies (repo standard is JS, not Python - see CLAUDE.md), Node ESM.
//
// Usage:
//   node tools/orm-migration/burndown.mjs [--manifest=path] [--top=N] [--json]
//
//   --manifest=path  Manifest to read (default: manifest.json next to this script)
//   --top=N          How many modules to list in the "top remaining" table (default 15)
//   --json           Print one JSON object instead of the text report, for feeding
//                    a dashboard. Same data, no formatting.

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const STATUSES = ['raw', 'in-progress', 'converted', 'keep-raw', 'test-fixture', 'retired']
// Sites in these statuses are what plan 7.4 calls "done that means done": the
// programme's own completion definition (zero raw, zero in-progress) is
// scoped to sites that are actually in play for the rewrite - test-fixture
// (7.6, budgeted separately for the engine migration) and retired (deleted
// code) are excluded from the denominator, or "percent complete" would
// mechanically shift just because someone deletes a test file.
const IN_SCOPE_STATUSES = ['raw', 'in-progress', 'converted', 'keep-raw']

function parseArgs(argv) {
  const args = { top: 15, json: false, manifest: null }
  for (const arg of argv) {
    if (arg === '--json') args.json = true
    else if (arg.startsWith('--top=')) args.top = Number(arg.slice('--top='.length))
    else if (arg.startsWith('--manifest=')) args.manifest = arg.slice('--manifest='.length)
    else {
      process.stderr.write(`burndown: unrecognised argument '${arg}'\n`)
      process.exit(1)
    }
  }
  return args
}

function loadManifest(path) {
  let text
  try {
    text = readFileSync(path, 'utf8')
  } catch (err) {
    process.stderr.write(`burndown: cannot read manifest at ${path}: ${err.message}\n`)
    process.exit(1)
  }
  try {
    return JSON.parse(text)
  } catch (err) {
    process.stderr.write(`burndown: manifest at ${path} is not valid JSON: ${err.message}\n`)
    process.exit(1)
  }
}

// module: the directory a site's file lives in. Matches plan 7.3's batch rule
// ("one module or package per PR"), so this is the grouping a PR author would
// actually plan a batch against.
function moduleOf(file) {
  const dir = dirname(file)
  return dir === '.' ? file : dir
}

function tally(sites, keyFn) {
  const counts = new Map()
  for (const site of sites) {
    const key = keyFn(site)
    counts.set(key, (counts.get(key) || 0) + 1)
  }
  return counts
}

function mapToSortedArray(map, order) {
  const keys = order ? order.filter((k) => map.has(k)) : [...map.keys()].sort()
  return keys.map((key) => ({ key, count: map.get(key) }))
}

function build(manifest, topN) {
  const sites = Object.values(manifest.sites || {})

  const byStatus = tally(sites, (s) => s.status || 'unknown')
  const byWave = tally(sites, (s) => (s.wave === undefined || s.wave === null ? 'unknown' : String(s.wave)))
  const byComplexity = tally(sites, (s) => s.complexity || 'unknown')
  const byKind = tally(sites, (s) => s.kind || 'unknown')

  // Wave breakdown, restricted to in-scope sites (test-fixture/retired don't
  // belong to a conversion wave in any meaningful sense) and split by
  // remaining-vs-done, since "is wave N finished" is the question plan 7.3's
  // batch rule actually needs answered ("a wave does not start until the
  // previous wave's raw count is zero").
  const inScope = sites.filter((s) => IN_SCOPE_STATUSES.includes(s.status))
  const waveDetail = new Map()
  for (const site of inScope) {
    const wave = site.wave === undefined || site.wave === null ? 'unknown' : String(site.wave)
    if (!waveDetail.has(wave)) waveDetail.set(wave, { total: 0, remaining: 0, done: 0 })
    const entry = waveDetail.get(wave)
    entry.total += 1
    if (site.status === 'raw' || site.status === 'in-progress') entry.remaining += 1
    else entry.done += 1
  }
  const waveOrder = ['0', '1', '2', '3', '4', '5', 'unknown']

  // Top modules by remaining (raw + in-progress) count - where a batch should
  // land next.
  const remainingByModule = new Map()
  for (const site of sites) {
    if (site.status !== 'raw' && site.status !== 'in-progress') continue
    const mod = moduleOf(site.file || 'unknown')
    remainingByModule.set(mod, (remainingByModule.get(mod) || 0) + 1)
  }
  const topModules = [...remainingByModule.entries()]
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .slice(0, topN)
    .map(([module, count]) => ({ module, remaining: count }))

  const rawCount = byStatus.get('raw') || 0
  const inProgressCount = byStatus.get('in-progress') || 0
  const convertedCount = byStatus.get('converted') || 0
  const keepRawCount = byStatus.get('keep-raw') || 0
  const inScopeTotal = rawCount + inProgressCount + convertedCount + keepRawCount
  const percentComplete = inScopeTotal === 0 ? 100 : Math.round(((convertedCount + keepRawCount) / inScopeTotal) * 1000) / 10

  return {
    generated: manifest.generated ?? null,
    root: manifest.root ?? null,
    totalSites: sites.length,
    byStatus: mapToSortedArray(byStatus, STATUSES),
    byWave: mapToSortedArray(byWave, waveOrder),
    byComplexity: mapToSortedArray(byComplexity, ['simple', 'moderate', 'complex']),
    byKind: mapToSortedArray(byKind, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DDL', 'OTHER']),
    waveDetail: waveOrder.filter((w) => waveDetail.has(w)).map((w) => ({ wave: w, ...waveDetail.get(w) })),
    topModules,
    summary: {
      raw: rawCount,
      inProgress: inProgressCount,
      converted: convertedCount,
      keepRaw: keepRawCount,
      inScopeTotal,
      percentComplete,
    },
  }
}

function pad(str, width) {
  str = String(str)
  return str.length >= width ? str : str + ' '.repeat(width - str.length)
}
function padLeft(str, width) {
  str = String(str)
  return str.length >= width ? str : ' '.repeat(width - str.length) + str
}

function printTable(title, rows, keyHeader) {
  console.log(`\n${title}`)
  console.log('-'.repeat(title.length))
  const keyWidth = Math.max(keyHeader.length, ...rows.map((r) => String(r.key).length))
  console.log(`${pad(keyHeader, keyWidth)}  count`)
  for (const row of rows) {
    console.log(`${pad(row.key, keyWidth)}  ${padLeft(row.count, 5)}`)
  }
}

function printReport(report) {
  console.log(`ORM migration burn-down (${report.root ?? 'unknown root'}, ${report.totalSites} sites total)`)
  console.log(
    `Programme: ${report.summary.percentComplete}% complete ` +
      `(${report.summary.converted} converted + ${report.summary.keepRaw} keep-raw of ${report.summary.inScopeTotal} in-scope; ` +
      `${report.summary.raw} raw, ${report.summary.inProgress} in-progress remaining)`
  )

  printTable('By status', report.byStatus, 'status')
  printTable('By complexity', report.byComplexity, 'complexity')
  printTable('By kind', report.byKind, 'kind')

  console.log('\nBy wave (in-scope sites only: raw, in-progress, converted, keep-raw)')
  console.log('-'.repeat(64))
  console.log(`${pad('wave', 8)}${padLeft('total', 8)}${padLeft('remaining', 12)}${padLeft('done', 8)}${padLeft('%done', 8)}`)
  for (const w of report.waveDetail) {
    const pct = w.total === 0 ? 0 : Math.round((w.done / w.total) * 1000) / 10
    console.log(`${pad(w.wave, 8)}${padLeft(w.total, 8)}${padLeft(w.remaining, 12)}${padLeft(w.done, 8)}${padLeft(pct + '%', 8)}`)
  }

  console.log(`\nTop modules by remaining raw+in-progress count`)
  console.log('-'.repeat(48))
  if (report.topModules.length === 0) {
    console.log('(none - nothing remaining)')
  } else {
    const moduleWidth = Math.max(6, ...report.topModules.map((m) => m.module.length))
    console.log(`${pad('module', moduleWidth)}  remaining`)
    for (const m of report.topModules) {
      console.log(`${pad(m.module, moduleWidth)}  ${padLeft(m.remaining, 9)}`)
    }
  }
}

function main() {
  const args = parseArgs(process.argv.slice(2))
  const scriptDir = dirname(fileURLToPath(import.meta.url))
  const manifestPath =
    args.manifest ??
    join(scriptDir, '..', '..', 'iznik-server-go', 'ormharness', 'manifest.json')
  const manifest = loadManifest(manifestPath)
  const report = build(manifest, args.top)

  if (args.json) {
    console.log(JSON.stringify(report, null, 2))
  } else {
    printReport(report)
  }
}

main()
