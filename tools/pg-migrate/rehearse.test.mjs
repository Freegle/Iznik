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
import { checkCounts, checkForeignKeys, checkGeometry } from './parity.mjs'

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
  assert.ok(list.length >= 18 && list.length < 40, `${list.length} tables`)
})

test('the audit passes clean fixtures and lists zero dates', { skip }, async () => {
  const rep = await runAudit(env, { sourceMap })
  assert.equal(rep.ok, true)
  assert.deepEqual(rep.enums, [])
  assert.deepEqual(rep.zeroDates.map((z) => `${z.table}.${z.column}:${z.rows}`).sort(), ['messages_spatial.arrival:1', 'messages_spatial.modified:1'])
  assert.deepEqual(rep.failed, [])
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
