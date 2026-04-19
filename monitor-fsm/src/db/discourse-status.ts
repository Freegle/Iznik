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
import { listOpenDiscourseBugs, listPendingDrafts, type DiscourseBugRow } from './index.js'

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
  lines.push('> :robot: **Produced by AI.** This post is rewritten end-to-end every time our automated monitoring checker runs — no human types it.', '')
  lines.push('This is an automatically-updated list of the bugs and issues we\'re currently aware of on Freegle — both ones reported by moderators on Discourse and errors spotted in the site itself.', '')
  lines.push(`*Last updated ${new Date().toISOString().replace('T', ' ').slice(0, 16)} UTC. No need to reply here — replies aren\'t read and nobody gets an email when this post changes.*`, '')

  // Stable-ish display IDs: B-<topic>-<post> for bugs, D-<draft_id> for drafts.
  const bugId = (b: { topic: number; post: number }) => `B-${b.topic}-${b.post}`
  const draftId = (d: { id: number }) => `D-${d.id}`

  // Has a reply draft been posted for this bug? If so we really have "sent"
  // it. If not (or none drafted), it's misleading to say "fix sent" — the
  // reporter has heard nothing. Build a quick index of drafts by topic.post.
  const postedKeys = new Set<string>()
  const queuedKeys = new Set<string>()
  for (const d of pending) queuedKeys.add(`${d.topic}.${d.post}`)
  const postedDrafts = db.prepare(`
    SELECT topic, post FROM discourse_draft WHERE posted_at IS NOT NULL
  `).all() as Array<{ topic: number; post: number }>
  for (const d of postedDrafts) postedKeys.add(`${d.topic}.${d.post}`)

  const prLink = (n: number | null) =>
    n ? `[#${n}](https://github.com/Freegle/Iznik/pull/${n})` : '—'

  const workingLabel = (b: DiscourseBugRow): string => {
    const key = `${b.topic}.${b.post}`
    if (b.state === 'investigating') return 'investigating'
    if (b.state === 'open') return 'new'
    // fix-queued — collapsed to one of two: "fixed" or "retesting" (reply posted).
    return postedKeys.has(key) ? 'retesting' : 'fixed'
  }

  const escapeCell = (s: string) => s.replace(/\|/g, '\\|').replace(/\n/g, ' ')

  // Small wrappers keep the table visually tight — IDs are reference handles
  // (you shouldn't need to read them often), status labels are secondary info.
  const tiny = (s: string) => `<small>${s}</small>`
  const small = (s: string) => `<small>${s}</small>`

  // Bugs being worked on — rendered as a table for scannability.
  const workingStates = ['open', 'investigating', 'fix-queued']
  const working = open.filter(b => workingStates.includes(b.state))
  if (working.length > 0) {
    lines.push('## Bugs we\'re working on', '')
    lines.push('| ID | Reporter | Issue | Status | PR |')
    lines.push('|---|---|---|---|---|')
    for (const b of working) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
      lines.push(`| ${tiny('`' + bugId(b) + '`')} | [${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${small(workingLabel(b))} | ${small(prLink(b.pr_number))} |`)
    }
    lines.push('')
  }

  // Recently fixed
  if (recentFixed.length > 0) {
    lines.push('## Recently fixed (last 7 days)', '')
    lines.push('| ID | Reporter | Issue | Status | PR |')
    lines.push('|---|---|---|---|---|')
    for (const b of recentFixed) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
      const status = b.deployed_at ? 'live' : 'deploying'
      lines.push(`| ${tiny('`' + bugId(b) + '`')} | [${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${small(status)} | ${small(prLink((b as DiscourseBugRow).pr_number ?? null))} |`)
    }
    lines.push('')
  }

  // Deferred — "waiting on more information"
  const deferred = open.filter(b => b.state === 'deferred')
  if (deferred.length > 0) {
    lines.push('## Waiting on more information', '')
    lines.push('| ID | Reporter | Issue | Why it\'s waiting |')
    lines.push('|---|---|---|---|')
    for (const b of deferred) {
      const url = `${DISCOURSE_BASE}/t/${b.topic}/${b.post}`
      const excerpt = escapeCell((b.excerpt ?? '').slice(0, 160))
      const reason = escapeCell(b.reason ?? '—')
      lines.push(`| ${tiny('`' + bugId(b) + '`')} | [${b.reporter ?? 'reporter'}](${url}) | ${excerpt} | ${small(reason)} |`)
    }
    lines.push('')
  }

  if (working.length === 0 && recentFixed.length === 0 && deferred.length === 0) {
    lines.push('*Nothing currently on the list — we\'re all caught up!*', '')
  }

  // Reply drafts — split by whether the fix is actually live. Telling a
  // reporter "please retest after the next deploy" is useless because they
  // can't see deploys; so we only show "ready to send" drafts once the PR
  // is merged AND deployed. Everything else is "on hold" so moderators can
  // still see what's queued without accidentally posting it early.
  const prLookup = new Map<number, { state: string | null; deploy_state: string | null }>()
  const prRows = db.prepare('SELECT number, state, deploy_state FROM pr').all() as Array<{ number: number; state: string | null; deploy_state: string | null }>
  for (const r of prRows) prLookup.set(r.number, { state: r.state, deploy_state: r.deploy_state })

  const isLive = (prNumber: number | null): boolean => {
    if (!prNumber) return false
    const pr = prLookup.get(prNumber)
    if (!pr) return false
    return pr.state === 'MERGED' && (pr.deploy_state === 'live' || pr.deploy_state === 'deployed')
  }

  const ready = pending.filter(d => isLive(d.pr_number))
  const onHold = pending.filter(d => !isLive(d.pr_number))

  const renderDraft = (d: typeof pending[number], note?: string) => {
    const targetUrl = `${DISCOURSE_BASE}/t/${d.topic}/${d.post}`
    lines.push(`### \`${draftId(d)}\` → reply to @${d.username} on [${d.topic}/${d.post}](${targetUrl})`)
    if (note) lines.push('', `*${note}*`)
    lines.push('', '```')
    lines.push(`[quote="${d.username}, post:${d.post}, topic:${d.topic}"]`)
    lines.push(d.quote)
    lines.push('[/quote]')
    lines.push('')
    lines.push(d.body)
    lines.push('```', '')
    if (!note) lines.push(`[Open post to reply →](${targetUrl})`, '')
  }

  if (ready.length > 0) {
    lines.push('---', '')
    lines.push('## Replies ready to send', '')
    lines.push(`*${ready.length} reply${ready.length === 1 ? '' : 'ies'} ready — the fix is live. Open the link, paste the block, send.*`, '')
    for (const d of ready) renderDraft(d)
  }

  if (onHold.length > 0) {
    lines.push('---', '')
    lines.push('## Replies on hold (fix not yet live)', '')
    lines.push(`*${onHold.length} reply${onHold.length === 1 ? '' : 'ies'} queued but **don't post yet** — the fix is still being tested or hasn't been deployed. They'll move to "ready to send" above once it's live.*`, '')
    for (const d of onHold) {
      const prNote = d.pr_number
        ? `Waiting for [#${d.pr_number}](https://github.com/Freegle/Iznik/pull/${d.pr_number}) to merge & go live.`
        : 'No PR yet — fix still being prepared.'
      renderDraft(d, prNote)
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
