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
import { formatReport } from './parity.mjs'

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
  assert.match(map.authorities, /^SELECT 1 AS `id`, 'Testshire Council' AS `name`, ST_GeomFromText\('POLYGON\(/)
  assert.match(map.authorities, /, 3857\)/)
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
