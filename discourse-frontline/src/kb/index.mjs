// What the service knows, and how it looks things up.
//
// Three sources, all of them already public: the volunteer wiki, docs/ in this
// repository, and the titles of changes merged lately. Nothing here comes from the
// production database, the logs or anybody's mail.
//
// Looking something up costs no model tokens. It is a BM25 search over an SQLite
// full-text table, and only the best few sections are ever shown to the model.

import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { contentTerms, ftsQuery, tx } from '../db.mjs'
import { chunkMarkdown, chunkWikitext } from './chunk.mjs'

function replaceSource(db, source, rows) {
  tx(db, () => {
    for (const { id } of db.prepare('SELECT id FROM kb WHERE source = ?').all(source)) {
      db.prepare('DELETE FROM kb_fts WHERE rowid = ?').run(id)
    }
    db.prepare('DELETE FROM kb WHERE source = ?').run(source)
    const ins = db.prepare('INSERT INTO kb (source, title, heading, url, body) VALUES (?, ?, ?, ?, ?)')
    const fts = db.prepare('INSERT INTO kb_fts (rowid, title, heading, body) VALUES (?, ?, ?, ?)')
    for (const r of rows) {
      const { lastInsertRowid } = ins.run(source, r.title, r.heading, r.url, r.body)
      fts.run(lastInsertRowid, r.title, r.heading, r.body)
    }
  })
  return rows.length
}

function walk(dir) {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name)
    return statSync(p).isDirectory() ? walk(p) : p.endsWith('.md') ? [p] : []
  })
}

export function ingestDocs(db, cfg, root = process.cwd()) {
  const rows = []
  for (const dir of cfg.docsDirs) {
    const abs = resolve(root, dir)
    const docsRoot = resolve(abs, '..')
    for (const file of walk(abs)) {
      const { title, chunks } = chunkMarkdown(readFileSync(file, 'utf8'))
      const url = `${cfg.docsBase}/${relative(docsRoot, file).replace(/\\/g, '/')}`
      for (const c of chunks) rows.push({ title: title || relative(docsRoot, file), heading: c.heading, url, body: c.body })
    }
  }
  return replaceSource(db, 'docs', rows)
}

export async function ingestWiki(db, cfg, { fetchFn = fetch, sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
  const rows = []
  let cont = {}
  for (let page = 0; page < 200; page++) {
    const q = new URLSearchParams({
      action: 'query', format: 'json', formatversion: '2', generator: 'allpages', gaplimit: '50',
      gapfilterredir: 'nonredirects', prop: 'revisions', rvprop: 'content', rvslots: 'main', ...cont,
    })
    const resp = await fetchFn(`${cfg.wikiApi}?${q}`, { headers: { 'User-Agent': 'discourse-frontline' }, signal: AbortSignal.timeout(60000) })
    if (!resp.ok) throw new Error(`wiki: HTTP ${resp.status}`)
    const data = await resp.json()
    for (const p of data.query?.pages ?? []) {
      const wt = p.revisions?.[0]?.slots?.main?.content
      if (!wt) continue
      const url = `${cfg.wikiBase}/${encodeURIComponent(p.title.replace(/ /g, '_'))}`
      for (const c of chunkWikitext(wt)) rows.push({ title: p.title, heading: c.heading, url, body: c.body })
    }
    if (!data.continue) break
    cont = data.continue
    await sleep(300)
  }
  // A wiki that answers with nothing is a fault, not an empty wiki. Keep what we had.
  if (rows.length === 0) throw new Error('wiki: no pages returned, index left as it was')
  return replaceSource(db, 'wiki', rows)
}

/** Titles of pull requests merged lately, read from the local git history. */
export function ingestChanges(db, cfg, { days = 45, cwd = process.cwd() } = {}) {
  let log
  try {
    log = execFileSync('git', ['log', '--merges', `--since=${days}.days`, '--format=%H%x1f%cs%x1f%s%x1f%b%x1e', 'origin/master'], { cwd, encoding: 'utf8', maxBuffer: 1 << 24 })
  } catch {
    return 0
  }
  const rows = []
  for (const rec of log.split('\x1e')) {
    const [, date, subject, body] = rec.trim().split('\x1f')
    const pr = /Merge pull request #(\d+)/.exec(subject ?? '')
    const title = (body ?? '').trim().split('\n')[0]
    if (!pr || !title) continue
    rows.push({ title, heading: date, url: `https://github.com/Freegle/Iznik/pull/${pr[1]}`, body: title })
  }
  return replaceSource(db, 'changes', rows)
}

/**
 * The best sections for a passage of text, ranked by BM25.
 *
 * A weak match shown to a model becomes a confident wrong answer, so a section has
 * to share enough of the passage's words to be offered at all: `minShared` of them,
 * and a third of however many there are. That floor is counted in words and not as
 * a BM25 score, because a score means something different in an index of twenty
 * sections and one of twenty thousand. BM25 scores are negative in SQLite and lower
 * is better, so they are flipped here: bigger is a better match.
 */
export function searchKb(db, text, { sources = ['wiki', 'docs'], limit = 3, minShared = 3 } = {}) {
  const terms = contentTerms(text)
  // Crude stemming, enough to count "moderation" and "moderated" as one word.
  const stems = terms.map((t) => t.slice(0, Math.max(4, Math.ceil(t.length * 0.6))))
  const shared = (r) => {
    const hay = `${r.title} ${r.heading} ${r.body}`.toLowerCase()
    return stems.filter((s) => hay.includes(s)).length
  }
  const q = ftsQuery(text)
  if (!q) return []
  const marks = sources.map(() => '?').join(',')
  const rows = db.prepare(`
    SELECT kb.id, kb.source, kb.title, kb.heading, kb.url, kb.body,
           -bm25(kb_fts, 6.0, 3.0, 1.0) AS score
    FROM kb_fts JOIN kb ON kb.id = kb_fts.rowid
    WHERE kb_fts MATCH ? AND kb.source IN (${marks})
    ORDER BY score DESC LIMIT ?`).all(q, ...sources, limit * 3)
  // One section per page: three cuts of the same page crowd out a second opinion.
  const seen = new Set()
  const out = []
  for (const r of rows) {
    const n = shared(r)
    if (n < minShared || n < terms.length / 3 || seen.has(r.url)) continue
    seen.add(r.url)
    out.push(r)
    if (out.length >= limit) break
  }
  return out
}
