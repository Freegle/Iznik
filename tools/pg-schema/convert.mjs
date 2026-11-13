#!/usr/bin/env node
// Converts a MySQL schema dump (mysqldump --no-data --routines) into a PostgreSQL baseline.
//
// The migrated MySQL schema is the source of truth. This script reads its dump and writes one
// PostgreSQL schema file, so the two cannot drift: re-run it and commit the result.
// Anything it does not recognise is an error, never a guess. docs/developers/reference/
// database-engines.md lists every construct it translates and the decision behind each.
//
//   node convert.mjs <dump.sql> <baseline.sql> [--config config.json] [--migrations-dir dir]
//
// Without an output path the baseline goes to stdout. The translation report goes to stderr.

import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { fileURLToPath } from 'node:url'

// Words PostgreSQL will not accept as a bare identifier (pg_get_keywords, catcode R and T).
const RESERVED = new Set(
  (
    'all analyse analyze and any array as asc asymmetric authorization binary both case cast check collate ' +
    'collation column concurrently constraint create cross current_catalog current_date current_role ' +
    'current_schema current_time current_timestamp current_user default deferrable desc distinct do else end ' +
    'except false fetch for foreign freeze from full grant group having ilike in initially inner intersect ' +
    'into is isnull join lateral leading left like limit localtime localtimestamp natural not notnull null ' +
    'offset on only or order outer overlaps placing primary references returning right select session_user ' +
    'similar some symmetric system_user table tablesample then to trailing true union unique user using ' +
    'variadic verbose when where window with'
  ).split(' ')
)

const MAX_IDENT = 63

export class ConvertError extends Error {}

function fail(msg) {
  throw new ConvertError(msg)
}

