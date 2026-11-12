// The rehearsal: loads the fixture rows through the ETL into a scratch PostgreSQL database built
// from the baseline, then runs the audit and the parity checks over the result. Needs both a
// PostgreSQL server with PostGIS and a MySQL 8 server to read from; skipped unless both are named:
//
//   PG_PSQL="docker exec -i freegle-postgres psql -U root" \
//   MYSQL_CMD="docker exec -i freegle-percona mysql -uroot -p<password>" \
//   node --test tools/pg-migrate/rehearse.test.mjs
//
// PG_PSQL is a psql command line without -d. The test creates a database named
// ${PG_MIGRATE_DB:-pg_migrate_test}, drops it afterwards and touches nothing else. MySQL is only
// read: the fixture rows are served through derived tables (SELECT ... UNION ALL SELECT ...), so
// no table is created or written there.
import test, { before, after } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { fixtureSourceMap, mysqlLiteral } from './lib.mjs'
import { copyTable, etlTables, runEtl } from './etl.mjs'
import { runAudit } from './audit.mjs'
import { checkCounts, checkForeignKeys, checkGeometry, checkValues } from './parity.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const PSQL = process.env.PG_PSQL
const skip = PSQL && process.env.MYSQL_CMD ? false : 'PG_PSQL and MYSQL_CMD are not both set'
const DB = process.env.PG_MIGRATE_DB || 'pg_migrate_test'
const env = { mysqlCmd: process.env.MYSQL_CMD, mysqlDb: process.env.MYSQL_DB || 'iznik', pgPsql: PSQL, pgDb: DB }
const baselinePath = path.join(here, '../../iznik-batch/database/postgres/baseline.sql')
const fixture = JSON.parse(fs.readFileSync(path.join(here, 'fixtures/rehearsal.json'), 'utf8'))
const sourceMap = fixtureSourceMap(fixture)
const tables = Object.keys(sourceMap)

function psql(db, sql) {
  const r = spawnSync('sh', ['-c', `${PSQL} -d ${db} -X -q -At -v ON_ERROR_STOP=1`], { input: sql, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  assert.equal(r.status, 0, `psql failed: ${r.stderr}`)
  return r.stdout.trim()
}
const q = (sql) => psql(DB, sql)

before(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE); CREATE DATABASE ${DB};`)
  psql(DB, fs.readFileSync(baselinePath, 'utf8'))
})

after(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE);`)
})

test('the ETL owns the geometry tables and the tables with a generated column', { skip }, async () => {
  const list = await etlTables(env)
  const by = Object.fromEntries(list.map((t) => [t.table, t.reasons]))
  assert.deepEqual(by.authorities, ['geometry'])
  assert.deepEqual(by.messages_outcomes, ['generated column'])
  assert.deepEqual(by.rippling_reach, ['geometry', 'generated column'])
  assert.ok(by.users_emails, 'users_emails has a generated md5 column')
  assert.equal(by.helper_batches, undefined, 'a plain table is left to pgloader')
  assert.equal(by.migrations, undefined)
  // The list is the tables with a geometry or a generated column, counted here straight from the catalog.
  assert.equal(list.length, Number(q(`SELECT count(DISTINCT c.relname) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
    WHERE c.relkind = 'r' AND c.relnamespace = 'public'::regnamespace AND a.attnum > 0 AND NOT a.attisdropped
      AND (a.attgenerated <> '' OR a.atttypid = 'geometry'::regtype)`)))
})

test('the audit passes clean fixtures and lists zero dates', { skip }, async () => {
  const rep = await runAudit(env, { sourceMap })
  assert.equal(rep.ok, true)
  assert.deepEqual(rep.enums, [])
  assert.deepEqual(rep.zeroDates.map((z) => `${z.table}.${z.column}:${z.rows}`).sort(), ['messages_spatial.arrival:1', 'messages_spatial.modified:1'])
  assert.deepEqual(rep.failed, [])
})

