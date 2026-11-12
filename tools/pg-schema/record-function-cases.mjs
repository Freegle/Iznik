// Records what MySQL answers for each input in the stored-function parity table.
//
//   node tools/pg-schema/record-function-cases.mjs            # rewrite the table
//   node tools/pg-schema/record-function-cases.mjs --check    # fail if MySQL now answers differently
//
// The table is iznik-batch/tests/fixtures/stored-functions/cases.json. It holds the inputs and the
// answers MySQL gave, and both engines are tested against it: the Laravel test runs it on MySQL
// against the real stored functions, tools/pg-schema/functions.test.mjs runs it on PostgreSQL.
//
// This tool only reads. It runs SELECT statements in the percona container and never creates or
// changes anything there, so it does not need the stored functions to exist. haversine and
// GetMaxDimension are single expressions, so their bodies are evaluated inline with the arguments
// coerced as the function would (FLOAT parameters, FLOAT result). damlevlim is a loop that a SELECT
// cannot run, so its algorithm is evaluated step by step in a recursive CTE, one cell of the table
// per step, with MySQL doing every comparison in the database's own collation. The Laravel test
// then checks the table against the real functions, so a slip in the transcription shows up there.
//
// Settings, all optional:
//   COMPOSE_PROJECT_NAME  container prefix (default freegle)
//   MYSQL_CONTAINER       the percona container (default <prefix>-percona)
//   MYSQL_COLLATION       collation of the iznik schema (default utf8mb4_0900_ai_ci)
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
export const CASES_PATH = path.join(here, '../../iznik-batch/tests/fixtures/stored-functions/cases.json')

const PREFIX = process.env.COMPOSE_PROJECT_NAME || 'freegle'
const CONTAINER = process.env.MYSQL_CONTAINER || `${PREFIX}-percona`
const COLLATION = process.env.MYSQL_COLLATION || 'utf8mb4_0900_ai_ci'

// ---------------------------------------------------------------------------------------------
// Inputs
// ---------------------------------------------------------------------------------------------

