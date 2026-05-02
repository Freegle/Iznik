// Generated "views" over the SQLite data — backward-compat files in /tmp/freegle-monitor/
// Nothing ever reads these files authoritatively (the DB is authoritative). They exist so
// humans who used to grep /tmp/freegle-monitor/ still find something, and so the legacy
// email pipeline can still attach summary.md unchanged.
//
// Call renderSummaryMd() / renderDraftsMd() / renderStateJson() at end of iteration.

import { writeFile, mkdir } from 'node:fs/promises'
import { dirname } from 'node:path'
import type { Database as DB } from 'better-sqlite3'
import { listOpenDiscourseBugs, listPendingDrafts, listTopicCursors, kvGet } from './index.js'
import { DISCOURSE_BASE } from '../discourse.js'

export const FS_DIR = '/tmp/freegle-monitor'
export const SUMMARY_PATH = `${FS_DIR}/summary.md`
export const DRAFTS_PATH = `${FS_DIR}/retest-drafts.md`
export const STATE_PATH = `${FS_DIR}/state.json`

async function writeAt(path: string, content: string): Promise<void> {
  await mkdir(dirname(path), { recursive: true })
  await writeFile(path, content, 'utf8')
}

export async function renderStateJson(db: DB, path = STATE_PATH): Promise<void> {
  const cursors = listTopicCursors(db)
  const topics: Record<string, { last_post: number; title: string | null }> = {}
  for (const c of cursors) {
    topics[String(c.topic_id)] = { last_post: c.last_post_number, title: c.title }
  }
  const state = {
    topics,
    last_email_sent: kvGet(db, 'last_email_sent'),
    sentry_last_check: kvGet(db, 'sentry_last_check'),
  }
  await writeAt(path, JSON.stringify(state, null, 2))
}

export async function renderDraftsMd(db: DB, path = DRAFTS_PATH): Promise<void> {
  const pending = listPendingDrafts(db)
  const lines: string[] = [
    '# Discourse Reply Drafts',
    '',
    'Queued for human approval. NEVER auto-posted. Copy-paste into Discourse after review.',
    '',
  ]
  for (const d of pending) {
    const prRef = d.pr_number ? ` *(PR #${d.pr_number}${d.pr_url ? ` — ${d.pr_url}` : ''})*` : ''
    const reply = d.preview_url
      ? `@${d.username} Possible fix — please test: ${d.preview_url}`
      : `@${d.username} ${d.body}`
    lines.push(
      `## ${d.topic}.${d.post} — @${d.username}${prRef}`,
      `*Queued ${d.queued_at} — ${DISCOURSE_BASE}/t/${d.topic}/${d.post}*`,
      '',
      `[quote="${d.username}, post:${d.post}, topic:${d.topic}"]`,
      d.quote,
      '[/quote]',
      '',
      reply,
      '',
      '---',
      '',
    )
  }
  await writeAt(path, lines.join('\n'))
}

export async function renderSummaryMd(db: DB, path = SUMMARY_PATH): Promise<void> {
  const openBugs = listOpenDiscourseBugs(db)
  const pending = listPendingDrafts(db)
  const iterCount = (db.prepare('SELECT COUNT(*) AS c FROM iteration').get() as { c: number }).c
  const firstIter = db.prepare('SELECT MIN(started_at) AS t FROM iteration').get() as { t: string | null }
  const now = new Date().toISOString()

  const lines: string[] = [
    '# Freegle Monitor Summary',
    '',
    `Last updated: ${now} | Iterations: ${iterCount} | Monitoring since: ${firstIter.t ?? '—'}`,
    '',
    '## Open bugs',
    '',
  ]
  if (openBugs.length === 0) {
    lines.push('*No open bugs.*', '')
  } else {
    lines.push('| Topic.Post | Reporter | Excerpt | State | Reason |', '|---|---|---|---|---|')
    for (const b of openBugs) {
      const excerpt = (b.excerpt ?? '').replace(/\|/g, '\\|').slice(0, 120)
      lines.push(
        `| [${b.topic}.${b.post}](${DISCOURSE_BASE}/t/${b.topic}/${b.post}) | ${b.reporter ?? '—'} | ${excerpt} | ${b.state} | ${(b.reason ?? '').slice(0, 100)} |`,
      )
    }
    lines.push('')
  }

  lines.push('## Pending Discourse drafts (awaiting approval)', '')
  if (pending.length === 0) {
    lines.push('*None.*', '')
  } else {
    for (const d of pending) {
      lines.push(`- **${d.topic}.${d.post}** — @${d.username}${d.pr_number ? ` *(PR #${d.pr_number})*` : ''}`)
    }
    lines.push('')
  }

  await writeAt(path, lines.join('\n'))
}

export async function renderAllViews(db: DB): Promise<void> {
  await Promise.all([
    renderStateJson(db),
    renderDraftsMd(db),
    renderSummaryMd(db),
  ])
}
