// Tests for the neutral fixtures: the files, the loader and the export. Needs no database.
//
//   node --test tools/fixtures/fixtures.test.mjs
import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  FixtureError,
  allTables,
  dialects,
  loadSql,
  mysql,
  parseTableText,
  pgsql,
  readRepairs,
  readSteps,
  readTable,
  stepSql,
  tableNames,
  tableSql,
  tableText,
  valueSql,
} from './lib.mjs'
import { exportTables, readInserts, writeTables } from './export.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const fixturesDir = path.join(here, '../../scripts/fixtures')
const baselinePath = path.join(here, '../../iznik-batch/database/postgres/baseline.sql')

const table = (lines, name = 'things') => parseTableText(lines.join('\n') + '\n', name)
const all = (it) => [...it].join('')

// --- the table format -------------------------------------------------------------------------

test('a table file is a header line then one JSON array per row', () => {
  const t = table(['{"table":"things","columns":["id","name","pic","pt"],"bin":["pic"],"geom":["pt"]}', '[1,"a",null,null]', '[2,"b","00ff","3857:0101"]'])
  assert.equal(t.table, 'things')
  assert.deepEqual(t.columns, ['id', 'name', 'pic', 'pt'])
  assert.equal(t.rows.length, 2)
  assert.ok(t.bin.has('pic') && t.geom.has('pt'))
})

test('a table file that does not match its header is refused, with the line named', () => {
  const head = '{"table":"things","columns":["id","pic"],"bin":["pic"]}'
  assert.throws(() => table([head, '[1]']), /line 2 does not have 2 values/)
  assert.throws(() => table([head, '[1,"xyz"]']), /pic is not a hex string/)
  assert.throws(() => table([head, '[1,{"a":1}]']), /pic is not a hex string/)
  assert.throws(() => table(['{"table":"other","columns":["id"]}', '[1]']), /names table other/)
  assert.throws(() => table(['{"table":"things","columns":["id"],"bin":["nope"]}']), /not a column/)
  assert.throws(() => table(['{"table":"things","columns":["id","g"],"geom":["g"]}', '[1,"0101"]']), /not <srid>:<hex>/)
  assert.throws(() => table(['{"table":"things","columns":["id"]}', '[{"now":0}]']), /not null, a number or a string/)
  assert.throws(() => table(['not json']), /header is not JSON/)
})

test('the text a table is written as is read back to the same table', () => {
  const t = { table: 'things', columns: ['id', 'name', 'pic', 'pt'], bin: ['pic'], geom: ['pt'], rows: [[1, 'it\'s a "quote"\n\\', '00ff', '3857:0101'], [2, null, null, null]] }
  const back = parseTableText(tableText(t), 'things')
  assert.deepEqual(back.rows, t.rows)
  assert.deepEqual([...back.bin], ['pic'])
  assert.deepEqual([...back.geom], ['pt'])
})

test('line and paragraph separators are written as escapes, so one row is one line', () => {
  const text = tableText({ table: 't', columns: ['a'], rows: [[String.fromCharCode(0x2028, 0x2029, 27)]] })
  assert.equal(text.split('\n').length, 3)
  assert.ok(!text.includes(String.fromCharCode(0x2028)))
  assert.deepEqual(parseTableText(text, 't').rows, [[String.fromCharCode(0x2028, 0x2029, 27)]])
})

// --- values and statements --------------------------------------------------------------------

test('MySQL text is escaped the way mysqldump escapes it', () => {
  assert.equal(mysql.str("a'b\\c\nd\re\0f\x1ag"), "'a\\'b\\\\c\\nd\\re\\0f\\Zg'")
  assert.equal(mysql.str('plain é ü 😀'), "'plain é ü 😀'")
})

test('PostgreSQL text doubles the quote, leaves the backslash alone, and refuses a NUL', () => {
  assert.equal(pgsql.str("a'b\\c"), "'a''b\\c'")
  assert.throws(() => pgsql.str('a\0b'), /NUL/)
})

