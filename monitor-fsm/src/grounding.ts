/**
 * Ground-truth read actions for the FSM's diagnosis states.
 *
 * WHY: post-mortems of wrong-diagnosis PRs (#1103 blank-mail, #1104 language
 * detect, earlier #581/#582/#585) showed a single structural cause: the
 * diagnose loop's entire evidence base was source code it could read plus a
 * test it wrote FROM its own hypothesis. When the real cause lived in live
 * data or runtime behaviour, the FSM substituted the most plausible
 * code-visible cause and argued it convincingly. These actions give the brain
 * eyes on the real data so a report can be GROUNDED before it is theorised:
 *
 *   query_live_db  - read-only SELECT/SHOW against the production DB over the
 *                    local tunnel (dedicated iznik_ro grant: SELECT, SHOW VIEW
 *                    on iznik.* only).
 *   query_loki     - LogQL query against production Loki over the tunnel
 *                    (falls back to the local dev Loki, clearly labelled,
 *                    which must NOT be used as evidence about production).
 *
 * Both degrade gracefully: if the tunnel is down the action returns
 * {available:false, reason} rather than failing the step, and the state
 * prompts require the resulting report to say its diagnosis is ungrounded.
 */
import type { ActionDefinition } from 'ai-flower'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { readFileSync } from 'node:fs'

const exec = promisify(execFile)

const ENV_PATH = '/home/edward/FreegleDockerWSL/.env'

/** Minimal .env parser - mirrors how other actions read SENTRY_AUTH_TOKEN. */
export function parseEnvFile(raw: string): Record<string, string> {
  const env: Record<string, string> = {}
  for (const line of raw.split('\n')) {
    if (!line || line.startsWith('#') || !line.includes('=')) continue
    const idx = line.indexOf('=')
    env[line.slice(0, idx).trim()] = line.slice(idx + 1).trim()
  }
  return env
}

function loadEnv(): Record<string, string> {
  try {
    return parseEnvFile(readFileSync(ENV_PATH, 'utf8'))
  } catch {
    return {}
  }
}

/**
 * Defence-in-depth guard on top of the iznik_ro grant (SELECT, SHOW VIEW
 * only): accept exactly one statement, starting SELECT or SHOW, with no
 * INTO OUTFILE/DUMPFILE. Returns null when ok, else a human-readable reason.
 * Pure + exported for unit testing.
 */
export function validateReadOnlySql(sql: string): string | null {
  const s = sql.trim()
  if (!s) return 'empty query'
  if (!/^(select|show)\b/i.test(s)) return 'only SELECT/SHOW statements are allowed'
  // One statement only: a semicolon may terminate the query but nothing may follow it.
  const inner = s.endsWith(';') ? s.slice(0, -1) : s
  if (inner.includes(';')) return 'multiple statements are not allowed'
  if (/\binto\s+(outfile|dumpfile)\b/i.test(inner)) return 'INTO OUTFILE/DUMPFILE is not allowed'
  return null
}

/**
 * Cap result size: if a SELECT has no LIMIT clause, append one. SHOW
 * statements pass through (their output is naturally bounded). Pure +
 * exported for unit testing.
 */
export function ensureLimit(sql: string, max = 200): string {
  const s = sql.trim().replace(/;$/, '')
  if (/^show\b/i.test(s)) return s
  if (/\blimit\s+\d+/i.test(s)) return s
  return `${s} LIMIT ${max}`
}

/**
 * Candidate Loki endpoints in preference order. Production (via the Windows
 * tunnel on the WSL gateway, port 3102, or LOKI_PROD_URL override) is the
 * only endpoint that grounds a report about production; the local dev Loki
 * (localhost:3100) is a labelled fallback for local-stack questions only.
 * Pure + exported for unit testing.
 */
export function lokiCandidates(env: Record<string, string>, gatewayIp: string | null): Array<{ url: string; source: 'prod' | 'local-dev' }> {
  const out: Array<{ url: string; source: 'prod' | 'local-dev' }> = []
  if (env.LOKI_PROD_URL) out.push({ url: env.LOKI_PROD_URL.replace(/\/$/, ''), source: 'prod' })
  if (gatewayIp) out.push({ url: `http://${gatewayIp}:3102`, source: 'prod' })
  out.push({ url: 'http://localhost:3100', source: 'local-dev' })
  return out
}

async function wslGatewayIp(): Promise<string | null> {
  try {
    const { stdout } = await exec('ip', ['route', 'show', 'default'])
    const m = stdout.match(/default via (\d+\.\d+\.\d+\.\d+)/)
    return m ? m[1] : null
  } catch {
    return null
  }
}

async function probe(url: string, ms = 2500): Promise<boolean> {
  try {
    const resp = await fetch(`${url}/ready`, { signal: AbortSignal.timeout(ms) })
    return resp.ok
  } catch {
    return false
  }
}