test('the audit finds an impossible date and a NUL character that no loader accepts', { skip }, async () => {
  const bad = {
    messages_spatial: "SELECT 1 AS `id`, 5 AS `msgid`, ST_GeomFromText('POINT(0 0)', 3857) AS `point`, 0 AS `successful`, 0 AS `promised`, 1 AS `groupid`, 'Offer' AS `msgtype`, '2026-02-30 10:00:00' AS `arrival`, '2026-10-01 10:00:00' AS `modified`",
    helper_batches: `SELECT 1 AS \`id\`, 1 AS \`msgid\`, 1 AS \`offereruserid\`, ${mysqlLiteral('active\0')} AS \`status\`, 'automatic' AS \`automode\`, NULL AS \`briefing\`, NULL AS \`lastpolledat\`, NULL AS \`lastrunat\`, NULL AS \`pausedat\`, '2026-10-01 10:00:00' AS \`created_at\`, '2026-10-01 10:00:00' AS \`updated_at\``,
  }
  const rep = await runAudit(env, { sourceMap: bad })
  assert.equal(rep.ok, false)
  assert.deepEqual(rep.badDates, [{ table: 'messages_spatial', column: 'arrival', rows: 1 }])
  assert.deepEqual(rep.nuls, [{ table: 'helper_batches', column: 'status', rows: 1 }])
})

test('the audit finds an ENUM value the baseline would refuse', { skip }, async () => {
  const bad = { messages_outcomes: "SELECT 1 AS `id`, '2026-10-01 10:00:00' AS `timestamp`, 5 AS `msgid`, NULL AS `userid`, 'Bogus' AS `outcome`, '' AS `happiness`, NULL AS `comments`, 0 AS `reviewed` UNION ALL SELECT 2, '2026-10-01 10:00:00', 6, NULL, 'Taken', 'Happy', NULL, 0" }
  const rep = await runAudit(env, { sourceMap: bad })
  assert.equal(rep.ok, false)
  assert.deepEqual(rep.enums.map((e) => `${e.column}=${e.value}:${e.rows}`).sort(), ['happiness=:1', 'outcome=Bogus:1'])
})

test('the fixture loads and every row is counted', { skip }, async () => {
  const results = await runEtl(env, tables, { sourceMap }, () => {})
  for (const [i, t] of tables.entries()) {
    assert.equal(results[i].inserted, fixture.tables[t].rows.length, t)
    assert.equal(Number(q(`SELECT count(*) FROM "${t}"`)), fixture.tables[t].rows.length, `rows now in ${t}`)
  }
  assert.deepEqual(results.find((r) => r.table === 'messages_spatial').zeroDates, { arrival: 1, modified: 1 })
})

test('geometry arrives valid, labelled 4326 and with the coordinates unchanged', { skip }, () => {
  assert.equal(q(`SELECT count(*) FROM authorities WHERE NOT ST_IsValid(polygon) OR ST_SRID(polygon) <> 4326`), '0')
  assert.equal(q(`SELECT ST_AsText(position) FROM users_approxlocs WHERE id = 1`), 'POINT(-2.5 51.5)')
  assert.equal(q(`SELECT ST_AsText(polygon) FROM authorities WHERE id = 1`), 'POLYGON((-2.6 51.4,-2.4 51.4,-2.4 51.6,-2.6 51.6,-2.6 51.4))')
  // The bow-tie became the two triangles it was made of.
  assert.equal(q(`SELECT GeometryType(polygon) || ' ' || ST_NumGeometries(polygon) FROM authorities WHERE id = 2`), 'MULTIPOLYGON 2')
  assert.equal(q(`SELECT GeometryType(SHAPE) FROM os_county_electoral_division_region WHERE "OGR_FID" = 2`.replace('SHAPE', '"SHAPE"')), 'MULTIPOLYGON')
  assert.equal(q(`SELECT GeometryType(bbox) FROM rippling_reach_overflow WHERE msgid = 101`), 'POLYGON')
  assert.equal(q(`SELECT count(*) FROM pg_attribute a WHERE a.attrelid = 'authorities'::regclass AND a.atttypid = 'geometry'::regtype AND format_type(a.atttypid, a.atttypmod) <> 'geometry(Geometry,4326)'`), '0')
})