test('a geometry reaches MySQL in its storage form and PostgreSQL labelled 4326', () => {
  const wkb = '010100000000000000000000000000000000000000'
  assert.equal(mysql.geom(`3857:${wkb}`), `X'110f0000${wkb}'`)
  assert.equal(mysql.geom(`0:${wkb}`), `X'00000000${wkb}'`)
  assert.equal(pgsql.geom(`3857:${wkb}`), `ST_MakeValid(ST_SetSRID(ST_GeomFromWKB(decode('${wkb}', 'hex')), 4326))`)
  assert.equal(mysql.bin('00ff'), "X'00ff'")
  assert.equal(pgsql.bin('00ff'), "decode('00ff', 'hex')")
})

test('a table is written as INSERT IGNORE on MySQL and ON CONFLICT DO NOTHING on PostgreSQL', () => {
  const t = table(['{"table":"things","columns":["id","name","pic","pt"],"bin":["pic"],"geom":["pt"]}', '[1,"a",null,null]', '[2,"b","00ff","3857:0101"]'])
  assert.equal(
    all(tableSql(mysql, t)),
    "INSERT IGNORE INTO `things` (`id`, `name`, `pic`, `pt`) VALUES (1,'a',NULL,NULL),\n(2,'b',X'00ff',X'110f00000101');\n"
  )
  assert.equal(
    all(tableSql(pgsql, t)),
    `INSERT INTO "things" ("id", "name", "pic", "pt") VALUES (1,'a',NULL,NULL),\n(2,'b',decode('00ff', 'hex'),ST_MakeValid(ST_SetSRID(ST_GeomFromWKB(decode('0101', 'hex')), 4326))) ON CONFLICT DO NOTHING;\n`
  )
})

test('rows are chunked by count and by size', () => {
  const rows = [1, 2, 3, 4, 5].map((n) => `[${n}]`)
  const t = table(['{"table":"t","columns":["id"]}', ...rows], 't')
  assert.equal([...tableSql(mysql, t, { batch: 2 })].length, 3)
  assert.equal([...tableSql(mysql, t, { batch: 1 })].length, 5)
  assert.equal([...tableSql(mysql, t, { maxChars: 6 })].length, 3)
  assert.equal([...tableSql(mysql, t)].length, 1)
})

test('an empty table writes nothing', () => {
  assert.equal([...tableSql(mysql, table(['{"table":"t","columns":["id"]}'], 't'))].length, 0)
})

test('a repair changes only the value it names, and is counted', () => {
  const t = table(['{"table":"things","columns":["id","kind"]}', '[1,""]', '[2,"x"]', '[3,null]'])
  const stats = {}
  const sql = all(tableSql(pgsql, t, { repairs: [{ column: 'kind', from: '', to: null }], stats }))
  assert.match(sql, /\(1,NULL\),\n\(2,'x'\),\n\(3,NULL\)/)
  assert.deepEqual(stats, { 'things.kind': 1 })
  assert.throws(() => all(tableSql(pgsql, t, { repairs: [{ column: 'nope', from: '', to: null }] })), /no column nope/)
})

test('times can be the moment of loading, moved by a whole unit', () => {
  assert.equal(valueSql(mysql, { now: 0 }), 'NOW()')
  assert.equal(valueSql(mysql, { now: { days: 14 } }), 'NOW() + INTERVAL 14 DAY')
  assert.equal(valueSql(pgsql, { now: 0 }), "date_trunc('second', now())")
  assert.equal(valueSql(pgsql, { now: { days: 14 } }), "date_trunc('second', now()) + interval '14 days'")
  assert.throws(() => valueSql(mysql, { now: { fortnights: 1 } }), /bad time offset/)
  assert.throws(() => valueSql(mysql, { later: 1 }), /unknown value/)
})

const upsert = {
  op: 'insert', table: 'config', mode: 'upsert', key: ['key'], update: ['value'], columns: ['key', 'value'], rows: [['a', 'b']],
}