// A small deterministic generator so the table is the same every time it is rebuilt.
function rng(seed) {
  let s = seed >>> 0
  return () => {
    s = (s + 0x6d2b79f5) >>> 0
    let t = s
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}
const round = (x, dp) => Number(x.toFixed(dp))

// Pairs of points as the application sees them: UK places, then the rest of the world, then the
// edges (poles, the antimeridian, antipodes, the same point, points a metre apart).
function haversineInputs() {
  const out = []
  const add = (name, lat1, lon1, lat2, lon2) => out.push({ name, args: [lat1, lon1, lat2, lon2] })

  add('london to manchester', 51.5074, -0.1278, 53.4808, -2.2426)
  add('manchester to london', 53.4808, -2.2426, 51.5074, -0.1278)
  add('london to edinburgh', 51.5074, -0.1278, 55.9533, -3.1883)
  add('bristol to bath', 51.4545, -2.5879, 51.3811, -2.359)
  add('norwich to ormesby', 52.6309, 1.2974, 52.6847, 1.7106)
  add('penzance to john o groats', 50.1186, -5.5373, 58.6373, -3.0689)
  add('belfast to cardiff', 54.5973, -5.9301, 51.4816, -3.1791)
  add('london to sydney', 51.5074, -0.1278, -33.8688, 151.2093)
  add('sydney to melbourne', -33.87, 151.21, -37.81, 144.96)
  add('new york to los angeles', 40.7128, -74.006, 34.0522, -118.2437)
  add('equator one degree of longitude', 0, 0, 0, 1)
  add('equator one degree of latitude', 0, 0, 1, 0)
  add('origin to one one', 0, 0, 1, 1)
  add('north pole to south pole', 90, 0, -90, 0)
  add('north pole to london', 90, 0, 51.5074, -0.1278)
  add('across the antimeridian', 0, 179.5, 0, -179.5)
  add('antipodal on the equator', 0, 0, 0, 180)
  add('antipodal', 51.5, -0.1, -51.5, 179.9)
  add('a metre apart', 51.5, -0.1, 51.500009, -0.1)
  add('ten metres apart', 51.5, -0.1, 51.50009, -0.1)
  add('a hundred metres apart', 51.5, -0.1, 51.5009, -0.1)
  add('a small step in both', 51.5, -0.1, 51.5001, -0.1001)
  add('southern hemisphere pair', -33.87, 151.21, -37.81, 144.96)
  add('both coordinates negative', -10.5, -20.25, -11.5, -21.75)
  add('latitude past the pole', 100, 0, 0, 0)
  add('longitude past the date line', 0, 200, 0, -200)
  add('null first latitude', null, 0, 0, 0)
  add('null last longitude', 0, 0, 0, null)

  // Real UK-shaped pairs at the precision the columns hold (six decimal places).
  const r = rng(101)
  for (let i = 0; i < 40; i++) {
    add(`uk pair ${i + 1}`, round(50 + r() * 8.5, 6), round(-6 + r() * 7.5, 6), round(50 + r() * 8.5, 6), round(-6 + r() * 7.5, 6))
  }
  // Nearby UK pairs, the shape of a group lookup for a point inside a group.
  for (let i = 0; i < 20; i++) {
    const lat = round(50 + r() * 8.5, 6)
    const lng = round(-6 + r() * 7.5, 6)
    add(`uk near pair ${i + 1}`, lat, lng, round(lat + (r() - 0.5) * 0.2, 6), round(lng + (r() - 0.5) * 0.3, 6))
  }
  // Worldwide pairs.
  for (let i = 0; i < 30; i++) {
    add(`world pair ${i + 1}`, round(-85 + r() * 170, 4), round(-179 + r() * 358, 4), round(-85 + r() * 170, 4), round(-179 + r() * 358, 4))
  }
  return out
}

// The same point twice. The formula can come out a hair over 1 and ACOS then gives NULL in MySQL;
// which points do it depends only on the arithmetic, so these are picked from a sweep of 20,000.
// The recorder finds them, so this lists only the candidates.
function samePointCandidates() {
  const r = rng(7)
  const out = []
  for (let i = 0; i < 400; i++) {
    const lat = round(-80 + r() * 160, 4)
    const lng = round(-179 + r() * 358, 4)
    out.push({ name: `same point ${i + 1}`, args: [lat, lng, lat, lng] })
  }
  return out
}

const SQUARE = (x, y, s) => `POLYGON((${x} ${y},${x} ${y + s},${x + s} ${y + s},${x + s} ${y},${x} ${y}))`

function maxDimensionInputs() {
  const out = []
  const add = (name, wkt) => out.push({ name, wkt })

  add('point', 'POINT(5 5)')
  add('point in the uk', 'POINT(-0.1278 51.5074)')
  add('line', 'LINESTRING(0 0,10 10)')
  add('long line', 'LINESTRING(-5 50,2 58,3 59)')
  add('unit square', SQUARE(0, 0, 1))
  add('ten degree square', SQUARE(0, 0, 10))
  add('square clockwise', 'POLYGON((0 0,10 0,10 10,0 10,0 0))')
  add('square far from the origin', SQUARE(-170, -80, 5))
  add('negative coordinates', SQUARE(-3.5, 51.25, 0.5))
  add('triangle', 'POLYGON((0 0,4 0,0 3,0 0))')
  add('thin sliver', 'POLYGON((0 0,10 0,10 0.0001,0 0.0001,0 0))')
  add('tiny square', SQUARE(1.234567, 52.345678, 0.0001))
  add('neighbourhood sized', SQUARE(-2.25, 53.5, 0.02))
  add('town sized', SQUARE(-1.5, 52.5, 0.2))
  add('polygon with a hole', 'POLYGON((0 0,10 0,10 10,0 10,0 0),(2 2,2 4,4 4,4 2,2 2))')
  add('polygon with two holes', 'POLYGON((0 0,20 0,20 20,0 20,0 0),(2 2,2 4,4 4,4 2,2 2),(10 10,10 12,12 12,12 10,10 10))')
  add('concave L shape', 'POLYGON((0 0,6 0,6 2,2 2,2 6,0 6,0 0))')
  add('multipolygon of two squares', 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)),((5 5,5 7,7 7,7 5,5 5)))')
  add('multipolygon of one square', 'MULTIPOLYGON(((0 0,0 3,3 3,3 0,0 0)))')
  add('multipoint', 'MULTIPOINT((0 0),(1 1))')
  add('multilinestring', 'MULTILINESTRING((0 0,1 1),(5 5,9 9))')
  add('collection of points', 'GEOMETRYCOLLECTION(POINT(1 1),POINT(2 2))')
  add('collection of a point and a line', 'GEOMETRYCOLLECTION(POINT(1 1),LINESTRING(0 0,5 5))')
  add('collection with a polygon', `GEOMETRYCOLLECTION(POINT(1 1),${SQUARE(0, 0, 2)})`)
  add('empty collection', 'GEOMETRYCOLLECTION EMPTY')
  add('self-touching ring', 'POLYGON((0 0,4 0,4 4,2 0,0 4,0 0))')
  add('bow tie', 'POLYGON((0 0,4 4,4 0,0 4,0 0))')
  // Ring shapes from real boundaries: many vertices, one decimal-place pattern.
  const r = rng(55)
  for (let i = 0; i < 12; i++) {
    const cx = round(-5 + r() * 6, 4)
    const cy = round(50 + r() * 8, 4)
    const rad = round(0.005 + r() * 0.3, 5)
    const pts = []
    const n = 8 + Math.floor(r() * 20)
    for (let k = 0; k < n; k++) {
      const a = (k / n) * 2 * Math.PI
      const rr = rad * (0.6 + 0.4 * r())
      pts.push(`${round(cx + rr * Math.cos(a), 6)} ${round(cy + rr * Math.sin(a), 6)}`)
    }
    pts.push(pts[0])
    add(`boundary ${i + 1}`, `POLYGON((${pts.join(',')}))`)
  }
  return out
}