test('other values arrive intact: text, enums, json, bytes, dates and generated columns', { skip }, () => {
  assert.equal(q(`SELECT name FROM authorities WHERE id = 7`), 'Cafe\tWard "North"\nline two \\ slash')
  assert.equal(q(`SELECT comments FROM messages_outcomes WHERE id = 3`), 'Café ☃ snowman and 😀')
  assert.equal(q(`SELECT comments FROM messages_outcomes WHERE id = 1`), 'Lovely, thanks!\nCollected today.\tSee you')
  assert.equal(q(`SELECT string_agg(feedback::text, ',' ORDER BY id) FROM messages_outcomes`), '1,0,1,0')
  assert.equal(q(`SELECT briefing->>'note' FROM helper_batches WHERE id = 1`), 'back \\ slash and "quote"')
  assert.equal(q(`SELECT briefing IS NULL FROM helper_batches WHERE id = 2`), 't')
  assert.equal(q(`SELECT encode(content, 'hex') FROM partnerships_statsfiles WHERE id = 1`), '00ff10800a09')
  assert.equal(q(`SELECT content IS NULL FROM partnerships_statsfiles WHERE id = 2`), 't')
  assert.equal(q(`SELECT arrival IS NULL AND modified IS NOT NULL FROM messages_spatial WHERE id = 3`), 't')
  assert.equal(q(`SELECT modified IS NULL FROM messages_spatial WHERE id = 4`), 't')
  assert.equal(q(`SELECT to_char(arrival AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM messages_spatial WHERE id = 1`), '2026-10-01 09:30:00')
})

test('identity sequences are moved past the loaded ids', { skip }, () => {
  assert.equal(q(`INSERT INTO authorities (name, polygon) VALUES ('Next', ST_GeomFromText('POINT(0 0)', 4326)) RETURNING id`), '8')
  q(`DELETE FROM authorities WHERE id = 8`)
})

test('a table that already has rows is refused, and replaced with --truncate', { skip }, async () => {
  await assert.rejects(copyTable(env, 'helper_batches', { sourceMap }), /already has 2 rows.*--truncate/)
  const r = await copyTable(env, 'helper_batches', { sourceMap, truncate: true })
  assert.equal(r.inserted, 2)
  assert.equal(q('SELECT count(*) FROM helper_batches'), '2')
})

test('a row PostgreSQL refuses rolls the whole table back', { skip }, async () => {
  const bad = { messages_outcomes: "SELECT 1 AS `id`, '2026-10-01 10:00:00' AS `timestamp`, 5 AS `msgid`, NULL AS `userid`, 'Taken' AS `outcome`, NULL AS `happiness`, NULL AS `comments`, 0 AS `reviewed` UNION ALL SELECT 2, '2026-10-01 10:00:00', 6, NULL, 'Bogus', NULL, NULL, 0" }
  await assert.rejects(copyTable(env, 'messages_outcomes', { sourceMap: bad, truncate: true }), /rolled back.*messages_outcomes_outcome_check/s)
  assert.equal(q('SELECT count(*) FROM messages_outcomes'), '4', 'the earlier rows are still there')
})

test('a repaired shape a typed column cannot hold stops the load with its own message', { skip }, async () => {
  const bowtie = { rippling_reach_overflow: "SELECT 5 AS `msgid`, ST_GeomFromText('POLYGON((0 0,1 1,1 0,0 1,0 0))', 3857) AS `bbox`, '2026-10-01 10:00:00' AS `updated_at`" }
  await assert.rejects(copyTable(env, 'rippling_reach_overflow', { sourceMap: bowtie, truncate: true }), /a repaired MULTIPOLYGON cannot be held in a Polygon column/)
  assert.equal(q('SELECT count(*) FROM rippling_reach_overflow'), '2')
})