test('an upsert is ON DUPLICATE KEY UPDATE on MySQL and ON CONFLICT on the named key on PostgreSQL', () => {
  assert.equal(stepSql(mysql, upsert), "INSERT INTO `config` (`key`, `value`) VALUES ('a', 'b') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);\n")
  assert.equal(stepSql(pgsql, upsert), `INSERT INTO "config" ("key", "value") VALUES ('a', 'b') ON CONFLICT ("key") DO UPDATE SET "value" = EXCLUDED."value";\n`)
})

test('a delete matches on every column named, and NULL as IS NULL', () => {
  const s = { op: 'delete', table: 't', where: { a: 'x', b: null } }
  assert.equal(stepSql(mysql, s), "DELETE FROM `t` WHERE `a` = 'x' AND `b` IS NULL;\n")
  assert.equal(stepSql(pgsql, s), `DELETE FROM "t" WHERE "a" = 'x' AND "b" IS NULL;\n`)
})

test('a row that looks something up, or runs only when nothing matches, is a guarded SELECT', () => {
  const uid = { lookup: { table: 'users_emails', column: 'userid', where: { email: 'a@example.invalid' } } }
  const s = {
    op: 'insert', table: 'users_logins', mode: 'plain', unless: { table: 'users_logins', where: { userid: uid, type: 'Native' } },
    columns: ['userid', 'uid'], rows: [[uid, { lookup: { ...uid.lookup, text: true } }]],
  }
  const lookup = "(SELECT `userid` FROM `users_emails` WHERE `email` = 'a@example.invalid')"
  assert.equal(
    stepSql(mysql, s),
    `INSERT INTO \`users_logins\` (\`userid\`, \`uid\`) SELECT ${lookup}, CAST(${lookup} AS CHAR) FROM DUAL WHERE ${lookup} IS NOT NULL AND CAST(${lookup} AS CHAR) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM \`users_logins\` WHERE \`userid\` = ${lookup} AND \`type\` = 'Native');\n`
  )
  const pgLookup = lookup.replace(/`/g, '"')
  assert.equal(
    stepSql(pgsql, s),
    `INSERT INTO "users_logins" ("userid", "uid") SELECT ${pgLookup}, CAST(${pgLookup} AS text) WHERE ${pgLookup} IS NOT NULL AND CAST(${pgLookup} AS text) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM "users_logins" WHERE "userid" = ${pgLookup} AND "type" = 'Native');\n`
  )
})

test('the id the last insert was given is LAST_INSERT_ID on MySQL and currval on PostgreSQL', () => {
  const s = { op: 'insert', table: 'users_emails', mode: 'plain', unless: { table: 'users_emails', where: { email: 'e' } }, columns: ['userid'], rows: [[{ lastId: 'users' }]] }
  assert.match(stepSql(mysql, s), /SELECT LAST_INSERT_ID\(\) FROM DUAL WHERE NOT EXISTS/)
  assert.match(stepSql(pgsql, s), /SELECT currval\(pg_get_serial_sequence\('"users"', 'id'\)\) WHERE NOT EXISTS/)
})

test('steps are checked when they are read', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fixtures-'))
  try {
    const write = (steps) => fs.writeFileSync(path.join(dir, 's.json'), JSON.stringify(steps))
    write([{ op: 'insert', table: 't', columns: ['a'], rows: [[1]], mode: 'nope' }])
    assert.throws(() => readSteps(dir, 's.json'), /mode must be one of/)
    write([{ op: 'insert', table: 't', columns: ['a'], rows: [[1, 2]], mode: 'ignore' }])
    assert.throws(() => readSteps(dir, 's.json'), /does not match the columns/)
    write([{ op: 'insert', table: 't', columns: ['a'], rows: [[1]], mode: 'upsert' }])
    assert.throws(() => readSteps(dir, 's.json'), /unique key/)
    write([{ op: 'truncate', table: 't' }])
    assert.throws(() => readSteps(dir, 's.json'), /unknown op/)
    assert.deepEqual(readSteps(dir, 'missing.json'), [])
  } finally {
    fs.rmSync(dir, { recursive: true })
  }
})

test('repairs must say why, and must name a table and column', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fixtures-'))
  try {
    const write = (r) => fs.writeFileSync(path.join(dir, 'repairs.json'), JSON.stringify({ pgsql: r }))
    write([{ table: 't', column: 'c', from: '', to: null }])
    assert.throws(() => readRepairs(dir, 'pgsql'), /needs a reason/)
    write([{ table: 't', column: 'c', from: '' , reason: 'x' }])
    assert.throws(() => readRepairs(dir, 'pgsql'), /needs from and to/)
    write([{ table: 't', column: 'c', from: '', to: null, reason: 'x' }])
    assert.equal(readRepairs(dir, 'pgsql').length, 1)
    assert.deepEqual(readRepairs(dir, 'mysql'), [])
  } finally {
    fs.rmSync(dir, { recursive: true })
  }
})

// --- a whole load, from a small fixtures directory --------------------------------------------

function smallDir() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fixtures-'))
  fs.mkdirSync(path.join(dir, 'tables'))
  fs.writeFileSync(path.join(dir, 'tables', 'a.jsonl'), '{"table":"a","columns":["id"]}\n[1]\n')
  fs.writeFileSync(path.join(dir, 'tables', 'b.jsonl'), '{"table":"b","columns":["id"]}\n[2]\n')
  fs.writeFileSync(path.join(dir, 'captured-steps.json'), JSON.stringify([{ op: 'delete', table: 'c', where: { id: 3 } }]))
  fs.writeFileSync(path.join(dir, 'extra-steps.json'), JSON.stringify([{ op: 'delete', table: 'd', where: { id: 4 } }]))
  return dir
}

test('a MySQL load sets the session as mysqldump does, and the steps follow the tables', () => {
  const dir = smallDir()
  try {
    const sql = all(loadSql('mysql', dir))
    const at = (s) => {
      const i = sql.indexOf(s)
      assert.ok(i >= 0, `missing ${s}`)
      return i
    }
    assert.ok(sql.startsWith('SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n'))
    assert.match(sql, /SQL_MODE='NO_AUTO_VALUE_ON_ZERO'/)
    assert.match(sql, /TIME_ZONE='\+00:00'/)
    assert.match(sql, /SET NAMES utf8mb4/)
    assert.ok(at('INSERT IGNORE INTO `a`') < at('INSERT IGNORE INTO `b`'))
    assert.ok(at('INSERT IGNORE INTO `b`') < at('SET TIME_ZONE=@OLD_TIME_ZONE'))
    assert.ok(at('SET TIME_ZONE=@OLD_TIME_ZONE') < at('DELETE FROM `c`'))
    assert.ok(at('DELETE FROM `c`') < at('SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;'))
    assert.ok(at('SET FOREIGN_KEY_CHECKS=1;') < at('DELETE FROM `d`'))
  } finally {
    fs.rmSync(dir, { recursive: true })
  }
})

test('a PostgreSQL load switches foreign keys off for the tables and moves identities before each step', () => {
  const dir = smallDir()
  try {
    const sql = all(loadSql('pgsql', dir))
    const at = (s, from = 0) => {
      const i = sql.indexOf(s, from)
      assert.ok(i >= 0, `missing ${s}`)
      return i
    }
    assert.ok(sql.startsWith("SET client_encoding = 'UTF8';"))
    assert.match(sql, /SET TIME ZONE 'UTC'/)
    const replica = at('SET session_replication_role = replica')
    const tables = at('INSERT INTO "b"')
    const reset1 = at('SELECT iznik_reset_identities();')
    const captured = at('DELETE FROM "c"')
    const reset2 = at('SELECT iznik_reset_identities();', reset1 + 1)
    const normal = at('SET session_replication_role = DEFAULT')
    const extra = at('DELETE FROM "d"')
    assert.ok(replica < tables && tables < reset1 && reset1 < captured && captured < reset2 && reset2 < normal && normal < extra)
    assert.ok(sql.trimEnd().endsWith('SELECT iznik_reset_identities();'))
  } finally {
    fs.rmSync(dir, { recursive: true })
  }
})

test('--tables loads only those tables and no steps; --part picks a section', () => {
  const dir = smallDir()
  try {
    const only = all(loadSql('mysql', dir, { tables: ['b'] }))
    assert.match(only, /`b`/)
    assert.doesNotMatch(only, /`a`|DELETE/)
    assert.throws(() => all(loadSql('mysql', dir, { tables: ['zzz'] })), /no fixture table zzz/)
    const extra = all(loadSql('mysql', dir, { part: 'extra' }))
    assert.match(extra, /DELETE FROM `d`/)
    assert.doesNotMatch(extra, /INSERT|`c`/)
    const tablesOnly = all(loadSql('pgsql', dir, { part: 'tables' }))
    assert.doesNotMatch(tablesOnly, /DELETE|iznik_reset_identities/)
    assert.deepEqual(allTables(dir), ['a', 'b', 'c', 'd'])
    assert.throws(() => loadSql('oracle', dir).next(), /unknown engine/)
  } finally {
    fs.rmSync(dir, { recursive: true })
  }
})

// --- the export from a mysqldump --------------------------------------------------------------

const SCHEMA = `CREATE TABLE \`things\` (
  \`id\` bigint unsigned NOT NULL AUTO_INCREMENT,
  \`name\` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  \`price\` decimal(10,2) DEFAULT NULL,
  \`big\` bigint DEFAULT NULL,
  \`ratio\` double DEFAULT NULL,
  \`pic\` blob,
  \`pt\` geometry DEFAULT NULL,
  \`area\` geometry NOT NULL /*!80003 SRID 3857 */,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
`

const DUMP = `-- a comment
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
SET FOREIGN_KEY_CHECKS=0;

LOCK TABLES \`things\` WRITE;
/*!40000 ALTER TABLE \`things\` DISABLE KEYS */;
INSERT  IGNORE INTO \`things\` (\`id\`, \`name\`, \`price\`, \`big\`, \`ratio\`, \`pic\`, \`pt\`, \`area\`) VALUES (1,'it\\'s \\"x\\" \\\\ é\\n',12.50,12345678901234567890,5.1e-7,0x00FF,0x110F00000101000000,0x110F00000102000000),(2,NULL,-3,NULL,0.25,NULL,0x000000000103000000,0x110F00000104000000);
/*!40000 ALTER TABLE \`things\` ENABLE KEYS */;
UNLOCK TABLES;
`

test('the export reads a mysqldump into neutral tables', () => {
  const [t] = exportTables(Buffer.from(DUMP), SCHEMA)
  assert.equal(t.table, 'things')
  assert.deepEqual(t.bin, ['pic'])
  assert.deepEqual(t.geom, ['pt', 'area'])
  assert.deepEqual(t.rows[0], [1, "it's \"x\" \\ é\n", 12.5, '12345678901234567890', 5.1e-7, '00ff', '3857:0101000000', '3857:0102000000'])
  assert.deepEqual(t.rows[1], [2, null, -3, null, 0.25, null, '0:0103000000', '3857:0104000000'])
  assert.deepEqual(parseTableText(tableText(t), 'things').rows, t.rows)
})

test('the export keeps raw bytes, as a dump written without --hex-blob has them', () => {
  const dump = Buffer.concat([
    Buffer.from("INSERT INTO `things` (`id`, `pic`, `pt`) VALUES (1,'"),
    Buffer.from([0xff, 0x5c, 0x30, 0xe9]), // a raw 0xff, the escape for NUL, a raw 0xe9
    Buffer.from("',_binary '"),
    Buffer.from([0x11, 0x0f, 0, 0, 1, 1, 0, 0, 0]), // SRID 3857, then WKB
    Buffer.from("');\n"),
  ])
  const [t] = exportTables(dump, SCHEMA.replace('`area` geometry NOT NULL', '`area` geometry DEFAULT NULL'))
  assert.deepEqual(t.rows[0], [1, 'ff00e9', '3857:0101000000'])
})

test('the export refuses what it would otherwise lose', () => {
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`) SELECT 1 FROM DUAL;\n"), SCHEMA), /not a statement this export reads/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `nope` (`id`) VALUES (1);\n"), SCHEMA), /not in the schema dump/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`,`name`) VALUES (1,0xFF);\n"), SCHEMA), /hex value in a column/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`,`name`) VALUES (1,'\xff');\n", 'latin1'), SCHEMA), /not valid UTF-8/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`,`pt`) VALUES (1,5);\n"), SCHEMA), /a number in a column/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`,`area`) VALUES (1,0x00000000010100000000);\n"), SCHEMA), /has SRID 0 and the column 3857/)
  assert.throws(() => exportTables(Buffer.from("INSERT INTO `things` (`id`,`name`) VALUES (1);\n"), SCHEMA), /a row has 1 values for 2 columns/)
  assert.throws(() => [...readInserts("INSERT INTO `t` (`a`) VALUES (1)")], /expected , or ;/)
})

