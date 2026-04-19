// Regenerate the "Monitor Status — Live Summary" Discourse wiki post (category
// "Bug Reports", id 17) from the SQLite DB. Called at iteration end.
//
// Post ID + topic ID are fixed (created once on 2026-04-19). We only EDIT
// via PUT /posts/{id}.json — never POST a new reply, which would fire
// notifications to anyone watching.
//
// The content is written in plain English for a Freegle-moderator audience:
// no PR numbers, CI state, Sentry IDs, iteration steps, or any developer
// jargon. Technical details stay in the DB for the developer audience.

import { readFileSync } from 'node:fs'
import type { Database as DB } from 'better-sqlite3'
import { listOpenDiscourseBugs, listPendingDrafts } from './index.js'

export const STATUS_POST_ID = 63250
export const STATUS_TOPIC_ID = 9599
export const STATUS_CATEGORY_ID = 17

const DISCOURSE_BASE = 'https://discourse.ilovefreegle.org'

export interface StatusRenderResult {
  raw: string
  bugCount: number
  draftCount: number
  fixedRecentCount: number
}

export function renderStatusPostBody(db: DB): StatusRenderResult {
  const open = listOpenDiscourseBugs(db)
  const pending = listPendingDrafts(db)

  // Recently-fixed bugs (last 7 days) — shows moderators we're responsive.
  const recentFixed = db.prepare(`
    SELECT topic, post, reporter, excerpt, fixed_at, deployed_at
    FROM discourse_bug
    WHERE state = 'fixed'
      AND fixed_at IS NOT NULL
      AND fixed_at >= datetime('now', '-7 days')
    ORDER BY fixed_at DESC
    LIMIT 20
  `).all() as Array<{ topic: number; post: number; reporter: string | null; excerpt: string | null; fixed_at: string; deployed_at: string | null }>

  const lines: string[] = []
  lines.push('# Bug Reports — Live Summary', '')
  lines.push('Hi! This is an automatically-updated list of the bugs and issues we\'re currently aware of on Freegle — both ones reported by moderators on Discourse and errors spotted in the site itself. It\'s rewritten from scratch each time our monitoring checker runs, so it\'s always up to date.')
  lines.push('')
  lines.push(`*Last updated ${new Date().toISOString().replace('T', ' ').slice(0, 16)} UTC*`)
  lines.push('')
  lines.push('No need to reply here — replies aren\'t read and nobody gets an email when this post changes.')
  lines.push('')

  // Stable-ish display IDs: B-<topic>-<post> for bugs, D-<draft_id> for drafts.
  const bugId = (b: { topic: number; post: number }) => `B-${b.topic}-${b.post}`
  const draftId = (d: { id: number }) => `D-${d.id}`

  // Bugs being worked on — open + investigating + fix-queued + deferred
  const workingStates = ['open', 'investigating', 'fix-queued']
  const working = open.filter(b => workingStates.includes(b.state))
  if (working.length > 0) {
    lines.push('## Bugs we\'re working on', '')
    for (const b of working) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = (b.excerpt ?? '').slice(0, 160)
      const stateLabel = b.state === 'fix-queued' ? 'fix sent for testing' : b.state === 'investigating' ? 'being investigated' : 'to look at'
      lines.push(`- \`${bugId(b)}\` — **[${b.reporter ?? 'reporter'}](${url})** — ${excerpt} *(${stateLabel})*`)
    }
    lines.push('')
  }

  // Recently fixed
  if (recentFixed.length > 0) {
    lines.push('## Recently fixed (last 7 days)', '')
    for (const b of recentFixed) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = (b.excerpt ?? '').slice(0, 160)
      const status = b.deployed_at ? 'live' : 'fix on the way to the live site'
      lines.push(`- \`${bugId(b)}\` — **[${b.reporter ?? 'reporter'}](${url})** — ${excerpt} *(${status})*`)
    }
    lines.push('')
  }

  // Deferred — "waiting on you"
  const deferred = open.filter(b => b.state === 'deferred')
  if (deferred.length > 0) {
    lines.push('## Waiting on more information', '')
    for (const b of deferred) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = (b.excerpt ?? '').slice(0, 160)
      const reason = b.reason ? ` — ${b.reason}` : ''
      lines.push(`- \`${bugId(b)}\` — **[${b.reporter ?? 'reporter'}](${url})** — ${excerpt}${reason}`)
    }
    lines.push('')
  }

  if (working.length === 0 && recentFixed.length === 0 && deferred.length === 0) {
    lines.push('*Nothing currently on the list — we\'re all caught up!*', '')
  }

  // Reply drafts — show inline so moderators can review and post them. We
  // cannot embed a real click-to-post button inside a Discourse wiki post, so
  // instead each draft gets: (1) a unique ID you can point at in chat; (2) the
  // full quoted body as a fenced block (Discourse's "copy" chip picks it up);
  // (3) a "Reply on Discourse" link that jumps to the target post so you can
  // click Reply, paste, and send.
  if (pending.length > 0) {
    lines.push('---', '')
    lines.push('## Reply drafts awaiting review', '')
    lines.push(`*${pending.length} reply draft${pending.length === 1 ? '' : 's'} queued. Each is keyed to a reporter; open the link, paste the block, send.*`, '')
    for (const d of pending) {
      const targetUrl = `${DISCOURSE_BASE}/t/${d.topic}/${d.post}`
      lines.push(`### \`${draftId(d)}\` → reply to @${d.username} on [${d.topic}/${d.post}](${targetUrl})`)
      lines.push('')
      lines.push('```')
      lines.push(`[quote="${d.username}, post:${d.post}, topic:${d.topic}"]`)
      lines.push(d.quote)
      lines.push('[/quote]')
      lines.push('')
      lines.push(d.body)
      lines.push('```')
      lines.push('')
      lines.push(`[Open post to reply →](${targetUrl})`)
      lines.push('')
    }
  }

  return {
    raw: lines.join('\n'),
    bugCount: working.length + deferred.length,
    draftCount: pending.length,
    fixedRecentCount: recentFixed.length,
  }
}

function getDiscourseApiKey(): string | null {
  try {
    const profile = JSON.parse(readFileSync('/home/edward/profile.json', 'utf8')) as { auth_pairs?: Array<{ user_api_key?: string }> }
    return profile.auth_pairs?.[0]?.user_api_key ?? null
  } catch {
    return null
  }
}

export async function putStatusPost(db: DB, opts: { postId?: number; apiKey?: string; editReason?: string } = {}): Promise<{ posted: boolean; status?: number; reason?: string; body: string }> {
  const body = renderStatusPostBody(db)
  const postId = opts.postId ?? STATUS_POST_ID
  const apiKey = opts.apiKey ?? getDiscourseApiKey()
  if (!apiKey) {
    return { posted: false, reason: 'no Discourse API key available', body: body.raw }
  }

  const form = new URLSearchParams({
    'post[raw]': body.raw,
    'post[edit_reason]': opts.editReason ?? `Auto-update from monitor-fsm (${body.bugCount} open, ${body.fixedRecentCount} recent, ${body.draftCount} draft${body.draftCount === 1 ? '' : 's'})`,
  })

  const resp = await fetch(`${DISCOURSE_BASE}/posts/${postId}.json`, {
    method: 'PUT',
    headers: {
      'User-Api-Key': apiKey,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: form.toString(),
  })

  if (!resp.ok) {
    const text = await resp.text().catch(() => '')
    return { posted: false, status: resp.status, reason: text.slice(0, 500), body: body.raw }
  }
  return { posted: true, status: resp.status, body: body.raw }
}
