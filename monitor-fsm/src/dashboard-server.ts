// Local dashboard HTTP server — Edward-only, port 8765.
// Serves dashboard-dist/ (Vite build) as static files and provides a REST API
// backed by the same SQLite DB used by monitor-fsm.
//
// Start: node --enable-source-maps dist/dashboard-server.js

import { createServer, type IncomingMessage, type ServerResponse } from 'node:http'
import { readFileSync, existsSync } from 'node:fs'
import { resolve, extname, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { exec } from 'node:child_process'
import { promisify } from 'node:util'
import https from 'node:https'
import { getDb, kvGet } from './db/index.js'
import { putStatusPost } from './db/discourse-status.js'
import type { Database as DB } from 'better-sqlite3'

const execAsync = promisify(exec)

const __dirname = dirname(fileURLToPath(import.meta.url))
const DIST_DIR = resolve(__dirname, '..', 'dashboard-dist')
const PORT = 8765

// PR cache: { data, timestamp }
let prCache: { data: any; timestamp: number } | null = null
const PR_CACHE_TTL = 30000 // 30 seconds

const MIME: Record<string, string> = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.json': 'application/json',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
}

function readBody(req: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = []
    req.on('data', c => chunks.push(c))
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    req.on('error', reject)
  })
}

function getDiscourseApiKey(): string {
  try {
    const profile = JSON.parse(readFileSync('/home/edward/profile.json', 'utf8'))
    return profile.auth_pairs[0].user_api_key
  } catch {
    throw new Error('Cannot read Discourse API key from profile.json')
  }
}

async function fetchPrsLive(): Promise<any[]> {
  // Check cache
  if (prCache && Date.now() - prCache.timestamp < PR_CACHE_TTL) {
    return prCache.data
  }

  try {
    // Get PR list
    const { stdout: listOutput } = await execAsync(
      'gh pr list --repo Freegle/Iznik --author "@me" --json number,title,headRefName,createdAt,url,isDraft,mergeable --limit 20 2>/dev/null',
      { maxBuffer: 10 * 1024 * 1024 }
    )
    const prs = JSON.parse(listOutput)

    // Get detailed status for each PR in parallel
    const results = await Promise.all(
      prs.map(async (pr: any) => {
        try {
          const { stdout: statusOutput } = await execAsync(
            `gh pr view ${pr.number} --repo Freegle/Iznik --json statusCheckRollup,mergeStateStatus,mergeable 2>/dev/null`,
            { maxBuffer: 10 * 1024 * 1024 }
          )
          const status = JSON.parse(statusOutput)

          // Compute CI status
          let ciStatus = 'unknown'
          const failedChecks: string[] = []

          if (status.statusCheckRollup && Array.isArray(status.statusCheckRollup)) {
            const checks: any[] = status.statusCheckRollup
            // GitHub returns two check types:
            //   CheckRun   → uses c.conclusion (SUCCESS/FAILURE/NEUTRAL/SKIPPED/...)
            //   StatusContext → uses c.state (SUCCESS/FAILURE/PENDING/ERROR)
            const isFailure = (c: any) => c.__typename === 'StatusContext'
              ? (c.state === 'FAILURE' || c.state === 'ERROR')
              : (c.conclusion === 'FAILURE' || c.conclusion === 'ERROR')
            const isPending = (c: any) => c.__typename === 'StatusContext'
              ? (c.state === 'PENDING' || c.state === 'EXPECTED')
              : (!c.status || c.status === 'IN_PROGRESS' || c.status === 'QUEUED' || c.status === 'WAITING')
            // NEUTRAL/SKIPPED are informational — don't count toward pending

            const hasFailure = checks.some(isFailure)
            const hasPending = checks.some(isPending)

            if (hasFailure) {
              ciStatus = 'red'
              failedChecks.push(...checks.filter(isFailure).map(c => c.name ?? c.context ?? '?'))
            } else if (hasPending) {
              ciStatus = 'pending'
            } else {
              ciStatus = 'green'
            }
          }

          return {
            number: pr.number,
            title: pr.title,
            url: pr.url,
            branch: pr.headRefName,
            createdAt: pr.createdAt,
            isDraft: pr.isDraft,
            mergeable: pr.mergeable,
            mergeStateStatus: status.mergeStateStatus,
            ciStatus,
            failedChecks,
          }
        } catch (err) {
          console.error(`Failed to get status for PR ${pr.number}:`, err)
          return {
            number: pr.number,
            title: pr.title,
            url: pr.url,
            branch: pr.headRefName,
            createdAt: pr.createdAt,
            isDraft: pr.isDraft,
            mergeable: pr.mergeable,
            mergeStateStatus: 'UNKNOWN',
            ciStatus: 'unknown',
            failedChecks: [],
          }
        }
      })
    )

    // Annotate each PR with its associated bug from the DB
    const db = getDb()
    const annotated = results.map((pr: any) => {
      const bug = db.prepare(
        'SELECT topic, post, reporter, excerpt FROM discourse_bug WHERE pr_number = ? LIMIT 1'
      ).get(pr.number) as { topic: number; post: number; reporter: string | null; excerpt: string | null } | undefined
      return bug ? { ...pr, bug: { topic: bug.topic, post: bug.post, reporter: bug.reporter, excerpt: bug.excerpt } } : pr
    })

    prCache = { data: annotated, timestamp: Date.now() }
    return annotated
  } catch (err) {
    console.error('Failed to fetch PRs:', err)
    return []
  }
}

