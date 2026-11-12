// Loads the committed PostgreSQL baseline into a scratch database and checks it. Needs a
// PostgreSQL server with PostGIS; skipped unless PG_SCHEMA_PSQL says how to reach one.
//
//   PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node --test tools/pg-schema/pg.test.mjs
//
// The value is a psql command line without -d. The test creates a database named
// ${PG_SCHEMA_DB:-pg_schema_test}, drops it afterwards, and touches nothing else.
import test, { before, after } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const PSQL = process.env.PG_SCHEMA_PSQL
const DB = process.env.PG_SCHEMA_DB || 'pg_schema_test'
const baselinePath = path.join(here, '../../iznik-batch/database/postgres/baseline.sql')
const migrationsDir = path.join(here, '../../iznik-batch/database/migrations')
const skip = PSQL ? false : 'PG_SCHEMA_PSQL is not set'

function psql(db, sql, opts = {}) {
  const r = spawnSync('sh', ['-c', `${PSQL} -d ${db} -X -q -At -v ON_ERROR_STOP=1`], { input: sql, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  if (opts.allowError) return r
  assert.equal(r.status, 0, `psql failed: ${r.stderr}`)
  return r.stdout.trim()
}

// Runs sql in a transaction that is rolled back, and returns the last result.
function scratch(sql) {
  return psql(DB, `BEGIN;\n${sql}\nROLLBACK;`)
}

// Runs sql that must fail, in its own transaction; returns the error text.
function mustFail(sql) {
  const r = psql(DB, `BEGIN;\n${sql}\nROLLBACK;`, { allowError: true })
  assert.notEqual(r.status, 0, 'expected an error')
  return r.stderr
}

before(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE); CREATE DATABASE ${DB};`)
  const sql = fs.readFileSync(baselinePath, 'utf8')
  psql(DB, sql)
})

after(() => {
  if (skip) return
  psql('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE);`)
})

test('the baseline applies cleanly and every statement in it took effect', { skip }, () => {
  const text = fs.readFileSync(baselinePath, 'utf8')
  const count = (re) => (text.match(re) || []).length
  const tables = count(/^CREATE TABLE /gm)
  const pgTables = Number(psql(DB, `SELECT count(*) FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace AND relname <> 'spatial_ref_sys'`))
  assert.equal(pgTables, tables)

  const pks = count(/^ {2}PRIMARY KEY /gm)
  const indexes = count(/^CREATE (UNIQUE )?INDEX /gm)
  const pgIndexes = Number(psql(DB, `SELECT count(*) FROM pg_indexes WHERE schemaname = 'public' AND tablename <> 'spatial_ref_sys'`))
  assert.equal(pgIndexes, pks + indexes)

  const fks = count(/^ALTER TABLE .* FOREIGN KEY /gm)
  assert.equal(Number(psql(DB, `SELECT count(*) FROM pg_constraint WHERE contype = 'f' AND connamespace = 'public'::regnamespace`)), fks)

  const checks = count(/^ {2}CONSTRAINT .* CHECK /gm)
  assert.equal(Number(psql(DB, `SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND connamespace = 'public'::regnamespace AND conrelid::regclass::text <> 'spatial_ref_sys'`)), checks)

  const triggers = count(/^CREATE TRIGGER /gm)
  assert.equal(Number(psql(DB, `SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal`)), triggers)
  assert.ok(triggers > 50)
})

test('the migrations the baseline stands for are recorded as run', { skip }, () => {
  const files = fs.readdirSync(migrationsDir).filter((f) => f.endsWith('.php'))
  assert.equal(Number(psql(DB, 'SELECT count(*) FROM migrations')), files.length)
})

test('every geometry column is SRID 4326', { skip }, () => {
  assert.equal(psql(DB, `SELECT count(*) FROM geometry_columns WHERE f_table_schema = 'public' AND srid <> 4326`), '0')
  assert.ok(Number(psql(DB, `SELECT count(*) FROM geometry_columns WHERE f_table_schema = 'public'`)) >= 15)
})

