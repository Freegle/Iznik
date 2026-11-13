// The thing this service exists to produce: a report somebody could act on.
//
// One JSON file and one readable file per case, rewritten whenever the case changes.
// They are the hand-off to whatever diagnoses and fixes: that side reads a report,
// not the forum, so it starts from the facts and the reporter's own words in order.
//
// Reports hold what volunteers wrote, which can include a member's details. They stay
// on this machine. Nothing here sends them anywhere.

import { mkdirSync, renameSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { searchKb } from './kb/index.mjs'
import { ASK_TEXT } from './slots.mjs'

export function buildReport(db, cfg, caseId) {
  const c = db.prepare('SELECT * FROM "case" WHERE id = ?').get(caseId)
  if (!c) throw new Error(`no case ${caseId}`)
  const url = (t, n) => `${cfg.discourseBase}/t/${t}/${n}`
  const dupes = db.prepare('SELECT id, topic_id, post_number, reporter FROM "case" WHERE duplicate_of = ?').all(caseId)
  const ids = [caseId, ...dupes.map((d) => d.id)]
  const posts = db.prepare(`SELECT topic_id, post_number, author, created_at, text, has_image, disposition FROM post
    WHERE case_id IN (${ids.map(() => '?').join(',')}) AND disposition NOT LIKE 'skipped:own%' ORDER BY created_at`).all(...ids)
  const facts = JSON.parse(c.facts)
  const unknowns = JSON.parse(c.unknowns)
  const related = c.kind === 'bug'
    ? searchKb(db, `${c.summary} ${c.area}`, { sources: ['changes'], limit: 3, minShared: 2 }).map((r) => ({ title: r.title, merged: r.heading, url: r.url }))
    : []
  return {
    id: c.id,
    version: c.report_version,
    kind: c.kind,
    // complete: nothing a developer needs is missing. incomplete: the reporter was
    // asked and has not said, or we stopped asking. waiting: asked, still within time.
    status: c.state === 'waiting-reporter' ? 'waiting' : unknownsNeeded(unknowns).length ? 'incomplete' : 'complete',
    state: c.state,
    summary: c.summary,
    area: c.area,
    urgency: c.urgency,
    affected: c.affected,
    stillBrokenReported: Boolean(c.still_broken),
    aPersonReplied: Boolean(c.human_replied),
    reporter: c.reporter,
    source: { topicId: c.topic_id, postNumber: c.post_number, url: url(c.topic_id, c.post_number) },
    facts,
    unknowns,
    questionsAsked: c.asks,
    duplicates: dupes.map((d) => ({ caseId: d.id, url: url(d.topic_id, d.post_number) })),
    possiblyRelatedChanges: related,
    posts: posts.map((p) => ({ url: url(p.topic_id, p.post_number), author: p.author, at: p.created_at, hasImage: Boolean(p.has_image), text: p.text })),
    createdAt: c.created_at,
    updatedAt: c.updated_at,
  }
}

// The two a report is never asked for alone do not make it incomplete.
function unknownsNeeded(unknowns) {
  return unknowns.filter((u) => !['where', 'screenshot', 'when'].includes(u))
}

export function reportMarkdown(r) {
  const f = r.facts
  const fact = (label, v) => (v && (!Array.isArray(v) || v.length) ? `- ${label}: ${Array.isArray(v) ? v.join(', ') : v}\n` : '')
  let md = `# ${r.kind === 'bug' ? 'Problem' : r.kind === 'feature' ? 'Suggestion' : 'Question'} ${r.id}: ${r.summary}\n\n`
  md += `- Status: ${r.status}${r.stillBrokenReported ? ' (a reporter says it is still happening)' : ''}\n- Area: ${r.area || 'not clear'}\n- Urgency: ${r.urgency}\n- People affected so far: ${r.affected}\n- First reported: ${r.source.url}\n\n`
  md += '## What we know\n\n'
  md += fact('Where', [f.platform, f.site].filter(Boolean).join(', ')) + fact('Devices', f.devices) + fact('Browsers', f.browsers) + fact('When', f.when) + fact('How often', f.frequency) + fact('Error text', f.errorText) + fact('Links given', f.links) + fact('Numbers given', f.ids) + (f.hasScreenshot ? '- A screenshot is attached in the thread\n' : '')
  if (r.unknowns.length) md += `\n## Not known\n\n${r.unknowns.map((u) => `- ${ASK_TEXT[u] ?? u}`).join('\n')}\n\nAsked ${r.questionsAsked} time(s).\n`
  if (r.possiblyRelatedChanges.length) md += `\n## Changes merged lately that mention similar words\n\nA word match only. Nobody has checked they are related.\n\n${r.possiblyRelatedChanges.map((c) => `- ${c.merged}: ${c.title} (${c.url})`).join('\n')}\n`
  md += '\n## In their words\n\n'
  for (const p of r.posts) md += `**${p.author}**, ${p.at.slice(0, 16).replace('T', ' ')} (${p.url})${p.hasImage ? ' [has a picture]' : ''}\n\n${p.text.split('\n').map((l) => `> ${l}`).join('\n')}\n\n`
  return md
}

/** Write the report files for a case and bump its version. */
export function writeReport(db, cfg, caseId) {
  db.prepare('UPDATE "case" SET report_version = report_version + 1 WHERE id = ?').run(caseId)
  const r = buildReport(db, cfg, caseId)
  const dir = join(cfg.stateDir, 'reports')
  mkdirSync(dir, { recursive: true })
  for (const [ext, body] of [['json', JSON.stringify(r, null, 2)], ['md', reportMarkdown(r)]]) {
    const path = join(dir, `case-${r.id}.${ext}`)
    // Written beside and moved into place, so a reader never sees half a file.
    writeFileSync(`${path}.tmp`, body)
    renameSync(`${path}.tmp`, path)
  }
  return r
}
