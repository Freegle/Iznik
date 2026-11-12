// Shared helpers for the data-migration tooling: how to reach the two databases, how a row is
// carried from MySQL into a PostgreSQL COPY stream, and the catalog queries that describe a target
// table. Plain Node, no packages: both databases are reached through their command-line clients,
// as tools/pg-schema does.
import { spawn } from 'node:child_process'
import readline from 'node:readline'
import { once } from 'node:events'

export const ZERO_DATE_RE = '^0000-|^[0-9]{4}-00-|^[0-9]{4}-[0-9]{2}-00'

// Where the two databases are. Every value comes from the environment so that nothing about a
// particular host or password is committed. The defaults point at the dev compose stack.
export function loadEnv(env = process.env) {
  const prefix = env.COMPOSE_PROJECT_NAME || 'freegle'
  const mysqlPassword = env.MYSQL_ROOT_PASSWORD || 'iznik'
  return {
    mysqlCmd: env.MYSQL_CMD || `docker exec -i ${prefix}-percona mysql -uroot -p${mysqlPassword}`,
    mysqlDb: env.MYSQL_DB || 'iznik',
    pgPsql: env.PG_PSQL || `docker exec -i ${prefix}-postgres psql -U root`,
    pgDb: env.PG_DATABASE || 'iznik',
  }
}