test('a geometry with another SRID is refused, one labelled 4326 is stored', { skip }, () => {
  const err = mustFail(`INSERT INTO places (name, lat, lng, position) VALUES ('x', 1, 1, ST_GeomFromText('POINT(1 1)', 3857));`)
  assert.match(err, /Geometry SRID \(3857\) does not match column SRID \(4326\)/)
  assert.equal(scratch(`INSERT INTO places (name, lat, lng, position) VALUES ('x', 1, 1, ST_GeomFromText('POINT(1 1)', 4326)); SELECT ST_SRID(position) FROM places;`), '4326')
})

test('an enum column refuses a value outside its list', { skip }, () => {
  const err = mustFail(`INSERT INTO ai_images (name, status) VALUES ('x', 'nonsense');`)
  assert.match(err, /ai_images_status_check/)
  assert.equal(scratch(`INSERT INTO ai_images (name, status) VALUES ('x', 'rejected'); SELECT status FROM ai_images;`), 'rejected')
})

test('a citext column matches and deduplicates case-insensitively', { skip }, () => {
  assert.equal(scratch(`INSERT INTO ai_images (name) VALUES ('Sofa'); SELECT count(*) FROM ai_images WHERE name = 'SOFA';`), '1')
  const err = mustFail(`INSERT INTO ai_images (name) VALUES ('Sofa'); INSERT INTO ai_images (name) VALUES ('sofa');`)
  assert.match(err, /duplicate key value violates unique constraint/)
})

test('a citext column keeps the varchar limit it had in MySQL', { skip }, () => {
  const err = mustFail(`INSERT INTO ai_images (name) VALUES ('${'x'.repeat(256)}');`)
  assert.match(err, /ai_images_name_length/)
  assert.equal(scratch(`INSERT INTO ai_images (name) VALUES ('${'x'.repeat(255)}'); SELECT count(*) FROM ai_images;`), '1')
})

test('an ON UPDATE column moves unless the statement sets it to a different value; setting it to itself still moves it', { skip }, () => {
  // MySQL leaves the column alone for SET ts = ts. A BEFORE trigger cannot tell that from not
  // naming the column, so PostgreSQL moves it. The difference is listed in the docs page.
  const out = scratch(`
    INSERT INTO abtest (uid, variant, shown, action, rate, timestamp) VALUES ('u', 'v', 0, 0, 0, '2020-01-01 00:00:00+00');
    UPDATE abtest SET shown = 1, timestamp = '2020-01-01 00:00:00+00';
    SELECT timestamp = '2020-01-01 00:00:00+00' FROM abtest;
    UPDATE abtest SET shown = 2, timestamp = '2021-02-03 04:05:06+00';
    SELECT timestamp = '2021-02-03 04:05:06+00' FROM abtest;
    UPDATE abtest SET shown = 3;
    SELECT timestamp > '2022-01-01' FROM abtest;`).split('\n')
  assert.deepEqual(out.slice(-3), ['f', 't', 't'])
})

test('a case-sensitive unique column stays case sensitive', { skip }, () => {
  assert.equal(scratch(`INSERT INTO abtest (uid, variant, shown, action, rate) VALUES ('A', 'x', 0, 0, 0), ('a', 'x', 0, 0, 0); SELECT count(*) FROM abtest;`), '2')
})

test('identity columns take explicit ids and iznik_reset_identities moves them on', { skip }, () => {
  const out = scratch(`
    INSERT INTO ai_images (id, name) VALUES (100, 'a');
    SELECT iznik_reset_identities();
    INSERT INTO ai_images (name) VALUES ('b') RETURNING id;`)
  assert.match(out.split('\n').pop(), /^101$/)
  // On an empty table the sequence starts at 1.
  assert.equal(scratch(`SELECT iznik_reset_identities(); INSERT INTO ai_images (name) VALUES ('b') RETURNING id;`).split('\n').pop(), '1')
})

test('a zero-date default becomes the epoch', { skip }, () => {
  assert.equal(
    psql(DB, `SELECT column_default FROM information_schema.columns WHERE table_name = 'communityevents_dates' AND column_name = 'end'`),
    "'1970-01-01 00:00:00+00'::timestamp with time zone"
  )
})

test('generated columns compute from the stored ones', { skip }, () => {
  const out = scratch(`INSERT INTO users_emails (email) VALUES ('Foo@Example.COM'); SELECT md5hash = md5('foo@example.com') FROM users_emails;`)
  assert.equal(out.split('\n').pop(), 't')
})

