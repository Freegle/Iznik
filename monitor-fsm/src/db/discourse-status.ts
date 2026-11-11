// Regenerate the "Monitor Status — Live Summary" Discourse wiki post (category
// "Bug Reports", id 17) from the SQLite DB. Called at iteration end.
//
// Post ID + topic ID are fixed (created once on 2026-04-19). We only EDIT
// via PUT /posts/{id}.json — never POST a new reply, which would fire
// notifications to anyone watching.
//
// Plain English for a Freegle-moderator audience: no Sentry IDs, CI steps,
// iteration counts, or V1/V2/Go/Nuxt jargon. PR links ARE included — mods
// asked for them so they can see what's outstanding.

import { readFileSync } from 'node:fs'
import type { Database as DB } from 'better-sqlite3'
import { listOpenDiscourseBugs, markDiscourseBugFixed, kvGet, kvSet, type DiscourseBugRow } from './index.js'
import { DISCOURSE_BASE, PROFILE_PATH } from '../discourse.js'

export const STATUS_POST_ID = 63250
export const STATUS_TOPIC_ID = 9599
export const STATUS_CATEGORY_ID = 17

export interface StatusRenderResult {
  raw: string
  bugCount: number
  draftCount: number
  fixedRecentCount: number
}

// Reconcile bug state against PR state before rendering. A bug sits in
// 'fix-queued' from the moment a draft reply is queued (PR opened). It should
// only move to 'fixed' once the PR is MERGED and live. Nothing in the rest of
// the pipeline was calling markDiscourseBugFixed, so bugs accumulated forever
// in "working on" with the misleading label "fixed". Do the transition here,
// on every render, based on the pr table.
function reconcileBugStates(db: DB): void {
  const rows = db.prepare(`
    SELECT b.topic, b.post, b.pr_number, p.state AS pr_state, p.deploy_state
    FROM discourse_bug b
    JOIN pr p ON p.number = b.pr_number
    WHERE b.state = 'fix-queued'
      AND b.pr_number IS NOT NULL
      AND p.state = 'MERGED'
      -- deploy_state is rarely populated; Freegle auto-deploys on merge to
      -- production, so MERGED is sufficient to consider a fix live.
  `).all() as Array<{ topic: number; post: number; pr_number: number }>
  for (const r of rows) {
    markDiscourseBugFixed(db, r.topic, r.post, r.pr_number)
  }
}

// Discourse refuses a post over 32000 characters, and the edit then fails every lap. Stay a little
// under it so the edit reason and any encoding have room.
export const MAX_STATUS_POST_CHARS = 30000

// A report in the queue or waiting for information with no activity for this long is left off the list.
export const STALE_AFTER_DAYS = Number(process.env.MONITOR_STATUS_STALE_DAYS ?? 14)

// "26 Oct" from an SQLite UTC timestamp.
function formatDay(ts: string): string {
  const d = new Date(ts.replace(' ', 'T') + 'Z')
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' })
}

export interface StatusSection {
  title: string
  header: string[]
  rows: string[]
  // Lower loses rows first when the post is too long.
  dropOrder: number
}

// Join the sections into the post, dropping rows from the end of the least important tables until it
// fits MAX_STATUS_POST_CHARS. A table that lost rows says how many are not shown; one with no rows at
// all is left out.
export function fitStatusPost(head: string[], sections: StatusSection[], tail: string[] = [], max = MAX_STATUS_POST_CHARS): string {
  const shown = sections.map(s => s.rows.length)
  const assemble = () => {
    const lines = [...head]
    sections.forEach((s, i) => {
      if (s.rows.length === 0) return
      lines.push(`## ${s.title}`, '')
      if (shown[i] > 0) lines.push(...s.header, ...s.rows.slice(0, shown[i]))
      const hidden = s.rows.length - shown[i]
      if (hidden > 0) lines.push('', `*…and ${hidden} more not shown here, to keep this post within Discourse's length limit.*`)
      lines.push('')
    })
    return [...lines, ...tail].join('\n')
  }

  const byDrop = sections.map((s, i) => i).sort((x, y) => sections[x].dropOrder - sections[y].dropOrder)
  let raw = assemble()
  while (raw.length > max) {
    const i = byDrop.find(j => shown[j] > 0)
    if (i === undefined) break
    shown[i]--
    raw = assemble()
  }
  return raw
}