test('geometry labelled with an SRID other than 3857 stops the load', { skip }, async () => {
  const geo = { rippling_reach_overflow: "SELECT 5 AS `msgid`, ST_GeomFromText('POLYGON((51 -2,51 -1,52 -1,52 -2,51 -2))', 4326) AS `bbox`, '2026-10-01 10:00:00' AS `updated_at`" }
  await assert.rejects(copyTable(env, 'rippling_reach_overflow', { sourceMap: geo, truncate: true }), /labelled SRID 4326/)
})

test('a NUL character in a value is reported, not dropped', { skip }, async () => {
  const nul = { helper_batches: `SELECT 1 AS \`id\`, 1 AS \`msgid\`, 1 AS \`offereruserid\`, ${mysqlLiteral('active\0')} AS \`status\`, 'automatic' AS \`automode\`, NULL AS \`briefing\`, NULL AS \`lastpolledat\`, NULL AS \`lastrunat\`, NULL AS \`pausedat\`, '2026-10-01 10:00:00' AS \`created_at\`, '2026-10-01 10:00:00' AS \`updated_at\`` }
  await assert.rejects(copyTable(env, 'helper_batches', { sourceMap: nul, truncate: true }), /NUL/)
  assert.equal(q('SELECT count(*) FROM helper_batches'), '2')
})

test('row counts match, and a missing row is reported', { skip }, async () => {
  const ok = await checkCounts(env, { sourceMap })
  assert.equal(ok.ok, true)
  assert.equal(ok.rows.length, tables.length)
  q(`DELETE FROM users_approxlocs WHERE id = 4`)
  const bad = await checkCounts(env, { sourceMap })
  assert.equal(bad.ok, false)
  assert.deepEqual(bad.rows.filter((r) => !r.ok).map((r) => [r.table, r.mysql, r.pg]), [['users_approxlocs', 4, 3]])
  await copyTable(env, 'users_approxlocs', { sourceMap, truncate: true })
  assert.equal((await checkCounts(env, { sourceMap })).ok, true)
})

test('values match after the load, and a shifted time, a cut string or a changed number is found', { skip }, async () => {
  const ok = await checkValues(env, { sourceMap })
  assert.equal(ok.ok, true, JSON.stringify(ok.mismatches))
  assert.ok(ok.columns > 25, `${ok.columns} columns compared`)

  // A timestamptz an hour out: what a MySQL or pgloader session that was not UTC would do to every row.
  q(`UPDATE messages_outcomes SET timestamp = timestamp + interval '1 hour'`)
  const shifted = await checkValues(env, { sourceMap, tables: ['messages_outcomes'] })
  assert.equal(shifted.ok, false)
  assert.deepEqual(shifted.mismatches.map((m) => `${m.column} ${m.what}`), ['timestamp seconds'])
  q(`UPDATE messages_outcomes SET timestamp = timestamp - interval '1 hour'`)

  q(`UPDATE authorities SET area_code = left(area_code, 2) WHERE id = 1; UPDATE partnerships_statsfiles SET size = size + 1 WHERE id = 1`)
  const damaged = await checkValues(env, { sourceMap, tables: ['authorities', 'partnerships_statsfiles'] })
  assert.deepEqual(damaged.mismatches.map((m) => `${m.table}.${m.column} ${m.what}`).sort(), ['authorities.area_code chars', 'partnerships_statsfiles.size sum'])
  q(`UPDATE authorities SET area_code = 'UTW' WHERE id = 1; UPDATE partnerships_statsfiles SET size = size - 1 WHERE id = 1`)
  assert.equal((await checkValues(env, { sourceMap })).ok, true)
})