// --- the committed fixtures -------------------------------------------------------------------

// Columns of every table in the PostgreSQL baseline.
function baselineColumns() {
  const out = new Map()
  let current = null
  for (const line of fs.readFileSync(baselinePath, 'utf8').split('\n')) {
    let m = line.match(/^CREATE TABLE "?([A-Za-z0-9_]+)"? \($/)
    if (m) {
      current = new Set()
      out.set(m[1], current)
    } else if (current && line.startsWith(')')) {
      current = null
    } else if (current && (m = line.match(/^ {2}"?([A-Za-z0-9_]+)"? [a-z]/)) && !['PRIMARY', 'CONSTRAINT', 'UNIQUE', 'CHECK'].includes(m[1])) {
      current.add(m[1])
    }
  }
  return out
}

test('every fixture table and column exists in the PostgreSQL baseline, which is built from the migrated MySQL schema', () => {
  const columns = baselineColumns()
  assert.ok(columns.size > 250)
  for (const name of allTables(fixturesDir)) assert.ok(columns.has(name), `${name} is not a table in the baseline`)
  for (const name of tableNames(fixturesDir)) {
    for (const c of readTable(fixturesDir, name).columns) assert.ok(columns.get(name).has(c), `${name}.${c} is not in the baseline`)
  }
  for (const f of ['captured-steps.json', 'extra-steps.json']) {
    for (const s of readSteps(fixturesDir, f)) {
      for (const c of s.columns || []) assert.ok(columns.get(s.table).has(c), `${f}: ${s.table}.${c} is not in the baseline`)
    }
  }
})

test('the committed table files are well formed, and none holds a line separator', () => {
  const names = tableNames(fixturesDir)
  assert.ok(names.length > 40)
  let rows = 0
  for (const name of names) {
    const file = path.join(fixturesDir, 'tables', `${name}.jsonl`)
    const bytes = fs.readFileSync(file)
    assert.ok(!bytes.toString('utf8').includes(String.fromCharCode(0x2028)), `${name} holds U+2028`)
    const t = readTable(fixturesDir, name)
    assert.ok(t.rows.length > 0, `${name} has no rows`)
    rows += t.rows.length
    assert.equal(tableText({ table: name, columns: t.columns, bin: [...t.bin], geom: [...t.geom], rows: t.rows }), bytes.toString('utf8'), `${name} is not in the form the export writes`)
  }
  assert.ok(rows > 3000)
})

test('the committed fixtures write SQL for both engines', () => {
  for (const engine of Object.keys(dialects)) {
    const stats = {}
    const sql = all(loadSql(engine, fixturesDir, { stats }))
    assert.ok(sql.length > 1_000_000)
    assert.ok(!sql.includes('\0') || engine === 'mysql')
    assert.ok(sql.includes('plainmod@test.com'))
    if (engine === 'pgsql') {
      assert.deepEqual(Object.keys(stats).sort(), ['isochrones.source', 'messages_attachments.contenttype', 'messages_history.source'])
    } else {
      assert.deepEqual(stats, {})
    }
  }
})

test('every value a repair changes occurs in the fixtures, so none is left over', () => {
  for (const r of readRepairs(fixturesDir, 'pgsql')) {
    const t = readTable(fixturesDir, r.table)
    const i = t.columns.indexOf(r.column)
    assert.ok(i >= 0, `${r.table}.${r.column}`)
    assert.ok(t.rows.some((row) => row[i] === r.from), `${r.table}.${r.column} no longer holds ${JSON.stringify(r.from)}: remove the repair`)
  }
})

test('a bad step file stops the load before any SQL is produced', () => {
  const dir = smallDir()
  try {
    fs.writeFileSync(path.join(dir, 'captured-steps.json'), JSON.stringify([{ op: 'delete', table: 'd', where: {} }]))
    for (const engine of Object.keys(dialects)) {
      const gen = loadSql(engine, dir)
      assert.throws(() => gen.next(), FixtureError)
    }
  } finally {
    fs.rmSync(dir, { recursive: true, force: true })
  }
})

test('a bad table file stops the load before any SQL is produced', () => {
  const dir = smallDir()
  try {
    const bad = path.join(dir, 'tables', 'zzz.jsonl')
    fs.writeFileSync(bad, '{"table":"zzz","columns":["id"]}\n[1,2]\n')
    const gen = loadSql('pgsql', dir)
    assert.throws(() => gen.next(), FixtureError)
  } finally {
    fs.rmSync(dir, { recursive: true, force: true })
  }
})

test('an export of almost no tables writes nothing and keeps the committed files', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fx-export-'))
  try {
    fs.mkdirSync(path.join(dir, 'tables'))
    fs.writeFileSync(path.join(dir, 'tables', 'keep.jsonl'), 'x')
    assert.throws(() => writeTables([], dir), FixtureError)
    assert.ok(fs.existsSync(path.join(dir, 'tables', 'keep.jsonl')))
  } finally {
    fs.rmSync(dir, { recursive: true, force: true })
  }
})