function postToDiscourse(topicId: number, raw: string): Promise<{ ok: boolean; error?: string }> {
  return new Promise((resolve) => {
    const apiKey = getDiscourseApiKey()
    const body = JSON.stringify({ topic_id: topicId, raw })

    const options = {
      hostname: 'discourse.ilovefreegle.org',
      port: 443,
      path: '/posts.json',
      method: 'POST',
      headers: {
        'User-Api-Key': apiKey,
        'Api-Username': 'Edward_Hibbert',
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
      },
    }

    const req = https.request(options, (res) => {
      let data = ''
      res.on('data', chunk => { data += chunk })
      res.on('end', () => {
        if (res.statusCode === 200 || res.statusCode === 201) {
          resolve({ ok: true })
        } else {
          resolve({ ok: false, error: `HTTP ${res.statusCode}` })
        }
      })
    })

    req.on('error', (err) => {
      resolve({ ok: false, error: String(err) })
    })

    req.write(body)
    req.end()
  })
}

function json(res: ServerResponse, status: number, data: unknown): void {
  const body = JSON.stringify(data)
  res.writeHead(status, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' })
  res.end(body)
}

function notFound(res: ServerResponse): void {
  json(res, 404, { error: 'not found' })
}

async function handleApi(db: DB, req: IncomingMessage, res: ServerResponse, path: string): Promise<void> {
  if (req.method === 'OPTIONS') {
    res.writeHead(204, { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Methods': 'GET,PUT,POST', 'Access-Control-Allow-Headers': 'Content-Type' })
    res.end()
    return
  }

  // GET /api/bugs
  if (req.method === 'GET' && path === '/api/bugs') {
    const rows = db.prepare(`
      SELECT b.topic, b.post, b.topic_title, b.reporter, b.excerpt, b.state,
             b.pr_number, b.reason, b.first_seen_at, b.last_seen_at,
             b.fixed_at, b.deployed_at, b.feature_area, b.pr_rejections,
             COALESCE(b.feature_area, 'Uncategorised') AS group_key,
             p.deploy_state
      FROM discourse_bug b
      LEFT JOIN pr p ON p.number = b.pr_number
      ORDER BY COALESCE(b.feature_area, 'Uncategorised'), b.topic, b.post
    `).all()
    json(res, 200, rows)
    return
  }

  // GET /api/drafts
  if (req.method === 'GET' && path === '/api/drafts') {
    const rows = db.prepare(`
      SELECT d.*, p.deploy_state
      FROM discourse_draft d
      LEFT JOIN pr p ON p.number = d.pr_number
      ORDER BY d.queued_at
    `).all()
    json(res, 200, rows)
    return
  }

  // GET /api/iterations
  if (req.method === 'GET' && path === '/api/iterations') {
    const rows = db.prepare('SELECT * FROM iteration ORDER BY id DESC LIMIT 50').all()
    json(res, 200, rows)
    return
  }

  // GET /api/prs
  if (req.method === 'GET' && path === '/api/prs') {
    const rows = db.prepare('SELECT * FROM pr ORDER BY number DESC').all()
    json(res, 200, rows)
    return
  }

  // GET /api/prs/exhausted  — PRs that hit the 3-attempt budget and need human review
  if (req.method === 'GET' && path === '/api/prs/exhausted') {
    const kvRows = db.prepare(`SELECT key, value FROM kv WHERE key LIKE 'pr_fix_attempts_%'`).all() as Array<{ key: string; value: string }>
    const exhausted = kvRows
      .map(r => ({ number: parseInt(r.key.replace('pr_fix_attempts_', ''), 10), attempts: parseInt(r.value, 10) }))
      .filter(r => r.attempts >= 3)
    const focusRaw = kvGet(db, 'focus_pr_number')
    const focusPRNumber = focusRaw ? parseInt(focusRaw, 10) : null
    json(res, 200, { exhausted, focusPRNumber })
    return
  }

  // GET /api/prs/live  — ?refresh=1 busts the cache
  if (req.method === 'GET' && path === '/api/prs/live') {
    if (req.url?.includes('refresh=1')) prCache = null
    try {
      const prs = await fetchPrsLive()
      json(res, 200, prs)
    } catch (err: any) {
      json(res, 500, { error: String(err?.message ?? err) })
    }
    return
  }

  // PUT /api/drafts/:id  — edit draft body
  const draftEdit = path.match(/^\/api\/drafts\/(\d+)$/)
  if (req.method === 'PUT' && draftEdit) {
    const id = Number(draftEdit[1])
    const body = await readBody(req)
    let parsed: { body?: string; quote?: string }
    try { parsed = JSON.parse(body) } catch { json(res, 400, { error: 'bad json' }); return }
    if (parsed.body !== undefined) {
      db.prepare('UPDATE discourse_draft SET body = ? WHERE id = ?').run(parsed.body, id)
    }
    if (parsed.quote !== undefined) {
      db.prepare('UPDATE discourse_draft SET quote = ? WHERE id = ?').run(parsed.quote, id)
    }
    const row = db.prepare('SELECT * FROM discourse_draft WHERE id = ?').get(id)
    json(res, 200, row ?? { error: 'not found' })
    return
  }

  // POST /api/drafts/:id/approve  — mark draft approved (human reviewed it)
  const draftApprove = path.match(/^\/api\/drafts\/(\d+)\/approve$/)
  if (req.method === 'POST' && draftApprove) {
    const id = Number(draftApprove[1])
    db.prepare("UPDATE discourse_draft SET approved_at = datetime('now') WHERE id = ? AND approved_at IS NULL").run(id)
    const row = db.prepare('SELECT * FROM discourse_draft WHERE id = ?').get(id)
    json(res, 200, row ?? { error: 'not found' })
    return
  }

  // POST /api/drafts/:id/reject  — reject draft with reason
  const draftReject = path.match(/^\/api\/drafts\/(\d+)\/reject$/)
  if (req.method === 'POST' && draftReject) {
    const id = Number(draftReject[1])
    const body = await readBody(req)
    let parsed: { reason?: string } = {}
    try { parsed = JSON.parse(body) } catch { /* reason optional */ }
    db.prepare("UPDATE discourse_draft SET rejected_at = datetime('now'), rejection_reason = ? WHERE id = ?").run(parsed.reason ?? null, id)
    const row = db.prepare('SELECT * FROM discourse_draft WHERE id = ?').get(id)
    json(res, 200, row ?? { error: 'not found' })
    return
  }

  // POST /api/drafts/:id/send  — send draft to Discourse
  const draftSend = path.match(/^\/api\/drafts\/(\d+)\/send$/)
  if (req.method === 'POST' && draftSend) {
    const id = Number(draftSend[1])
    const draft = db.prepare('SELECT * FROM discourse_draft WHERE id = ?').get(id) as any
    if (!draft) {
      json(res, 404, { error: 'draft not found' })
      return
    }

    try {
      // Format as Discourse reply with quote
      const raw = `[quote="${draft.username}, post:${draft.post}, topic:${draft.topic}"]\n${draft.quote}\n[/quote]\n\n${draft.body}`

      // Post to Discourse
      const result = await postToDiscourse(draft.topic, raw)
      if (!result.ok) {
        json(res, 502, { error: result.error ?? 'Failed to post' })
        return
      }

      // Mark as posted
      db.prepare("UPDATE discourse_draft SET posted_at = datetime('now') WHERE id = ?").run(id)
      const updated = db.prepare('SELECT * FROM discourse_draft WHERE id = ?').get(id)
      json(res, 200, updated ?? { error: 'not found' })
    } catch (err: any) {
      json(res, 500, { error: String(err?.message ?? err) })
    }
    return
  }

  // POST /api/bugs/:topic/:post/state  — update bug state/reason
  const bugState = path.match(/^\/api\/bugs\/(\d+)\/(\d+)\/state$/)
  if (req.method === 'POST' && bugState) {
    const topic = Number(bugState[1])
    const post = Number(bugState[2])
    const body = await readBody(req)
    let parsed: { state?: string; reason?: string }
    try { parsed = JSON.parse(body) } catch { json(res, 400, { error: 'bad json' }); return }
    if (parsed.state) {
      db.prepare("UPDATE discourse_bug SET state = ?, reason = COALESCE(?, reason), last_seen_at = datetime('now') WHERE topic = ? AND post = ?")
        .run(parsed.state, parsed.reason ?? null, topic, post)
    }
    const row = db.prepare('SELECT * FROM discourse_bug WHERE topic = ? AND post = ?').get(topic, post)
    json(res, 200, row ?? { error: 'not found' })
    return
  }

  // POST /api/bugs/:topic/:post/link-pr  — link a PR and set fix-queued
  const bugLinkPr = path.match(/^\/api\/bugs\/(\d+)\/(\d+)\/link-pr$/)
  if (req.method === 'POST' && bugLinkPr) {
    const topic = Number(bugLinkPr[1])
    const post = Number(bugLinkPr[2])
    const body = await readBody(req)
    let parsed: { prNumber?: number }
    try { parsed = JSON.parse(body) } catch { json(res, 400, { error: 'bad json' }); return }
    if (!parsed.prNumber) { json(res, 400, { error: 'prNumber required' }); return }
    db.prepare(`
      UPDATE discourse_bug
      SET state = 'fix-queued', pr_number = ?, pr_rejections = 0,
          reason = 'Linked by human', last_seen_at = datetime('now')
      WHERE topic = ? AND post = ?
    `).run(parsed.prNumber, topic, post)
    const row = db.prepare('SELECT * FROM discourse_bug WHERE topic = ? AND post = ?').get(topic, post)
    json(res, 200, row ?? { error: 'not found' })
    return
  }

  // POST /api/status/push  — regenerate and push the Discourse wiki post
  if (req.method === 'POST' && path === '/api/status/push') {
    try {
      const result = await putStatusPost(db)
      json(res, result.posted ? 200 : 502, result)
    } catch (err: any) {
      json(res, 500, { error: String(err?.message ?? err) })
    }
    return
  }

  // POST /api/prs/:number/merge  — merge a ready PR
  const prMerge = path.match(/^\/api\/prs\/(\d+)\/merge$/)
  if (req.method === 'POST' && prMerge) {
    const prNumber = Number(prMerge[1])
    try {
      await execAsync(
        `gh pr merge ${prNumber} --repo Freegle/Iznik --merge --auto`,
        { maxBuffer: 1024 * 1024, timeout: 30000 }
      )
      prCache = null
      json(res, 200, { merged: true, prNumber })
    } catch (err: any) {
      json(res, 502, { error: String(err?.stderr ?? err?.message ?? err) })
    }
    return
  }

  // GET /api/status/preview  — render but don't post
  if (req.method === 'GET' && path === '/api/status/preview') {
    const { renderStatusPostBody } = await import('./db/discourse-status.js')
    const result = renderStatusPostBody(db)
    json(res, 200, result)
    return
  }

  notFound(res)
}

function serveStatic(req: IncomingMessage, res: ServerResponse, urlPath: string): void {
  // Strip query string
  const cleanPath = urlPath.split('?')[0]
  let filePath = resolve(DIST_DIR, '.' + cleanPath)

  // SPA fallback — anything without an extension serves index.html
  if (!existsSync(filePath) || extname(filePath) === '') {
    filePath = resolve(DIST_DIR, 'index.html')
  }

  if (!existsSync(filePath)) {
    res.writeHead(404)
    res.end('Not found')
    return
  }

  const ext = extname(filePath)
  const mime = MIME[ext] ?? 'application/octet-stream'
  res.writeHead(200, { 'Content-Type': mime })
  res.end(readFileSync(filePath))
}

const db = getDb()

// Auto-post Discourse status every 30 minutes
const STATUS_POST_INTERVAL = 30 * 60 * 1000
async function autoPostStatus() {
  try {
    const result = await putStatusPost(db)
    if (result.posted) {
      console.log('[status] auto-posted Discourse status')
    } else {
      console.log(`[status] auto-post skipped: ${result.reason ?? 'unknown'}`)
    }
  } catch (err: any) {
    console.error('[status] auto-post error:', err.message)
  }
}
// Run once at startup then on interval
autoPostStatus()
setInterval(autoPostStatus, STATUS_POST_INTERVAL)

const server = createServer(async (req: IncomingMessage, res: ServerResponse) => {
  const url = req.url ?? '/'
  try {
    if (url.startsWith('/api/')) {
      await handleApi(db, req, res, url.split('?')[0])
    } else {
      serveStatic(req, res, url)
    }
  } catch (err: any) {
    console.error('Dashboard server error:', err)
    json(res, 500, { error: String(err?.message ?? err) })
  }
})

server.listen(PORT, '127.0.0.1', () => {
  console.log(`Monitor dashboard: http://localhost:${PORT}`)
})
