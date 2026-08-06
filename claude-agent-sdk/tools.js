/**
 * Direct read-only investigation tools for the Freegle support AI.
 *
 * NO anonymisation: these return real data (the caller is an authenticated
 * moderator/support/admin). Everything here is read-only and bounded so a
 * query can't write or run away (SELECT-only guard, forced LIMIT, tight Loki
 * windows). Recipes are from the monitor-fsm / iznik-server-go/userdump.
 */

const { tool } = require('@anthropic-ai/claude-agent-sdk')
const { z } = require('zod')
const mysql = require('mysql2/promise')
const Database = require('better-sqlite3')
const { execFile } = require('child_process')
const util = require('util')
const fs = require('fs')
const os = require('os')
const path = require('path')
const execFileP = util.promisify(execFile)

// ---------------------------------------------------------------------------
// Read-only MySQL pool.
//   Local dev : percona / root / iznik  (whole-stack DB).
//   Edge/prod : a read-only grant against a read replica, via env.
// ---------------------------------------------------------------------------
let pool
function getPool() {
  if (!pool) {
    const host = process.env.DB_HOST || 'percona'
    // No privileged fallback on a non-local host: refuse rather than silently
    // connect as root if the read-only-grant env is missing (config drift on edge).
    const isLocal = host === 'percona' || host === 'localhost' || host === '127.0.0.1'
    const user = process.env.DB_USER || (isLocal ? 'root' : null)
    const password = process.env.DB_PASSWORD != null ? process.env.DB_PASSWORD : (isLocal ? 'iznik' : null)
    if (!user || password == null) {
      throw new Error('Refusing to open DB pool: set DB_USER/DB_PASSWORD (a read-only grant) for a non-local DB host')
    }
    pool = mysql.createPool({
      host,
      port: Number(process.env.DB_PORT || 3306),
      user,
      password,
      database: process.env.DB_NAME || 'iznik',
      connectionLimit: Number(process.env.DB_POOL || 4),
      connectTimeout: 10000,
    })
  }
  return pool
}

// Read-only SQL guard. Lives in ./guard.js (no mysql2 dep) so it is unit-testable
// on the host / in CI; see guard.test.js.
const { guardSelect } = require('./guard')

async function dbSelect(sql, maxRows = 500) {
  const guarded = guardSelect(sql, maxRows)
  // Fail CLOSED: take a dedicated connection and make it read-only + time-bounded
  // BEFORE running the query; if that can't be set, abort rather than run writable.
  const conn = await getPool().getConnection()
  try {
    // Two statements: SET TRANSACTION and SET <variable> are different SET
    // forms and cannot be comma-combined (syntax error on MySQL/Percona).
    await conn.query('SET SESSION TRANSACTION READ ONLY')
    await conn.query('SET SESSION max_execution_time = 15000')
    const [rows] = await conn.query(guarded)
    return rows
  } finally {
    conn.release()
  }
}

// Audit trail (the priority): who investigated whom, and with what. Structured to
// stdout (→ docker logs → Loki, queryable by `|= "SUPPORT_AUDIT"`) and appended to
// AUDIT_LOG_PATH if set (durable, e.g. a mounted volume).
function audit(entry) {
  const line = JSON.stringify({ ts: new Date().toISOString(), ...entry })
  console.log('SUPPORT_AUDIT ' + line)
  if (process.env.AUDIT_LOG_PATH) {
    try {
      fs.appendFileSync(process.env.AUDIT_LOG_PATH, line + '\n')
    } catch {}
  }
}