export const qi = (s) => '"' + String(s).replace(/"/g, '""') + '"'
export const mi = (s) => '`' + String(s).replace(/`/g, '``') + '`'
export const ql = (s) => "'" + String(s).replace(/'/g, "''") + "'"

// The relation a table is read from on the MySQL side. A map entry (table name to a SELECT) replaces
// the real table; the rehearsal uses it to read fixture rows through a derived table, because the dev
// MySQL database may hold no tables and is never written to.
export function relation(env, table, sourceMap) {
  if (sourceMap && sourceMap[table]) return sourceMap[table]
  return `SELECT * FROM ${mi(env.mysqlDb)}.${mi(table)}`
}

// ---------------------------------------------------------------------------------------------
// Rows

// mysql --batch escapes backslash, tab, newline and NUL in its output. Each row we read is one JSON
// array on one line, so undoing that escaping gives the JSON text back exactly.
export function unescapeBatch(line) {
  return line.replace(/\\([\\nt0r])/g, (_, c) => ({ '\\': '\\', n: '\n', t: '\t', '0': '\0', r: '\r' })[c])
}

export function parseMysqlRow(line) {
  return JSON.parse(unescapeBatch(line))
}

// One value in PostgreSQL COPY text format. null is \N. A NUL character cannot be stored in a
// PostgreSQL text value, so it is an error and not silently dropped.
export function copyEscape(v) {
  if (v === null || v === undefined) return '\\N'
  const s = String(v)
  if (s.includes('\0')) throw new Error('a value holds a NUL character, which PostgreSQL text cannot store')
  return s.replace(/[\\\n\r\t\b\f\v]/g, (c) => ({ '\\': '\\\\', '\n': '\\n', '\r': '\\r', '\t': '\\t', '\b': '\\b', '\f': '\\f', '\v': '\\v' })[c])
}

export const encodeCopyRow = (values) => values.map(copyEscape).join('\t') + '\n'

// ---------------------------------------------------------------------------------------------
// Processes

function shell(cmdline) {
  return spawn('sh', ['-c', cmdline], { stdio: ['pipe', 'pipe', 'pipe'] })
}

const noPasswordWarning = (s) => s.split('\n').filter((l) => l && !/Using a password on the command line/.test(l)).join('\n')

function collect(stream) {
  const chunks = []
  stream.on('data', (d) => chunks.push(d))
  return () => Buffer.concat(chunks).toString('utf8')
}

// The mysql client arguments every read uses: tab-separated, no header, utf8mb4, rows streamed as
// they arrive. --force lets a script of many statements carry on past one that fails; the failures
// come back by line number.
export function mysqlCommand(env, { force = false } = {}) {
  return `${env.mysqlCmd} --default-character-set=utf8mb4 --batch --skip-column-names --quick${force ? ' --force' : ''}`
}

// Statements that start every MySQL read session, one per line (so line numbers in errors can be
// mapped back to statements). Timestamps are read as UTC.
export const MYSQL_PRELUDE = ["SET time_zone = '+00:00'"]

// Runs the statements, one per line, and resolves with the output lines and the errors by statement.
export async function mysqlRun(env, statements, { force = false } = {}) {
  const child = shell(mysqlCommand(env, { force }) + ' ' + env.mysqlDb)
  const out = collect(child.stdout)
  const err = collect(child.stderr)
  child.stdin.on('error', () => {})
  const all = [...MYSQL_PRELUDE, ...statements]
  child.stdin.end(all.map((s) => s.replace(/\n/g, ' ') + ';').join('\n') + '\n')
  const [code] = await once(child, 'close')
  const errors = []
  for (const l of noPasswordWarning(err()).split('\n')) {
    if (!l) continue
    const m = l.match(/at line (\d+)/)
    errors.push({ index: m ? Number(m[1]) - 1 - MYSQL_PRELUDE.length : null, message: l })
  }
  if (code !== 0 && !force) throw new Error(`mysql failed: ${noPasswordWarning(err())}`)
  return { lines: out().split('\n').filter((l) => l.length), errors }
}

// One SELECT whose rows are streamed. Returns the lines as an async iterable and a promise that
// rejects if mysql fails.
export function mysqlStream(env, sql) {
  const child = shell(mysqlCommand(env) + ' ' + env.mysqlDb)
  const err = collect(child.stderr)
  child.stdin.on('error', () => {})
  child.stdin.end([...MYSQL_PRELUDE, sql].map((s) => s.replace(/\n/g, ' ') + ';').join('\n') + '\n')
  const done = once(child, 'close').then(([code]) => {
    if (code !== 0) throw new Error(`mysql failed: ${noPasswordWarning(err())}`)
  })
  done.catch(() => {})
  const rl = readline.createInterface({ input: child.stdout, crlfDelay: Infinity })
  return { lines: rl, done }
}

// A psql session that takes a script on standard input. Everything is -At: bare rows, no alignment.
export class PgSession {
  constructor(env, { db = env.pgDb } = {}) {
    this.child = shell(`${env.pgPsql} -d ${db} -X -q -At -v ON_ERROR_STOP=1`)
    this.out = collect(this.child.stdout)
    this.err = collect(this.child.stderr)
    this.closed = once(this.child, 'close')
    this.child.stdin.on('error', () => {})
  }

  async write(text) {
    if (!this.child.stdin.write(text)) await Promise.race([once(this.child.stdin, 'drain'), this.closed])
  }

  async finish() {
    this.child.stdin.end()
    const [code] = await this.closed
    return { code, stdout: this.out(), stderr: this.err() }
  }
}

// Runs a script and returns its output; throws if psql fails.
export async function pgRun(env, sql, { db = env.pgDb } = {}) {
  const s = new PgSession(env, { db })
  await s.write(sql)
  const r = await s.finish()
  if (r.code !== 0) throw new Error(`psql failed: ${r.stderr.trim()}`)
  return r.stdout
}

// Runs a query that returns one JSON value and parses it.
export async function pgJson(env, sql, opts) {
  const out = await pgRun(env, `SELECT coalesce((${sql})::text, 'null');\n`, opts)
  return JSON.parse(out.trim())
}

// Streams the rows of a MySQL SELECT into an open COPY ... FROM STDIN on a psql session. mapRow
// turns the parsed JSON array into the values to copy. Returns the number of rows. The caller has
// already written the COPY header and writes the terminating \. itself.
export async function streamMysqlIntoCopy(env, session, sql, mapRow = (r) => r) {
  const { lines, done } = mysqlStream(env, sql)
  let n = 0
  try {
    for await (const line of lines) {
      await session.write(encodeCopyRow(mapRow(parseMysqlRow(line))))
      n++
    }
    await done
  } catch (e) {
    await done.catch(() => {})
    throw e
  }
  return n
}

// ---------------------------------------------------------------------------------------------
// The target schema

// Every table in the PostgreSQL public schema except the ones the tooling never copies.
export const SKIPPED_TABLES = ['spatial_ref_sys', 'migrations']

export async function listTables(env) {
  return pgJson(env, `SELECT coalesce(json_agg(relname ORDER BY relname), '[]'::json) FROM pg_class
    WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relname NOT IN (${SKIPPED_TABLES.map(ql).join(', ')})`)
}

// The columns of a table, in order, as the load must supply them. Generated columns are left out:
// PostgreSQL computes them.
export async function describeTable(env, table) {
  const cols = await pgJson(env, `SELECT json_agg(json_build_object(
      'name', a.attname, 'type', format_type(a.atttypid, a.atttypmod), 'typname', t.typname,
      'notnull', a.attnotnull, 'generated', a.attgenerated <> '') ORDER BY a.attnum)
    FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid
    WHERE a.attrelid = ${ql(qi(table))}::regclass AND a.attnum > 0 AND NOT a.attisdropped`)
  if (!cols) throw new Error(`table ${table} has no columns in PostgreSQL`)
  return cols.map((c) => {
    const g = c.type.match(/^geometry\((\w+),(\d+)\)$/)
    return {
      ...c,
      isGeom: c.typname === 'geometry',
      geomType: g && g[1].toLowerCase() !== 'geometry' ? g[1] : null,
      geomSrid: g ? Number(g[2]) : null,
    }
  })
}

// The geometry columns of every table: [{table, column, geomType}].
export async function geometryColumns(env) {
  return pgJson(env, `SELECT coalesce(json_agg(json_build_object('table', c.relname, 'column', a.attname,
      'geomType', CASE WHEN lower(substring(format_type(a.atttypid, a.atttypmod) from 'geometry\\((\\w+),')) = 'geometry' THEN NULL
                       ELSE substring(format_type(a.atttypid, a.atttypmod) from 'geometry\\((\\w+),') END)
      ORDER BY c.relname, a.attnum), '[]'::json)
    FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
    WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace AND t.typname = 'geometry'
      AND a.attnum > 0 AND NOT a.attisdropped AND c.relname NOT IN (${SKIPPED_TABLES.map(ql).join(', ')})`)
}

// The single-column primary key of a table, or null when it has none or has several columns.
export async function primaryKey(env, table) {
  const cols = await pgJson(env, `SELECT json_agg(a.attname ORDER BY k.ord) FROM pg_index i
    CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
    WHERE i.indrelid = ${ql(qi(table))}::regclass AND i.indisprimary`)
  return cols && cols.length === 1 ? cols[0] : null
}

// What to select on the MySQL side for a target column, so that every value arrives as text (or
// as hex for bytes) and NULL stays NULL.
export function mysqlExpr(col) {
  const c = mi(col.name)
  if (col.isGeom) return `HEX(ST_AsBinary(${c}))`
  if (col.typname === 'bytea') return `HEX(${c})`
  return `CAST(${c} AS CHAR)`
}

const DATE_TYPES = new Set(['timestamp', 'timestamptz', 'date'])

// What to insert for a staged text value, per target type.
export function pgExpr(col, stage) {
  if (col.isGeom) return `pg_temp.pgmig_geom(${stage}, ${col.geomType ? ql(col.geomType) : 'NULL'})`
  if (col.typname === 'bytea') return `decode(${stage}, 'hex')`
  if (DATE_TYPES.has(col.typname)) {
    const fallback = col.notnull ? `'1970-01-01 00:00:00'::${col.type}` : 'NULL'
    return `CASE WHEN ${stage} ~ ${ql(ZERO_DATE_RE)} THEN ${fallback} ELSE ${stage}::${col.type} END`
  }
  // A bounded character column takes the text as it is and lets the INSERT's assignment conversion
  // check the length: an explicit cast to varchar(n) would cut an over-long value without an error.
  if (col.typname === 'varchar' || col.typname === 'bpchar') return stage
  return `${stage}::${col.type}`
}

// A SQL function, created per session, that turns hex WKB into a valid geometry labelled 4326. The
// label is changed, not the coordinates: the data is degrees whatever MySQL called it. A repaired
// polygon may come back as a multipolygon; a column typed as polygon takes it only when it holds one
// member, and any other row stops the load with its own message.
export const GEOM_FUNCTION = `CREATE FUNCTION pg_temp.pgmig_geom(h text, want text) RETURNS geometry LANGUAGE plpgsql AS $f$
DECLARE g geometry; got text; dim int;
BEGIN
  IF h IS NULL THEN RETURN NULL; END IF;
  g := ST_MakeValid(ST_SetSRID(ST_GeomFromWKB(decode(h, 'hex')), 4326));
  IF want IS NOT NULL THEN
    got := upper(GeometryType(g));
    IF got = 'GEOMETRYCOLLECTION' THEN
      dim := CASE upper(want) WHEN 'POINT' THEN 1 WHEN 'LINESTRING' THEN 2 ELSE 3 END;
      g := ST_CollectionExtract(g, dim);
      got := upper(GeometryType(g));
    END IF;
    IF got = 'MULTI' || upper(want) AND ST_NumGeometries(g) = 1 THEN
      g := ST_GeometryN(g, 1);
      got := upper(GeometryType(g));
    END IF;
    IF got <> upper(want) THEN
      RAISE EXCEPTION 'pgmig: a repaired % cannot be held in a % column (wkb starts %)', got, want, left(h, 40);
    END IF;
  END IF;
  RETURN g;
END
$f$;
`

// ---------------------------------------------------------------------------------------------
// Fixtures

// A MySQL string literal on one line: backslash escapes stand in for the control characters.
export function mysqlLiteral(s) {
  return "'" + String(s).replace(/[\\'\n\r\t\0]/g, (c) => ({ '\\': '\\\\', "'": "\\'", '\n': '\\n', '\r': '\\r', '\t': '\\t', '\0': '\\0' })[c]) + "'"
}

function fixtureValue(v) {
  if (v === null || v === undefined) return 'NULL'
  if (typeof v === 'object' && v.wkt !== undefined) return `ST_GeomFromText(${mysqlLiteral(v.wkt)}, 3857)`
  if (typeof v === 'object' && v.hex !== undefined) return `UNHEX('${v.hex}')`
  if (typeof v === 'number') return String(v)
  return mysqlLiteral(v)
}

// Turns a fixture file ({tables: {name: {columns, rows}}}) into a source map: for each table, one
// SELECT that yields its rows. Geometry is built with ST_GeomFromText under the 3857 label, as the
// application stores it. The first row fixes each column's type, so a column that holds NULL in the
// first row must hold NULL throughout or be text. A table's optional "types" object maps a column to a
// MySQL CAST type (DATETIME, DECIMAL(10,6), JSON, UNSIGNED, DATE), so the derived table carries a real
// typed column instead of a string literal. A zero date cannot be cast, so a column that holds one is
// left untyped.
export function fixtureSourceMap(fixture) {
  const map = {}
  for (const [table, def] of Object.entries(fixture.tables)) {
    const types = def.types || {}
    const value = (c, v) => (types[c] ? `CAST(${fixtureValue(v)} AS ${types[c]})` : fixtureValue(v))
    const selects = def.rows.map((row, i) =>
      'SELECT ' + def.columns.map((c, j) => value(c, row[j]) + (i === 0 ? ` AS ${mi(c)}` : '')).join(', '))
    map[table] = selects.join(' UNION ALL ')
  }
  return map
}
