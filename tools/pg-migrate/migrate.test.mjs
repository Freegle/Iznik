// Unit tests for the migration tooling that need no database. Run: node --test tools/pg-migrate/migrate.test.mjs
import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  copyEscape, encodeCopyRow, fixtureSourceMap, loadEnv, mysqlExpr, mysqlLiteral, parseMysqlRow, pgExpr, relation, unescapeBatch,
} from './lib.mjs'
import { enumValues } from './audit.mjs'
import { fillTemplate } from './pgloader.mjs'
import { formatReport, sameAggregate, valueAggregates } from './parity.mjs'
import { formatAudit } from './audit.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(fs.readFileSync(path.join(here, 'fixtures/rehearsal.json'), 'utf8'))

test('mysql --batch escaping is undone and the JSON row parses', () => {
  // The client prints a backslash as two and a newline as backslash-n; a JSON row holding a tab and a backslash arrives doubled.
  const printed = '["a\\\\tb","x\\\\\\\\y",null]'
  assert.deepEqual(parseMysqlRow(printed), ['a\tb', 'x\\y', null])
  assert.equal(unescapeBatch('a\\nb\\\\c\\td'), 'a\nb\\c\td')
})

test('COPY text escaping keeps tabs, newlines, backslashes and NULL apart', () => {
  assert.equal(copyEscape(null), '\\N')
  assert.equal(copyEscape('\\N'), '\\\\N')
  assert.equal(copyEscape('a\tb\nc\rd\\e'), 'a\\tb\\nc\\rd\\\\e')
  assert.equal(encodeCopyRow(['1', null, 'x y']), '1\t\\N\tx y\n')
  assert.throws(() => copyEscape('a\0b'), /NUL/)
})

test('a MySQL literal stays on one line', () => {
  assert.equal(mysqlLiteral("it's\na\\b\t"), "'it\\'s\\na\\\\b\\t'")
  assert.ok(!mysqlLiteral('x\ny').includes('\n'))
})

