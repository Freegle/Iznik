// Sweeps the PostgreSQL stored functions against MySQL on many generated inputs, beyond the fixed table
// in iznik-batch/tests/fixtures/stored-functions/cases.json.
//
//   PG_SCHEMA_PSQL="docker exec -i freegle-postgres psql -U root" node tools/pg-schema/sweep-functions.mjs
//
// It compares:
//   * haversine on N identical points and N random UK pairs (the answers must match to the bit, and
//     NULL must appear for the same inputs),
//   * damlevlim on N random pairs and limits, against the MySQL routine run by record-function-cases.mjs.
//
// MySQL is only read, in the percona container (see record-function-cases.mjs for its settings). The
// PostgreSQL side uses a database named ${PG_SWEEP_DB:-pg_functions_sweep}, which it creates and drops.
// Exit status is 1 if anything differs. N is SWEEP_N (default 40000 for haversine, 1500 for damlevlim).
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { runDamlevlim } from './record-function-cases.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
const PSQL = process.env.PG_SCHEMA_PSQL
if (!PSQL) {
  console.error('Set PG_SCHEMA_PSQL, for example "docker exec -i freegle-postgres psql -U root"')
  process.exit(2)
}
const PREFIX = process.env.COMPOSE_PROJECT_NAME || 'freegle'
const MYSQL = process.env.MYSQL_CONTAINER || `${PREFIX}-percona`
const DB = process.env.PG_SWEEP_DB || 'pg_functions_sweep'
const N_POINTS = Number(process.env.SWEEP_N || 40000)
const N_STRINGS = Number(process.env.SWEEP_N || 1500)

function run(cmd, args, input) {
  const r = spawnSync(cmd, args, { input, encoding: 'utf8', maxBuffer: 1 << 29 })
  if (r.status !== 0) throw new Error(r.stderr)
  return r.stdout
}
const mysql = (sql) =>
  run('docker', ['exec', '-i', MYSQL, 'mysql', '-uroot', '-piznik', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'], sql)
const pg = (db, sql) => run('sh', ['-c', `${PSQL} -d ${db} -X -q -At -F '\t' -v ON_ERROR_STOP=1`], sql)

const f = (c) => `CAST(${c} AS FLOAT)`
const body = (a, b, c, d) =>
  `CAST(CAST(69 * DEGREES(ACOS(COS(RADIANS(${a})) * COS(RADIANS(${c})) * COS(RADIANS(${d}) - RADIANS(${b})) + SIN(RADIANS(${a})) * SIN(RADIANS(${c})))) AS FLOAT) AS DOUBLE)`

let failed = false

function sweepHaversine(kind) {
  const gen =
    kind === 'identical'
      ? `SELECT i, ROUND(-85 + RAND(i)*170, 6) AS a, ROUND(-179 + RAND(i+1000003)*358, 6) AS b, NULL AS c, NULL AS d`
      : `SELECT i, ROUND(49 + RAND(i)*10, 6) AS a, ROUND(-8 + RAND(i+1000003)*10, 6) AS b, ROUND(49 + RAND(i+2000003)*10, 6) AS c, ROUND(-8 + RAND(i+3000007)*10, 6) AS d`
  const sql = `SET SESSION cte_max_recursion_depth = 1000000;
WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < ${N_POINTS}), p AS (${gen} FROM n),
q AS (SELECT i, a, b, IFNULL(c, a) AS c, IFNULL(d, b) AS d FROM p)
SELECT i, a, b, c, d, ${body(f('a'), f('b'), f('c'), f('d'))} FROM q;`
  const rows = mysql(sql).trim().split('\n').map((l) => l.split('\t'))
  const values = rows.map((r) => `(${r[0]}, ${r[1]}, ${r[2]}, ${r[3]}, ${r[4]})`).join(',')
  const out = pg(DB, `SELECT i, haversine(a, b, c, d)::float8 FROM (VALUES ${values}) AS t(i, a, b, c, d) ORDER BY i;`)
    .trim()
    .split('\n')
    .map((l) => l.split('\t'))
  let nullMysql = 0
  let nullPg = 0
  let nullMismatch = 0
  let valueMismatch = 0
  let worst = 0
  rows.forEach((r, k) => {
    const m = r[5] === 'NULL' ? null : Number(r[5])
    const p = out[k][1] === '' ? null : Number(out[k][1])
    if (m === null) nullMysql++
    if (p === null) nullPg++
    if ((m === null) !== (p === null)) nullMismatch++
    else if (m !== null) {
      const d = Math.abs(m - p) / Math.max(Math.abs(m), Math.abs(p), 1e-300)
      worst = Math.max(worst, d)
      if (d > 0) valueMismatch++
    }
  })
  console.log(`haversine ${kind}: ${rows.length} inputs, NULL on MySQL ${nullMysql}, on PostgreSQL ${nullPg}, NULL differs ${nullMismatch}, values differ ${valueMismatch}, largest relative difference ${worst}`)
  if (nullMismatch || valueMismatch) failed = true
}

function sweepDamlevlim() {
  let s = 12345
  const rand = () => {
    s = (s * 1103515245 + 12345) & 0x7fffffff
    return s / 0x7fffffff
  }
  const L = 'abcdefghijklmnopqrstuvwxyz.-0123'
  const word = (n) => Array.from({ length: n }, () => L[Math.floor(rand() * L.length)]).join('')
  const mutate = (x) => {
    const t = x.split('')
    for (let e = Math.floor(rand() * 5); e > 0; e--) {
      const p = Math.floor(rand() * (t.length || 1))
      const k = Math.floor(rand() * 4)
      if (k === 0) t.splice(p, 1)
      else if (k === 1) t.splice(p, 0, L[Math.floor(rand() * L.length)])
      else if (k === 2 && t.length > 1) {
        const q = Math.min(p, t.length - 2)
        ;[t[q], t[q + 1]] = [t[q + 1], t[q]]
      } else if (t.length) t[p] = L[Math.floor(rand() * L.length)]
    }
    return t.join('')
  }
  const cases = []
  for (let i = 0; i < N_STRINGS; i++) {
    const a = word(1 + Math.floor(rand() * 14))
    const b = rand() < 0.7 ? mutate(a) : word(1 + Math.floor(rand() * 14))
    const n = rand() < 0.5 ? Buffer.byteLength(b) : Math.floor(rand() * 12)
    cases.push({ s1: a, s2: b, n })
  }
  const want = runDamlevlim(cases)
  const got = pg(DB, cases.map((c) => `SELECT damlevlim('${c.s1}', '${c.s2}', ${c.n});`).join('\n'))
    .trim()
    .split('\n')
    .map(Number)
  const bad = cases.map((c, i) => [c, want[i], got[i]]).filter(([, a, b]) => a !== b)
  console.log(`damlevlim: ${cases.length} inputs, differences ${bad.length}`)
  if (bad.length) {
    console.log(JSON.stringify(bad.slice(0, 5)))
    failed = true
  }
}

try {
  pg('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE); CREATE DATABASE ${DB};`)
  pg(DB, fs.readFileSync(path.join(here, '../../iznik-batch/database/postgres/functions.sql'), 'utf8'))
  sweepHaversine('identical')
  sweepHaversine('random')
  sweepDamlevlim()
} finally {
  pg('postgres', `DROP DATABASE IF EXISTS ${DB} WITH (FORCE);`)
}
process.exit(failed ? 1 : 0)
