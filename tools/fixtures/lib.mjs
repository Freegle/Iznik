// Neutral test fixtures: reading the files and writing SQL for either engine.
//
// The fixtures live in scripts/fixtures/ in a format that belongs to neither engine:
//
//   tables/<table>.jsonl   one table. Line 1 is a header, every other line is one row.
//   captured-steps.json    ordered steps that run after the tables, in the same session.
//   extra-steps.json       ordered steps that run last, in a session of their own.
//
// docs/developers/reference/test-fixtures.md describes the format and how to change it.

import fs from 'node:fs'
import path from 'node:path'

export class FixtureError extends Error {}

function fail(msg) {
  throw new FixtureError(msg)
}

// --- the neutral files ------------------------------------------------------------------------

const HEX = /^([0-9a-fA-F]{2})*$/
const GEOM = /^\d+:([0-9a-fA-F]{2})+$/

// Parses the text of one table file. `expected` is the table name the file name promises.
export function parseTableText(text, expected) {
  const lines = text.split('\n')
  if (lines[lines.length - 1] === '') lines.pop()
  if (!lines.length) fail(`${expected}: the file is empty`)
  let head
  try {
    head = JSON.parse(lines[0])
  } catch (e) {
    fail(`${expected}: the header is not JSON`)
  }
  if (head.table !== expected) fail(`${expected}: the header names table ${head.table}`)
  if (!Array.isArray(head.columns) || !head.columns.length) fail(`${expected}: the header has no columns`)
  const bin = new Set(head.bin || [])
  const geom = new Set(head.geom || [])
  for (const c of [...bin, ...geom]) {
    if (!head.columns.includes(c)) fail(`${expected}: the header marks ${c}, which is not a column`)
    if (bin.has(c) && geom.has(c)) fail(`${expected}: ${c} is both bin and geom`)
  }
  const rows = []
  for (let n = 1; n < lines.length; n++) {
    let row
    try {
      row = JSON.parse(lines[n])
    } catch (e) {
      fail(`${expected}: line ${n + 1} is not JSON`)
    }
    if (!Array.isArray(row) || row.length !== head.columns.length) {
      fail(`${expected}: line ${n + 1} does not have ${head.columns.length} values`)
    }
    row.forEach((v, i) => {
      const col = head.columns[i]
      if (v === null) return
      if (bin.has(col)) {
        if (typeof v !== 'string' || !HEX.test(v)) fail(`${expected}: line ${n + 1}, ${col} is not a hex string`)
      } else if (geom.has(col)) {
        if (typeof v !== 'string' || !GEOM.test(v)) fail(`${expected}: line ${n + 1}, ${col} is not <srid>:<hex>`)
      } else if (typeof v !== 'string' && typeof v !== 'number') {
        fail(`${expected}: line ${n + 1}, ${col} is not null, a number or a string`)
      }
    })
    rows.push(row)
  }
  return { table: head.table, columns: head.columns, bin, geom, rows }
}

export function tableNames(dir) {
  const d = path.join(dir, 'tables')
  if (!fs.existsSync(d)) return []
  return fs
    .readdirSync(d)
    .filter((f) => f.endsWith('.jsonl'))
    .map((f) => f.slice(0, -'.jsonl'.length))
    .sort()
}

export function readTable(dir, name) {
  return parseTableText(fs.readFileSync(path.join(dir, 'tables', `${name}.jsonl`), 'utf8'), name)
}

// Values a table file holds that one engine refuses. The file keeps the value MySQL stored, and
// the engine named here loads `to` in its place. Each repair says why.
export function readRepairs(dir, engine) {
  const p = path.join(dir, 'repairs.json')
  if (!fs.existsSync(p)) return []
  const all = JSON.parse(fs.readFileSync(p, 'utf8'))
  const list = all[engine] || []
  for (const r of list) {
    const where = `repairs.json ${engine} ${r.table}.${r.column}`
    if (typeof r.table !== 'string' || typeof r.column !== 'string') fail(`${where}: needs a table and a column`)
    if (!('from' in r) || !('to' in r)) fail(`${where}: needs from and to`)
    if (typeof r.reason !== 'string' || !r.reason) fail(`${where}: needs a reason`)
  }
  return list
}

export function readSteps(dir, file) {
  const p = path.join(dir, file)
  if (!fs.existsSync(p)) return []
  const steps = JSON.parse(fs.readFileSync(p, 'utf8'))
  if (!Array.isArray(steps)) fail(`${file}: expected a list of steps`)
  steps.forEach((s, i) => validateStep(s, `${file} step ${i + 1}`))
  return steps
}

const MODES = ['ignore', 'plain', 'upsert']

