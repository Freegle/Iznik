// The copy ETL: reads whole tables from MySQL and loads them into the PostgreSQL baseline.
//
// It owns every table that has a geometry column, because pgloader does not convert MySQL
// geometry (see pgloader.load.template). For those columns it reads well-known binary
// (ST_AsBinary), relabels it to SRID 4326 and passes it through ST_MakeValid. It can also copy any
// plain table, which the rehearsal uses and which is the fallback if pgloader cannot do one.
//
// A table is loaded in one transaction: rows are streamed into a temporary all-text staging table,
// then one INSERT ... SELECT casts them to the target types. A failure rolls the table back whole.
import {
  PgSession, describeTable, pgJson, geometryColumns, mysqlExpr, mysqlRun, parseMysqlRow, pgExpr, pgRun, qi, ql,
  relation, streamMysqlIntoCopy, GEOM_FUNCTION, ZERO_DATE_RE,
} from './lib.mjs'

// The tables the ETL owns and pgloader must leave alone: those with a geometry column (pgloader
// does not convert MySQL geometry) and those with a generated column (pgloader copies every source
// column, and PostgreSQL refuses a value for a generated one). Returns [{table, reasons}].
export async function etlTables(env) {
  const geom = await geometryColumns(env)
  const generated = await pgJson(env, `SELECT coalesce(json_agg(DISTINCT c.relname), '[]'::json)
    FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
    WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated <> ''`)
  const byTable = {}
  for (const g of geom) (byTable[g.table] ||= new Set()).add('geometry')
  for (const t of generated) (byTable[t] ||= new Set()).add('generated column')
  return Object.keys(byTable).sort().map((table) => ({ table, reasons: [...byTable[table]] }))
}

// Which SRIDs MySQL labelled each geometry column with. Anything but 3857 (or 0, unlabelled) is a
// surprise: the relabel to 4326 assumes the stored numbers are WGS84 degrees, which is what the
// application always wrote under the 3857 label. Returns {column: [srids]}.
export async function sourceSrids(env, table, columns, sourceMap) {
  const out = {}
  const statements = columns.map((c) =>
    `SELECT JSON_ARRAY(${ql(c)}, ST_SRID(\`${c}\`)) FROM (${relation(env, table, sourceMap)}) AS src WHERE \`${c}\` IS NOT NULL GROUP BY ST_SRID(\`${c}\`)`)
  const { lines } = await mysqlRun(env, statements)
  for (const c of columns) out[c] = []
  for (const l of lines) {
    const [col, srid] = parseMysqlRow(l)
    out[col].push(srid)
  }
  return out
}

// Copies one table. Returns counts: rows staged and inserted, and values replaced per date column.
export async function copyTable(env, table, opts = {}) {
  const { truncate = false, replica = true, sourceMap, allowSrid = false } = opts
  const cols = (await describeTable(env, table)).filter((c) => !c.generated)

  const existing = Number((await pgRun(env, `SELECT count(*) FROM ${qi(table)};\n`)).trim())
  if (existing > 0 && !truncate) {
    throw new Error(`${table} already has ${existing} rows in PostgreSQL; run with --truncate to replace them`)
  }

  const geom = cols.filter((c) => c.isGeom)
  if (geom.length && !allowSrid) {
    const srids = await sourceSrids(env, table, geom.map((c) => c.name), sourceMap)
    for (const [col, list] of Object.entries(srids)) {
      const odd = list.filter((s) => s !== 3857 && s !== 0)
      if (odd.length) {
        throw new Error(`${table}.${col} is labelled SRID ${odd.join(', ')} in MySQL; the 4326 relabel assumes 3857. Check the data, then run with --allow-srid`)
      }
    }
  }

  const stage = cols.map((_, i) => `c${i}`)
  const selectList = cols.map(mysqlExpr).join(', ')
  const mysqlSql = `SELECT JSON_ARRAY(${selectList}) FROM (${relation(env, table, sourceMap)}) AS src`
  const dateCols = cols.map((c, i) => ({ c, s: stage[i] })).filter(({ c }) => ['timestamp', 'timestamptz', 'date'].includes(c.typname))

  const session = new PgSession(env)
  await session.write('BEGIN;\nSET LOCAL TIME ZONE \'UTC\';\n')
  if (replica) await session.write('SET LOCAL session_replication_role = replica;\n')
  // With foreign keys switched off (replica mode) DELETE ignores them; TRUNCATE would refuse a table another one points at.
  if (truncate) await session.write(replica ? `DELETE FROM ${qi(table)};\n` : `TRUNCATE ${qi(table)};\n`)
  await session.write(GEOM_FUNCTION)
  await session.write(`CREATE TEMP TABLE pgmig_stage (${stage.map((s) => `${s} text`).join(', ')}) ON COMMIT DROP;\n`)
  await session.write('COPY pgmig_stage FROM STDIN;\n')
  let streamed
  try {
    streamed = await streamMysqlIntoCopy(env, session, mysqlSql)
  } catch (e) {
    await session.write('\\.\nROLLBACK;\n')
    await session.finish()
    throw e
  }
  await session.write('\\.\n')
  await session.write(`SELECT '@@staged|' || count(*) FROM pgmig_stage;\n`)
  for (const { c, s } of dateCols) {
    await session.write(`SELECT '@@zerodate|${c.name}|' || count(*) FROM pgmig_stage WHERE ${s} ~ ${ql(ZERO_DATE_RE)};\n`)
  }
  await session.write(`WITH ins AS (INSERT INTO ${qi(table)} (${cols.map((c) => qi(c.name)).join(', ')})
    SELECT ${cols.map((c, i) => pgExpr(c, stage[i])).join(', ')} FROM pgmig_stage RETURNING 1)
    SELECT '@@inserted|' || count(*) FROM ins;\n`)
  await session.write('COMMIT;\n')
  const r = await session.finish()
  if (r.code !== 0) throw new Error(`loading ${table} failed and was rolled back: ${r.stderr.trim()}`)

  const result = { table, streamed, staged: 0, inserted: 0, zeroDates: {} }
  for (const l of r.stdout.split('\n')) {
    const m = l.match(/^@@(\w+)\|(?:(.*)\|)?(\d+)$/)
    if (!m) continue
    if (m[1] === 'staged') result.staged = Number(m[3])
    else if (m[1] === 'inserted') result.inserted = Number(m[3])
    else if (m[1] === 'zerodate' && Number(m[3]) > 0) result.zeroDates[m[2]] = Number(m[3])
  }
  if (result.staged !== streamed || result.inserted !== streamed) {
    throw new Error(`${table}: ${streamed} rows read, ${result.staged} staged, ${result.inserted} inserted`)
  }
  return result
}

// Moves every identity sequence past the largest id loaded.
export async function resetIdentities(env) {
  await pgRun(env, 'SELECT iznik_reset_identities();\n')
  // The planner knows nothing about freshly loaded rows, and ignores an expression index, until the
  // tables are analysed.
  await pgRun(env, 'ANALYZE;\n')
}

export async function runEtl(env, tables, opts = {}, log = console.log) {
  const list = tables && tables.length ? tables : (await etlTables(env)).map((t) => t.table)
  const results = []
  for (const t of list) {
    const r = await copyTable(env, t, opts)
    const zero = Object.entries(r.zeroDates).map(([c, n]) => `${c}=${n}`).join(' ')
    log(`${t}: ${r.inserted} rows${zero ? `; zero or partial dates replaced: ${zero}` : ''}`)
    results.push(r)
  }
  if (!opts.noReset) await resetIdentities(env)
  return results
}
