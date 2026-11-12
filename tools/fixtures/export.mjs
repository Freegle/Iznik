#!/usr/bin/env node
// Writes the neutral fixture tables (scripts/fixtures/tables/) from a mysqldump of row data.
//
//   node export.mjs --dump data.sql --schema schema.sql --out scripts/fixtures
//
//   data.sql    mysqldump --no-create-info --complete-insert --insert-ignore --hex-blob
//   schema.sql  mysqldump --no-data of the same database, which says which columns hold
//               geometry and binary values
//   --out       the fixtures directory; tables/ in it is replaced, and the step files are
//               not touched
//
// Only INSERT ... VALUES statements are read. Anything else that writes data stops the export,
// so a statement is never dropped silently; session settings, comments and LOCK TABLES are skipped.
// The file is read as bytes, so the geometry and binary values come through unchanged.

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseDump } from '../pg-schema/convert.mjs'
import { FixtureError, safeNumber, tableText } from './lib.mjs'

function fail(msg) {
  throw new FixtureError(msg)
}

const BIN_TYPES = new Set(['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'])
const GEOM_TYPES = new Set(['geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'])

const UNESCAPE = { n: '\n', r: '\r', t: '\t', 0: '\0', Z: '\x1a', b: '\b' }

// Reads a quoted string at s[i] === "'". The text is a byte string (one character per byte).
function readQuoted(s, i) {
  let out = ''
  let from = i + 1
  for (let j = from; j < s.length; j++) {
    const c = s[j]
    if (c === '\\') {
      out += s.slice(from, j)
      const n = s[j + 1]
      out += n in UNESCAPE ? UNESCAPE[n] : n
      j++
      from = j + 1
    } else if (c === "'") {
      if (s[j + 1] === "'") {
        out += s.slice(from, j + 1)
        j++
        from = j + 1
      } else {
        return [out + s.slice(from, j), j + 1]
      }
    }
  }
  return fail('unterminated string')
}

const NUMBER = /-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/y
const HEXLIT = /0x([0-9A-Fa-f]*)/y
const INSERT = /INSERT\s+(IGNORE\s+)?INTO\s+`([^`]+)`\s*\(((?:`[^`]+`(?:\s*,\s*)?)+)\)\s*VALUES\s*/y

