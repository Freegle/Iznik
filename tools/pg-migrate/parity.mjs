// Parity checks between the MySQL source and the PostgreSQL copy.
//
//   counts    exact row counts per table, both sides.
//   geometry  per geometry column: every row compared with its MySQL original, SRID and validity,
//             and the answers to a set of probe questions (which rows contain this point, which are
//             within this distance) compared between the two engines.
//   fk        foreign keys with orphan rows, for a load that ran with foreign keys switched off.
//
// Row counts alone do not show the SRID relabel or the repair went right, so the geometry check
// re-reads the originals from MySQL and compares them in PostgreSQL with the loaded rows.
import {
  PgSession, encodeCopyRow, geometryColumns, listTables, mi, mysqlRun, parseMysqlRow, pgJson, primaryKey, qi, ql,
  relation, streamMysqlIntoCopy, SKIPPED_TABLES,
} from './lib.mjs'

// ---------------------------------------------------------------------------------------------
// Counts

export async function mysqlTables(env, sourceMap) {
  if (sourceMap) return Object.keys(sourceMap)
  const { lines } = await mysqlRun(env, [
    `SELECT JSON_ARRAY(table_name) FROM information_schema.tables WHERE table_schema = ${ql(env.mysqlDb)} AND table_type = 'BASE TABLE'`,
  ])
  return lines.map((l) => parseMysqlRow(l)[0])
}

export async function checkCounts(env, { sourceMap, tables } = {}) {
  const pgTables = await listTables(env)
  const myTables = await mysqlTables(env, sourceMap)
  const list = tables && tables.length ? tables : sourceMap ? Object.keys(sourceMap) : pgTables
  const statements = list.map((t) => `SELECT JSON_ARRAY(${ql(t)}, COUNT(*)) FROM (${relation(env, t, sourceMap)}) AS src`)
  const { lines, errors } = await mysqlRun(env, statements, { force: true })
  const mysqlCounts = {}
  for (const l of lines) {
    const [t, n] = parseMysqlRow(l)
    mysqlCounts[t] = Number(n)
  }
  const pgCounts = await pgJson(env, `SELECT json_object_agg(relname, (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I', relname), false, true, '')))[1]::text::bigint)
    FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relname IN (${list.map(ql).join(', ')})`)
  const rows = []
  for (const t of list) {
    const m = mysqlCounts[t]
    const p = pgCounts ? pgCounts[t] : undefined
    const err = errors.find((e) => e.index === list.indexOf(t))
    rows.push({ table: t, mysql: m ?? null, pg: p ?? null, ok: m !== undefined && p !== undefined && m === p, error: err ? err.message : undefined })
  }
  const onlyMysql = myTables.filter((t) => !pgTables.includes(t) && !SKIPPED_TABLES.includes(t) && !(tables && tables.length))
  return { kind: 'counts', rows, onlyMysql, ok: rows.every((r) => r.ok) && onlyMysql.length === 0 }
}

// ---------------------------------------------------------------------------------------------
// Geometry

const FAIL_CLASSES = ['missing-in-pg', 'extra-in-pg', 'null-mismatch', 'bad-srid', 'invalid-in-pg', 'changed-though-valid', 'repaired-extent-changed']
const REPAIRED = ['repaired', 'repaired-extent-changed']

// The probe points for a column: the explicit ones, plus a point on the surface of the first few
// loaded geometries so that a column is always asked something it can answer.
async function autoProbes(env, table, column, pk, n) {
  if (!n) return []
  const rows = await pgJson(env, `SELECT coalesce(json_agg(json_build_object('name', 'auto-' || pk, 'x', ST_X(p), 'y', ST_Y(p))), '[]'::json)
    FROM (SELECT ${qi(pk)}::text AS pk, ST_PointOnSurface(${qi(column)}) AS p FROM ${qi(table)}
          WHERE ${qi(column)} IS NOT NULL AND NOT ST_IsEmpty(${qi(column)}) ORDER BY ${qi(pk)} LIMIT ${Number(n)}) s`)
  return rows
}

const num = (v) => {
  if (!Number.isFinite(Number(v))) throw new Error(`probe coordinate is not a number: ${v}`)
  return Number(v)
}

export async function checkGeometry(env, { sourceMap, tables, probes = [], autoProbeCount = 3, radius = 0.05, tolerance = 1e-9, allowExtentChange = false } = {}) {
  const cols = (await geometryColumns(env)).filter((c) => (!tables || !tables.length || tables.includes(c.table)) && (!sourceMap || c.table in sourceMap))
  const results = []
  for (const c of cols) results.push(await checkOneGeometryColumn(env, c, { sourceMap, probes, autoProbeCount, radius, tolerance, allowExtentChange }))
  return { kind: 'geometry', columns: results, ok: results.every((r) => r.ok) }
}