// Strings for the loop. Each is a pair and the limit that the one call site passes: the byte length
// of the second string.
const bytes = (s) => Buffer.byteLength(s, 'utf8')

function damlevlimInputs() {
  const out = []
  const add = (name, s1, s2, n = bytes(s2)) => out.push({ name, s1, s2, n })

  add('identical', 'gmail.com', 'gmail.com')
  add('identical but for case', 'gmail.com', 'GMAIL.COM')
  add('one substitution', 'gmail.com', 'gmeil.com')
  add('transposition', 'gmail.com', 'gmial.com')
  add('missing letter', 'gmail.com', 'gmai.com')
  add('extra letter', 'gmail.com', 'gmaill.com')
  add('two edits', 'gmail.com', 'gmeil.co')
  add('three edits', 'gmail.com', 'gmeil.cm.')
  add('far apart', 'gmail.com', 'yahoo.co.uk')
  add('yahoo typo', 'yahoo.com', 'yaho.com')
  add('hotmail typo', 'hotmail.com', 'hotmial.com')
  add('outlook typo', 'outlook.com', 'outlok.com')
  add('compare with a longer string', 'hotmail.co.uk', 'hotmail.com')
  add('empty first', '', 'abc')
  add('empty second', 'abc', '')
  add('both empty', '', '')
  add('one character each, same', 'a', 'a')
  add('one character each, different', 'a', 'b')
  add('one character against many', 'gmail.com', 'g')
  add('two characters against many', 'gmail.com', 'gm')
  add('three characters against many', 'gmail.com', 'gma')
  add('short input, close match', 'ab', 'abc')
  add('limit zero', 'abc', 'abd', 0)
  add('limit one', 'abc', 'abd', 1)
  add('limit two', 'abc', 'xyz', 2)
  add('limit three, distance three', 'abc', 'xyz', 3)
  add('limit larger than the strings', 'kitten', 'sitting', 50)
  add('kitten and sitting', 'kitten', 'sitting')
  add('sunday and saturday', 'sunday', 'saturday')
  add('prefix', 'abc', 'abcdef')
  add('suffix', 'abcdef', 'def')
  add('repeated letters', 'aaaa', 'aa')
  add('digits', '12345', '12354')
  add('accented against plain', 'café.com', 'cafe.com')
  add('plain against accented', 'cafe.com', 'café.com')
  add('two byte letter changes the byte limit', 'gmail.com', 'gmäil.com')
  add('long strings, one edit', 'a'.repeat(40) + 'b', 'a'.repeat(40) + 'c')
  add('long strings, far apart', 'a'.repeat(40), 'b'.repeat(40))
  add('long and short', 'a'.repeat(60), 'a'.repeat(5))
  const r = rng(909)
  const letters = 'abcdefghijklmnopqrstuvwxyz.-0123456789'
  const word = (len) => Array.from({ length: len }, () => letters[Math.floor(r() * letters.length)]).join('')
  const mutate = (s, edits) => {
    let t = s.split('')
    for (let e = 0; e < edits; e++) {
      const k = Math.floor(r() * 3)
      const p = Math.floor(r() * Math.max(1, t.length))
      if (k === 0 && t.length) t.splice(p, 1)
      else if (k === 1) t.splice(p, 0, letters[Math.floor(r() * letters.length)])
      else if (t.length) t[p] = letters[Math.floor(r() * letters.length)]
    }
    return t.join('')
  }
  for (let i = 0; i < 40; i++) {
    const a = word(5 + Math.floor(r() * 12))
    add(`random near pair ${i + 1}`, a, mutate(a, Math.floor(r() * 4)))
  }
  for (let i = 0; i < 15; i++) {
    add(`random unrelated pair ${i + 1}`, word(4 + Math.floor(r() * 12)), word(4 + Math.floor(r() * 12)))
  }
  return out
}