// ---------------------------------------------------------------------------
// Loki client (direct — no pseudonymizer proxy). Only useful against PROD,
// where user_id is populated. Prod is reached via a Windows SSH tunnel on the
// WSL default-gateway IP:3102 (see LOKI_URL). Timestamps are Unix nanoseconds.
// ---------------------------------------------------------------------------
const LOKI_URL = process.env.LOKI_URL || 'http://loki:3100'
const relToNs = (rel) => {
  const now = Date.now() * 1e6
  const m = /^(\d+)([smhd])$/.exec(String(rel || '').trim())
  if (!m) return null
  const mult = { s: 1e9, m: 60e9, h: 3600e9, d: 86400e9 }[m[2]]
  return String(BigInt(Math.round(now)) - BigInt(m[1]) * BigInt(mult))
}
const toNs = (t, dflt) => {
  if (!t) return dflt
  const rel = relToNs(t)
  if (rel) return rel
  const ms = Date.parse(t)
  return Number.isNaN(ms) ? dflt : String(BigInt(ms) * 1000000n)
}
async function lokiQuery({ query, start = '1h', end, limit = 100, metric = false }) {
  const nowNs = String(BigInt(Date.now()) * 1000000n)
  let startNs = toNs(start, relToNs('1h'))
  const endNs = toNs(end, nowNs)
  // Cap any window to 30 days so a stray "5y" can't launch a multi-year prod-Loki scan.
  const MAX_SPAN = 30n * 86400n * 1000000000n
  if (BigInt(endNs) - BigInt(startNs) > MAX_SPAN) startNs = String(BigInt(endNs) - MAX_SPAN)
  const base = metric ? '/loki/api/v1/query' : '/loki/api/v1/query_range'
  const params = new URLSearchParams({ query })
  if (metric) {
    params.set('time', endNs)
  } else {
    params.set('start', startNs)
    params.set('end', endNs)
    params.set('limit', String(Math.min(Number(limit) || 100, 1000)))
    params.set('direction', 'backward')
  }
  const res = await fetch(`${LOKI_URL}${base}?${params.toString()}`, {
    signal: AbortSignal.timeout(20000),
  })
  if (!res.ok) throw new Error(`Loki ${res.status}: ${(await res.text()).slice(0, 200)}`)
  const data = await res.json()
  if (metric) return data.data?.result || []
  const out = []
  for (const stream of data.data?.result || []) {
    for (const [ts, line] of stream.values || []) out.push({ ts, line, labels: stream.stream })
  }
  return out
}

// The unlabelled sources that carry user_id only inside the JSON line, minus
// api_headers — that source is ~67GB per 7 days on prod and dominates any scan
// that includes it (it is what made the old whole-app passes always hit the
// 20s abort and error out). user_id IS an indexed stream label on api,
// chat_reply and client, so those are covered by cheap label selectors.
const LOKI_SLIM_SOURCES = 'batch|batch_event|email|incoming_mail|similar_posts|vector_search'

// 3-pass user log search. Every parse/regex stage sits behind a `|=` substring
// prefilter — the JSON parser is the expensive part, and each of these values
// appears verbatim in the raw line, so only candidate lines get parsed.
async function lokiUserSearch({ userid, emails = [], window = '24h', limit = 200 }) {
  const passes = {}
  // Labelled sources: an index lookup, ~0.5s over 30d.
  passes.byUserId = await lokiQuery({
    query: `{app="freegle", user_id="${userid}"}`,
    start: window,
    limit,
  })
  // Slim unlabelled sources: prefilter, then the exact JSON post-filter.
  try {
    const slim = await lokiQuery({
      query: `{app="freegle", source=~"${LOKI_SLIM_SOURCES}"} |= "${userid}" | json | user_id="${userid}"`,
      start: window,
      limit,
    })
    passes.byUserId.push(...slim)
  } catch (e) {
    console.error('[lokiUserSearch] slim-source pass failed:', e.message)
  }
  passes.byEmail = []
  for (const email of emails.slice(0, 5)) {
    const esc = email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    try {
      const hits = await lokiQuery({
        query: `{app="freegle", source=~"${LOKI_SLIM_SOURCES}"} |= "${email.toLowerCase()}" |~ "(?i)${esc}"`,
        start: window,
        limit: Math.floor(limit / 2),
      })
      passes.byEmail.push(...hits)
    } catch (e) {
      console.error('[lokiUserSearch] email pass failed:', e.message)
    }
  }
  // Harvest session ids from api lines for the client-side pass.
  const sids = new Set()
  for (const e of passes.byUserId) {
    const m = /"session_id"\s*:\s*"([^"]+)"/.exec(e.line)
    if (m) sids.add(m[1])
  }
  passes.bySession = []
  for (const sid of Array.from(sids).slice(0, 25)) {
    try {
      // Two legs: the indexed user_id label (cheap at any window), plus the
      // anonymous pre-login streams — capped at 7d, the client source's
      // retention, beyond which there is nothing to find.
      const labelled = await lokiQuery({
        query: `{app="freegle", source="client", user_id="${userid}"} |= "${sid}" | json | session_id="${sid}"`,
        start: window,
        limit: 50,
      })
      const anonWindow = /d$/.test(window) && parseInt(window, 10) > 7 ? '7d' : window
      const anon = await lokiQuery({
        query: `{app="freegle", source="client", user_id=""} |= "${sid}" | json | session_id="${sid}"`,
        start: anonWindow,
        limit: 50,
      })
      passes.bySession.push(...labelled, ...anon)
    } catch (e) {
      console.error('[lokiUserSearch] session pass failed:', e.message)
    }
  }
  return passes
}