test('a value longer than its varchar column is refused, not cut', { skip }, async () => {
  const long = { authorities: "SELECT 1 AS `id`, 'Long Code' AS `name`, ST_GeomFromText('POINT(0 0)', 3857) AS `polygon`, 'ABCDEFGHIJKLMNOP' AS `area_code`, NULL AS `simplified`" }
  await assert.rejects(copyTable(env, 'authorities', { sourceMap: long, truncate: true }), /value too long for type character varying\(10\)/)
  assert.equal(q('SELECT count(*) FROM authorities'), '7', 'rolled back')
})

test('geometry parity flags the one repair that moved the extent, and passes when that is allowed', { skip }, async () => {
  const probes = fixture.probes
  const rep = await checkGeometry(env, { sourceMap, probes })
  const poly = rep.columns.find((c) => c.table === 'authorities' && c.column === 'polygon')
  assert.deepEqual(poly.classes, { identical: 5, repaired: 1, 'repaired-extent-changed': 1 })
  assert.deepEqual(poly.examples['repaired-extent-changed'], ['6'])
  assert.equal(poly.ok, false)
  assert.equal(rep.ok, false)
  for (const c of rep.columns.filter((x) => x !== poly)) assert.equal(c.ok, true, `${c.table}.${c.column}`)

  const allowed = await checkGeometry(env, { sourceMap, probes, allowExtentChange: true })
  assert.equal(allowed.ok, true)
  const p = allowed.columns.find((c) => c.table === 'authorities' && c.column === 'polygon')
  // The hole that lay outside its shell is filled after the repair, so the engines disagree about a
  // point inside it. That row was repaired, so it is a warning; no unchanged row differs.
  assert.deepEqual(p.answers.failures, [])
  assert.deepEqual(p.answers.warnings.map((w) => `${w.kind} ${w.probe} ${w.row} ${w.side}`).sort(),
    ['contains in-misplaced-hole 6 pg-only', 'near in-misplaced-hole 6 pg-only'])
  // Both engines gave real answers, not two empty sets.
  assert.ok(p.answers.mysqlHits > 0 && p.answers.mysqlHits <= p.answers.pgHits)
  assert.deepEqual(p.geometryTypes, { MULTIPOLYGON: 3, POLYGON: 4 })
})

test('geometry parity catches damage to a loaded row', { skip }, async () => {
  q(`UPDATE users_approxlocs SET position = ST_GeomFromText('POINT(-2.4 51.5)', 4326) WHERE id = 1;
     UPDATE users_approxlocs SET position = ST_GeomFromText('POINT(0.2 55.2)', 4326) WHERE id = 3;
     DELETE FROM users_approxlocs WHERE id = 2`)
  const rep = await checkGeometry(env, { sourceMap, tables: ['users_approxlocs'], probes: fixture.probes })
  const c = rep.columns[0]
  assert.equal(c.ok, false)
  assert.deepEqual(c.classes, { 'changed-though-valid': 1, identical: 2, 'missing-in-pg': 1 })
  assert.ok(c.answers.failures.length > 0, 'the moved point changes a probe answer on an unchanged row')
  await copyTable(env, 'users_approxlocs', { sourceMap, truncate: true })
})