// The call site: domains_common holds the common mail domains with a count, and the API suggests up
// to five whose damlevlim to what the member typed is under 3, most common first.
export const DOMAINS = [
  ['gmail.com', 9000], ['hotmail.com', 8000], ['yahoo.com', 7000], ['yahoo.co.uk', 6500], ['hotmail.co.uk', 6000],
  ['outlook.com', 5500], ['icloud.com', 5000], ['btinternet.com', 4500], ['live.co.uk', 4000], ['aol.com', 3500],
  ['sky.com', 3000], ['talktalk.net', 2800], ['msn.com', 2600], ['me.com', 2400], ['virginmedia.com', 2200],
  ['googlemail.com', 2000], ['live.com', 1800], ['ntlworld.com', 1600], ['protonmail.com', 1400], ['mail.com', 1200],
  ['gmx.com', 1000], ['yahoo.fr', 900], ['att.net', 800], ['comcast.net', 700], ['example.org', 600],
]
function domainInputs() {
  return [
    'gmial.com', 'gmail.con', 'gmal.com', 'gmaill.com', 'gnail.com', 'GMAIL.COM', 'Gmial.Com', 'hotmial.com',
    'hotmail.co', 'hotmai.com', 'yaho.com', 'yahoo.co.uk', 'yahoo.couk', 'outlok.com', 'outlook.co',
    'iclod.com', 'icloud.co', 'btinternet.co', 'sky.co', 'sky.c', 'aol.co', 'ao.com', 'me.co', 'live.co.u',
    'mail.co', 'gmx.co', 'example.com', 'unknown.org', 'g', 'gm', 'ab', 'a', 'x.c', 'xy', 'com', 'gmail',
    'gmail.com.', 'gmai.l.com', 'café.com', 'live', 'sky.', 'msn.',
  ].map((input) => ({ name: input, input }))
}

// ---------------------------------------------------------------------------------------------
// MySQL
// ---------------------------------------------------------------------------------------------

function mysql(sql) {
  const r = spawnSync(
    'docker',
    ['exec', '-i', CONTAINER, 'mysql', '-uroot', '-piznik', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names', '--max-allowed-packet=64M'],
    { input: sql, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 },
  )
  if (r.status !== 0) {
    const err = r.stderr.split('\n').filter((l) => !l.includes('Using a password')).join('\n')
    throw new Error(`mysql failed: ${err}`)
  }
  return r.stdout
}

// A statement that may fail is run on its own so that one bad input does not stop the rest.
function mysqlTry(sql) {
  const r = spawnSync(
    'docker',
    ['exec', '-i', CONTAINER, 'mysql', '-uroot', '-piznik', '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'],
    { input: sql, encoding: 'utf8' },
  )
  if (r.status === 0) return { ok: true, out: r.stdout.trim() }
  const msg = r.stderr.split('\n').filter((l) => l.startsWith('ERROR'))[0] || r.stderr
  return { ok: false, error: msg.replace(/^ERROR (\d+) \([^)]*\) at line \d+: /, 'ERROR $1: ') }
}

const num = (v) => (v === null || v === undefined ? 'NULL' : String(v))
const hex = (s) => `_utf8mb4 X'${Buffer.from(s, 'utf8').toString('hex')}'`

// The body of haversine() with each parameter coerced to FLOAT, as the function's own parameters are,
// and the result coerced to FLOAT as RETURNS FLOAT does. Read back as a DOUBLE so the single-precision
// value is printed exactly.
function haversineSql(a) {
  const f = (v) => `CAST(${num(v)} AS FLOAT)`
  const [lat1, lon1, lat2, lon2] = a.map(f)
  return `SELECT CAST(CAST(69 * DEGREES(ACOS(COS(RADIANS(${lat1})) * COS(RADIANS(${lat2})) * COS(RADIANS(${lon2}) - RADIANS(${lon1})) + SIN(RADIANS(${lat1})) * SIN(RADIANS(${lat2})))) AS FLOAT) AS DOUBLE);`
}