export function qi(name) {
  if (name.length > MAX_IDENT) fail(`identifier longer than ${MAX_IDENT} characters: ${name}`)
  return /^[a-z_][a-z0-9_$]*$/.test(name) && !RESERVED.has(name) ? name : '"' + name.replace(/"/g, '""') + '"'
}

// A generated name (index, constraint, function) that must fit in 63 characters. Over-long names
// are cut and given a short hash of the full name so they stay unique and stable.
// Shared by every ON UPDATE CURRENT_TIMESTAMP trigger. A statement trigger with a column list fires only
// when the UPDATE names that column, and before any row is touched: it notes the column for the table.
// The row trigger reads the note, and the statement trigger at the end clears it. The setting is local to
// the transaction, so a statement that fails and is rolled back takes its note with it.
const ON_UPDATE_NAMED_FUNCTIONS =
  `CREATE FUNCTION iznik_on_update_named() RETURNS trigger LANGUAGE plpgsql AS $$\nBEGIN\n` +
  `  PERFORM set_config('iznik.named_' || TG_TABLE_NAME, coalesce(current_setting('iznik.named_' || TG_TABLE_NAME, true), '') || ',' || TG_ARGV[0] || ',', true);\n` +
  `  RETURN NULL;\nEND\n$$;\n` +
  `CREATE FUNCTION iznik_on_update_done() RETURNS trigger LANGUAGE plpgsql AS $$\nBEGIN\n` +
  `  PERFORM set_config('iznik.named_' || TG_TABLE_NAME, '', true);\n` +
  `  RETURN NULL;\nEND\n$$;`

export function fit(name) {
  if (name.length <= MAX_IDENT) return name
  const h = crypto.createHash('sha1').update(name).digest('hex').slice(0, 8)
  return name.slice(0, MAX_IDENT - 9) + '_' + h
}

export function ql(s) {
  return "'" + s.replace(/'/g, "''") + "'"
}

// --- the translation report -------------------------------------------------------------------

export class Report {
  constructor() {
    this.counts = new Map()
    this.notes = []
  }

  hit(name, detail) {
    const e = this.counts.get(name) || { n: 0, examples: [] }
    e.n++
    if (detail && e.examples.length < 3 && !e.examples.includes(detail)) e.examples.push(detail)
    this.counts.set(name, e)
  }

  note(s) {
    this.notes.push(s)
  }

  text() {
    const lines = []
    for (const k of [...this.counts.keys()].sort()) {
      const e = this.counts.get(k)
      lines.push(`${String(e.n).padStart(5)}  ${k}${e.examples.length ? '   e.g. ' + e.examples.join(', ') : ''}`)
    }
    for (const n of this.notes) lines.push(`  note  ${n}`)
    return lines.join('\n')
  }
}

// --- MySQL string and token helpers -----------------------------------------------------------

// Reads a MySQL quoted string starting at s[i] === "'". Returns [value, indexAfter].
export function readString(s, i) {
  if (s[i] !== "'") fail(`expected a quote at ${i} in ${s}`)
  let out = ''
  i++
  for (;;) {
    if (i >= s.length) fail(`unterminated string in ${s}`)
    const c = s[i]
    if (c === '\\') {
      const n = s[i + 1]
      const map = { n: '\n', r: '\r', t: '\t', 0: '\0', Z: '\x1a', b: '\b' }
      out += n in map ? map[n] : n
      i += 2
    } else if (c === "'") {
      if (s[i + 1] === "'") {
        out += "'"
        i += 2
      } else {
        return [out, i + 1]
      }
    } else {
      out += c
      i++
    }
  }
}

// Reads a balanced (...) group starting at s[i] === '('. Returns [inner, indexAfter].
export function readParens(s, i) {
  if (s[i] !== '(') fail(`expected ( at ${i} in ${s}`)
  let depth = 0
  const start = i
  for (; i < s.length; i++) {
    const c = s[i]
    if (c === "'") {
      i = readString(s, i)[1] - 1
    } else if (c === '`') {
      i = s.indexOf('`', i + 1)
    } else if (c === '(') {
      depth++
    } else if (c === ')') {
      depth--
      if (depth === 0) return [s.slice(start + 1, i), i + 1]
    }
  }
  return fail(`unbalanced parentheses in ${s}`)
}

function readStringList(inner) {
  const out = []
  let i = 0
  while (i < inner.length) {
    const [v, j] = readString(inner, i)
    out.push(v)
    i = j
    if (inner[i] === ',') i++
    else if (i < inner.length) fail(`bad list: ${inner}`)
  }
  return out
}

// --- column parsing ---------------------------------------------------------------------------

export function parseColumn(line) {
  const m = line.match(/^`([^`]+)`\s+(.*)$/s)
  if (!m) fail(`not a column: ${line}`)
  const col = { name: m[1], notNull: false, auto: false, onUpdate: null, comment: null, srid: null }
  let rest = m[2].replace(/,$/, '')

  const t = rest.match(/^([a-z]+)\s*/i)
  if (!t) fail(`no type in ${line}`)
  col.type = t[1].toLowerCase()
  rest = rest.slice(t[0].length)
  col.args = null
  if (rest[0] === '(') {
    const [inner, j] = readParens(rest, 0)
    col.args = inner
    rest = rest.slice(j).trimStart()
  }
  const mods = rest.match(/^((?:unsigned|zerofill)\s*)+/i)
  if (mods) {
    col.unsigned = /unsigned/i.test(mods[0])
    if (/zerofill/i.test(mods[0])) fail(`ZEROFILL is not translated: ${line}`)
    rest = rest.slice(mods[0].length)
  }

  let guard = 0
  while (rest.length && guard++ < 40) {
    rest = rest.trimStart()
    let a
    if ((a = rest.match(/^CHARACTER SET \w+\s*/i))) {
      col.charset = a[0].split(/\s+/)[2]
    } else if ((a = rest.match(/^COLLATE (\w+)\s*/i))) {
      col.collate = a[1]
    } else if ((a = rest.match(/^NOT NULL\s*/i))) {
      col.notNull = true
    } else if ((a = rest.match(/^NULL\s*/i))) {
      col.notNull = false
    } else if ((a = rest.match(/^AUTO_INCREMENT\s*/i))) {
      col.auto = true
    } else if ((a = rest.match(/^ON UPDATE (CURRENT_TIMESTAMP(?:\(\d+\))?)\s*/i))) {
      col.onUpdate = a[1]
    } else if ((a = rest.match(/^\/\*!80003 SRID (\d+) \*\/\s*/))) {
      col.srid = Number(a[1])
    } else if ((a = rest.match(/^DEFAULT\s+/i))) {
      rest = rest.slice(a[0].length)
      if ((a = rest.match(/^NULL\b\s*/i))) {
        col.default = { kind: 'null' }
      } else if (rest[0] === "'") {
        const [v, j] = readString(rest, 0)
        col.default = { kind: 'string', value: v }
        a = [rest.slice(0, j)]
        rest = rest.slice(j)
        a = [rest.match(/^\s*/)[0]]
      } else if ((a = rest.match(/^(CURRENT_TIMESTAMP(?:\(\d+\))?)\s*/i))) {
        col.default = { kind: 'now', value: a[1] }
      } else if ((a = rest.match(/^(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)\s*/))) {
        col.default = { kind: 'number', value: a[1] }
      } else if ((a = rest.match(/^b'([01]*)'\s*/))) {
        col.default = { kind: 'bit', value: a[1] }
      } else if (rest[0] === '(') {
        const [inner, j] = readParens(rest, 0)
        col.default = { kind: 'expr', value: inner }
        a = [rest.slice(0, j) + (rest.slice(j).match(/^\s*/)[0] || '')]
      } else {
        fail(`unrecognised DEFAULT in ${line}`)
      }
    } else if ((a = rest.match(/^COMMENT\s+/i))) {
      rest = rest.slice(a[0].length)
      const [v, j] = readString(rest, 0)
      col.comment = v
      a = [rest.slice(0, j) + (rest.slice(j).match(/^\s*/)[0] || '')]
    } else if ((a = rest.match(/^GENERATED ALWAYS AS\s*/i))) {
      rest = rest.slice(a[0].length)
      const [inner, j] = readParens(rest, 0)
      const after = rest.slice(j).match(/^\s*(VIRTUAL|STORED)\s*/i)
      if (!after) fail(`generated column without VIRTUAL or STORED: ${line}`)
      col.generated = { expr: inner, mode: after[1].toUpperCase() }
      a = [rest.slice(0, j) + after[0]]
    } else {
      fail(`unrecognised column attribute near "${rest}" in ${line}`)
    }
    rest = rest.slice(a[0].length)
  }
  return col
}

// --- key parsing ------------------------------------------------------------------------------

function parseKeyParts(inner) {
  // `col`(191) DESC, `col2`
  const parts = []
  let i = 0
  while (i < inner.length) {
    while (inner[i] === ' ' || inner[i] === ',') i++
    if (inner[i] === '(') fail(`functional key parts are not translated: ${inner}`)
    const m = inner.slice(i).match(/^`([^`]+)`(?:\((\d+)\))?(?:\s+(ASC|DESC))?/i)
    if (!m) fail(`bad key part in ${inner}`)
    parts.push({ col: m[1], prefix: m[2] ? Number(m[2]) : null, desc: /desc/i.test(m[3] || '') })
    i += m[0].length
  }
  return parts
}

function parseKey(line) {
  let m
  line = line.replace(/,$/, '')
  if ((m = line.match(/^PRIMARY KEY \((.*)\)$/))) return { kind: 'primary', parts: parseKeyParts(m[1]) }
  if ((m = line.match(/^(UNIQUE |SPATIAL |FULLTEXT )?KEY `([^`]+)` \((.*)\)(.*)$/))) {
    if (m[4].trim()) fail(`key options are not translated: ${line}`)
    const kind = (m[1] || '').trim().toLowerCase() || 'plain'
    if (kind === 'fulltext') fail(`FULLTEXT keys are not translated: ${line}`)
    return { kind, name: m[2], parts: parseKeyParts(m[3]) }
  }
  if ((m = line.match(/^CONSTRAINT `([^`]+)` FOREIGN KEY \((.*?)\) REFERENCES `([^`]+)` \((.*?)\)(.*)$/))) {
    const fk = {
      kind: 'fk',
      name: m[1],
      cols: parseKeyParts(m[2]).map((p) => p.col),
      refTable: m[3],
      refCols: parseKeyParts(m[4]).map((p) => p.col),
      onDelete: null,
      onUpdate: null,
    }
    let tail = m[5].trim()
    while (tail) {
      const a = tail.match(/^ON (DELETE|UPDATE) (CASCADE|SET NULL|RESTRICT|NO ACTION|SET DEFAULT)\s*/i)
      if (!a) fail(`bad foreign key tail "${tail}" in ${line}`)
      fk[a[1].toUpperCase() === 'DELETE' ? 'onDelete' : 'onUpdate'] = a[2].toUpperCase()
      tail = tail.slice(a[0].length)
    }
    return fk
  }
  if (/^CONSTRAINT .* CHECK /.test(line)) fail(`CHECK constraints are not translated: ${line}`)
  return fail(`unrecognised table line: ${line}`)
}

// --- dump parsing -----------------------------------------------------------------------------

export function parseDump(text) {
  const tables = []
  const routines = []
  const lines = text.split('\n')
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    let m
    if ((m = line.match(/^CREATE TABLE `([^`]+)` \($/))) {
      const t = { name: m[1], columns: [], keys: [], comment: null, options: '' }
      for (i++; i < lines.length; i++) {
        const l = lines[i]
        if (l.startsWith(')')) {
          t.options = l
          const c = l.match(/ COMMENT='((?:[^'\\]|\\.|'')*)'/)
          if (c) t.comment = readString("'" + c[1] + "'", 0)[0]
          const stripped = l
            .replace(/ COMMENT='(?:[^'\\]|\\.|'')*'/, '')
            .replace(/ ENGINE=\w+/, '')
            .replace(/ AUTO_INCREMENT=\d+/, '')
            .replace(/ DEFAULT CHARSET=\w+/, '')
            .replace(/ COLLATE=\w+/, '')
            .replace(/ ROW_FORMAT=\w+/, '')
            .replace(/^\)/, '')
            .replace(/;$/, '')
            .trim()
          if (stripped) fail(`unrecognised table option "${stripped}" on ${t.name}`)
          break
        }
        const body = l.replace(/^ {2}/, '')
        if (body.startsWith('`')) t.columns.push(parseColumn(body))
        else if (body.trim()) t.keys.push(parseKey(body))
      }
      tables.push(t)
    } else if (line.startsWith('/*!') || /^(CREATE|DROP|DELIMITER)\b/.test(line)) {
      // mysqldump wraps triggers, events, views and partitioning in version comments such as
      // /*!50003 CREATE*/ ... /*!50003 TRIGGER ...*/. Strip the markers before matching, so none of
      // them is missed because of how it is written.
      const plain = line.replace(/\/\*!\d+\s*/g, '').replace(/\*\//g, '').trim()
      let r
      if (/\bPARTITION BY\b/.test(plain)) fail(`table partitioning is not translated: ${line}`)
      else if (/^CREATE\b.*?\b(TRIGGER|EVENT)\b\s*`/.test(plain) || /^(TRIGGER|EVENT)\s+`/.test(plain)) fail(`triggers and events are not translated: ${line}`)
      else if (/^(?:(?:CREATE|DROP)\b.*?)?\bVIEW\b\s*[`\w]/.test(plain)) fail(`views are not translated: ${line}`)
      else if ((r = plain.match(/^CREATE\b.*?\b(FUNCTION|PROCEDURE) `([^`]+)`/))) routines.push({ kind: r[1], name: r[2] })
    }
  }
  return { tables, routines }
}