async function checkOneGeometryColumn(env, { table, column }, opts) {
  const { sourceMap, probes, autoProbeCount, radius, tolerance, allowExtentChange } = opts
  const result = { table, column, ok: false, classes: {}, geometryTypes: {}, srids: [], answers: { compared: 0, failures: [], warnings: [] }, notes: [] }
  const pk = await primaryKey(env, table)
  if (!pk) {
    result.notes.push('no single-column primary key: rows cannot be paired, so only the counts check applies')
    result.ok = false
    return result
  }
  const rel = relation(env, table, sourceMap)
  const allProbes = [...probes.map((p) => ({ ...p })), ...(await autoProbes(env, table, column, pk, autoProbeCount))]

  // MySQL answers to the probe questions. One statement per probe and kind, so one failing probe
  // is reported and does not stop the others.
  const questions = []
  for (const p of allProbes) {
    const r = p.radius ?? radius
    const point = `ST_GeomFromText('POINT(${num(p.x)} ${num(p.y)})', ST_SRID(${mi(column)}))`
    questions.push({ kind: 'contains', probe: p.name, sql: `SELECT JSON_ARRAY('contains', ${ql(p.name)}, CAST(${mi(pk)} AS CHAR)) FROM (${rel}) AS src WHERE ST_Contains(${mi(column)}, ${point})` })
    questions.push({ kind: 'near', probe: p.name, sql: `SELECT JSON_ARRAY('near', ${ql(p.name)}, CAST(${mi(pk)} AS CHAR)) FROM (${rel}) AS src WHERE ST_Distance(${mi(column)}, ${point}) <= ${num(r)}` })
  }
  const failedQuestions = new Set()
  let hitLines = []
  if (questions.length) {
    const { lines, errors } = await mysqlRun(env, questions.map((q) => q.sql), { force: true })
    hitLines = lines
    for (const e of errors) {
      const q = e.index !== null ? questions[e.index] : null
      result.notes.push(`MySQL could not answer ${q ? `${q.kind} ${q.probe}` : 'a probe'}: ${e.message}`)
      if (q) failedQuestions.add(`${q.kind}|${q.probe}`)
    }
  }

  const session = new PgSession(env)
  await session.write('BEGIN;\n')
  await session.write('CREATE TEMP TABLE pgmig_src (pk text, wkb text, srid int) ON COMMIT DROP;\nCOPY pgmig_src FROM STDIN;\n')
  const n = await streamMysqlIntoCopy(env, session, `SELECT JSON_ARRAY(CAST(${mi(pk)} AS CHAR), HEX(ST_AsBinary(${mi(column)})), ST_SRID(${mi(column)})) FROM (${rel}) AS src`)
  await session.write('\\.\n')
  await session.write('CREATE TEMP TABLE pgmig_hits (kind text, probe text, pk text) ON COMMIT DROP;\nCOPY pgmig_hits FROM STDIN;\n')
  for (const l of hitLines) await session.write(encodeCopyRow(parseMysqlRow(l)))
  await session.write('\\.\n')
  await session.write(`CREATE TEMP TABLE pgmig_probes (name text, x float8, y float8, r float8) ON COMMIT DROP;
    INSERT INTO pgmig_probes VALUES ${allProbes.length ? allProbes.map((p) => `(${ql(p.name)}, ${num(p.x)}, ${num(p.y)}, ${num(p.radius ?? radius)})`).join(', ') : "(NULL, NULL, NULL, NULL)"};
    DELETE FROM pgmig_probes WHERE name IS NULL;\n`)
  const tol = Number(tolerance)
  await session.write(`
CREATE TEMP TABLE pgmig_class ON COMMIT DROP AS
WITH j AS (
  SELECT coalesce(s.pk, t.pk) AS pk, s.pk IS NOT NULL AS in_src, t.pk IS NOT NULL AS in_tgt, s.srid, t.g AS tg,
         CASE WHEN s.wkb IS NULL THEN NULL ELSE ST_SetSRID(ST_GeomFromWKB(decode(s.wkb, 'hex')), 4326) END AS og
  FROM pgmig_src s FULL JOIN (SELECT ${qi(pk)}::text AS pk, ${qi(column)} AS g FROM ${qi(table)}) t ON t.pk = s.pk)
SELECT pk, srid, tg, og, CASE
  WHEN NOT in_tgt THEN 'missing-in-pg'
  WHEN NOT in_src THEN 'extra-in-pg'
  WHEN og IS NULL AND tg IS NULL THEN 'both-null'
  WHEN og IS NULL OR tg IS NULL THEN 'null-mismatch'
  WHEN ST_SRID(tg) <> 4326 THEN 'bad-srid'
  WHEN NOT ST_IsValid(tg) THEN 'invalid-in-pg'
  WHEN ST_AsBinary(tg, 'NDR') = ST_AsBinary(og, 'NDR') THEN 'identical'
  WHEN ST_IsValid(og) THEN 'changed-though-valid'
  WHEN (ST_IsEmpty(tg) AND ST_IsEmpty(og)) OR coalesce(
       abs(ST_XMin(tg) - ST_XMin(og)) <= ${tol} AND abs(ST_XMax(tg) - ST_XMax(og)) <= ${tol}
       AND abs(ST_YMin(tg) - ST_YMin(og)) <= ${tol} AND abs(ST_YMax(tg) - ST_YMax(og)) <= ${tol}, false) THEN 'repaired'
  ELSE 'repaired-extent-changed' END AS class
FROM j;

CREATE TEMP TABLE pgmig_pg_hits ON COMMIT DROP AS
SELECT 'contains' AS kind, p.name AS probe, c.pk FROM pgmig_probes p JOIN pgmig_class c ON c.tg IS NOT NULL
  AND ST_Contains(c.tg, ST_SetSRID(ST_MakePoint(p.x, p.y), 4326))
UNION ALL
SELECT 'near', p.name, c.pk FROM pgmig_probes p JOIN pgmig_class c ON c.tg IS NOT NULL
  AND ST_DWithin(c.tg, ST_SetSRID(ST_MakePoint(p.x, p.y), 4326), p.r);

SELECT '@@json|' || json_build_object(
  'srcRows', (SELECT count(*) FROM pgmig_src),
  'srids', (SELECT coalesce(json_agg(DISTINCT srid), '[]'::json) FROM pgmig_src WHERE srid IS NOT NULL),
  'classes', (SELECT coalesce(json_object_agg(class, n), '{}'::json) FROM (SELECT class, count(*) n FROM pgmig_class GROUP BY 1) q),
  'examples', (SELECT coalesce(json_object_agg(class, ids), '{}'::json) FROM (SELECT class, (array_agg(pk ORDER BY pk))[1:5] AS ids FROM pgmig_class WHERE class NOT IN ('identical', 'both-null') GROUP BY 1) q),
  'types', (SELECT coalesce(json_object_agg(t, n), '{}'::json) FROM (SELECT GeometryType(tg) t, count(*) n FROM pgmig_class WHERE tg IS NOT NULL GROUP BY 1) q),
  'diffs', (SELECT coalesce(json_agg(json_build_object('kind', kind, 'probe', probe, 'pk', pk, 'side', side, 'class', class)), '[]'::json) FROM (
      SELECT h.kind, h.probe, h.pk, 'mysql-only' AS side, c.class FROM pgmig_hits h LEFT JOIN pgmig_class c ON c.pk = h.pk
        WHERE NOT EXISTS (SELECT 1 FROM pgmig_pg_hits g WHERE g.kind = h.kind AND g.probe = h.probe AND g.pk = h.pk)
      UNION ALL
      SELECT g.kind, g.probe, g.pk, 'pg-only', c.class FROM pgmig_pg_hits g LEFT JOIN pgmig_class c ON c.pk = g.pk
        WHERE NOT EXISTS (SELECT 1 FROM pgmig_hits h WHERE g.kind = h.kind AND g.probe = h.probe AND g.pk = h.pk)
      ORDER BY 1, 2, 3) d),
  'pgHits', (SELECT count(*) FROM pgmig_pg_hits),
  'mysqlHits', (SELECT count(*) FROM pgmig_hits))::text;
COMMIT;
`)
  const r = await session.finish()
  if (r.code !== 0) throw new Error(`geometry check for ${table}.${column} failed: ${r.stderr.trim()}`)
  const line = r.stdout.split('\n').find((l) => l.startsWith('@@json|'))
  const j = JSON.parse(line.slice('@@json|'.length))

  result.rows = n
  result.classes = j.classes
  result.examples = j.examples
  result.geometryTypes = j.types
  result.srids = j.srids
  result.answers.compared = allProbes.length * 2 - failedQuestions.size
  result.answers.mysqlHits = j.mysqlHits
  result.answers.pgHits = j.pgHits
  for (const d of j.diffs) {
    if (failedQuestions.has(`${d.kind}|${d.probe}`)) continue
    const entry = { kind: d.kind, probe: d.probe, row: d.pk, side: d.side, rowClass: d.class }
    if (REPAIRED.includes(d.class)) result.answers.warnings.push(entry)
    else result.answers.failures.push(entry)
  }
  const failing = FAIL_CLASSES.filter((cl) => j.classes[cl] && !(allowExtentChange && cl === 'repaired-extent-changed'))
  const badSrids = j.srids.filter((s) => s !== 3857 && s !== 0)
  if (badSrids.length) result.notes.push(`MySQL labelled this column SRID ${badSrids.join(', ')}`)
  result.failingClasses = failing
  result.ok = failing.length === 0 && badSrids.length === 0 && result.answers.failures.length === 0 && failedQuestions.size === 0
  return result
}