test('the child car seat offer is a copy of message 150, group and map point included', () => {
  const rows = (name) => {
    const t = readTable(fixturesDir, name)
    return t.rows.map((r) => Object.fromEntries(t.columns.map((c, i) => [c, r[i]])))
  }
  const steps = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'extra-steps.json'), 'utf8'))
  const stepRow = (table) => {
    const s = steps.find((x) => x.op === 'insert' && x.table === table && x.rows.some((r) => r[0] === 990101))
    assert.ok(s, `no step inserts message 990101 into ${table}`)
    const r = s.rows.find((x) => x[0] === 990101)
    return Object.fromEntries(s.columns.map((c, i) => [c, r[i]]))
  }
  const src = rows('messages').find((r) => r.id === 150)
  const copy = stepRow('messages')
  for (const c of ['arrival', 'date', 'source', 'fromip', 'fromuser', 'fromname', 'fromaddr', 'lat', 'lng', 'locationid']) {
    assert.deepEqual(copy[c], src[c], `messages.${c} differs from message 150`)
  }
  const srcGroup = rows('messages_groups').find((r) => r.msgid === 150)
  const copyGroup = stepRow('messages_groups')
  for (const c of ['groupid', 'arrival']) assert.deepEqual(copyGroup[c], srcGroup[c], `messages_groups.${c}`)
  const srcSpatial = rows('messages_spatial').find((r) => r.msgid === 150)
  const copySpatial = stepRow('messages_spatial')
  assert.equal(copySpatial.point.geom, srcSpatial.point)
  for (const c of ['groupid', 'arrival']) assert.deepEqual(copySpatial[c], srcSpatial[c], `messages_spatial.${c}`)
})
