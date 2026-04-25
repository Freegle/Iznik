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
import { listOpenDiscourseBugs, markDiscourseBugFixed, type DiscourseBugRow } from './index.js'

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

  const lines: string[] = []
  lines.push('> :robot: **Produced by AI.** This post is rewritten automatically every time our bug monitor runs — no human types it.', '')
  lines.push('This is a live list of known bugs and issues on Freegle. It covers problems reported here and errors detected automatically in the site.', '')
  lines.push(`*Last updated ${new Date().toISOString().replace('T', ' ').slice(0, 16)} UTC*`, '')

  const escapeCell = (s: string) => s.replace(/\|/g, '\\|').replace(/\n/g, ' ')
  const prLink = (n: number | null) =>
    n ? `[#${n}](https://github.com/Freegle/Iznik/pull/${n})` : ''

  // ---- Bugs being investigated / worked on ----
  const workingStates = ['open', 'investigating', 'fix-queued']
  const working = open.filter(b => workingStates.includes(b.state))

  if (working.length > 0) {
    lines.push('## Bugs we\'re working on', '')

    // Group by feature_area; bugs with no area go under a catch-all group.
    const groups = new Map<string, DiscourseBugRow[]>()
    for (const b of working) {
      const area = b.feature_area ?? 'Other'
      if (!groups.has(area)) groups.set(area, [])
      groups.get(area)!.push(b)
    }

    for (const [area, bugs] of groups) {
      lines.push(`### ${area}`, '')
      lines.push('| Reporter | Issue | PR |')
      lines.push('|---|---|---|')
      for (const b of bugs) {
        const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
        const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
        const pr = prLink(b.pr_number)
        lines.push(`| [@${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${pr} |`)
      }
      lines.push('')
    }
  }

  // ---- Recently fixed ----
  if (recentFixed.length > 0) {
    lines.push('## Fixed in the last 7 days', '')
    lines.push('| Area | Reporter | Issue | PR |')
    lines.push('|---|---|---|---|')
    for (const b of recentFixed) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
      const area = b.feature_area ?? '—'
      const pr = prLink(b.pr_number)
      lines.push(`| ${area} | [@${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${pr} |`)
    }
    lines.push('')
  }

  // ---- Deferred ----
  const deferred = open.filter(b => b.state === 'deferred')
  if (deferred.length > 0) {
    lines.push('## Waiting on more information', '')
    lines.push('| Area | Reporter | Issue | What we\'re waiting for |')
    lines.push('|---|---|---|---|')
    for (const b of deferred) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
      const reason = escapeCell(b.reason ?? '—')
      const area = b.feature_area ?? '—'
      lines.push(`| ${area} | [@${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${reason} |`)
    }
    lines.push('')
  }

  if (working.length === 0 && recentFixed.length === 0 && deferred.length === 0) {
    lines.push('*Nothing on the list right now — all caught up!*', '')
  }

  return {
    raw: lines.join('\n'),
    bugCount: working.length + deferred.length,
    draftCount: pendingDraftCount,
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