// The body of GetMaxDimension with the geometry bound as the application binds it.
function maxDimensionSql(wkt) {
  const g = `ST_GeomFromText(${hex(wkt)}, 3857)`
  return `SELECT IF(ST_Dimension(${g}) > 1, SQRT(SQRT(ST_Area(${g}) / PI()) * SQRT(ST_Area(${g}) / PI()) * 2), 0);`
}

// damlevlim as a table of steps. Each row is the state of the loop after one assignment block of the
// function: i and j are the loop counters, c the running cell, cmin the row minimum, cv1 the previous
// row and cv0 the row being built (as JSON arrays where the function uses a byte string). j = 0 marks
// the test at the top of the outer loop. res is set when the function would RETURN.
function damlevlimSql(cases) {
  const eq = (a, b) => `(${a} COLLATE ${COLLATION}) = (${b} COLLATE ${COLLATION})`
  const prev = (k) => `IF(i = 1, ${k}, CAST(JSON_EXTRACT(cv1, CONCAT('$[', ${k}, ']')) AS SIGNED))`
  const cell = `LEAST(LEAST(c + 1, ${prev('j - 1')} + IF(${eq('SUBSTRING(s1, i, 1)', 'SUBSTRING(s2, j, 1)')}, 0, 1)), ${prev('j')} + 1)`
  const rows = cases.map((c, k) => `SELECT ${k} AS cid, ${hex(c.s1)} AS s1, ${hex(c.s2)} AS s2, ${c.n} AS n`).join(' UNION ALL ')
  return `
SET SESSION cte_max_recursion_depth = 4000000;
WITH RECURSIVE cases AS (${rows}),
st AS (
  SELECT cid, s1, s2, n, CHAR_LENGTH(s1) AS l1, CHAR_LENGTH(s2) AS l2,
         1 AS i, 0 AS j, 0 AS c, 0 AS cmin, JSON_ARRAY(0) AS cv1, JSON_ARRAY(0) AS cv0,
         CASE WHEN ${eq('s1', 's2')} THEN 0 WHEN CHAR_LENGTH(s1) = 0 THEN CHAR_LENGTH(s2) WHEN CHAR_LENGTH(s2) = 0 THEN CHAR_LENGTH(s1) ELSE NULL END AS res
  FROM cases
  UNION ALL
  SELECT cid, s1, s2, n, l1, l2,
    CASE WHEN j = 0 THEN i WHEN j < l2 THEN i ELSE i + 1 END,
    CASE WHEN j = 0 THEN IF(i <= l1 AND cmin < n, 1, 0) WHEN j < l2 THEN j + 1 ELSE 0 END,
    CASE WHEN j = 0 THEN IF(i <= l1 AND cmin < n, i, c) ELSE ${cell} END,
    CASE WHEN j = 0 THEN IF(i <= l1 AND cmin < n, i, cmin) ELSE LEAST(cmin, ${cell}) END,
    CASE WHEN j = 0 OR j < l2 THEN cv1 ELSE JSON_ARRAY_APPEND(cv0, '$', ${cell}) END,
    CASE WHEN j = 0 THEN IF(i <= l1 AND cmin < n, JSON_ARRAY(i), cv0) ELSE JSON_ARRAY_APPEND(cv0, '$', ${cell}) END,
    CASE WHEN j = 0 AND NOT (i <= l1 AND cmin < n) THEN IF(i <= l1, cmin, c) ELSE NULL END
  FROM st WHERE res IS NULL
)
SELECT cid, res FROM st WHERE res IS NOT NULL ORDER BY cid;`
}

export function runDamlevlim(cases) {
  const out = mysql(damlevlimSql(cases))
  const byCid = new Map(out.trim().split('\n').filter(Boolean).map((l) => l.split('\t').map(Number)))
  return cases.map((c, k) => {
    if (!byCid.has(k)) throw new Error(`no result for damlevlim case ${c.name}`)
    return byCid.get(k)
  })
}

// ---------------------------------------------------------------------------------------------
// Build the table
// ---------------------------------------------------------------------------------------------