function validateStep(s, where) {
  if (s.op === 'insert') {
    if (typeof s.table !== 'string') fail(`${where}: no table`)
    if (!Array.isArray(s.columns) || !s.columns.length) fail(`${where}: no columns`)
    if (!Array.isArray(s.rows) || !s.rows.length) fail(`${where}: no rows`)
    for (const r of s.rows) {
      if (!Array.isArray(r) || r.length !== s.columns.length) fail(`${where}: a row does not match the columns`)
    }
    if (!MODES.includes(s.mode)) fail(`${where}: mode must be one of ${MODES.join(', ')}`)
    if (s.mode === 'upsert') {
      if (!Array.isArray(s.key) || !s.key.length) fail(`${where}: an upsert names the columns of its unique key`)
      if (!Array.isArray(s.update) || !s.update.length) fail(`${where}: an upsert names the columns it refreshes`)
    }
  } else if (s.op === 'delete') {
    if (typeof s.table !== 'string' || !s.where || !Object.keys(s.where).length) {
      fail(`${where}: a delete needs a table and a where`)
    }
  } else {
    fail(`${where}: unknown op ${s.op}`)
  }
}

// Every table a set of steps touches, so setup can check each one still exists.
export function stepTables(steps) {
  const out = new Set()
  const walk = (v) => {
    if (v && typeof v === 'object') {
      if (typeof v.table === 'string') out.add(v.table)
      Object.values(v).forEach(walk)
    }
  }
  steps.forEach(walk)
  return [...out]
}

// --- writing the neutral table files ----------------------------------------------------------

// JSON.stringify leaves U+2028 and U+2029 as they are. They are valid JSON, and a line separator
// to some editors, so they are written as escapes.
const LS = String.fromCharCode(0x2028)
const PS = String.fromCharCode(0x2029)

export function jsonText(v) {
  return JSON.stringify(v).split(LS).join('\\u2028').split(PS).join('\\u2029')
}

// A number that fits a double with no loss is written as a JSON number. Anything longer is
// written as a string, which both engines convert back exactly.
export function safeNumber(lit) {
  const n = Number(lit)
  if (!Number.isFinite(n)) return false
  if (/^-?\d+$/.test(lit)) return Number.isSafeInteger(n)
  const digits = lit.replace(/e.*$/i, '').replace(/[-.]/g, '').replace(/^0+/, '')
  return digits.length <= 15
}

export function tableText({ table, columns, bin, geom, rows }) {
  const head = { table, columns }
  if (bin && bin.length) head.bin = bin
  if (geom && geom.length) head.geom = geom
  return jsonText(head) + '\n' + rows.map((r) => jsonText(r) + '\n').join('')
}

// --- the dialects -----------------------------------------------------------------------------

const MYSQL_ESCAPES = { '\\': '\\\\', "'": "\\'", '\0': '\\0', '\n': '\\n', '\r': '\\r', '\x1a': '\\Z' }

// A geometry value in MySQL's own storage form: four bytes of SRID, little endian, then WKB.
// This is what mysqldump wrote, so the bytes loaded are the bytes that were captured.
function mysqlGeometryHex(v) {
  const i = v.indexOf(':')
  const b = Buffer.alloc(4)
  b.writeUInt32LE(Number(v.slice(0, i)))
  return b.toString('hex') + v.slice(i + 1)
}

function intervalText(d, spec) {
  const units = Object.entries(spec)
  if (units.length !== 1) fail(`a time offset names one unit: ${JSON.stringify(spec)}`)
  const [unit, n] = units[0]
  if (!Number.isInteger(n) || !['days', 'hours', 'minutes', 'seconds'].includes(unit)) {
    fail(`bad time offset ${JSON.stringify(spec)}`)
  }
  return d.interval(n, unit)
}