// Yields {table, columns, rows} for each INSERT in a dump read as a byte string. A value is
// {n: true} for NULL, {num}, {hex}, or {raw} for a quoted string (still bytes).
export function* readInserts(s) {
  let i = 0
  const n = s.length
  while (i < n) {
    const c = s[i]
    if (c === ' ' || c === '\n' || c === '\r' || c === '\t') {
      i++
    } else if (s.startsWith('--', i)) {
      const e = s.indexOf('\n', i)
      i = e < 0 ? n : e + 1
    } else if (s.startsWith('/*', i)) {
      const e = s.indexOf('*/', i)
      if (e < 0) fail('unterminated comment')
      i = e + 2
      if (s[i] === ';') i++
    } else if (/^(SET|LOCK TABLES|UNLOCK TABLES)\b/.test(s.slice(i, i + 16))) {
      const e = s.indexOf(';', i)
      if (e < 0) fail('unterminated statement')
      i = e + 1
    } else {
      INSERT.lastIndex = i
      const m = INSERT.exec(s)
      if (!m) fail(`not a statement this export reads, at byte ${i}: ${s.slice(i, i + 80).replace(/\n/g, ' ')}`)
      i = INSERT.lastIndex
      const columns = [...m[3].matchAll(/`([^`]+)`/g)].map((x) => x[1])
      const rows = []
      for (;;) {
        if (s[i] !== '(') fail(`expected a row at byte ${i}`)
        i++
        const row = []
        for (;;) {
          let v
          const ch = s[i]
          if (ch === "'") {
            const [raw, j] = readQuoted(s, i)
            v = { raw }
            i = j
          } else if (s.startsWith('_binary', i)) {
            i += 7
            while (s[i] === ' ') i++
            const [raw, j] = readQuoted(s, i)
            v = { raw }
            i = j
          } else if (s.startsWith('NULL', i)) {
            v = { n: true }
            i += 4
          } else {
            HEXLIT.lastIndex = i
            const h = HEXLIT.exec(s)
            if (h) {
              v = { hex: h[1] }
              i = HEXLIT.lastIndex
            } else {
              NUMBER.lastIndex = i
              const x = NUMBER.exec(s)
              if (!x) fail(`unreadable value at byte ${i}: ${s.slice(i, i + 40)}`)
              v = { num: x[0] }
              i = NUMBER.lastIndex
            }
          }
          row.push(v)
          if (s[i] === ',') i++
          else if (s[i] === ')') {
            i++
            break
          } else fail(`expected , or ) at byte ${i}`)
        }
        if (row.length !== columns.length) fail(`${m[2]}: a row has ${row.length} values for ${columns.length} columns`)
        rows.push(row)
        if (s[i] === ',') i++
        else if (s[i] === ';') {
          i++
          break
        } else fail(`expected , or ; at byte ${i}`)
      }
      yield { table: m[2], columns, rows }
    }
  }
}

function utf8(raw, where) {
  const b = Buffer.from(raw, 'latin1')
  const t = b.toString('utf8')
  if (!Buffer.from(t, 'utf8').equals(b)) fail(`${where}: not valid UTF-8`)
  return t
}

// Builds the neutral table objects from the bytes of a data dump and the text of a schema dump.
export function exportTables(dumpBytes, schemaText) {
  const schema = new Map()
  for (const t of parseDump(schemaText).tables) schema.set(t.name, new Map(t.columns.map((c) => [c.name, c])))
  const out = new Map()
  for (const ins of readInserts(dumpBytes.toString('latin1'))) {
    const cols = schema.get(ins.table)
    if (!cols) fail(`${ins.table}: not in the schema dump`)
    let t = out.get(ins.table)
    if (!t) {
      t = { table: ins.table, columns: ins.columns, kinds: ins.columns.map((c) => kindOf(ins.table, c, cols)), rows: [] }
      out.set(ins.table, t)
    } else if (t.columns.join() !== ins.columns.join()) {
      fail(`${ins.table}: two INSERTs list different columns`)
    }
    for (const row of ins.rows) {
      t.rows.push(row.map((v, i) => convert(t, i, v, cols.get(t.columns[i]))))
    }
  }
  return [...out.values()]
    .sort((a, b) => (a.table < b.table ? -1 : 1))
    .map((t) => ({
      table: t.table,
      columns: t.columns,
      bin: t.columns.filter((_, i) => t.kinds[i] === 'bin'),
      geom: t.columns.filter((_, i) => t.kinds[i] === 'geom'),
      rows: t.rows,
    }))
}

function kindOf(table, col, cols) {
  const c = cols.get(col)
  if (!c) fail(`${table}.${col}: not in the schema dump`)
  if (GEOM_TYPES.has(c.type)) return 'geom'
  if (BIN_TYPES.has(c.type)) return 'bin'
  return 'plain'
}

function convert(t, i, v, colDef) {
  const col = t.columns[i]
  const where = `${t.table}.${col}`
  const kind = t.kinds[i]
  if (v.n) return null
  if (kind === 'plain') {
    if ('hex' in v) fail(`${where}: a hex value in a column that holds neither binary nor geometry`)
    if ('raw' in v) return utf8(v.raw, where)
    return safeNumber(v.num) ? Number(v.num) : v.num
  }
  let bytes
  if ('hex' in v) bytes = Buffer.from(v.hex, 'hex')
  else if ('raw' in v) bytes = Buffer.from(v.raw, 'latin1')
  else fail(`${where}: a number in a column that holds ${kind === 'geom' ? 'geometry' : 'binary data'}`)
  if (kind === 'bin') return bytes.toString('hex')
  if (bytes.length < 9) fail(`${where}: too short for a geometry value`)
  const srid = bytes.readUInt32LE(0)
  if (colDef.srid != null && colDef.srid !== srid) fail(`${where}: the value has SRID ${srid} and the column ${colDef.srid}`)
  return `${srid}:${bytes.subarray(4).toString('hex')}`
}

// A real seeding has dozens of tables. An export with almost none is an empty or wrong database, and
// writing it would delete the committed files.
export const MIN_TABLES = 10

export function writeTables(tables, outDir) {
  if (tables.length < MIN_TABLES) {
    fail(`the dump holds only ${tables.length} tables, fewer than ${MIN_TABLES}: is the database seeded? Nothing was written`)
  }
  const dir = path.join(outDir, 'tables')
  fs.mkdirSync(dir, { recursive: true })
  const keep = new Set(tables.map((t) => `${t.table}.jsonl`))
  for (const f of fs.readdirSync(dir)) {
    if (f.endsWith('.jsonl') && !keep.has(f)) fs.rmSync(path.join(dir, f))
  }
  for (const t of tables) fs.writeFileSync(path.join(dir, `${t.table}.jsonl`), tableText(t))
}

function main() {
  const args = process.argv.slice(2)
  const get = (name) => {
    const i = args.indexOf(name)
    return i < 0 ? null : args[i + 1]
  }
  const dump = get('--dump')
  const schema = get('--schema')
  const out = get('--out')
  if (!dump || !schema || !out) {
    console.error('usage: export.mjs --dump data.sql --schema schema.sql --out <fixtures dir>')
    process.exit(2)
  }
  try {
    const tables = exportTables(fs.readFileSync(dump), fs.readFileSync(schema, 'utf8'))
    writeTables(tables, out)
    console.error(`wrote ${tables.length} tables, ${tables.reduce((n, t) => n + t.rows.length, 0)} rows to ${path.join(out, 'tables')}`)
  } catch (e) {
    if (e instanceof FixtureError) {
      console.error(`export failed: ${e.message}`)
      process.exit(1)
    }
    throw e
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) main()