export function renderStatusPostBody(db: DB): StatusRenderResult {
  reconcileBugStates(db)
  const open = listOpenDiscourseBugs(db)

  // Count pending drafts for the edit-reason metadata only (not rendered here).
  const pendingDraftCount = (db.prepare(`
    SELECT COUNT(*) AS n FROM discourse_draft
    WHERE approved_at IS NULL AND posted_at IS NULL AND rejected_at IS NULL
  `).get() as { n: number }).n

  // Recently-fixed bugs (last 7 days) — shows moderators we're responsive.
  const recentFixed = db.prepare(`
    SELECT topic, post, reporter, excerpt, pr_number, fixed_at, deployed_at, feature_area
    FROM discourse_bug
    WHERE state = 'fixed'
      AND fixed_at IS NOT NULL
      AND fixed_at >= datetime('now', '-7 days')
    ORDER BY fixed_at DESC
    LIMIT 20
  `).all() as Array<{ topic: number; post: number; reporter: string | null; excerpt: string | null; pr_number: number | null; fixed_at: string; deployed_at: string | null; feature_area: string | null }>

  // Fetch deploy_state for each bug that has a PR, keyed by pr_number.
  const deployStates = new Map<number, string | null>()
  const prRows = db.prepare(`SELECT number, deploy_state FROM pr`).all() as Array<{ number: number; deploy_state: string | null }>
  for (const row of prRows) deployStates.set(row.number, row.deploy_state)

  const head: string[] = []
  head.push('> :robot: **Produced by AI.** This post is rewritten automatically every time our bug monitor runs — no human types it.', '')
  head.push('This is a live list of known bugs and issues on Freegle. It covers problems reported here and errors detected automatically in the site.', '')
  head.push(`*Last updated: ${new Date().toISOString().replace('T', ' ').slice(0, 16)} UTC*`, '')

  const escapeCell = (s: string) => s.replace(/\|/g, '\\|').replace(/\n/g, ' ')
  const prLink = (n: number | null) =>
    n ? `[#${n}](https://github.com/Freegle/Iznik/pull/${n})` : ''
  const liveOf = (pr: number | null, otherwise: string) => {
    if (!pr) return otherwise
    const ds = deployStates.get(pr)
    if (ds === 'deployed' || ds === 'live') return ':white_check_mark: Live'
    if (ds === 'pending_deploy') return ':hourglass: Deploying'
    return otherwise
  }
  // The reporter in plain text, and the issue itself as the link to the reported post.
  const who = (b: { reporter: string | null }) => `@${escapeCell(b.reporter ?? 'reporter')}`
  const issue = (b: { excerpt: string | null; topic: number; post: number }) => {
    const text = escapeCell((b.excerpt ?? '').slice(0, 160)).replace(/[[\]]/g, '') || '(no summary)'
    return `[${text}](${DISCOURSE_BASE}/t/${b.topic}/${b.post})`
  }

  // Last activity on a report: when it was last classified, or last confirmed (last_seen_at). Not the
  // topic's last post: in a long thread, posts about other things kept old reports looking live
  // (10226/12). The queue and the waiting list leave out anything quiet for longer than STALE_AFTER_DAYS.
  const lastActivity = (b: { last_seen_at: string }) => b.last_seen_at
  const cutoff = new Date(Date.now() - STALE_AFTER_DAYS * 86400000).toISOString().replace('T', ' ').slice(0, 19)
  const fresh = <T extends { last_seen_at: string }>(rows: T[]) => rows.filter(b => lastActivity(b) >= cutoff)
  const byActivity = <T extends { last_seen_at: string }>(rows: T[]) =>
    [...rows].sort((x, y) => lastActivity(y).localeCompare(lastActivity(x)))
  const when = (b: { last_seen_at: string }) => formatDay(lastActivity(b))

  const inProgress = open.filter(b => b.state === 'fix-queued' && b.pr_number)
  const queuedAll = open.filter(b => ['open', 'investigating'].includes(b.state))
  // A question the classifier marked as already answered in its thread is not waiting on anyone.
  const deferredAll = open.filter(b => b.state === 'deferred' && !/already answered in the thread/i.test(b.reason ?? ''))
  const queued = byActivity(fresh(queuedAll))
  const deferred = byActivity(fresh(deferredAll))
  const stale = queuedAll.length - queued.length + deferredAll.length - deferred.length
  const working = [...inProgress, ...queued]

  // Each table, in the order it appears. dropOrder says which loses rows first when the post is
  // over Discourse's length limit: the waiting list goes first, the bugs being fixed last.
  const sections: StatusSection[] = [
    {
      title: 'Bugs being fixed',
      header: ['| Area | Reporter | Issue | PR | Status |', '|---|---|---|---|---|'],
      rows: inProgress.map(b => `| ${b.feature_area ?? '—'} | ${who(b)} | ${issue(b)} | ${prLink(b.pr_number)} | ${liveOf(b.pr_number, ':hammer_and_wrench: In progress')} |`),
      dropOrder: 3,
    },
    {
      title: 'In our queue',
      header: ['| Area | Reporter | Issue | Last activity |', '|---|---|---|---|'],
      rows: queued.map(b => `| ${b.feature_area ?? '—'} | ${who(b)} | ${issue(b)} | ${when(b)} |`),
      dropOrder: 1,
    },
    {
      title: 'Fixed in the last 7 days',
      header: ['| Area | Reporter | Issue | PR | Live? |', '|---|---|---|---|---|'],
      rows: recentFixed.map(b => `| ${b.feature_area ?? '—'} | ${who(b)} | ${issue(b)} | ${prLink(b.pr_number)} | ${b.pr_number ? liveOf(b.pr_number, ':hammer_and_wrench: Pending') : ''} |`),
      dropOrder: 2,
    },
    {
      title: 'Waiting on more information',
      header: ['| Area | Reporter | Issue | What we\'re waiting for | Last activity |', '|---|---|---|---|---|'],
      rows: deferred.map(b => `| ${b.feature_area ?? '—'} | ${who(b)} | ${issue(b)} | ${escapeCell(b.reason ?? '—')} | ${when(b)} |`),
      dropOrder: 0,
    },
  ]

  const empty = working.length === 0 && recentFixed.length === 0 && deferred.length === 0
  const tail: string[] = []
  if (empty) tail.push('*Nothing on the list right now — all caught up!*', '')
  if (stale > 0) tail.push(`*${stale} older report${stale === 1 ? '' : 's'} with no activity for ${STALE_AFTER_DAYS} days ${stale === 1 ? 'is' : 'are'} not listed. A new post on the topic brings it back.*`, '')
  const raw = fitStatusPost(head, sections, tail)

  return {
    raw,
    bugCount: working.length + deferred.length,
    draftCount: pendingDraftCount,
    fixedRecentCount: recentFixed.length,
  }
}