// ---------------------------------------------------------------------------
// User dump — the centrepiece. ONE Go-API call returns a SQLite DB of ~69
// user-linked tables + Loki 3-pass + Sentry, secrets redacted. We save it and
// let the AI run SQL against it locally (query_dump), which is the big
// token/timeout win vs many separate queries.
// ---------------------------------------------------------------------------
const API_URL = process.env.API_URL || 'http://apiv2.localhost:8192'

// The framed dump protocol (iznik-server-go/userdump/frame.go): each frame is
// a 5-byte header (1 type byte + uint32 big-endian payload length) then the
// payload. 0x01 progress (JSON), 0x02 raw SQLite bytes, 0x03 end (JSON with
// bytes/sha256/warnings). We use framed rather than raw because the server
// flushes a frame after every collection section, so bytes keep flowing and
// the API LB's idle timeout (50s on prod HAProxy) never fires mid-build — the
// raw format goes silent for the whole build, which is exactly how big dumps
// died: HAProxy cut them at 50s, the model retried, and the retries piled
// more concurrent builds onto the server.
async function fetchUserDump({ userId, jwt, since = '90d', include = 'db,loki,sentry', onProgress }) {
  const url = `${API_URL}/api/modtools/user/${userId}/dump?format=framed&include=${encodeURIComponent(
    include
  )}&since=${encodeURIComponent(since)}&jwt=${encodeURIComponent(jwt || '')}`

  // Inactivity-based abort, not a fixed overall deadline: a healthy framed
  // dump is never silent for long, while total build time legitimately varies
  // with the member's size.
  const ctrl = new AbortController()
  let idleTimer
  const armIdle = () => {
    clearTimeout(idleTimer)
    idleTimer = setTimeout(() => ctrl.abort(new Error('dump stalled: no frame for 90s')), 90000)
  }
  armIdle()

  try {
    // identity: undici advertises gzip by default, and any compression layer
    // that buffers the response would hold the heartbeat frames off the wire
    // until the build completes - exactly the LB-timeout failure the framed
    // protocol exists to prevent. The SQLite payload is worth compressing one
    // day, but only via a layer proven to flush per frame.
    const res = await fetch(url, { signal: ctrl.signal, headers: { 'accept-encoding': 'identity' } })
    if (!res.ok) throw new Error(`dump ${res.status}: ${(await res.text()).slice(0, 200)}`)

    const crypto = require('crypto')
    const hash = crypto.createHash('sha256')
    const reader = res.body.getReader()
    const dataChunks = []
    let buf = Buffer.alloc(0)
    let end = null

    while (!end) {
      const { done, value } = await reader.read()
      if (done) break
      armIdle()
      buf = Buffer.concat([buf, Buffer.from(value)])
      while (buf.length >= 5) {
        const type = buf[0]
        const len = buf.readUInt32BE(1)
        if (buf.length < 5 + len) break
        // Copy the payload out so the ever-growing parse buffer can be GC'd.
        const payload = Buffer.from(buf.subarray(5, 5 + len))
        buf = buf.subarray(5 + len)
        if (type === 0x01) {
          try {
            onProgress && onProgress(JSON.parse(payload.toString()))
          } catch {}
        } else if (type === 0x02) {
          hash.update(payload)
          dataChunks.push(payload)
        } else if (type === 0x03) {
          end = JSON.parse(payload.toString())
          break
        }
      }
    }

    if (!end) throw new Error('dump stream ended without an end frame')
    const data = Buffer.concat(dataChunks)
    if (!data.length) {
      throw new Error('dump produced no data: ' + ((end.warnings || []).join('; ') || 'no warnings'))
    }
    if (end.bytes && Number(end.bytes) !== data.length) {
      throw new Error(`dump truncated: got ${data.length} of ${end.bytes} bytes`)
    }
    if (end.sha256 && hash.digest('hex') !== end.sha256) {
      throw new Error('dump checksum mismatch')
    }

    const file = path.join(os.tmpdir(), `dump-${userId}-${process.pid}-${data.length}.sqlite`)
    fs.writeFileSync(file, data, { mode: 0o600 }) // member PII snapshot — owner-only
    return file
  } finally {
    clearTimeout(idleTimer)
  }
}