test('geometry parity fails a polygon repaired into lines, a point or a collection, and one that lost area', { skip }, async () => {
  const wkts = [
    'POLYGON((0 0,4 0,4 4,0 4,0 0))', // valid
    'POLYGON((0 0,1 1,2 2,0 0))', // collinear ring: repaired to a line
    'POLYGON((0 0,0 0,0 0,0 0))', // repeated point: repaired to a point
    'POLYGON((0 0,4 0,4 4,2 4,2 6,2 4,0 4,0 0))', // spike: repaired to a polygon plus a line
    'POLYGON((0 0,1 0,1 1,0 1,0 0,1 0,1 1,0 1,0 0))', // wound twice: the area of the original reads as double
  ]
  const odd = fixtureSourceMap({ tables: { authorities: { columns: ['id', 'name', 'polygon', 'area_code', 'simplified'], rows: wkts.map((w, i) => [i + 1, `Shape ${i + 1}`, { wkt: w }, null, null]) } } })
  await copyTable(env, 'authorities', { sourceMap: odd, truncate: true })
  const rep = await checkGeometry(env, { sourceMap: odd, tables: ['authorities'], autoProbeCount: 0 })
  const c = rep.columns.find((x) => x.column === 'polygon')
  assert.deepEqual(c.classes, { identical: 1, 'repaired-type-changed': 3, 'repaired-area-lost': 1 })
  assert.deepEqual(c.examples['repaired-type-changed'], ['2', '3', '4'])
  assert.deepEqual(c.examples['repaired-area-lost'], ['5'])
  assert.equal(c.ok, false)
  assert.equal(c.geometryTypes.POLYGON, 2)
  assert.equal(c.geometryTypes.MULTILINESTRING + c.geometryTypes.POINT + c.geometryTypes.GEOMETRYCOLLECTION, 3)

  const extentOnly = await checkGeometry(env, { sourceMap: odd, tables: ['authorities'], autoProbeCount: 0, allowExtentChange: true })
  assert.equal(extentOnly.ok, false, 'allowing a moved extent does not allow a changed type')
  const allowed = await checkGeometry(env, { sourceMap: odd, tables: ['authorities'], autoProbeCount: 0, allowTypeChange: true })
  assert.equal(allowed.ok, true)
  await copyTable(env, 'authorities', { sourceMap, truncate: true })
})

test('the foreign key check finds rows whose parent was not loaded', { skip }, async () => {
  const rep = await checkForeignKeys(env)
  assert.equal(rep.ok, false)
  const orphans = Object.fromEntries(rep.rows.map((r) => [r.name, r.orphans]))
  assert.equal(orphans.helper_batches_msgid_foreign, 2)
  assert.equal(orphans.messages_outcomes_msgid_foreign, 4)
  q(`DELETE FROM helper_batches; DELETE FROM messages_outcomes; DELETE FROM messages_spatial; DELETE FROM users_approxlocs; DELETE FROM partnerships_statsfiles`)
  const clean = await checkForeignKeys(env)
  assert.equal(clean.ok, true, JSON.stringify(clean.rows))
})

test('the documented command line sequence loads the fixture and ends in a pass', { skip }, () => {
  const file = path.join(here, 'fixtures/rehearsal.json')
  const run = (...args) => spawnSync('node', [path.join(here, 'migrate.mjs'), ...args], {
    encoding: 'utf8', env: { ...process.env, PG_DATABASE: DB, MYSQL_DB: env.mysqlDb, MYSQL_CMD: env.mysqlCmd, PG_PSQL: PSQL },
  })
  const load = run('etl', '--fixture', file, '--truncate')
  assert.equal(load.status, 0, load.stderr)
  assert.match(load.stdout, /Loaded 8 tables, 29 rows/)
  const strict = run('parity', 'all', '--fixture', file)
  assert.equal(strict.status, 1, 'the hole outside its shell is a failure until it has been looked at')
  assert.match(strict.stdout, /repaired-extent-changed/)
  const checked = run('parity', 'all', '--fixture', file, '--allow-extent-change')
  assert.equal(checked.status, 0, checked.stdout + checked.stderr)
  assert.match(checked.stdout, /Row counts: 8 of 8 tables match/)
  assert.match(checked.stdout, /Values: \d+ columns in 8 tables compared; 0 differ/)
  assert.match(checked.stdout, /Foreign keys: not checked with --fixture/)
  assert.match(checked.stdout.trim().split('\n').pop(), /^PASS$/)
})
