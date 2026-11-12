// Loads the PostgreSQL baseline and then the fixtures into a scratch database, and checks what
// arrived. Needs a PostgreSQL server with PostGIS; skipped unless PG_SCHEMA_PSQL says how to
// reach one.
//
//   PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node --test tools/fixtures/pg.test.mjs
//
// The value is a psql command line without -d. The test creates a database named
// ${PG_FIXTURES_DB:-pg_fixtures_test}, drops it afterwards, and touches nothing else.
import test, { before, after } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadSql, readSteps, readTable, stepTables, tableNames } from './lib.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const PSQL = process.env.PG_SCHEMA_PSQL
const DB = process.env.PG_FIXTURES_DB || 'pg_fixtures_test'
const baselinePath = path.join(here, '../../iznik-batch/database/postgres/baseline.sql')
const fixturesDir = path.join(here, '../../scripts/fixtures')
const orphansPath = path.join(here, 'orphans.pg.sql')
const skip = PSQL ? false : 'PG_SCHEMA_PSQL is not set'

function psql(db, sql, opts = {}) {
  const r = spawnSync('sh', ['-c', `${PSQL} -d ${db} -X -q -At -v ON_ERROR_STOP=1`], { input: sql, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
  if (opts.allowError) return r
  assert.equal(r.status, 0, `psql failed: ${r.stderr}`)
  return r.stdout.trim()
}

const fixtureSql = (opts) => [...loadSql('pgsql', fixturesDir, opts)].join('')
const count = (t) => Number(psql(DB, `SELECT count(*) FROM "${t}"`))

before(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE); CREATE DATABASE ${DB};`)
  psql(DB, `BEGIN;\n${fs.readFileSync(baselinePath, 'utf8')}\nCOMMIT;`)
  psql(DB, `BEGIN;\n${fixtureSql()}\nCOMMIT;`)
})

after(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE);`)
})

const stepTableSet = new Set(stepTables([...readSteps(fixturesDir, 'captured-steps.json'), ...readSteps(fixturesDir, 'extra-steps.json')]))

test('every table holds the rows of its file, and a table a step writes to holds at least that', { skip }, () => {
  for (const name of tableNames(fixturesDir)) {
    const n = readTable(fixturesDir, name).rows.length
    if (stepTableSet.has(name)) assert.ok(count(name) >= n, `${name} has fewer rows than its file`)
    else assert.equal(count(name), n, name)
  }
})

test('the steps ran: the plain moderator, the Freegle account, the car seat and the experiment rows', { skip }, () => {
  assert.equal(psql(DB, `SELECT count(*) FROM users_emails WHERE email = 'plainmod@test.com'`), '1')
  assert.equal(psql(DB, `SELECT count(*) FROM users u JOIN users_emails ue ON ue.userid = u.id AND ue.email = 'plainmod@test.com' JOIN memberships m ON m.userid = u.id AND m.groupid = 1 JOIN users_logins l ON l.userid = u.id AND l.type = 'Native' AND l.uid = u.id::text WHERE u.systemrole = 'Moderator'`), '1')
  assert.equal(psql(DB, `SELECT count(*) FROM users WHERE id = 990001 AND fullname = 'Freegle'`), '1')
  assert.equal(psql(DB, `SELECT subject FROM messages WHERE id = 990101`), 'OFFER: Child car seat (EX1 1SR)')
  assert.equal(psql(DB, `SELECT count(*) FROM messages_groups WHERE msgid = 990101`), '1')
  assert.equal(psql(DB, `SELECT ST_SRID(point) FROM messages_spatial WHERE msgid = 990101`), '4326')
  assert.equal(psql(DB, `SELECT value FROM config WHERE key = 'ux_server_arms'`), '1')
  assert.equal(psql(DB, `SELECT status FROM ux_experiments WHERE name = 'interested-button'`), 'running')
  assert.equal(psql(DB, `SELECT (ends_at - started_at)::text FROM ux_experiments WHERE name = 'interested-button'`), '14 days')
})

test('the plain moderator is given the next free id, not a fixed one', { skip }, () => {
  const max = Number(psql(DB, `SELECT max(id) FROM users WHERE id < 990000`))
  assert.equal(Number(psql(DB, `SELECT id FROM users WHERE fullname = 'Plain Moderator'`)), max)
  assert.ok(max > 100)
})

test('every geometry is SRID 4326 and valid', { skip }, () => {
  const cols = psql(DB, `SELECT f_table_name || '.' || f_geometry_column FROM geometry_columns WHERE f_table_schema = 'public' ORDER BY 1`).split('\n')
  let seen = 0
  for (const tc of cols) {
    const [t, c] = tc.split('.')
    const n = Number(psql(DB, `SELECT count("${c}") FROM "${t}"`))
    if (!n) continue
    seen += n
    assert.equal(psql(DB, `SELECT count(*) FROM "${t}" WHERE "${c}" IS NOT NULL AND (ST_SRID("${c}") <> 4326 OR NOT ST_IsValid("${c}"))`), '0', tc)
  }
  assert.ok(seen > 200)
})

test('the values PostgreSQL refuses are loaded as their repairs say', { skip }, () => {
  assert.equal(psql(DB, `SELECT count(*) FROM isochrones WHERE source IS NULL`), '25')
  assert.equal(psql(DB, `SELECT count(*) FROM messages_history WHERE source IS NULL`), '169')
  assert.equal(psql(DB, `SELECT count(*) FROM messages_attachments WHERE contenttype = ''`), '169')
})

test('no loaded row points at a row that is not there', { skip }, () => {
  const r = spawnSync('sh', ['-c', `${PSQL} -d ${DB} -X -q -At -f -`], { input: fs.readFileSync(orphansPath, 'utf8'), encoding: 'utf8' })
  assert.equal(r.status, 0, r.stderr)
  assert.match(r.stderr, /orphaned rows in total: 0/)
})

test('the identities are past the loaded ids, so a new row does not collide', { skip }, () => {
  assert.equal(psql(DB, `INSERT INTO users (firstname) VALUES ('fixtures-test') RETURNING id > 990001`).split('\n')[0], 't')
  assert.equal(psql(DB, `INSERT INTO config (key, value) VALUES ('fixtures-test', 'x') RETURNING id > 2`).split('\n')[0], 't')
})

test('loading again changes nothing', { skip }, () => {
  const before = tableNames(fixturesDir).map((t) => `${t}=${count(t)}`)
  psql(DB, `BEGIN;\n${fixtureSql()}\nCOMMIT;`)
  const after = tableNames(fixturesDir).map((t) => `${t}=${count(t)}`)
  assert.deepEqual(after, before)
  assert.equal(psql(DB, `SELECT count(*) FROM users_emails WHERE email = 'plainmod@test.com'`), '1')
  assert.equal(psql(DB, `SELECT count(*) FROM ux_experiments WHERE name = 'interested-button'`), '1')
})

test('a value the repairs do not cover stops the load, and says which', { skip }, () => {
  const r = psql(
    DB,
    `BEGIN;\nINSERT INTO isochrones (minutes, source, polygon) VALUES (15, '', ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 0))', 4326));\nROLLBACK;`,
    { allowError: true }
  )
  assert.notEqual(r.status, 0)
  assert.match(r.stderr, /isochrones_source_check/)
})