function build() {
  const version = mysql('SELECT VERSION();').trim()

  // haversine: record each input, one statement for all of them.
  const hv = haversineInputs()
  const same = samePointCandidates()
  const hvAll = [...hv, ...same]
  const hvOut = mysql(hvAll.map((c) => haversineSql(c.args)).join('\n')).trim().split('\n')
  if (hvOut.length !== hvAll.length) throw new Error(`haversine: expected ${hvAll.length} rows, got ${hvOut.length}`)
  const hvVal = (s) => (s === 'NULL' ? null : Number(s))
  const haversine = hv.map((c, k) => ({ ...c, mysql: hvVal(hvOut[k]) }))
  // Keep thirty same-point inputs MySQL turns into NULL and thirty it does not.
  const sameVals = same.map((c, k) => ({ ...c, mysql: hvVal(hvOut[hv.length + k]) }))
  const nulls = sameVals.filter((c) => c.mysql === null).slice(0, 30)
  const values = sameVals.filter((c) => c.mysql !== null).slice(0, 30)
  if (nulls.length < 10) throw new Error('too few same-point NULL cases found')
  haversine.push(...nulls, ...values)

  // GetMaxDimension.
  const md = maxDimensionInputs()
  const maxdimension = md.map((c) => {
    const r = mysqlTry(maxDimensionSql(c.wkt))
    if (r.ok) return { ...c, mysql: r.out === 'NULL' ? null : Number(r.out) }
    return { ...c, mysql: null, mysqlError: r.error }
  })

  // damlevlim.
  const dl = damlevlimInputs()
  const dlOut = runDamlevlim(dl)
  const damlevlim = dl.map((c, k) => ({ ...c, mysql: dlOut[k] }))

  // The call site: every pair of (typed domain, common domain), then the suggestions as the API
  // returns them.
  const di = domainInputs()
  const pairs = []
  for (const d of di) for (const [dom] of DOMAINS) pairs.push({ s1: dom, s2: d.input, n: bytes(d.input) })
  const pOut = runDamlevlim(pairs)
  const domainSuggestions = di.map((d, k) => {
    const hits = []
    DOMAINS.forEach(([dom, count], idx) => {
      if (pOut[k * DOMAINS.length + idx] < 3) hits.push([dom, count])
    })
    hits.sort((a, b) => b[1] - a[1])
    return { name: d.name, input: d.input, suggestions: hits.slice(0, 5).map((h) => h[0]) }
  })

  return {
    _comment: 'Generated by tools/pg-schema/record-function-cases.mjs. Inputs are listed with the answer MySQL gave (mysql). A case with a divergence block is one where PostgreSQL answers differently; the block gives the PostgreSQL answer and the reason, and both sides are pinned by the tests.',
    mysqlVersion: version,
    collation: COLLATION,
    haversine,
    maxdimension,
    damlevlim,
    domains: DOMAINS.map(([domain, count]) => ({ domain, count })),
    domainSuggestions,
  }
}

// Inputs where PostgreSQL answers differently on purpose, with the PostgreSQL answer and the reason.
// The tests pin both sides.
const DIVERGENCES = {
  maxdimension: {
    'collection with a polygon': {
      pg: Math.sqrt((Math.sqrt(4 / Math.PI) ** 2) * 2),
      reason: 'MySQL refuses a GEOMETRYCOLLECTION that holds a polygon (ST_Area rejects the type); PostGIS adds up the polygons. No stored geometry is a collection.',
    },
  },
}

function applyDivergences(fresh) {
  for (const [key, byName] of Object.entries(DIVERGENCES)) {
    for (const c of fresh[key]) if (byName[c.name]) c.divergence = byName[c.name]
  }
  return fresh
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (isMain) {
  const old = fs.existsSync(CASES_PATH) ? JSON.parse(fs.readFileSync(CASES_PATH, 'utf8')) : null
  const fresh = applyDivergences(build())
  const text = JSON.stringify(fresh, null, 1) + '\n'
  if (process.argv.includes('--check')) {
    if (!old || JSON.stringify(old, null, 1) + '\n' !== text) {
      console.error('MySQL now answers differently from the committed table (or the table is missing). Run record-function-cases.mjs and look at the diff.')
      process.exit(1)
    }
    console.log('The committed table matches MySQL.')
  } else {
    fs.mkdirSync(path.dirname(CASES_PATH), { recursive: true })
    fs.writeFileSync(CASES_PATH, text)
    const n = (k) => fresh[k].length
    console.log(`Wrote ${CASES_PATH}: ${n('haversine')} haversine, ${n('maxdimension')} maxdimension, ${n('damlevlim')} damlevlim, ${n('domainSuggestions')} call-site cases (MySQL ${fresh.mysqlVersion})`)
  }
}