function getDiscourseApiKey(): string | null {
  try {
    const profile = JSON.parse(readFileSync(PROFILE_PATH, 'utf8')) as { auth_pairs?: Array<{ user_api_key?: string }> }
    return profile.auth_pairs?.[0]?.user_api_key ?? null
  } catch {
    return null
  }
}

const KV_LAST_STATUS_BODY = 'discourse_status_last_body'

// Strip the "Last updated: ..." timestamp line before diffing so a timestamp-only
// change doesn't trigger an unnecessary Discourse edit.
function stableBody(raw: string): string {
  return raw.replace(/^\*Last updated:.*\*\s*$/m, '')
}

export async function putStatusPost(db: DB, opts: { postId?: number; apiKey?: string; editReason?: string } = {}): Promise<{ posted: boolean; status?: number; reason?: string; body: string }> {
  const body = renderStatusPostBody(db)
  const postId = opts.postId ?? STATUS_POST_ID
  const apiKey = opts.apiKey ?? getDiscourseApiKey()
  if (!apiKey) {
    return { posted: false, reason: 'no Discourse API key available', body: body.raw }
  }

  const lastBody = kvGet(db, KV_LAST_STATUS_BODY)
  if (lastBody !== null && stableBody(lastBody) === stableBody(body.raw)) {
    return { posted: false, reason: 'content unchanged', body: body.raw }
  }

  const form = new URLSearchParams({
    'post[raw]': body.raw,
    'post[edit_reason]': opts.editReason ?? `Auto-update from monitor-fsm (${body.bugCount} open, ${body.fixedRecentCount} recent, ${body.draftCount} draft${body.draftCount === 1 ? '' : 's'})`,
  })

  const resp = await fetch(`${DISCOURSE_BASE}/posts/${postId}.json`, {
    method: 'PUT',
    headers: {
      'Api-Key': apiKey,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: form.toString(),
  })

  if (!resp.ok) {
    const text = await resp.text().catch(() => '')
    return { posted: false, status: resp.status, reason: text.slice(0, 500), body: body.raw }
  }

  kvSet(db, KV_LAST_STATUS_BODY, body.raw)
  return { posted: true, status: resp.status, body: body.raw }
}