// --- type translation -------------------------------------------------------------------------

const INT_TYPES = {
  tinyint: 'smallint',
  smallint: 'smallint',
  mediumint: 'integer',
  int: 'integer',
  integer: 'integer',
  bigint: 'bigint',
}

// MySQL unsigned types do not fit the signed type of the same width. bigint unsigned is kept as
// bigint: no id in the data is anywhere near 2^63.
const UNSIGNED_WIDEN = { smallint: 'integer', integer: 'bigint' }

function pgType(col, table, ctx) {
  const r = ctx.report
  const fqn = `${table}.${col.name}`
  const t = col.type
  let pg
  let check = null

  if (t in INT_TYPES) {
    pg = INT_TYPES[t]
    if (t === 'tinyint') r.hit('tinyint -> smallint', col.args ? `tinyint(${col.args})` : 'tinyint')
    else if (t === 'mediumint') r.hit('mediumint -> integer')
    if (col.unsigned) {
      if (UNSIGNED_WIDEN[pg] && t !== 'tinyint') pg = UNSIGNED_WIDEN[pg]
      r.hit(`${t} unsigned -> ${pg}`)
    }
  } else if (t === 'decimal' || t === 'numeric') {
    pg = `numeric(${col.args})`
    r.hit('decimal(p,s) -> numeric(p,s)')
  } else if (t === 'double') {
    pg = 'double precision'
    r.hit('double -> double precision')
  } else if (t === 'float') {
    pg = 'real'
    r.hit('float -> real')
  } else if (t === 'varchar') {
    pg = `varchar(${col.args})`
  } else if (t === 'char') {
    pg = `char(${col.args})`
  } else if (['text', 'tinytext', 'mediumtext', 'longtext'].includes(t)) {
    pg = 'text'
    if (t !== 'text') r.hit(`${t} -> text`)
  } else if (['blob', 'tinyblob', 'mediumblob', 'longblob', 'varbinary', 'binary'].includes(t)) {
    pg = 'bytea'
    r.hit(`${t} -> bytea`)
  } else if (t === 'json') {
    pg = 'jsonb'
    r.hit('json -> jsonb')
  } else if (t === 'timestamp') {
    pg = 'timestamptz' + (col.args ? `(${col.args})` : '')
    r.hit('timestamp -> timestamptz')
  } else if (t === 'datetime') {
    pg = 'timestamp' + (col.args ? `(${col.args})` : '')
    r.hit('datetime -> timestamp')
  } else if (t === 'date') {
    pg = 'date'
  } else if (t === 'time') {
    pg = 'time' + (col.args ? `(${col.args})` : '')
  } else if (t === 'year') {
    pg = 'smallint'
    r.hit('year -> smallint')
  } else if (t === 'enum') {
    pg = 'text'
    const values = readStringList(col.args)
    check = `${qi(col.name)} IN (${values.map(ql).join(', ')})`
    r.hit('enum -> text + CHECK')
  } else if (t === 'set') {
    pg = 'text'
    r.hit('set -> text (comma separated, as MySQL stores it)', fqn)
  } else if (['geometry', 'point', 'polygon', 'linestring', 'multipolygon', 'multipoint', 'geometrycollection'].includes(t)) {
    const kind = { geometry: 'Geometry', point: 'Point', polygon: 'Polygon', linestring: 'LineString', multipolygon: 'MultiPolygon', multipoint: 'MultiPoint', geometrycollection: 'GeometryCollection' }[t]
    pg = `geometry(${kind},4326)`
    if (col.srid !== null && col.srid !== 4326) r.hit(`geometry SRID ${col.srid} -> SRID 4326 (relabelled, not reprojected)`, fqn)
    else r.hit('geometry -> geometry(...,4326)', fqn)
  } else {
    fail(`unhandled column type ${t} on ${fqn}`)
  }

  if (/text|char/.test(pg) && !/^bytea/.test(pg)) {
    if (/_bin$/.test(col.collate || '')) {
      pg += ' COLLATE "C"'
      r.hit('binary collation (_bin) -> COLLATE "C"', fqn)
    } else if (col.collate && !/_ci$/.test(col.collate)) {
      fail(`unhandled collation ${col.collate} on ${fqn}`)
    }
  }
  return { pg, check }
}

function pgDefault(col, pg, table, ctx) {
  const d = col.default
  if (!d || d.kind === 'null') return null
  const r = ctx.report
  const fqn = `${table}.${col.name}`
  const numeric = /^(smallint|integer|bigint|numeric|double|real)/.test(pg)
  if (d.kind === 'now') {
    r.hit('DEFAULT CURRENT_TIMESTAMP')
    return 'CURRENT_TIMESTAMP' + (d.value.match(/\(\d+\)/) || [''])[0]
  }
  if (d.kind === 'number') return d.value
  if (d.kind === 'bit') fail(`bit default on ${fqn}`)
  if (d.kind === 'string') {
    if (numeric) {
      if (!/^-?\d+(\.\d+)?$/.test(d.value)) fail(`non-numeric default ${d.value} on numeric column ${fqn}`)
      r.hit("numeric DEFAULT '1' -> DEFAULT 1")
      return d.value
    }
    if (pg.startsWith('timestamp') || pg === 'date') {
      if (/^0000-00-00/.test(d.value)) {
        if (pg !== 'timestamptz') fail(`zero date default on ${fqn}`)
        r.hit('zero-date DEFAULT -> epoch', fqn)
        return "'1970-01-01 00:00:00+00'"
      }
      return ql(d.value)
    }
    if (pg === 'jsonb') return ql(d.value) + '::jsonb'
    return ql(d.value)
  }
  if (d.kind === 'expr') {
    const e = d.value.trim()
    const known = {
      'json_array()': "'[]'::jsonb",
      'json_object()': "'{}'::jsonb",
      'uuid()': 'gen_random_uuid()::text',
      'curdate()': 'CURRENT_DATE',
      'now()': 'CURRENT_TIMESTAMP',
    }
    if (e.toLowerCase() in known) {
      r.hit('expression DEFAULT (...) translated', `${e} -> ${known[e.toLowerCase()]}`)
      return known[e.toLowerCase()]
    }
    const s = e.match(/^_utf8mb4\\?'(.*)\\?'$/)
    if (s) {
      r.hit('expression DEFAULT (...) translated', e)
      return ql(s[1])
    }
    return fail(`unhandled expression default (${e}) on ${fqn}`)
  }
  return fail(`unhandled default on ${fqn}`)
}

