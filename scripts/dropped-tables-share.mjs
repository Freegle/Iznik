// What share of the database the migration removes: tables, rows and bytes.
// Usage: node scripts/dropped-tables-share.mjs <tables.tsv> [migration.php]
// tables.tsv is "TABLE_NAME<tab>TABLE_ROWS<tab>DATA_LENGTH+INDEX_LENGTH" for every base table in
// the schema, from information_schema.TABLES (row counts there are InnoDB estimates). Read from
// the live database, read-only, or from a dev database for a rough figure.
import { readFileSync } from 'node:fs'
const [,, tsv, migration = 'iznik-batch/database/migrations/2026_09_20_000001_remove_group_model.php'] = process.argv
const php = readFileSync(migration, 'utf8')
const list = (name) => [...(php.match(new RegExp(`${name} = \\[[\\s\\S]*?\\];`))?.[0] || '').matchAll(/'([a-z_]+)'/g)].map(m => m[1])
const dropped = new Set(list('DROP_TABLES'))
const truncated = new Set(list('TRUNCATE'))
const columns = [...(php.match(/DROP_COLUMNS = \[[\s\S]*?\];/)?.[0] || '').matchAll(/\['([a-z_]+)', '([a-z_]+)'\]/g)].map(m => m[1])
const rows = readFileSync(tsv, 'utf8').split('\n').filter(Boolean).map(l => { const [t, r, b] = l.split('\t'); return { t, r: Number(r), b: Number(b) } })
const sum = (xs, k) => xs.reduce((a, x) => a + x[k], 0)
const all = { n: rows.length, r: sum(rows, 'r'), b: sum(rows, 'b') }
const d = rows.filter(x => dropped.has(x.t)); const dr = { n: d.length, r: sum(d, 'r'), b: sum(d, 'b') }
const t = rows.filter(x => truncated.has(x.t)); const tr = { n: t.length, r: sum(t, 'r'), b: sum(t, 'b') }
const pct = (a, b) => b ? `${(a * 100 / b).toFixed(1)}%` : 'n/a'
const gb = (b) => `${(b / 1e9).toFixed(1)} GB`
const m = (r) => r >= 1e6 ? `${(r / 1e6).toFixed(1)}M` : r >= 1e3 ? `${(r / 1e3).toFixed(0)}k` : String(r)
console.log(`| What goes | Tables | Rows | Data and index size |`)
console.log(`|---|---|---|---|`)
console.log(`| Whole database | ${all.n} | ${m(all.r)} | ${gb(all.b)} |`)
console.log(`| Tables dropped | ${dr.n} (${pct(dr.n, all.n)}) | ${m(dr.r)} (${pct(dr.r, all.r)}) | ${gb(dr.b)} (${pct(dr.b, all.b)}) |`)
console.log(`| Tables emptied (per-community statistics, rebuilt nationally) | ${tr.n} (${pct(tr.n, all.n)}) | ${m(tr.r)} (${pct(tr.r, all.r)}) | ${gb(tr.b)} (${pct(tr.b, all.b)}) |`)
console.log(`| Columns dropped from surviving tables | ${columns.length} columns on ${new Set(columns).size} tables | | |`)
const missing = [...dropped].filter(x => !rows.some(y => y.t === x))
if (missing.length) console.log(`\nDropped tables not present in the export (already gone or never existed here): ${missing.join(', ')}`)
console.log(`\nLargest dropped tables:\n`)
console.log(`| Table | Rows | Size |`)
console.log(`|---|---|---|`)
for (const x of d.sort((a, b) => b.b - a.b).slice(0, 8)) console.log(`| ${x.t} | ${m(x.r)} | ${gb(x.b)} |`)