test('set columns are comma separated text', { skip }, () => {
  assert.equal(psql(DB, `SELECT data_type FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'systemrole'`), 'text')
})

test('every ON UPDATE CURRENT_TIMESTAMP trigger fires like MySQL', { skip }, () => {
  const sql = fs.readFileSync(path.join(here, 'check-triggers.sql'), 'utf8')
  const r = psql(DB, `BEGIN;\n${sql}\nROLLBACK;`, { allowError: true })
  assert.equal(r.status, 0, r.stderr)
  assert.match(r.stderr, /triggers checked on \d+ tables; skipped \(no changeable column\):\s*$/m)
})

test('loading the baseline into a second empty database gives the same columns and types', { skip }, () => {
  const second = `${DB}_again`
  try {
    psql('postgres', `DROP DATABASE IF EXISTS ${second} WITH (FORCE); CREATE DATABASE ${second};`)
    psql(second, fs.readFileSync(baselinePath, 'utf8'))
    const q = `SELECT md5(string_agg(c.relname || ':' || a.attname || ':' || format_type(a.atttypid, a.atttypmod), ',' ORDER BY c.relname, a.attnum)) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped`
    assert.equal(psql(second, q), psql(DB, q))
  } finally {
    psql('postgres', `DROP DATABASE IF EXISTS ${second} WITH (FORCE);`)
  }
})

test('setup-pg-database.sh builds both databases, leaves a built one alone, and rebuilds on request', { skip }, () => {
  const script = path.join(here, '../../scripts/setup-pg-database.sh')
  const a = `${DB}_s1`
  const b = `${DB}_s2`
  const run = (extra = {}) =>
    spawnSync('bash', [script], { encoding: 'utf8', env: { ...process.env, PG_PSQL: `${PSQL}`, PG_DATABASE: a, PG_TEST_DATABASE: b, ...extra } })
  try {
    const tables = (fs.readFileSync(baselinePath, 'utf8').match(/^CREATE TABLE /gm) || []).length
    let r = run()
    assert.equal(r.status, 0, r.stdout + r.stderr)
    assert.match(r.stdout, new RegExp(`PostgreSQL database ${a} ready \\(${tables} tables\\)`))
    assert.match(r.stdout, new RegExp(`PostgreSQL database ${b} ready \\(${tables} tables\\)`))
    r = run()
    assert.equal(r.status, 0, r.stdout + r.stderr)
    assert.match(r.stdout, new RegExp(`${a} already has ${tables} tables`))
    psql(a, 'CREATE TABLE marker (x int)')
    r = run({ PG_REBUILD: '1' })
    assert.equal(r.status, 0, r.stdout + r.stderr)
    assert.equal(psql(a, `SELECT count(*) FROM pg_class WHERE relname = 'marker'`), '0')
  } finally {
    psql('postgres', `DROP DATABASE IF EXISTS ${a} WITH (FORCE); DROP DATABASE IF EXISTS ${b} WITH (FORCE);`)
  }
})

test('a baseline that fails to load leaves an empty database, and the next run does not call it built', { skip }, () => {
  const script = path.join(here, '../../scripts/setup-pg-database.sh')
  const db = `${DB}_bad`
  const bad = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'pgbad-')), 'baseline.sql')
  fs.writeFileSync(bad, 'CREATE TABLE half (a int);\nSELECT no_such_function();\n')
  const run = () =>
    spawnSync('bash', [script], { encoding: 'utf8', env: { ...process.env, PG_PSQL: `${PSQL}`, PG_DATABASE: db, PG_TEST_DATABASE: db, PG_BASELINE: bad } })
  try {
    for (let i = 0; i < 2; i++) {
      const r = run()
      assert.notEqual(r.status, 0, r.stdout)
      assert.match(r.stdout, /FAILED/)
      assert.ok(!/already has/.test(r.stdout), 'a failed load must not look built on the next run')
    }
    assert.equal(psql(db, `SELECT count(*) FROM pg_class WHERE relname = 'half'`), '0')
  } finally {
    psql('postgres', `DROP DATABASE IF EXISTS ${db} WITH (FORCE);`)
  }
})
