// Pre-flight audit of the MySQL data. MySQL here runs non-strict, so it can hold values the
// PostgreSQL baseline refuses: an ENUM value outside the list (the empty string MySQL stores for a
// bad one), zero or partial dates, impossible dates (2026-02-30) and a NUL character in text. This
// lists them per column, so the production load is not the first time anyone finds out. Invalid
// UTF-8 is not scanned: the columns are utf8mb4, which MySQL does not let hold it. It reads the MySQL side only. A full scan per column: run it on
// a replica or the restored copy, not on the primary at a busy hour.
import { mi, mysqlRun, parseMysqlRow, pgJson, ql, relation, ZERO_DATE_RE } from './lib.mjs'
import { etlTables } from './etl.mjs'

// The allowed values from a CHECK constraint definition as PostgreSQL prints it, or null when the
// constraint is not an enum list (a length check, say).
export function enumValues(def) {
  const m = def.match(/^CHECK \(+"?(\w+)"?\)?(?:::text)? = (?:ANY|')/)
  if (!m) return null
  const values = [...def.matchAll(/'((?:[^']|'')*)'::(?:text|character varying)/g)].map((v) => v[1].replace(/''/g, "'"))
  return values.length ? { column: m[1], values } : null
}

export async function runAudit(env, { sourceMap } = {}) {
  const checks = await pgJson(env, `SELECT coalesce(json_agg(json_build_object('table', k.conrelid::regclass::text, 'name', k.conname, 'def', pg_get_constraintdef(k.oid))), '[]'::json)
    FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
    WHERE k.contype = 'c' AND c.relnamespace = 'public'::regnamespace AND k.conname LIKE '%\\_check' AND c.relname <> 'spatial_ref_sys'`)
  const dateCols = await pgJson(env, `SELECT coalesce(json_agg(json_build_object('table', c.relname, 'column', a.attname, 'notnull', a.attnotnull)), '[]'::json)
    FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
    WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped
      AND t.typname IN ('timestamp', 'timestamptz', 'date') AND c.relname NOT IN ('migrations', 'spatial_ref_sys')`)

  const textCols = await pgJson(env, `SELECT coalesce(json_agg(json_build_object('table', c.relname, 'column', a.attname) ORDER BY c.relname, a.attnum), '[]'::json)
    FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
    WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated = ''
      AND t.typname IN ('text', 'varchar', 'bpchar', 'citext') AND c.relname NOT IN ('migrations', 'spatial_ref_sys')`)
  const etlOwned = new Set((await etlTables(env)).map((t) => t.table))

  const inScope = (t) => !sourceMap || t in sourceMap
  const statements = []
  const meta = []
  const unparsed = []
  for (const k of checks) {
    const e = enumValues(k.def)
    if (!e) {
      unparsed.push(`${k.table}: ${k.name}`)
      continue
    }
    const t = k.table.replace(/^"|"$/g, '')
    if (!inScope(t)) continue
    const col = mi(e.column)
    statements.push(`SELECT JSON_ARRAY('enum', ${ql(t)}, ${ql(e.column)}, CAST(${col} AS CHAR), COUNT(*)) FROM (${relation(env, t, sourceMap)}) AS src
      WHERE ${col} IS NOT NULL AND (CAST(${col} AS CHAR) COLLATE utf8mb4_bin) NOT IN (${e.values.map(ql).join(', ')}) GROUP BY ${col}`)
    meta.push({ kind: 'enum', table: t, column: e.column })
  }
  for (const d of dateCols.filter((x) => inScope(x.table))) {
    const col = mi(d.column)
    statements.push(`SELECT JSON_ARRAY('zerodate', ${ql(d.table)}, ${ql(d.column)}, ${d.notnull ? 1 : 0}, COUNT(*)) FROM (${relation(env, d.table, sourceMap)}) AS src
      WHERE CAST(${col} AS CHAR) REGEXP ${ql(ZERO_DATE_RE)}`)
    meta.push({ kind: 'zerodate', table: d.table, column: d.column })
    statements.push(`SELECT JSON_ARRAY('baddate', ${ql(d.table)}, ${ql(d.column)}, ${d.notnull ? 1 : 0}, COUNT(*)) FROM (${relation(env, d.table, sourceMap)}) AS src
      WHERE ${col} IS NOT NULL AND CAST(${col} AS CHAR) NOT REGEXP ${ql(ZERO_DATE_RE)} AND STR_TO_DATE(LEFT(CAST(${col} AS CHAR), 10), '%Y-%m-%d') IS NULL`)
    meta.push({ kind: 'baddate', table: d.table, column: d.column })
  }
  // One scan per table for the NUL check, over every text column of it. The search is on the bytes: on a
  // multibyte string a character search for CHAR(0) answers yes.
  const textByTable = {}
  for (const x of textCols.filter((x) => inScope(x.table))) (textByTable[x.table] ||= []).push(x.column)
  for (const [t, cols] of Object.entries(textByTable)) {
    statements.push(`SELECT JSON_ARRAY('nul', ${ql(t)}, JSON_ARRAY(${cols.map(ql).join(', ')}), 0, JSON_ARRAY(${cols.map((c) => `COALESCE(SUM(LOCATE(CHAR(0), CAST(${mi(c)} AS BINARY)) > 0), 0)`).join(', ')})) FROM (${relation(env, t, sourceMap)}) AS src`)
    meta.push({ kind: 'nul', table: t, column: cols.join(',') })
  }
  const { lines, errors } = await mysqlRun(env, statements, { force: true })
  const enums = []
  const zeroDates = []
  const badDates = []
  const nuls = []
  for (const l of lines) {
    const [kind, table, column, a, n] = parseMysqlRow(l)
    if (kind === 'enum') enums.push({ table, column, value: a, rows: Number(n) })
    else if (kind === 'zerodate' && Number(n) > 0) {
      zeroDates.push({ table, column, notNull: a === 1 || a === '1', rows: Number(n), loader: etlOwned.has(table) ? 'etl' : 'pgloader' })
    } else if (kind === 'baddate' && Number(n) > 0) badDates.push({ table, column, rows: Number(n) })
    else if (kind === 'nul') column.forEach((c, i) => Number(n[i]) > 0 && nuls.push({ table, column: c, rows: Number(n[i]) }))
  }
  const failed = errors.map((e) => (e.index !== null && meta[e.index] ? `${meta[e.index].table}.${meta[e.index].column}: ${e.message}` : e.message))
  return {
    kind: 'audit', enums, zeroDates, badDates, nuls, unparsed, failed,
    // A zero date in a NOT NULL column stops a pgloader batch; the ETL replaces it with 1970-01-01, so only the first fails the audit.
    ok: enums.length === 0 && !zeroDates.some((z) => z.notNull && z.loader === 'pgloader') && badDates.length === 0 && nuls.length === 0 && failed.length === 0,
  }
}

export function formatAudit(rep) {
  const out = []
  out.push(`ENUM values the baseline would refuse: ${rep.enums.length === 0 ? 'none' : rep.enums.length}`)
  for (const e of rep.enums) out.push(`  ${e.table}.${e.column}: ${JSON.stringify(e.value)} in ${e.rows} rows`)
  const nnLoad = rep.zeroDates.filter((z) => z.notNull && z.loader === 'pgloader')
  const nnEtl = rep.zeroDates.filter((z) => z.notNull && z.loader === 'etl')
  const nullable = rep.zeroDates.filter((z) => !z.notNull)
  out.push(`Zero or partial dates in NOT NULL columns of tables pgloader loads (the batch fails, fix them in MySQL first): ${nnLoad.length === 0 ? 'none' : nnLoad.length}`)
  for (const z of nnLoad) out.push(`  ${z.table}.${z.column}: ${z.rows} rows`)
  out.push(`Zero or partial dates in NOT NULL columns of tables the ETL loads (loaded as 1970-01-01): ${nnEtl.length === 0 ? 'none' : nnEtl.length}`)
  for (const z of nnEtl) out.push(`  ${z.table}.${z.column}: ${z.rows} rows`)
  out.push(`Zero or partial dates in nullable columns (loaded as NULL by both): ${nullable.length === 0 ? 'none' : nullable.length}`)
  for (const z of nullable) out.push(`  ${z.table}.${z.column}: ${z.rows} rows`)
  out.push(`Impossible dates such as 2026-02-30 (no loader accepts them): ${rep.badDates.length === 0 ? 'none' : rep.badDates.length}`)
  for (const z of rep.badDates) out.push(`  ${z.table}.${z.column}: ${z.rows} rows`)
  out.push(`Text with a NUL character (PostgreSQL cannot store it): ${rep.nuls.length === 0 ? 'none' : rep.nuls.length}`)
  for (const z of rep.nuls) out.push(`  ${z.table}.${z.column}: ${z.rows} rows`)
  if (rep.unparsed.length) out.push(`CHECK constraints the audit does not read (not enum lists): ${rep.unparsed.length}`)
  for (const f of rep.failed) out.push(`  the MySQL query failed: ${f}`)
  out.push(rep.ok ? 'PASS' : 'FAIL')
  return out.join('\n')
}