// ---------------------------------------------------------------------------
// Cross-request dump cache. `state` is rebuilt every message, so without this a
// follow-up question re-downloads the whole (expensive: DB + Loki + ~tens of
// seconds of Sentry) snapshot each time. Keyed by member+window; swept on TTL.
// Files are the owner-only PII snapshots, so the TTL is short.
// ---------------------------------------------------------------------------
const DUMP_TTL_MS = 10 * 60 * 1000
const dumpCache = new Map() // `${userId}:${since}` -> { file, at }
function sweepDumpCache(now = Date.now()) {
  for (const [k, v] of dumpCache) {
    if (now - v.at > DUMP_TTL_MS) {
      try {
        fs.unlinkSync(v.file)
      } catch {}
      dumpCache.delete(k)
    }
  }
}
const _dumpSweep = setInterval(sweepDumpCache, 60 * 1000)
if (_dumpSweep.unref) _dumpSweep.unref()

// ---------------------------------------------------------------------------
// Tool factory. Each request builds tools bound to that caller's JWT + the
// member they picked. The user dump is cached across requests (see dumpCache).
// ---------------------------------------------------------------------------
function buildTools(ctx) {
  // ctx: { jwt, userId (selected member or 0), progress }
  const state = { dumpFile: null }
  const p = ctx.progress || (() => {})
  const text = (obj) => ({ content: [{ type: 'text', text: typeof obj === 'string' ? obj : JSON.stringify(obj) }] })
  const err = (msg) => ({ content: [{ type: 'text', text: `ERROR: ${msg}` }], isError: true })

  const identify_user = tool(
    'identify_user',
    'Resolve a member from an email, user id, or name. Returns the user id, ALL their email addresses ' +
      '(including any Apple private-relay @privaterelay.appleid.com addresses), login methods, and account status. ' +
      'Use this first when the member is given by email/name rather than id.',
    {
      email: z.string().optional().describe('Email address (checks users_emails, not users)'),
      userid: z.number().optional().describe('Numeric user id'),
      name: z.string().optional().describe('Display name (fuzzy)'),
    },
    async (a) => {
      try {
        let id = a.userid
        if (!id && a.email) {
          const r = await dbSelect(
            `SELECT u.id FROM users u JOIN users_emails ue ON ue.userid=u.id
             WHERE ue.email=${getPool().escape(a.email)} AND u.deleted IS NULL LIMIT 1`
          )
          id = r[0]?.id
        }
        if (!id && a.name) {
          const r = await dbSelect(
            `SELECT id FROM users WHERE fullname LIKE ${getPool().escape('%' + a.name + '%')}
             AND deleted IS NULL ORDER BY lastaccess DESC LIMIT 5`
          )
          if (r.length === 1) id = r[0].id
          else if (r.length > 1) return text({ ambiguous: r })
        }
        if (!id) return err('No user found for those identifiers')
        const [profile, emails, logins] = await Promise.all([
          dbSelect(`SELECT id, fullname, TIMESTAMPDIFF(DAY,added,NOW()) age_days, lastaccess, bouncing,
                    deleted IS NOT NULL AS in_limbo, forgotten IS NOT NULL AS purged FROM users WHERE id=${id}`),
          dbSelect(`SELECT email, preferred, validated IS NOT NULL AS confirmed, bounced,
                    email LIKE '%@privaterelay.appleid.com' AS apple_relay FROM users_emails WHERE userid=${id}`),
          dbSelect(`SELECT type, credentials IS NOT NULL AS has_password, lastaccess FROM users_logins WHERE userid=${id}`),
        ])
        return text({ userid: id, profile: profile[0], emails, logins })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const get_user_dump = tool(
    'get_user_dump',
    'Download a full read-only snapshot of a member as a local SQLite database: ~69 user-linked tables ' +
      '(profile, messages, BOTH sides of every chat, memberships, emails, bounces, spam flags, push tokens, ' +
      'email logs, mod logs) PLUS their Loki logs and Sentry issues, secrets redacted. This is usually the best ' +
      'first move — then use query_dump to run SQL against it (cheap, no timeouts) instead of many small queries.',
    {
      userid: z.number().optional().describe('Member id; defaults to the selected member'),
      since: z.string().optional().describe('History window, e.g. 30d, 90d (default 90d)'),
    },
    async (a) => {
      try {
        const id = a.userid || ctx.userId
        if (!id) return err('No member selected/provided')
        const since = a.since || '90d'
        // Audit every access, even a cache hit — the trail must stay complete.
        audit({ mod: ctx.modId, modEmail: ctx.modEmail, target: id, tool: 'get_user_dump', since })
        const key = `${id}:${since}`
        const cached = dumpCache.get(key)
        if (cached && fs.existsSync(cached.file) && Date.now() - cached.at < DUMP_TTL_MS) {
          state.dumpFile = cached.file
          state.dumpCached = true
          const ageS = Math.round((Date.now() - cached.at) / 1000)
          p('tool', `Reusing snapshot for user ${id} (built ${ageS}s ago)…`)
        } else {
          p('tool', `Downloading full snapshot for user ${id}…`)
          // Progress: the DB tables fly by, so only surface the coarse
          // milestones and the slow sections (loki/sentry) by name.
          let lastPct = 0
          const onProgress = (pr) => {
            const slow = pr.section === 'loki_logs' || pr.section === 'sentry_issues'
            if (slow || pr.percent - lastPct >= 25) {
              lastPct = pr.percent
              const eta = pr.eta_ms > 1000 ? `, ~${Math.round(pr.eta_ms / 1000)}s left` : ''
              p('tool', `Snapshot ${pr.percent}%: ${pr.section} ${pr.status}${eta}`)
            }
          }
          const file = await fetchUserDump({ userId: id, jwt: ctx.jwt, since, onProgress })
          if (cached && cached.file !== file) {
            try {
              fs.unlinkSync(cached.file)
            } catch {}
          }
          dumpCache.set(key, { file, at: Date.now() })
          state.dumpFile = file
          state.dumpCached = true
        }
        const db = new Database(state.dumpFile, { readonly: true })
        const tables = db
          .prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
          .all()
          .map((r) => r.name)
        db.close()
        return text({ ok: true, tables, hint: 'Use query_dump with SQL against these tables.' })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const query_dump = tool(
    'query_dump',
    'Run a read-only SQL SELECT against the downloaded user-dump SQLite (call get_user_dump first). ' +
      'Cheap and fast — prefer this over live DB/Loki queries once the dump is loaded.',
    { sql: z.string().describe('A single SELECT statement') },
    async (a) => {
      try {
        if (!state.dumpFile) return err('No dump loaded — call get_user_dump first')
        audit({ mod: ctx.modId, target: ctx.userId, tool: 'query_dump', sql: a.sql })
        const guarded = guardSelect(a.sql, 500)
        const db = new Database(state.dumpFile, { readonly: true })
        const rows = db.prepare(guarded).all()
        db.close()
        return text({ rowCount: rows.length, rows: rows.slice(0, 200) })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const db_query = tool(
    'db_query',
    'Run a read-only SQL SELECT against the LIVE Freegle database (real values, no anonymisation). ' +
      'SELECT/WITH only, auto-LIMITed. Email lives in users_emails (JOIN it), not users. For a full member ' +
      'picture prefer get_user_dump. Use this for cross-user/aggregate questions the dump can\'t answer.',
    { sql: z.string().describe('A single SELECT statement') },
    async (a) => {
      try {
        audit({ mod: ctx.modId, modEmail: ctx.modEmail, target: ctx.userId, tool: 'db_query', sql: a.sql })
        p('tool', `DB: ${a.sql.slice(0, 80)}`)
        const rows = await dbSelect(a.sql, 500)
        return text({ rowCount: rows.length, rows: rows.slice(0, 200) })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const loki_search = tool(
    'loki_search',
    'Search production logs (Loki). Two modes: (1) a raw LogQL "query", or (2) "userid" for the canonical ' +
      '3-pass user search (by user_id JSON field, by each email as text, and by client session ids). ' +
      'Keep windows tight (5–30m) for text/json filters to avoid timeouts; use metric:true for counts. ' +
      'Only useful against prod (local Loki has null user_id).',
    {
      userid: z.number().optional().describe('Run the 3-pass search for this member'),
      emails: z.array(z.string()).optional().describe('Emails to text-search in pass 2'),
      query: z.string().optional().describe('Raw LogQL, e.g. {app="freegle"} | json | trace_id="…"'),
      window: z.string().optional().describe('Time window, e.g. 15m, 24h (default 24h)'),
      metric: z.boolean().optional().describe('Instant metric query (counts) instead of log lines'),
      limit: z.number().optional(),
    },
    async (a) => {
      try {
        if (a.userid) {
          p('tool', `Loki 3-pass for user ${a.userid}…`)
          const r = await lokiUserSearch({
            userid: a.userid,
            emails: a.emails || [],
            window: a.window || '24h',
            limit: a.limit || 200,
          })
          return text({
            byUserId: r.byUserId.slice(0, 80).map((e) => e.line),
            byEmail: r.byEmail.slice(0, 40).map((e) => e.line),
            bySession: r.bySession.slice(0, 40).map((e) => e.line),
          })
        }
        if (a.query) {
          p('tool', `Loki: ${a.query.slice(0, 80)}`)
          const r = await lokiQuery({
            query: a.query,
            start: a.window || '1h',
            limit: a.limit || 100,
            metric: !!a.metric,
          })
          return text(a.metric ? r : r.slice(0, 100).map((e) => e.line))
        }
        return err('Provide either userid (3-pass) or query (raw LogQL)')
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const git_fixed_already = tool(
    'git_fixed_already',
    'Check the code history for a fix/regression around a report date (date-aware). Returns commits touching ' +
      'a path since ~2 weeks before the date. Reason over the subjects: a commit BEFORE the report date can be ' +
      'cited as "already known/fixed"; ON/AFTER it is only "a fix has since shipped", never "we already knew".',
    {
      path: z.string().describe('Suspect file or directory, e.g. iznik-nuxt3/components/Foo.vue'),
      reportDate: z.string().describe('Date the issue was reported (YYYY-MM-DD)'),
    },
    async (a) => {
      try {
        const d = new Date(a.reportDate)
        if (Number.isNaN(d.getTime())) return err('Bad reportDate')
        d.setDate(d.getDate() - 14)
        const since = d.toISOString().slice(0, 10)
        const repo = process.env.CODEBASE_DIR || '/app/codebase/iznik-nuxt3'
        const { stdout } = await execFileP(
          'git',
          ['-C', repo, 'log', '--oneline', `--since=${since}`, '--', a.path],
          { timeout: 15000 }
        )
        return text({ since, commits: stdout.trim().split('\n').filter(Boolean).slice(0, 40) })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const sentry_search = tool(
    'sentry_search',
    'Search Sentry for real errors — a usual place the truth behind "oh dear something went wrong" lives. ' +
      'Query a project (nuxt3 = frontend/SSR, go = Go API, capacitor = the mobile app, modtools) with a Sentry ' +
      'search: "is:unresolved", a message substring, "trace_id:<id>", "source:error-page-mount" (the Nuxt full-page ' +
      'error boundary), or "user.id:<n>". Set statsPeriod for older cases. CAVEAT: Sentry only captures THROWN ' +
      'exceptions — a slow-query timeout, a silent no-op or a blank page leaves nothing here, so an empty result ' +
      'does NOT mean nothing happened (fall back to loki_search / code_history_search).',
    {
      project: z.enum(['nuxt3', 'go', 'capacitor', 'modtools']).describe('nuxt3=frontend/SSR, go=Go API, capacitor=mobile app, modtools'),
      query: z
        .string()
        .optional()
        .describe('Sentry search, e.g. is:unresolved, trace_id:<id>, source:error-page-mount, user.id:<n>, or a message substring'),
      statsPeriod: z.string().optional().describe('Lookback window, e.g. 24h, 14d, 90d (default 14d; Sentry retention caps how far back)'),
      limit: z.number().optional(),
    },
    async (a) => {
      try {
        const token = process.env.SENTRY_AUTH_TOKEN
        if (!token) return err('SENTRY_AUTH_TOKEN not configured')
        const org = process.env.SENTRY_ORG_SLUG || 'freegle'
        const q = a.query || 'is:unresolved'
        p('tool', `Sentry ${a.project}: ${q.slice(0, 60)}`)
        const url =
          `https://sentry.io/api/0/projects/${org}/${a.project}/issues/` +
          `?query=${encodeURIComponent(q)}&sort=date&statsPeriod=${encodeURIComponent(a.statsPeriod || '14d')}` +
          `&limit=${Math.min(a.limit || 10, 25)}`
        const res = await fetch(url, {
          headers: { Authorization: `Bearer ${token}` },
          signal: AbortSignal.timeout(20000),
        })
        if (!res.ok) return err(`Sentry ${res.status}: ${(await res.text()).slice(0, 200)}`)
        const issues = await res.json()
        return text(
          (Array.isArray(issues) ? issues : []).map((i) => ({
            id: i.id,
            title: i.title,
            culprit: i.culprit,
            level: i.level,
            // WHOLE-ISSUE totals across ALL users, NOT the queried member. Named
            // explicitly so a per-user view never presents them as the member's
            // own event/user counts.
            totalEventsAllUsers: i.count,
            totalUsersAffected: i.userCount,
            lastSeen: i.lastSeen,
            permalink: i.permalink,
          }))
        )
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const discourse_search = tool(
    'discourse_search',
    'Search the Freegle volunteer forum (Discourse) where mods/members report bugs. Real fixes often cite a ' +
      'Discourse topic number that never appears in a support email, so this is how you find the original report, ' +
      'the discussion, and any reply. Supports Discourse search syntax (keywords, @username, #category, in:first).',
    {
      query: z.string().describe('e.g. "blank browse", "photo upload", "@Neville_Reid rippling", "waiting to send"'),
      limit: z.number().optional(),
    },
    async (a) => {
      try {
        const key = process.env.DISCOURSE_API_KEY
        if (!key) return err('DISCOURSE_API_KEY not configured')
        const user = process.env.DISCOURSE_API_USER || 'Edward_Hibbert'
        p('tool', `Discourse: ${a.query.slice(0, 60)}`)
        const res = await fetch(
          `https://discourse.ilovefreegle.org/search.json?q=${encodeURIComponent(a.query)}`,
          { headers: { 'User-Api-Key': key, 'Api-Username': user }, signal: AbortSignal.timeout(20000) }
        )
        if (!res.ok) return err(`Discourse ${res.status}`)
        const d = await res.json()
        const n = a.limit || 10
        return text({
          topics: (d.topics || []).slice(0, n).map((t) => ({
            id: t.id, title: t.title,
            url: `https://discourse.ilovefreegle.org/t/${t.slug}/${t.id}`,
          })),
          posts: (d.posts || []).slice(0, n).map((pp) => ({
            topic_id: pp.topic_id, post_number: pp.post_number, username: pp.username,
            blurb: (pp.blurb || '').slice(0, 200),
          })),
        })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  const code_history_search = tool(
    'code_history_search',
    'Search the FULL git history (all branches) for commits whose message matches a symptom — the ' +
      '"diff against the eventual fix" shortcut, which repeatedly beat live guesswork in testing. A commit that ' +
      'fixes/describes the exact symptom points straight at the root cause. Pass a keyword to list commits, or a ' +
      'sha to read that commit (message, files, truncated diff).',
    {
      keyword: z.string().optional().describe('Symptom keyword(s), e.g. "blank browse", "out of office", "waiting to send"'),
      sha: z.string().optional().describe('A commit sha to show'),
      repo: z.string().optional().describe('subdir: iznik-nuxt3 (default), iznik-server-go, iznik-batch'),
    },
    async (a) => {
      try {
        const root = process.env.CODEBASE_DIR || '/app/codebase'
        const REPOS = ['iznik-nuxt3', 'iznik-server-go', 'iznik-batch']
        const repo = a.repo || 'iznik-nuxt3'
        if (!REPOS.includes(repo)) return err('repo must be one of: ' + REPOS.join(', '))
        const dir = `${root}/${repo}`
        if (a.sha) {
          const { stdout } = await execFileP(
            'git',
            ['-C', dir, 'show', '--stat', '--format=%H%n%an %ad%n%s%n%n%b', a.sha],
            { timeout: 15000, maxBuffer: 2 * 1024 * 1024 }
          )
          return text(stdout.slice(0, 7000))
        }
        if (!a.keyword) return err('Provide keyword or sha')
        const { stdout } = await execFileP(
          'git',
          ['-C', dir, 'log', '--all', '--oneline', '-i', `--grep=${a.keyword}`, '-40'],
          { timeout: 15000 }
        )
        return text({ commits: stdout.trim().split('\n').filter(Boolean) })
      } catch (e) {
        return err(e.message)
      }
    },
    { annotations: { readOnlyHint: true } }
  )

  return {
    tools: [
      identify_user, get_user_dump, query_dump, db_query, loki_search, sentry_search,
      discourse_search, code_history_search, git_fixed_already,
    ],
    names: [
      'identify_user',
      'get_user_dump',
      'query_dump',
      'db_query',
      'loki_search',
      'sentry_search',
      'discourse_search',
      'code_history_search',
      'git_fixed_already',
    ],
    cleanup() {
      // Cached dumps are owned by dumpCache (swept on TTL) so a follow-up
      // question can reuse them — only delete a one-off, uncached file.
      if (state.dumpFile && !state.dumpCached) {
        try {
          fs.unlinkSync(state.dumpFile)
        } catch {}
      }
    },
  }
}

module.exports = { buildTools, getPool, lokiQuery, dbSelect, guardSelect, audit, fetchUserDump }