export const mysql = {
  name: 'mysql',
  id: (n) => '`' + n.replace(/`/g, '``') + '`',
  str: (s) => "'" + s.replace(/[\\'\0\n\r\x1a]/g, (c) => MYSQL_ESCAPES[c]) + "'",
  bin: (h) => `X'${h}'`,
  geom: (v) => `X'${mysqlGeometryHex(v)}'`,
  now: () => 'NOW()',
  interval: (n, unit) => `INTERVAL ${n} ${unit.slice(0, -1).toUpperCase()}`,
  plus: (a, b) => `${a} + ${b}`,
  lastId: () => 'LAST_INSERT_ID()',
  asText: (e) => `CAST(${e} AS CHAR)`,
  insertHead: (mode) => (mode === 'ignore' ? 'INSERT IGNORE INTO' : 'INSERT INTO'),
  dual: ' FROM DUAL',
  conflictTail: (s, qi) =>
    s.mode === 'upsert'
      ? ' ON DUPLICATE KEY UPDATE ' + s.update.map((c) => `${qi(c)} = VALUES(${qi(c)})`).join(', ')
      : '',
}

export const pgsql = {
  name: 'pgsql',
  id: (n) => '"' + n.replace(/"/g, '""') + '"',
  str: (s) => {
    if (s.includes('\0')) fail('PostgreSQL text cannot hold a NUL character')
    return "'" + s.replace(/'/g, "''") + "'"
  },
  bin: (h) => `decode('${h}', 'hex')`,
  // Relabelled to SRID 4326, not reprojected: the 3857 on the MySQL columns was always a label.
  // MySQL accepts a polygon that crosses itself and PostGIS does not work with one, so every
  // geometry goes through ST_MakeValid. A valid one comes back unchanged.
  geom: (v) => `ST_MakeValid(ST_SetSRID(ST_GeomFromWKB(decode('${v.slice(v.indexOf(':') + 1)}', 'hex')), 4326))`,
  now: () => "date_trunc('second', now())",
  interval: (n, unit) => `interval '${n} ${unit}'`,
  plus: (a, b) => `${a} + ${b}`,
  lastId: (table) => `currval(pg_get_serial_sequence(${pgsql.str(pgsql.id(table))}, 'id'))`,
  asText: (e) => `CAST(${e} AS text)`,
  insertHead: () => 'INSERT INTO',
  dual: '',
  conflictTail: (s, qi) => {
    if (s.mode === 'ignore') return ' ON CONFLICT DO NOTHING'
    if (s.mode === 'upsert') {
      return (
        ` ON CONFLICT (${s.key.map(qi).join(', ')}) DO UPDATE SET ` +
        s.update.map((c) => `${qi(c)} = EXCLUDED.${qi(c)}`).join(', ')
      )
    }
    return ''
  },
}

export const dialects = { mysql, pgsql }

// --- rendering values and steps ---------------------------------------------------------------

function whereSql(d, where) {
  return Object.entries(where)
    .map(([c, v]) => (v === null ? `${d.id(c)} IS NULL` : `${d.id(c)} = ${valueSql(d, v)}`))
    .join(' AND ')
}

function lookupSql(d, l) {
  const sub = `(SELECT ${d.id(l.column)} FROM ${d.id(l.table)} WHERE ${whereSql(d, l.where)})`
  return l.text ? d.asText(sub) : sub
}

// A plain value is null, a number or a string. An object is one of:
//   {"now": 0} or {"now": {"days": 14}}    the time of loading, optionally moved
//   {"geom": "3857:<wkb hex>"}             a geometry, as in a table file
//   {"bin": "<hex>"}                       binary data
//   {"lastId": "users"}                    the id the previous insert into that table was given
//   {"lookup": {table, column, where}}     one column of the row that `where` finds
export function valueSql(d, v) {
  if (v === null) return 'NULL'
  if (typeof v === 'number') return String(v)
  if (typeof v === 'string') return d.str(v)
  if (v && typeof v === 'object') {
    if ('geom' in v) return d.geom(v.geom)
    if ('bin' in v) return d.bin(v.bin)
    if ('now' in v) return v.now === 0 ? d.now() : d.plus(d.now(), intervalText(d, v.now))
    if ('lastId' in v) return d.lastId(v.lastId)
    if ('lookup' in v) return lookupSql(d, v.lookup)
  }
  return fail(`unknown value ${JSON.stringify(v)}`)
}

const isDynamic = (v) => v !== null && typeof v === 'object' && ('lastId' in v || 'lookup' in v)

export function stepSql(d, s) {
  if (s.op === 'delete') return `DELETE FROM ${d.id(s.table)} WHERE ${whereSql(d, s.where)};\n`
  const head = `${d.insertHead(s.mode)} ${d.id(s.table)} (${s.columns.map(d.id).join(', ')})`
  const tail = d.conflictTail(s, d.id)
  const dynamic = s.rows.some((r) => r.some(isDynamic)) || s.unless
  if (!dynamic) {
    return head + ' VALUES ' + s.rows.map((r) => '(' + r.map((v) => valueSql(d, v)).join(', ') + ')').join(', ') + tail + ';\n'
  }
  // A row that looks something up, or runs only when nothing matches, is written as a SELECT so
  // that a missing lookup or a match inserts nothing.
  return s.rows
    .map((r) => {
      const conds = r.filter((v) => v && typeof v === 'object' && 'lookup' in v).map((v) => `${lookupSql(d, v.lookup)} IS NOT NULL`)
      if (s.unless) conds.push(`NOT EXISTS (SELECT 1 FROM ${d.id(s.unless.table)} WHERE ${whereSql(d, s.unless.where)})`)
      return (
        `${head} SELECT ${r.map((v) => valueSql(d, v)).join(', ')}${d.dual}` +
        (conds.length ? ` WHERE ${conds.join(' AND ')}` : '') +
        `${tail};\n`
      )
    })
    .join('')
}

function cellSql(d, table, col, v, repairs, stats) {
  const fix = repairs && repairs.find((r) => r.column === col && r.from === v)
  if (fix) {
    v = fix.to
    if (stats) stats[`${table.table}.${col}`] = (stats[`${table.table}.${col}`] || 0) + 1
  }
  if (v === null) return 'NULL'
  if (table.bin.has(col)) return d.bin(v)
  if (table.geom.has(col)) return d.geom(v)
  if (typeof v === 'number') return String(v)
  return d.str(v)
}

// The INSERT statements for one table, in chunks of at most `batch` rows or `maxChars` characters.
export function* tableSql(d, table, { batch = 1000, maxChars = 1_000_000, repairs = [], stats = null } = {}) {
  for (const r of repairs) {
    if (!table.columns.includes(r.column)) fail(`repairs.json: ${table.table} has no column ${r.column}`)
  }
  const head =
    `${d.insertHead('ignore')} ${d.id(table.table)} (${table.columns.map(d.id).join(', ')}) VALUES `
  const tail = d.conflictTail({ mode: 'ignore' }, d.id)
  let parts = []
  let size = 0
  for (const row of table.rows) {
    const sql = '(' + row.map((v, i) => cellSql(d, table, table.columns[i], v, repairs, stats)).join(',') + ')'
    if (parts.length && (parts.length >= batch || size + sql.length > maxChars)) {
      yield head + parts.join(',\n') + tail + ';\n'
      parts = []
      size = 0
    }
    parts.push(sql)
    size += sql.length
  }
  if (parts.length) yield head + parts.join(',\n') + tail + ';\n'
}

// --- the whole load ---------------------------------------------------------------------------

// What the dump this replaces did around its data, session setting for session setting.
const MYSQL_PRELUDE = `SET FOREIGN_KEY_CHECKS=0;
SET UNIQUE_CHECKS=0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

`

const MYSQL_TABLES_DONE = `
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

`

const MYSQL_CAPTURED_DONE = `
SET UNIQUE_CHECKS=1;
SET FOREIGN_KEY_CHECKS=1;

`

// Foreign keys are switched off for the captured rows, as MySQL had them, and on again for the
// steps in extra-steps.json. Identities are moved past the loaded ids before any step that lets
// the database choose one.
const PG_PRELUDE = `SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET TIME ZONE 'UTC';
SET session_replication_role = replica;

`
const PG_RESET = 'SELECT iznik_reset_identities();\n'

// Yields the SQL text of a load, in order. Options:
//   tables  load only these tables (and none of the steps)
//   batch   rows per INSERT, to find every failing row (default: as many as fit)
//   stats   an object that collects how many values each repair changed
//   part    'all' (default), 'tables', 'captured' (tables and their steps) or 'extra' (the last steps)
export function* loadSql(dialect, dir, opts = {}) {
  const d = typeof dialect === 'string' ? dialects[dialect] : dialect
  if (!d) fail(`unknown engine ${dialect}`)
  const only = opts.tables ? new Set(opts.tables) : null
  const part = opts.part || 'all'
  const names = tableNames(dir)
  if (only) {
    for (const t of only) if (!names.includes(t)) fail(`there is no fixture table ${t}`)
  }
  const repairs = readRepairs(dir, d.name)
  for (const r of repairs) {
    if (!names.includes(r.table)) fail(`repairs.json: there is no fixture table ${r.table}`)
  }
  const doTables = part !== 'extra'
  const doCaptured = !only && (part === 'all' || part === 'captured')
  const doExtra = !only && (part === 'all' || part === 'extra')
  const pg = d.name === 'pgsql'

  if (doTables) {
    yield pg ? PG_PRELUDE : MYSQL_PRELUDE
    for (const name of names) {
      if (only && !only.has(name)) continue
      yield* tableSql(d, readTable(dir, name), {
        batch: opts.batch || 1000,
        repairs: repairs.filter((r) => r.table === name),
        stats: opts.stats,
      })
    }
    if (!pg) yield MYSQL_TABLES_DONE
  }
  if (doCaptured) {
    if (pg) yield PG_RESET
    for (const s of readSteps(dir, 'captured-steps.json')) yield stepSql(d, s)
    yield pg ? PG_RESET : MYSQL_CAPTURED_DONE
  }
  if (doExtra) {
    if (pg) yield 'SET session_replication_role = DEFAULT;\n'
    for (const s of readSteps(dir, 'extra-steps.json')) yield stepSql(d, s)
    if (pg) yield PG_RESET
  }
}

// Every table the fixtures write to.
export function allTables(dir) {
  return [
    ...new Set([
      ...tableNames(dir),
      ...stepTables(readSteps(dir, 'captured-steps.json')),
      ...stepTables(readSteps(dir, 'extra-steps.json')),
    ]),
  ].sort()
}