// ---------------------------------------------------------------------------------------------
// Foreign keys

export async function checkForeignKeys(env) {
  const fks = await pgJson(env, `SELECT coalesce(json_agg(json_build_object('name', c.conname, 'table', c.conrelid::regclass::text,
      'ref', c.confrelid::regclass::text,
      'cols', (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum),
      'refcols', (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum))
      ORDER BY c.conrelid::regclass::text, c.conname), '[]'::json)
    FROM pg_constraint c WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace`)
  if (!fks.length) return { kind: 'fk', rows: [], ok: true }
  const session = new PgSession(env)
  for (const f of fks) {
    const on = f.cols.map((c, i) => `p.${qi(f.refcols[i])} = c.${qi(c)}`).join(' AND ')
    const notNull = f.cols.map((c) => `c.${qi(c)} IS NOT NULL`).join(' AND ')
    await session.write(`SELECT '@@fk|' || ${ql(f.name)} || '|' || count(*) FROM ${f.table} c WHERE ${notNull} AND NOT EXISTS (SELECT 1 FROM ${f.ref} p WHERE ${on});\n`)
  }
  const r = await session.finish()
  if (r.code !== 0) throw new Error(`foreign key check failed: ${r.stderr.trim()}`)
  const orphans = {}
  for (const l of r.stdout.split('\n')) {
    const m = l.match(/^@@fk\|(.*)\|(\d+)$/)
    if (m) orphans[m[1]] = Number(m[2])
  }
  const rows = fks.map((f) => ({ name: f.name, table: f.table, ref: f.ref, orphans: orphans[f.name] ?? null })).filter((r) => r.orphans !== 0)
  return { kind: 'fk', checked: fks.length, rows, ok: rows.length === 0 }
}