// Translates a generated-column expression. Only a short list of functions is known; anything
// else stops the conversion so a human decides.
const EXPR_FUNCTIONS = new Set(['md5', 'lower', 'upper', 'coalesce'])
const EXPR_WORDS = new Set(['in', 'and', 'or', 'not', 'is', 'like', 'between', 'null', 'true', 'false'])

function splitTopLevel(s) {
  const parts = []
  let depth = 0
  let cur = ''
  for (let i = 0; i < s.length; i++) {
    const c = s[i]
    if (c === "'") {
      const j = readString(s, i)[1]
      cur += s.slice(i, j)
      i = j - 1
      continue
    }
    if (c === '(') depth++
    if (c === ')') depth--
    if (c === ',' && depth === 0) {
      parts.push(cur.trim())
      cur = ''
    } else cur += c
  }
  parts.push(cur.trim())
  return parts
}

export function translateExpr(s, fqn) {
  let out = ''
  let i = 0
  while (i < s.length) {
    const c = s[i]
    if (s.startsWith("_utf8mb4'", i)) {
      i += 8
    } else if (c === "'") {
      const [v, j] = readString(s, i)
      out += ql(v)
      i = j
    } else if (c === '`') {
      const j = s.indexOf('`', i + 1)
      out += qi(s.slice(i + 1, j))
      i = j + 1
    } else if (/[a-z_]/i.test(c)) {
      const w = s.slice(i).match(/^[a-z_][a-z0-9_]*/i)[0]
      i += w.length
      const word = w.toLowerCase()
      if (!EXPR_WORDS.has(word) && /^\s*\(/.test(s.slice(i))) {
        const open = i + s.slice(i).indexOf('(')
        const [inner, j] = readParens(s, open)
        const args = splitTopLevel(inner)
        const t = args.map((a) => translateExpr(a, fqn))
        if (word === 'if' && args.length === 3) out += `(CASE WHEN ${t[0]} THEN ${t[1]} ELSE ${t[2]} END)`
        else if (word === 'ifnull' && args.length === 2) out += `coalesce(${t.join(', ')})`
        else if (EXPR_FUNCTIONS.has(word)) out += `${word}(${t.join(', ')})`
        else fail(`generated column ${fqn} uses ${word}(), which is not translated`)
        i = j
      } else {
        out += w
      }
    } else {
      out += c
      i++
    }
  }
  return out
}

function pgExpr(expr, fqn, ctx) {
  ctx.report.hit('generated column expression translated', fqn)
  return translateExpr(expr, fqn)
}

// --- PostgreSQL-only additions from config.json ------------------------------------------------
//
// The MySQL schema is the source of truth for tables, columns and keys. These three config sections
// add what only PostgreSQL needs, and each entry must say why:
//   extraIndexes      an index MySQL does not have or does not need (an expression index for prefix
//                     LIKE, a partial index, a key that ends in the id as every InnoDB key does)
//   tableStorage      storage parameters such as fillfactor
//   columnStatistics  a per-column statistics target or distinct-value override for the planner
//   narrowedIndexes   a converted index that keeps only its leading columns, because a later column is
//                     updated so often that indexing it costs more than it saves
// Nothing here changes what a statement returns, only how fast PostgreSQL finds it.

// A section of the baseline, left out altogether when it has no lines.
function section(title, lines) {
  return lines.length ? [title, lines.join('\n'), ''] : []
}

function configList(config, key) {
  const list = config[key] === undefined ? [] : config[key]
  if (!Array.isArray(list)) fail(`config.${key} must be a list`)
  return list
}

function checkKeys(entry, allowed, where) {
  if (entry === null || typeof entry !== 'object' || Array.isArray(entry)) fail(`${where} must be an object`)
  for (const k of Object.keys(entry)) if (!allowed.includes(k)) fail(`${where} has an unknown setting "${k}" (allowed: ${allowed.join(', ')})`)
}

// The reason is written into the baseline as a comment, so it is one line of plain text.
function checkWhy(entry, where) {
  if (typeof entry.why !== 'string' || !entry.why.trim()) fail(`${where} needs a "why": the reason PostgreSQL needs it`)
  return entry.why.trim().replace(/\s+/g, ' ')
}

function checkTable(name, tableByName, where) {
  const t = typeof name === 'string' ? tableByName.get(name) : null
  if (!t) fail(`${where} names table ${name}, which is not in the schema`)
  return t
}

function checkColumns(list, t, where, what) {
  if (!Array.isArray(list) || !list.length || list.some((c) => typeof c !== 'string')) fail(`${where}: "${what}" must be a list of column names`)
  for (const c of list) if (!t.columns.some((x) => x.name === c)) fail(`${where}: ${t.name} has no column ${c}`)
  if (new Set(list).size !== list.length) fail(`${where}: "${what}" names a column twice`)
  return list
}

// Text that goes into the baseline as written (an index expression or a WHERE clause). It must be a
// single fragment: nothing that could end the statement or start a comment.
function checkFragment(text, where, what) {
  if (typeof text !== 'string' || !text.trim()) fail(`${where}: "${what}" must be SQL text`)
  if (/;|--|\/\*|\n/.test(text)) fail(`${where}: "${what}" must be one SQL fragment, with no semicolon, comment or line break`)
  return text.trim()
}

// A KEY whose columns are exactly those of the primary key, a unique key or an earlier KEY on the same
// table. MySQL schemas collect these over the years (users has PRIMARY KEY (id) and UNIQUE KEY id (id)).
// On PostgreSQL every index is written on every insert, and on every update that is not HOT, so a second
// copy costs writes and disk and can never be the better choice. Uniqueness is kept: the primary key
// wins, then a unique key, then the first plain one. A prefix length is dropped in conversion, so
// KEY (name(10)) and KEY (name) are the same index here. Spatial keys are left alone.
function duplicateKeys(t) {
  const sig = (k) => k.parts.map((p) => p.col + (p.desc ? ' DESC' : '')).join(',')
  const seen = new Map()
  const skip = new Map()
  const pk = t.keys.find((k) => k.kind === 'primary')
  if (pk) seen.set(sig(pk), 'PRIMARY KEY')
  for (const kind of ['unique', 'plain']) {
    for (const k of t.keys) {
      if (k.kind !== kind) continue
      const s = sig(k)
      if (seen.has(s)) skip.set(k, seen.get(s))
      else seen.set(s, k.name)
    }
  }
  return skip
}

// config.narrowedIndexes by converted index name, each checked as it is used.
function narrowedIndexes(config) {
  const byName = new Map()
  configList(config, 'narrowedIndexes').forEach((e, i) => {
    const where = `config.narrowedIndexes[${i}]${e && typeof e.index === 'string' ? ' (' + e.index + ')' : ''}`
    checkKeys(e, ['index', 'columns', 'why'], where)
    checkWhy(e, where)
    if (typeof e.index !== 'string' || !e.index) fail(`${where}: "index" must be the PostgreSQL name of a converted index`)
    if (byName.has(e.index)) fail(`${where}: ${e.index} is narrowed twice`)
    if (!Array.isArray(e.columns) || !e.columns.length || e.columns.some((c) => typeof c !== 'string')) fail(`${where}: "columns" must be a list of column names`)
    byName.set(e.index, { columns: e.columns, where, used: false })
  })
  return byName
}

function extraIndexes(config, { tableByName, usedIndexNames, convertedIndexes, ddlIndexes, report }) {
  const out = []
  const allowed = ['table', 'name', 'columns', 'expression', 'where', 'include', 'uses', 'replaces', 'why']
  configList(config, 'extraIndexes').forEach((e, i) => {
    const where = `config.extraIndexes[${i}]${e && typeof e.name === 'string' ? ' (' + e.name + ')' : ''}`
    if (e && typeof e === 'object' && 'unique' in e) fail(`${where}: a PostgreSQL-only index cannot be unique, because uniqueness would then differ between the engines; add the key in a MySQL migration`)
    checkKeys(e, allowed, where)
    const t = checkTable(e.table, tableByName, where)
    const why = checkWhy(e, where)

    if (typeof e.name !== 'string' || !/^[a-z_][a-z0-9_]*$/.test(e.name) || RESERVED.has(e.name)) fail(`${where}: "name" must be a plain lower-case identifier`)
    if (e.name.length > MAX_IDENT) fail(`${where}: the name is longer than ${MAX_IDENT} characters`)
    if (!e.name.startsWith(t.name + '_')) fail(`${where}: the name must start with the table name, as every converted index does (${t.name}_...)`)
    if (usedIndexNames.has(e.name)) fail(`${where}: the name is already used by ${usedIndexNames.get(e.name)}`)
    if (tableByName.has(e.name.replace(/_pkey$/, '')) && e.name.endsWith('_pkey')) fail(`${where}: the name is the one PostgreSQL gives a primary key`)
    usedIndexNames.set(e.name, `config.extraIndexes[${i}]`)

    if ((e.columns === undefined) === (e.expression === undefined)) fail(`${where}: give either "columns" or "expression", not both and not neither`)
    let keySql
    if (e.columns !== undefined) keySql = checkColumns(e.columns, t, where, 'columns').map(qi).join(', ')
    else keySql = checkFragment(e.expression, where, 'expression')
    let sql = `CREATE INDEX ${qi(e.name)} ON ${qi(t.name)} (${keySql})`

    if (e.include !== undefined) {
      const inc = checkColumns(e.include, t, where, 'include')
      if (e.columns && inc.some((c) => e.columns.includes(c))) fail(`${where}: "include" repeats a key column`)
      sql += ` INCLUDE (${inc.map(qi).join(', ')})`
    }
    let predicate = null
    if (e.where !== undefined) {
      predicate = checkFragment(e.where, where, 'where')
      sql += ` WHERE ${predicate}`
    }

    // An expression or a WHERE clause is copied as written, so the entry lists the columns it reads
    // and each is checked against the schema. A column that is renamed or dropped in MySQL then stops
    // the conversion here, not when the baseline is loaded.
    const verbatim = [e.expression, predicate].filter((x) => typeof x === 'string').join(' ')
    if (verbatim) {
      const uses = checkColumns(e.uses, t, where, 'uses')
      for (const c of uses) {
        const quoted = c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
        if (!new RegExp(`(^|[^a-z0-9_])"?${quoted}"?([^a-z0-9_]|$)`, 'i').test(verbatim)) fail(`${where}: "uses" lists ${c}, which the expression and where clause do not mention`)
      }
    } else if (e.uses !== undefined) {
      fail(`${where}: "uses" is only for an expression or a where clause`)
    }

    if (e.replaces !== undefined) {
      // The replaced index must be one the new index can stand in for everywhere: same table, not
      // unique or spatial, and its columns are the leading columns of the new one in the same order.
      const old = convertedIndexes.get(e.replaces)
      if (!old) fail(`${where}: "replaces" names ${e.replaces}, which is not a converted index`)
      if (old.table !== t.name) fail(`${where}: ${e.replaces} is on ${old.table}, not ${t.name}`)
      if (old.kind !== 'plain') fail(`${where}: ${e.replaces} is a ${old.kind} index and cannot be replaced`)
      if (old.at === null) fail(`${where}: ${e.replaces} is already replaced`)
      if (!e.columns || predicate) fail(`${where}: only a plain column index over every row can replace another index`)
      const oldCols = old.parts.map((p) => p.col)
      if (old.parts.some((p) => p.desc) || oldCols.some((c, j) => e.columns[j] !== c)) {
        fail(`${where}: ${e.replaces} is on (${oldCols.join(', ')}), which are not the leading columns of the new index`)
      }
      ddlIndexes[old.at] = null
      old.at = null
      report.hit('KEY replaced by a wider PostgreSQL-only index', `${e.replaces} -> ${e.name}`)
    }

    out.push(`-- ${why}\n${sql};`)
    report.hit('PostgreSQL-only index added', e.name)
  })
  return out
}

// Storage parameters that may be set, and the values each accepts.
const STORAGE_PARAMETERS = {
  fillfactor: (v) => Number.isInteger(v) && v >= 10 && v <= 100,
  autovacuum_vacuum_scale_factor: (v) => typeof v === 'number' && v >= 0 && v <= 1,
  autovacuum_vacuum_insert_scale_factor: (v) => typeof v === 'number' && v >= 0 && v <= 1,
  autovacuum_analyze_scale_factor: (v) => typeof v === 'number' && v >= 0 && v <= 1,
  autovacuum_vacuum_threshold: (v) => Number.isInteger(v) && v >= 0,
  autovacuum_vacuum_insert_threshold: (v) => Number.isInteger(v) && v >= 0,
  autovacuum_analyze_threshold: (v) => Number.isInteger(v) && v >= 0,
}

function tableStorage(config, { tableByName, report }) {
  const out = []
  const seen = new Set()
  configList(config, 'tableStorage').forEach((e, i) => {
    const where = `config.tableStorage[${i}]`
    checkKeys(e, ['table', 'set', 'why'], where)
    const t = checkTable(e.table, tableByName, where)
    const why = checkWhy(e, where)
    if (seen.has(t.name)) fail(`${where}: ${t.name} is listed twice`)
    seen.add(t.name)
    if (e.set === null || typeof e.set !== 'object' || Array.isArray(e.set) || !Object.keys(e.set).length) fail(`${where}: "set" must name at least one storage parameter`)
    const parts = Object.keys(e.set).map((k) => {
      if (!Object.hasOwn(STORAGE_PARAMETERS, k)) fail(`${where}: ${k} is not a storage parameter the converter knows (${Object.keys(STORAGE_PARAMETERS).join(', ')})`)
      if (!STORAGE_PARAMETERS[k](e.set[k])) fail(`${where}: ${JSON.stringify(e.set[k])} is not a valid value for ${k}`)
      return `${k} = ${e.set[k]}`
    })
    out.push(`-- ${why}\nALTER TABLE ${qi(t.name)} SET (${parts.join(', ')});`)
    report.hit('table storage parameters set', t.name)
  })
  return out
}

function columnStatistics(config, { tableByName, report }) {
  const out = []
  const seen = new Set()
  configList(config, 'columnStatistics').forEach((e, i) => {
    const where = `config.columnStatistics[${i}]`
    checkKeys(e, ['column', 'target', 'nDistinct', 'why'], where)
    const [tn, cn, more] = typeof e.column === 'string' ? e.column.split('.') : []
    if (!tn || !cn || more !== undefined) fail(`${where}: "column" must be written table.column`)
    const t = checkTable(tn, tableByName, where)
    checkColumns([cn], t, where, 'column')
    const why = checkWhy(e, where)
    if (seen.has(e.column)) fail(`${where}: ${e.column} is listed twice`)
    seen.add(e.column)
    if (e.target === undefined && e.nDistinct === undefined) fail(`${where}: give a "target", an "nDistinct", or both`)
    const lines = []
    if (e.target !== undefined) {
      // 10000 is the largest target PostgreSQL accepts.
      if (!Number.isInteger(e.target) || e.target < 1 || e.target > 10000) fail(`${where}: "target" must be a whole number from 1 to 10000`)
      lines.push(`ALTER TABLE ${qi(tn)} ALTER COLUMN ${qi(cn)} SET STATISTICS ${e.target};`)
    }
    if (e.nDistinct !== undefined) {
      // A positive value is a count of distinct values; a negative one, down to -1, is that share of the rows.
      if (typeof e.nDistinct !== 'number' || !Number.isFinite(e.nDistinct) || e.nDistinct === 0 || e.nDistinct < -1) fail(`${where}: "nDistinct" must be a count above 0, or a share of the rows from -1 to below 0`)
      lines.push(`ALTER TABLE ${qi(tn)} ALTER COLUMN ${qi(cn)} SET (n_distinct = ${e.nDistinct});`)
    }
    out.push(`-- ${why}\n${lines.join('\n')}`)
    report.hit('column statistics setting', e.column)
  })
  return out
}

// --- baseline writer --------------------------------------------------------------------------

export function convert(dumpText, config = {}, opts = {}) {
  const ctx = { report: new Report(), config }
  const r = ctx.report
  const { tables, routines } = parseDump(dumpText)
  if (!tables.length) fail('no tables found in the dump')

  const citext = new Set(config.citext || [])
  const csPatterns = Object.keys(config.caseSensitive || {}).map((p) => {
    const [tp, cp] = p.split('.')
    const rx = (x) => new RegExp('^' + x.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$')
    return [rx(tp), rx(cp)]
  })
  const isCaseSensitive = (t, c) => csPatterns.some(([tr, cr]) => tr.test(t) && cr.test(c))
  const tableByName = new Map(tables.map((t) => [t.name, t]))
  const colByName = (t, n) => {
    const c = t.columns.find((x) => x.name === n)
    if (!c) fail(`${t.name} has no column ${n}`)
    return c
  }

  // Case-insensitive columns. MySQL's default collation compares text case-insensitively, in
  // unique keys as well. PostgreSQL does not, so a column the code relies on is citext. A text
  // column in a unique key that is neither listed as citext nor as deliberately case sensitive
  // is an error: the audit has to decide it.
  const isText = (c) => ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'].includes(c.type)
  for (const name of citext) {
    const [tn, cn] = name.split('.')
    const t = tableByName.get(tn)
    if (!t) fail(`config.citext names ${name}, but there is no table ${tn}`)
    const c = colByName(t, cn)
    if (!isText(c)) fail(`config.citext names ${name}, which is not a text column`)
    if (/_bin$/.test(c.collate || '')) fail(`config.citext names ${name}, which is a binary collation`)
  }
  const unaudited = []
  for (const t of tables) {
    for (const k of t.keys) {
      if (!['primary', 'unique'].includes(k.kind)) continue
      for (const p of k.parts) {
        const c = colByName(t, p.col)
        const id = `${t.name}.${c.name}`
        if (isText(c) && !/_bin$/.test(c.collate || '') && !citext.has(id) && !isCaseSensitive(t.name, c.name)) {
          unaudited.push(`${id} (${k.kind} key)`)
        }
      }
    }
  }
  if (unaudited.length) {
    fail(`text columns in a unique key that config.json has not decided (add each to citext or caseSensitive, with a reason): ${[...new Set(unaudited)].join(', ')}`)
  }
  // A foreign key column takes the type of the column it references.
  const citextFinal = new Set(citext)
  for (let changed = true; changed; ) {
    changed = false
    for (const t of tables) {
      for (const k of t.keys.filter((x) => x.kind === 'fk')) {
        k.cols.forEach((cn, i) => {
          const parent = tableByName.get(k.refTable)
          if (!parent) fail(`${t.name}.${k.name} references missing table ${k.refTable}`)
          const pid = `${k.refTable}.${k.refCols[i]}`
          const cid = `${t.name}.${cn}`
          if (citextFinal.has(pid) && !citextFinal.has(cid)) {
            citextFinal.add(cid)
            r.hit('foreign key column follows a citext parent', cid)
            changed = true
          }
        })
      }
    }
  }

  const out = []
  const ddlIndexes = []
  const ddlFks = []
  const ddlTriggers = []
  const ddlComments = []
  const usedIndexNames = new Map()
  // Each converted index by its PostgreSQL name: where its statement sits in ddlIndexes, and what it
  // covers. config.extraIndexes may replace a plain one with a wider index.
  const convertedIndexes = new Map()
  const narrowed = narrowedIndexes(config)

  for (const t of tables) {
    const lines = []
    const colSql = []
    const checks = []
    const onUpdate = []
    if (t.columns.filter((c) => c.auto).length > 1) fail(`${t.name} has more than one AUTO_INCREMENT column`)
    for (const c of t.columns) {
      const fqn = `${t.name}.${c.name}`
      let { pg, check } = pgType(c, t.name, ctx)
      if (citextFinal.has(fqn)) {
        // citext has no length, so a varchar(n) or char(n) keeps its limit as a CHECK.
        const len = pg.match(/^(?:varchar|char)\((\d+)\)/)
        if (len) {
          checks.push(`CONSTRAINT ${qi(fit(`${t.name}_${c.name}_length`))} CHECK (char_length(${qi(c.name)}) <= ${len[1]})`)
          r.hit('citext column keeps its varchar(n) limit as a length CHECK', fqn)
        }
        pg = pg.replace(/^(varchar\(\d+\)|char\(\d+\)|text)/, 'citext')
        r.hit('case-insensitive text column -> citext', fqn)
      }
      let sql = `${qi(c.name)} ${pg}`
      if (c.generated) {
        let expr = pgExpr(c.generated.expr, fqn, ctx)
        if (c.type === 'tinyint') expr = `(${expr})::int`
        sql += ` GENERATED ALWAYS AS (${expr}) STORED`
        if (c.generated.mode === 'VIRTUAL') r.hit('VIRTUAL generated column -> STORED', fqn)
      } else if (c.auto) {
        sql += ' GENERATED BY DEFAULT AS IDENTITY'
        r.hit('AUTO_INCREMENT -> GENERATED BY DEFAULT AS IDENTITY')
      } else {
        const d = pgDefault(c, pg, t.name, ctx)
        if (d !== null) sql += ` DEFAULT ${d}`
      }
      if (c.notNull && !c.generated) sql += ' NOT NULL'
      colSql.push(sql)
      if (check) {
        checks.push(`CONSTRAINT ${qi(fit(`${t.name}_${c.name}_check`))} CHECK (${check})`)
      }
      if (c.onUpdate) {
        if (!/^(timestamp|datetime)$/.test(c.type)) fail(`ON UPDATE on a ${c.type} column ${fqn}`)
        onUpdate.push(c.name)
      }
      if (c.comment) ddlComments.push(`COMMENT ON COLUMN ${qi(t.name)}.${qi(c.name)} IS ${ql(c.comment)};`)
    }
    lines.push(...colSql)
    lines.push(...checks)

    const pk = t.keys.find((k) => k.kind === 'primary')
    if (pk) {
      if (pk.parts.some((p) => p.prefix)) fail(`prefix primary key on ${t.name}`)
      lines.push(`PRIMARY KEY (${pk.parts.map((p) => qi(p.col)).join(', ')})`)
    }
    out.push(`CREATE TABLE ${qi(t.name)} (\n  ${lines.join(',\n  ')}\n);`)
    if (t.comment) ddlComments.push(`COMMENT ON TABLE ${qi(t.name)} IS ${ql(t.comment)};`)

    const duplicates = duplicateKeys(t)
    for (const k of t.keys) {
      if (k.kind === 'primary') continue
      if (duplicates.has(k)) {
        r.hit('duplicate KEY left out (same columns as another key on the table)', `${t.name}.${k.name} = ${duplicates.get(k)}`)
        continue
      }
      if (k.kind === 'fk') {
        let s = `ALTER TABLE ${qi(t.name)} ADD CONSTRAINT ${qi(fit(k.name))} FOREIGN KEY (${k.cols.map(qi).join(', ')}) REFERENCES ${qi(k.refTable)} (${k.refCols.map(qi).join(', ')})`
        if (k.onDelete) s += ` ON DELETE ${k.onDelete}`
        if (k.onUpdate) s += ` ON UPDATE ${k.onUpdate}`
        ddlFks.push(s + ';')
        r.hit('FOREIGN KEY added after all tables' + (k.onDelete ? ` (ON DELETE ${k.onDelete})` : ''))
        continue
      }
      const iname = fit(`${t.name}_${k.name}`)
      if (usedIndexNames.has(iname)) fail(`index name ${iname} produced twice (${usedIndexNames.get(iname)} and ${t.name}.${k.name})`)
      usedIndexNames.set(iname, `${t.name}.${k.name}`)
      const narrow = narrowed.get(iname)
      if (narrow) {
        narrow.used = true
        if (k.kind !== 'plain') fail(`${narrow.where}: ${iname} is a ${k.kind} index and cannot be narrowed`)
        const have = k.parts.map((p) => p.col)
        if (narrow.columns.length >= have.length || narrow.columns.some((c, i) => have[i] !== c)) {
          fail(`${narrow.where}: the columns must be the leading columns of ${iname} (${have.join(', ')}), with at least one left off`)
        }
        k.parts = k.parts.slice(0, narrow.columns.length)
        const twin = t.keys.find((o) => o !== k && !duplicates.has(o) && ['primary', 'unique', 'plain'].includes(o.kind) &&
          o.parts.length === k.parts.length && o.parts.every((p, i) => p.col === k.parts[i].col && !!p.desc === !!k.parts[i].desc))
        if (twin) fail(`${narrow.where}: narrowed to (${narrow.columns.join(', ')}), ${iname} would repeat ${twin.kind === 'primary' ? 'the primary key' : 'key ' + twin.name} on ${t.name}; leave it out instead`)
        r.hit('KEY narrowed to its leading columns on PostgreSQL', `${iname} (${narrow.columns.join(', ')})`)
      }
      const parts = k.parts.map((p) => {
        if (p.prefix) {
          if (k.kind === 'unique') fail(`unique key with a prefix on ${t.name}.${k.name}: the uniqueness would change`)
          r.hit('key prefix length dropped', `${t.name}.${k.name}`)
        }
        return qi(p.col) + (p.desc ? ' DESC' : '')
      })
      convertedIndexes.set(iname, { table: t.name, kind: k.kind, parts: k.parts, at: ddlIndexes.length })
      if (k.kind === 'spatial') {
        ddlIndexes.push(`CREATE INDEX ${qi(iname)} ON ${qi(t.name)} USING gist (${parts.join(', ')});`)
        r.hit('SPATIAL KEY -> GiST index')
      } else if (k.kind === 'unique') {
        ddlIndexes.push(`CREATE UNIQUE INDEX ${qi(iname)} ON ${qi(t.name)} (${parts.join(', ')});`)
        r.hit('UNIQUE KEY -> CREATE UNIQUE INDEX (name prefixed with the table)')
      } else {
        ddlIndexes.push(`CREATE INDEX ${qi(iname)} ON ${qi(t.name)} (${parts.join(', ')});`)
        r.hit('KEY -> CREATE INDEX (name prefixed with the table)')
      }
    }

    if (onUpdate.length) {
      // The statement triggers keep their note in a setting named after the table, and a setting's name
      // must be a plain identifier that the row function can spell the same way.
      if (!/^[a-z_][a-z0-9_]*$/.test(t.name) || `iznik.named_${t.name}`.length > MAX_IDENT) {
        fail(`ON UPDATE CURRENT_TIMESTAMP on ${t.name}: the table name cannot be used in a setting name`)
      }
      const fn = fit(`iznik_on_update_${t.name}`)
      // The statement triggers below record which of these columns the UPDATE names in its SET list, in a
      // setting that lasts for the statement. MySQL leaves a column the statement names alone, whatever
      // value it is given, and a row trigger alone cannot tell "SET ts = ts" from not naming the column.
      const named = `coalesce(current_setting(${ql('iznik.named_' + t.name)}, true), '')`
      const sets = onUpdate
        .map(
          (cn) =>
            `    IF NEW.${qi(cn)} IS NOT DISTINCT FROM OLD.${qi(cn)} AND position(${ql(',' + cn + ',')} in ${named}) = 0 THEN NEW.${qi(cn)} := CURRENT_TIMESTAMP; END IF;`
        )
        .join('\n')
      // Generated columns are left out: they are not yet computed in NEW when a BEFORE trigger runs.
      const stored = t.columns.filter((c) => !c.generated).map((c) => qi(c.name))
      const newRow = `ROW(${stored.map((n) => 'NEW.' + n).join(', ')})`
      const oldRow = `ROW(${stored.map((n) => 'OLD.' + n).join(', ')})`
      if (!ddlTriggers.length) ddlTriggers.push(ON_UPDATE_NAMED_FUNCTIONS)
      ddlTriggers.push(
        `CREATE FUNCTION ${fn}() RETURNS trigger LANGUAGE plpgsql AS $$\nBEGIN\n  IF ${newRow} IS DISTINCT FROM ${oldRow} THEN\n${sets}\n  END IF;\n  RETURN NEW;\nEND\n$$;\n` +
          `CREATE TRIGGER ${fit(`${t.name}_on_update`)} BEFORE UPDATE ON ${qi(t.name)} FOR EACH ROW EXECUTE FUNCTION ${fn}();\n` +
          onUpdate
            .map(
              (cn) =>
                `CREATE TRIGGER ${fit(`${t.name}_on_update_named_${cn}`)} BEFORE UPDATE OF ${qi(cn)} ON ${qi(t.name)} FOR EACH STATEMENT EXECUTE FUNCTION iznik_on_update_named(${ql(cn)});\n`
            )
            .join('') +
          `CREATE TRIGGER ${fit(`${t.name}_on_update_done`)} AFTER UPDATE ON ${qi(t.name)} FOR EACH STATEMENT EXECUTE FUNCTION iznik_on_update_done();`
      )
      r.hit('ON UPDATE CURRENT_TIMESTAMP -> BEFORE UPDATE trigger', t.name)
    }
  }

  for (const [name, n] of narrowed) if (!n.used) fail(`${n.where}: ${name} is not a converted index`)
  const ddlExtraIndexes = extraIndexes(config, { tableByName, usedIndexNames, convertedIndexes, ddlIndexes, report: r })
  const ddlStorage = tableStorage(config, { tableByName, report: r })
  const ddlStatistics = columnStatistics(config, { tableByName, report: r })

  r.hit('table options ENGINE/CHARSET/COLLATE/AUTO_INCREMENT/ROW_FORMAT dropped')
  if (ddlComments.length) r.hit('table and column COMMENT -> COMMENT ON', String(ddlComments.length) + ' comments')
  if (routines.length) r.note(`skipped ${routines.length} stored routines (iznik-batch/database/postgres/functions.sql defines their PostgreSQL versions): ${routines.map((x) => x.name).join(', ')}`)

  const migrationInserts = []
  if (opts.migrations && opts.migrations.length) {
    for (const m of opts.migrations) migrationInserts.push(`  (${ql(m)}, 1)`)
    r.note(`recorded ${opts.migrations.length} existing migrations as run (batch 1)`)
  }

  const header = [
    '-- PostgreSQL baseline generated by tools/pg-schema/convert.mjs from the migrated MySQL schema.',
    '-- Do not edit by hand: change the MySQL migrations or the converter and regenerate.',
    '-- See docs/developers/reference/database-engines.md for every translation and its reason.',
    '',
  ]
  const body = [
    'CREATE EXTENSION IF NOT EXISTS postgis;',
    'CREATE EXTENSION IF NOT EXISTS citext;',
    'CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;',
    '',
    out.join('\n\n'),
    '',
    '-- Indexes',
    ddlIndexes.filter((x) => x !== null).join('\n'),
    '',
    ...section('-- PostgreSQL-only indexes (config.json extraIndexes). MySQL does not have these, or does not need them.', ddlExtraIndexes),
    ...section('-- Table storage settings (config.json tableStorage)', ddlStorage),
    ...section('-- Planner statistics settings (config.json columnStatistics)', ddlStatistics),
    '-- Foreign keys',
    ddlFks.join('\n'),
    '',
    '-- ON UPDATE CURRENT_TIMESTAMP columns',
    ddlTriggers.join('\n\n'),
    '',
    '-- Comments',
    ddlComments.join('\n'),
    '',
    RESET_IDENTITIES,
  ]
  if (migrationInserts.length) {
    body.push('', '-- Migrations already applied by this baseline', 'INSERT INTO migrations (migration, batch) VALUES', migrationInserts.join(',\n') + ';')
  }
  return { sql: header.concat(body).join('\n') + '\n', report: r, tables }
}

// Identity columns do not advance when a row is inserted with an explicit id, which is how the
// fixtures load. This moves every identity sequence past the largest id present.
const RESET_IDENTITIES = `-- Moves each identity sequence past the largest value in its column. Run after loading rows
-- that carry explicit ids.
CREATE FUNCTION iznik_reset_identities() RETURNS void LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT c.relname AS tbl, a.attname AS col, pg_get_serial_sequence(quote_ident(c.relname), a.attname) AS seq
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
    JOIN pg_attribute a ON a.attrelid = c.oid AND a.attidentity <> '' AND NOT a.attisdropped
    WHERE c.relkind = 'r'
  LOOP
    EXECUTE format('SELECT setval(%L, GREATEST(COALESCE((SELECT max(%I) FROM %I), 0), 1), (SELECT max(%I) FROM %I) IS NOT NULL)',
                   r.seq, r.col, r.tbl, r.col, r.tbl);
  END LOOP;
END
$$;`

// --- command line -----------------------------------------------------------------------------

function main(argv) {
  const args = argv.slice(2)
  const positional = []
  let configPath = path.join(path.dirname(fileURLToPath(import.meta.url)), 'config.json')
  let migrationsDir = null
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--config') configPath = args[++i]
    else if (args[i] === '--migrations-dir') migrationsDir = args[++i]
    else positional.push(args[i])
  }
  if (!positional.length) {
    console.error('usage: convert.mjs <dump.sql> [baseline.sql] [--config config.json] [--migrations-dir dir]')
    process.exit(2)
  }
  const config = JSON.parse(fs.readFileSync(configPath, 'utf8'))
  let migrations = null
  if (migrationsDir) {
    migrations = fs
      .readdirSync(migrationsDir)
      .filter((f) => f.endsWith('.php'))
      .map((f) => f.replace(/\.php$/, ''))
      .sort()
  }
  try {
    const { sql, report } = convert(fs.readFileSync(positional[0], 'utf8'), config, { migrations })
    if (positional[1]) fs.writeFileSync(positional[1], sql)
    else process.stdout.write(sql)
    console.error(report.text())
  } catch (e) {
    if (e instanceof ConvertError) {
      console.error('convert: ' + e.message)
      process.exit(1)
    }
    throw e
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) main(process.argv)