export const groundingActions: ActionDefinition[] = [
  {
    name: 'query_live_db',
    description:
      'Ground-truth read of the PRODUCTION database over the local tunnel, using a dedicated read-only grant (SELECT/SHOW VIEW on iznik.* only). Params: {sql, purpose}. sql must be a single SELECT or SHOW statement (a LIMIT 200 is appended to unbounded SELECTs); purpose is a one-line audit note of what hypothesis this checks. Returns {available, columns?, rows?, rowCount?, reason?}. USE THIS BEFORE THEORISING: if a diagnosis depends on what data is actually in production (a message row, a user setting, an alert body, counts), confirm it here first. If available=false the tunnel is down - the eventual report MUST say the diagnosis is ungrounded.',
    paramsSchema: {
      type: 'object',
      properties: {
        sql: { type: 'string' },
        purpose: { type: 'string' },
      },
      required: ['sql', 'purpose'],
    },
    handler: async (params) => {
      const sql = String(params.sql ?? '')
      const bad = validateReadOnlySql(sql)
      if (bad) return { available: false, reason: `rejected: ${bad}` }

      const env = loadEnv()
      const user = env.LIVE_DB_RO_USER
      const pass = env.LIVE_DB_RO_PASSWORD
      const port = env.LIVE_DB_PORT || '11234'
      if (!user || !pass) {
        return { available: false, reason: 'LIVE_DB_RO_USER/LIVE_DB_RO_PASSWORD not configured in .env' }
      }

      const bounded = ensureLimit(sql)
      try {
        const { stdout } = await exec(
          'timeout',
          ['25', 'mysql', '-h', '127.0.0.1', '-P', port, '-u', user, 'iznik',
            '--connect-timeout=5', '-N', '-B', '-e', bounded],
          { env: { ...process.env, MYSQL_PWD: pass }, maxBuffer: 4 * 1024 * 1024 },
        )
        const rows = stdout
          .split('\n')
          .filter((l) => l.length > 0)
          .slice(0, 200)
          .map((l) => l.split('\t'))
        return { available: true, rows, rowCount: rows.length, truncatedAt: 200 }
      } catch (err: any) {
        const msg = String(err.stderr || err.message || err)
        // Connection refused / timeout = tunnel down, not a query error.
        const tunnelDown = /Can't connect|connect-timeout|Connection refused|code 124/i.test(msg) || err.code === 124
        return {
          available: false,
          reason: tunnelDown ? `live DB tunnel unavailable: ${msg.slice(0, 200)}` : `query failed: ${msg.slice(0, 300)}`,
        }
      }
    },
  },

  {
    name: 'query_loki',
    description:
      "Ground-truth read of PRODUCTION logs (Loki over the tunnel; falls back to the LOCAL dev Loki, labelled source:'local-dev', which is NOT evidence about production). Params: {query, sinceHours?, limit?}. query is LogQL against the PROD label scheme: app=\"freegle\" is the main app stream; narrow with source (one of: api, api_headers, batch, batch_event, bounce, chat_reply, client, deprecated_endpoint, email, incoming_mail, vector_search), plus level, method, status_code, api_version, user_id, type/subtype, email_type, event_type. Examples: '{app=\"freegle\", source=\"api\"} |= \"/message/search\"', '{app=\"freegle\", level=\"error\"}', '{app=\"freegle\", source=\"client\"} |= \"120945664\"'. user_id is often a JSON FIELD in the line, not only a label - also try |= \"<userid>\". sinceHours defaults to 24, limit to 100. Timestamps are UTC. Returns {available, source?, entries?: [{ts, line}], reason?}. USE THIS BEFORE THEORISING about runtime behaviour: if a diagnosis claims requests fail / a code path fires / an error occurs in production, look for it in the logs first. If only local-dev is available, the eventual report MUST say the diagnosis is ungrounded for production.",
    paramsSchema: {
      type: 'object',
      properties: {
        query: { type: 'string' },
        sinceHours: { type: 'number' },
        limit: { type: 'number' },
      },
      required: ['query'],
    },
    handler: async (params) => {
      const query = String(params.query ?? '')
      if (!query.trim()) return { available: false, reason: 'empty query' }
      const sinceHours = Number(params.sinceHours ?? 24)
      const limit = Math.min(Number(params.limit ?? 100), 500)

      const env = loadEnv()
      const candidates = lokiCandidates(env, await wslGatewayIp())
      for (const cand of candidates) {
        if (!(await probe(cand.url))) continue
        try {
          const end = Date.now() * 1e6
          const start = end - sinceHours * 3600 * 1e9
          const u = new URL(`${cand.url}/loki/api/v1/query_range`)
          u.searchParams.set('query', query)
          u.searchParams.set('start', String(start))
          u.searchParams.set('end', String(end))
          u.searchParams.set('limit', String(limit))
          const resp = await fetch(u, { signal: AbortSignal.timeout(20000) })
          if (!resp.ok) {
            return { available: false, reason: `loki ${cand.source} returned ${resp.status}: ${(await resp.text()).slice(0, 200)}` }
          }
          const data = (await resp.json()) as any
          const entries: Array<{ ts: string; line: string }> = []
          for (const stream of data?.data?.result ?? []) {
            for (const [ts, line] of stream.values ?? []) {
              entries.push({ ts: new Date(Number(ts) / 1e6).toISOString(), line: String(line).slice(0, 500) })
            }
          }
          entries.sort((a, b) => (a.ts < b.ts ? 1 : -1))
          return { available: true, source: cand.source, entries: entries.slice(0, limit), count: entries.length }
        } catch (err: any) {
          return { available: false, reason: `loki ${cand.source} query failed: ${String(err.message || err).slice(0, 200)}` }
        }
      }
      return { available: false, reason: 'no Loki endpoint reachable (prod tunnel down, local dev Loki absent)' }
    },
  },
]