test('the fixture becomes one SELECT per table with typed geometry', () => {
  const map = fixtureSourceMap(fixture)
  assert.deepEqual(Object.keys(map).sort(), Object.keys(fixture.tables).sort())
  assert.match(map.authorities, /^SELECT CAST\(1 AS SIGNED\) AS `id`, 'Testshire Council' AS `name`, ST_GeomFromText\('POLYGON\(/)
  assert.match(map.authorities, /, 3857\)/)
  assert.match(map.helper_batches, /CAST\('\{.*\}' AS JSON\) AS `briefing`, CAST\('2026-10-01 11:00:00' AS DATETIME\)/)
  assert.match(map.helper_batches, /UNION ALL SELECT CAST\(2 AS SIGNED\), CAST\(102 AS SIGNED\), CAST\(9002 AS SIGNED\), 'paused', 'approve', CAST\(NULL AS JSON\)/)
  assert.ok(!/CAST/.test(map.messages_spatial), 'a table with zero dates is left untyped')
  assert.match(map.partnerships_statsfiles, /UNHEX\('00ff10800a09'\)/)
  assert.ok(!/\n/.test(map.authorities), 'no raw newline inside a statement')
  assert.equal(relation({ mysqlDb: 'd' }, 'authorities', map), map.authorities)
  assert.equal(relation({ mysqlDb: 'd' }, 'users', map), 'SELECT * FROM `d`.`users`')
})

test('every value is read as text, geometry and bytes as hex', () => {
  assert.equal(mysqlExpr({ name: 'polygon', isGeom: true }), 'HEX(ST_AsBinary(`polygon`))')
  assert.equal(mysqlExpr({ name: 'data', typname: 'bytea' }), 'HEX(`data`)')
  assert.equal(mysqlExpr({ name: 'a`b', typname: 'int8' }), 'CAST(`a``b` AS CHAR)')
})

test('the insert expression casts by target type and guards zero dates', () => {
  assert.equal(pgExpr({ typname: 'int8', type: 'bigint' }, 'c0'), 'c0::bigint')
  assert.equal(pgExpr({ typname: 'bytea', type: 'bytea' }, 'c1'), "decode(c1, 'hex')")
  assert.equal(pgExpr({ isGeom: true, geomType: null }, 'c2'), 'pg_temp.pgmig_geom(c2, NULL)')
  assert.equal(pgExpr({ isGeom: true, geomType: 'Polygon' }, 'c2'), "pg_temp.pgmig_geom(c2, 'Polygon')")
  // A bounded character column is not cast, so the insert refuses an over-long value instead of cutting it.
  assert.equal(pgExpr({ typname: 'varchar', type: 'character varying(10)' }, 'c4'), 'c4')
  assert.equal(pgExpr({ typname: 'bpchar', type: 'character(1)' }, 'c4'), 'c4')
  const nullable = pgExpr({ typname: 'timestamptz', type: 'timestamp with time zone', notnull: false }, 'c3')
  assert.match(nullable, /THEN NULL ELSE c3::timestamp with time zone END/)
  const notNull = pgExpr({ typname: 'timestamp', type: 'timestamp without time zone', notnull: true }, 'c3')
  assert.match(notNull, /THEN '1970-01-01 00:00:00'::timestamp without time zone/)
})

test('enum lists are read from CHECK definitions as PostgreSQL prints them', () => {
  assert.deepEqual(
    enumValues("CHECK (((msgtype)::text = ANY ((ARRAY['Offer'::character varying, 'Wanted'::character varying, 'it''s'::character varying])::text[])))"),
    { column: 'msgtype', values: ['Offer', 'Wanted', "it's"] })
  assert.deepEqual(enumValues("CHECK ((type = 'Exception'::text))"), { column: 'type', values: ['Exception'] })
  assert.deepEqual(enumValues(`CHECK (("to" = ANY (ARRAY['Users'::text, 'Mods'::text])))`), { column: 'to', values: ['Users', 'Mods'] })
  assert.equal(enumValues('CHECK ((char_length(name) <= 255))'), null)
})

test('the environment defaults point at the dev compose stack and hold no secret beyond the dev one', () => {
  const e = loadEnv({ COMPOSE_PROJECT_NAME: 'freegle-x' })
  assert.match(e.mysqlCmd, /^docker exec -i freegle-x-percona mysql -uroot/)
  assert.match(e.pgPsql, /^docker exec -i freegle-x-postgres psql -U root$/)
  const o = loadEnv({ MYSQL_CMD: 'm', PG_PSQL: 'p', PG_DATABASE: 'd', MYSQL_DB: 'q' })
  assert.deepEqual(o, { mysqlCmd: 'm', mysqlDb: 'q', pgPsql: 'p', pgDb: 'd' })
})

test('the pgloader file is filled from the template and refuses to guess a credential', () => {
  const template = fs.readFileSync(path.join(here, 'pgloader.load.template'), 'utf8')
  assert.throws(() => fillTemplate(template, { mysqlUri: undefined, pgUri: 'x', excluded: [] }), /MIGRATE_MYSQL_URI/)
  const text = fillTemplate(template, { mysqlUri: 'mysql://user:pass@host/db', pgUri: 'postgresql://user:pass@host/db', excluded: ['migrations', "o'k"] })
  assert.ok(!text.includes('{{'), 'no placeholder left')
  assert.match(text, /FROM mysql:\/\/user:pass@host\/db/)
  assert.match(text, /EXCLUDING TABLE NAMES MATCHING 'migrations', 'o''k'/)
  for (const clause of ['data only', 'create no tables', 'no reset sequences', 'quote identifiers', 'type tinyint to smallint', 'type set to text', 'zero-dates-to-null', 'iznik_reset_identities']) {
    assert.ok(text.includes(clause), `the load file has ${clause}`)
  }
  assert.throws(() => fillTemplate('x {{OTHER}}', { mysqlUri: 'a', pgUri: 'b', excluded: [] }), /OTHER/)
})

// pgloader is not available where this is built, so these tests check the file against the clause order and
// option names in the pgloader documentation. They cannot show that pgloader accepts it.
const LOAD_OPTIONS = ['data only', 'disable triggers', 'create no tables', 'create no indexes', 'no foreign keys', 'no reset sequences',
  'quote identifiers', 'workers', 'concurrency', 'batch rows', 'prefetch rows']

function renderedWithoutComments() {
  const template = fs.readFileSync(path.join(here, 'pgloader.load.template'), 'utf8')
  return fillTemplate(template, { mysqlUri: 'mysql://u:p@h/db', pgUri: 'postgresql://u:p@h/db', excluded: ['migrations'] }).replace(/\/\*[\s\S]*?\*\//g, '')
}

test('the pgloader file has its clauses in the documented order and only known options', () => {
  const text = renderedWithoutComments()
  const at = ['LOAD DATABASE', 'FROM mysql:', 'INTO postgresql:', 'WITH ', 'SET MySQL PARAMETERS', 'SET PostgreSQL PARAMETERS', 'CAST ', 'EXCLUDING TABLE NAMES MATCHING', 'AFTER LOAD DO'].map((c) => text.indexOf(c))
  assert.ok(at.every((i) => i >= 0), `a clause is missing: ${at}`)
  assert.deepEqual(at, [...at].sort((a, b) => a - b), 'the clauses are out of order')
  const withBlock = text.slice(text.indexOf('WITH '), text.indexOf('SET MySQL PARAMETERS'))
  for (const opt of withBlock.replace(/^WITH\s+/, '').split(',').map((o) => o.trim().replace(/\s*=.*$/, ''))) {
    assert.ok(LOAD_OPTIONS.includes(opt), `unknown WITH option "${opt}"`)
  }
})

test('the pgloader file does not truncate, because ETL tables point at the plain ones', () => {
  assert.ok(!/\btruncate\b/i.test(renderedWithoutComments()))
})

test('the pgloader file pins both sessions to UTC', () => {
  const text = renderedWithoutComments()
  assert.match(text, /SET MySQL PARAMETERS[^]*time_zone = '\+00:00'/)
  assert.match(text, /SET PostgreSQL PARAMETERS[^]*timezone to 'UTC'/)
})

test('the committed template and fixtures carry no credentials or member details', () => {
  const template = fs.readFileSync(path.join(here, 'pgloader.load.template'), 'utf8')
  assert.ok(!/:\/\/[^{\s]*:[^{\s]*@/.test(template), 'no user:password@ in the template')
  const text = fs.readFileSync(path.join(here, 'fixtures/rehearsal.json'), 'utf8')
  assert.ok(!/@[a-z0-9-]+\.[a-z]{2,}/i.test(text), 'no email-like text in the fixtures')
})

test('the report shows failures and the verdict', () => {
  const text = formatReport({
    kind: 'geometry', ok: false,
    columns: [{ table: 't', column: 'g', ok: false, rows: 3, classes: { identical: 2, 'bad-srid': 1 }, notes: [], failingClasses: ['bad-srid'], examples: { 'bad-srid': ['7'] },
      answers: { compared: 4, failures: [{ kind: 'near', probe: 'p', row: '7', side: 'pg-only' }], warnings: [] } }],
  })
  assert.match(text, /FAIL t\.g: 3 rows; identical 2, bad-srid 1/)
  assert.match(text, /failing: bad-srid \(rows 7\)/)
  assert.match(text, /FAIL near p: row 7 pg-only/)
  assert.match(text.trim().split('\n').pop(), /^FAIL$/)
})

test('the value aggregates cover numbers, text, bytes and dates, and leave geometry and json out', () => {
  assert.equal(valueAggregates({ name: 'g', typname: 'geometry', isGeom: true }), null)
  assert.equal(valueAggregates({ name: 'j', typname: 'jsonb' }), null)
  assert.deepEqual(valueAggregates({ name: 'n', typname: 'int8' }).pg, ['count("n")', 'coalesce(sum("n"), 0)'])
  assert.match(valueAggregates({ name: 't', typname: 'varchar' }).mysql[1], /SUM\(CHAR_LENGTH\(`t`\)\)/)
  assert.match(valueAggregates({ name: 'b', typname: 'bytea' }).mysql[1], /SUM\(LENGTH\(`b`\)\)/)
  const tz = valueAggregates({ name: 'd', typname: 'timestamptz' })
  assert.match(tz.pg[0], /EXTRACT\(EPOCH FROM "d" AT TIME ZONE 'UTC'\)/)
  assert.match(tz.mysql[0], /TIMESTAMPDIFF\(SECOND, '1970-01-01 00:00:00', `d`\)/)
  assert.ok(!/AT TIME ZONE/.test(valueAggregates({ name: 'd', typname: 'timestamp' }).pg[0]), 'a timestamp without a zone is already naive')
})

test('aggregates compare exactly for whole numbers and closely for decimals', () => {
  assert.equal(sameAggregate('9007199254740993', '9007199254740993'), true)
  assert.equal(sameAggregate('9007199254740993', '9007199254740992'), false)
  assert.equal(sameAggregate('12.500000', '12.5'), true)
  assert.equal(sameAggregate('12.5', '12.6'), false)
  assert.equal(sameAggregate(null, '1'), false)
  assert.equal(sameAggregate('x', 'x'), false)
})

test('the audit names the loader for a zero date in a NOT NULL column', () => {
  const text = formatAudit({
    kind: 'audit', enums: [], badDates: [{ table: 'a', column: 'c', rows: 2 }], nuls: [{ table: 'n', column: 't', rows: 1 }], unparsed: [], failed: [], ok: false,
    zeroDates: [{ table: 'p', column: 'x', notNull: true, rows: 3, loader: 'pgloader' }, { table: 'e', column: 'y', notNull: true, rows: 4, loader: 'etl' }, { table: 'q', column: 'z', notNull: false, rows: 5, loader: 'etl' }],
  })
  assert.match(text, /tables pgloader loads \(the batch fails.*: 1\n  p\.x: 3 rows/)
  assert.match(text, /tables the ETL loads \(loaded as 1970-01-01\): 1\n  e\.y: 4 rows/)
  assert.match(text, /nullable columns \(loaded as NULL by both\): 1\n  q\.z: 5 rows/)
  assert.match(text, /Impossible dates.*: 1\n  a\.c: 2 rows/)
  assert.match(text, /NUL character.*: 1\n  n\.t: 1 rows/)
})