// ---------------------------------------------------------------------------------------------
// Reports

export function formatReport(rep) {
  const out = []
  if (rep.kind === 'counts') {
    const bad = rep.rows.filter((r) => !r.ok)
    out.push(`Row counts: ${rep.rows.length - bad.length} of ${rep.rows.length} tables match.`)
    for (const r of bad) out.push(`  MISMATCH ${r.table}: MySQL ${r.mysql ?? 'n/a'}, PostgreSQL ${r.pg ?? 'n/a'}${r.error ? ` (${r.error})` : ''}`)
    if (rep.onlyMysql.length) out.push(`  In MySQL but not in the PostgreSQL schema: ${rep.onlyMysql.join(', ')}`)
  } else if (rep.kind === 'geometry') {
    for (const c of rep.columns) {
      out.push(`${c.ok ? 'OK  ' : 'FAIL'} ${c.table}.${c.column}: ${c.rows ?? 0} rows; ${Object.entries(c.classes).map(([k, v]) => `${k} ${v}`).join(', ') || 'none'}`)
      for (const n of c.notes) out.push(`       note: ${n}`)
      if (c.failingClasses && c.failingClasses.length) out.push(`       failing: ${c.failingClasses.map((k) => `${k} (rows ${(c.examples[k] || []).join(', ')})`).join('; ')}`)
      out.push(`       probes: ${c.answers.compared} questions; ${c.answers.failures.length} answer differences on unchanged rows, ${c.answers.warnings.length} on repaired rows`)
      for (const f of c.answers.failures.slice(0, 5)) out.push(`       FAIL ${f.kind} ${f.probe}: row ${f.row} ${f.side}`)
      for (const f of c.answers.warnings.slice(0, 5)) out.push(`       warn ${f.kind} ${f.probe}: row ${f.row} ${f.side} (${f.rowClass})`)
    }
  } else if (rep.kind === 'fk') {
    out.push(`Foreign keys: ${rep.rows.length === 0 ? 'no orphan rows' : `${rep.rows.length} with orphan rows`}.`)
    for (const r of rep.rows) out.push(`  ${r.table} -> ${r.ref} (${r.name}): ${r.orphans ?? 'check failed'}`)
  }
  out.push(rep.ok ? 'PASS' : 'FAIL')
  return out.join('\n')
}
