// Local dashboard HTTP server — Edward-only, port 8765.
// Serves dashboard-dist/ (Vite build) as static files and provides a REST API
// backed by the same SQLite DB used by monitor-fsm.
//
// Start: node --enable-source-maps dist/dashboard-server.js

import { createServer, type IncomingMessage, type ServerResponse } from 'node:http'
import { readFileSync, existsSync } from 'node:fs'
import { resolve, extname, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { getDb } from './db/index.js'
import { putStatusPost } from './db/discourse-status.js'
import type { Database as DB } from 'better-sqlite3'

const __dirname = dirname(fileURLToPath(import.meta.url))
const DIST_DIR = resolve(__dirname, '..', 'dashboard-dist')
const PORT = 8765

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
    const rows = db.prepare('SELECT * FROM discourse_bug ORDER BY feature_area, topic, post').all()
    json(res, 200, rows)
    return
  }

  // GET /api/drafts
  if (req.method === 'GET' && path === '/api/drafts') {
    const rows = db.prepare('SELECT * FROM discourse_draft ORDER BY queued_at').all()
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
