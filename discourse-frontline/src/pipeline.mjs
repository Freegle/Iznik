// One lap: read what is new on the forum and deal with each post in turn.
//
// The order of work for a post is the order of cost. Rules first (free), then the
// thread it belongs to (a database read), then one model call if it is still needed,
// then a decision by rule, then the reply through the one outbound door.
//
// The cursor moves one post at a time, in the same transaction that stores what was
// made of that post. A crash or a failed model call leaves the cursor where it was,
// and the next lap reads that post again.

import { getKv, setKv, tx, ftsQuery } from './db.mjs'
import { prefilter } from './prefilter.mjs'
import { extractFacts, mergeFacts } from './slots.mjs'
import { searchKb } from './kb/index.mjs'
import { buildUserMessage, checkForm } from './llm/prompt.mjs'
import { decide, pickAnchor } from './decide.mjs'
import { sendReply } from './outbound.mjs'
import { writeReport } from './report.mjs'

const QUESTION_LIKE = /\?|\b(?:how (?:do|can|should|to)|is (?:there|it)|can (?:i|we|you|a)|should (?:i|we)|what(?:'s| is| are| do| does| should)|where (?:do|can|is)|why (?:do|does|is|are)|does (?:anyone|anybody)|anyone know|wondering)\b/i
const MAX_ATTEMPTS = 3
const EMPTY_FACTS = extractFacts('')

export class LapStopped extends Error {}

export function createPipeline({ db, cfg, discourse, ask, now = () => new Date(), log = () => {} }) {
  const q = {
    postRow: db.prepare('SELECT * FROM post WHERE topic_id = ? AND post_number = ?'),
    postById: db.prepare('SELECT * FROM post WHERE post_id = ?'),
    caseById: db.prepare('SELECT * FROM "case" WHERE id = ?'),
    waitingFor: db.prepare(`SELECT * FROM "case" WHERE topic_id = ? AND reporter = ? AND state = 'waiting-reporter' ORDER BY id DESC LIMIT 1`),
    recentBug: db.prepare(`SELECT * FROM "case" WHERE topic_id = ? AND kind = 'bug' AND state != 'duplicate' AND updated_at > ? ORDER BY id DESC LIMIT 1`),
    topicCases: db.prepare(`SELECT * FROM "case" WHERE topic_id = ? AND state != 'duplicate' AND updated_at > ? ORDER BY id DESC LIMIT 3`),
    casePosts: db.prepare(`SELECT author, text, has_image, disposition FROM post WHERE case_id = ? ORDER BY post_id`),
    tokensToday: db.prepare(`SELECT COALESCE(SUM(input_tokens + cache_read + cache_write + output_tokens), 0) AS n FROM usage WHERE substr(at, 1, 10) = ?`),
  }
  const postUrl = (t, n) => `${cfg.discourseBase}/t/${t}/${n}`
  // Forward only. A re-read of an edited post must not wind the cursor back.
  const advance = (postId) => {
    if (postId > (getKv(db, 'cursor') ?? 0)) setKv(db, 'cursor', postId)
  }
  const daysAgo = (d) => new Date(now().getTime() - d * 86400000).toISOString()

  /** A duplicate stands for the case it was folded into. */
  function resolve(c) {
    for (let i = 0; c && c.state === 'duplicate' && c.duplicate_of && i < 5; i++) c = q.caseById.get(c.duplicate_of)
    return c ?? null
  }

  /** The case a post belongs to by threading alone: what it replies to, quotes, or answers. */
  function threadCase(post) {
    const refs = [post.replyTo, ...post.quotes.filter((x) => !x.topic || x.topic === post.topicId).map((x) => x.post)].filter(Boolean)
    for (const n of refs) {
      const row = q.postRow.get(post.topicId, n)
      if (row?.case_id) return resolve(q.caseById.get(row.case_id))
    }
    return q.waitingFor.get(post.topicId, post.author) ?? null
  }

  function candidatesFor(post, openCase) {
    const byId = new Map()
    const add = (c) => c && !byId.has(c.id) && byId.set(c.id, c)
    add(openCase)
    for (const c of q.topicCases.all(post.topicId, daysAgo(30))) add(c)
    const match = ftsQuery(post.text, 16)
    if (match) {
      // No score floor here. These are only offered: the model says whether one matches.
      const rows = db.prepare(`SELECT rowid AS id, -bm25(case_fts, 4.0, 2.0, 1.0) AS score FROM case_fts WHERE case_fts MATCH ? ORDER BY score DESC LIMIT 6`).all(match)
      for (const r of rows) {
        if (byId.size >= 5) break
        const c = resolve(q.caseById.get(r.id))
        if (c && c.updated_at > daysAgo(60)) add(c)
      }
    }
    return [...byId.values()].map((c) => ({ label: `C${c.id}`, kind: c.kind, summary: c.summary, sameTopic: c.topic_id === post.topicId, row: c }))
  }

  function storePost(post, disposition, caseId = null) {
    db.prepare(`INSERT INTO post (post_id, topic_id, post_number, reply_to, author, created_at, version, topic_title, text, has_image, disposition, case_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(post_id) DO UPDATE SET version = excluded.version, text = excluded.text, has_image = excluded.has_image,
        disposition = excluded.disposition, case_id = COALESCE(excluded.case_id, post.case_id)`)
      .run(post.postId, post.topicId, post.postNumber, post.replyTo, post.author, post.createdAt, post.version, post.topicTitle, post.text, post.hasImage ? 1 : 0, disposition, caseId)
  }

  function indexCase(id) {
    const c = q.caseById.get(id)
    const body = q.casePosts.all(id).map((p) => p.text).join('\n').slice(0, 4000)
    db.prepare('DELETE FROM case_fts WHERE rowid = ?').run(id)
    db.prepare('INSERT INTO case_fts (rowid, summary, area, body) VALUES (?, ?, ?, ?)').run(id, c.summary, c.area, body)
  }

  function patchCase(id, p) {
    const c = q.caseById.get(id)
    db.prepare(`UPDATE "case" SET state = ?, summary = ?, facts = ?, unknowns = ?, asks = ?, affected = ?, still_broken = ?, human_replied = ?, updated_at = ? WHERE id = ?`).run(
      p.state ?? c.state, p.summary ?? c.summary, JSON.stringify(p.facts ?? JSON.parse(c.facts)), JSON.stringify(p.unknowns ?? JSON.parse(c.unknowns)),
      p.asks ?? c.asks, c.affected + (p.affectedDelta ?? 0), p.stillBroken ? 1 : c.still_broken, p.humanReplied ? 1 : c.human_replied, now().toISOString(), id)
  }

  /** Everything the reporter has said in a case, with this post added. */
  function reporterView(caseRow, post) {
    const rows = caseRow ? q.casePosts.all(caseRow.id).filter((p) => p.author === caseRow.reporter) : []
    const mine = !caseRow || post.author === caseRow.reporter
    const texts = [...rows.map((p) => p.text), ...(mine ? [post.text] : [])]
    let facts = caseRow ? JSON.parse(caseRow.facts) : EMPTY_FACTS
    if (mine) facts = mergeFacts(facts, extractFacts(post.text, { hasImage: post.hasImage }))
    return { facts, caseText: texts.join('\n\n') }
  }

  /** Posts settled by rule. Returns false when the post needs the model after all. */
  function applySignal(post, pf, openCase) {
    if (pf.signal === 'staff') {
      tx(db, () => {
        if (openCase) patchCase(openCase.id, { humanReplied: true })
        storePost(post, 'signal:staff', openCase?.id ?? null)
        advance(post.postId)
      })
      return true
    }
    if (pf.signal === 'metoo' || pf.signal === 'still-broken') {
      // Only when it is plain which case is meant: the post is threaded to it, or its
      // own reporter is saying it is still broken. A bare "same here" in a long shared
      // topic could be about any of several problems, so the model reads that one.
      const recent = pf.signal === 'still-broken' ? q.recentBug.get(post.topicId, daysAgo(21)) : null
      const target = openCase ?? (recent?.reporter === post.author ? recent : null)
      if (!target) return false
      tx(db, () => {
        patchCase(target.id, pf.signal === 'metoo' ? { affectedDelta: post.author === target.reporter ? 0 : 1 } : { stillBroken: true })
        storePost(post, `signal:${pf.signal}`, target.id)
        writeReport(db, cfg, target.id)
        advance(post.postId)
      })
      return true
    }
    tx(db, () => {
      // The service's own reply joins the case it answered, so a reporter replying
      // to it is threaded to the right case. It also settles a send we were unsure of.
      const own = pf.why === 'own-or-system' && openCase
      if (own) db.prepare(`UPDATE outbound SET status = 'sent', discourse_post_id = ? WHERE topic_id = ? AND post_number = ? AND status IN ('sending','unknown')`).run(post.postId, post.topicId, post.replyTo ?? 0)
      storePost(post, `skipped:${pf.why}`, own || pf.signal ? openCase?.id ?? null : null)
      advance(post.postId)
    })
    return true
  }

  async function handlePost(post, lapState) {
    const pf = prefilter(post, cfg)
    const openCase = threadCase(post)
    if (!pf.pass && applySignal(post, pf, openCase)) {
      lapState.byRule++
      return
    }

    if (q.tokensToday.get(now().toISOString().slice(0, 10)).n >= cfg.maxTokensPerDay) throw new LapStopped('daily token cap reached')

    const candidates = candidatesFor(post, openCase)
    const found = !openCase && QUESTION_LIKE.test(post.text) ? searchKb(db, `${post.topicTitle ?? ''} ${post.text}`) : []
    const sections = found.map((s, i) => ({ ...s, label: `S${i + 1}` }))
    const thread = openCase
      ? q.casePosts.all(openCase.id).slice(-4).map((p) => ({ role: p.disposition.startsWith('skipped:own') ? 'the helper' : p.author === openCase.reporter ? 'the reporter' : 'another volunteer', text: p.text }))
      : []
    const facts0 = extractFacts(post.text, { hasImage: post.hasImage })

    let form
    try {
      const out = await ask(buildUserMessage({ post, thread, candidates, sections, facts: facts0 }))
      const u = out.usage
      db.prepare('INSERT INTO usage (at, post_id, model, input_tokens, cache_read, cache_write, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?)').run(now().toISOString(), post.postId, u.model, u.input, u.cacheRead, u.cacheWrite, u.output)
      form = checkForm(out.form, { candidates, sections })
    } catch (e) {
      // Try again next lap, a few times. After that the post is set aside for a
      // person and the lap moves on, so one bad post cannot hold up the forum.
      const attempts = (q.postById.get(post.postId)?.attempts ?? 0) + 1
      tx(db, () => {
        storePost(post, 'error')
        db.prepare('UPDATE post SET attempts = ? WHERE post_id = ?').run(attempts, post.postId)
        if (attempts >= MAX_ATTEMPTS) advance(post.postId)
      })
      if (attempts >= MAX_ATTEMPTS) {
        lapState.errors++
        return
      }
      throw new LapStopped(`post ${post.postId}: ${e.message}`)
    }
    lapState.byModel++

    const sameAs = form.sameAs ? candidates.find((c) => c.label === form.sameAs).row : null
    const anchor = pickAnchor({ form, post, openCase, sameAs })
    const { facts, caseText } = reporterView(anchor?.row ?? null, post)
    const d = decide({ form, post, anchor, sameAs, facts, caseText, sources: sections.filter((s) => form.answerSources.includes(s.label)), cfg, postUrl })

    let caseId = null
    let reportFor = null
    tx(db, () => {
      const iso = now().toISOString()
      if (d.op === 'skip') {
        storePost(post, `skipped:${d.why}`)
      } else if (d.op === 'followup') {
        caseId = d.caseId
        // Another volunteer answering a question means a person has it in hand.
        const target = q.caseById.get(caseId)
        const answeredByPerson = target.kind === 'question' && post.author !== target.reporter && d.type === 'detail'
        patchCase(caseId, { ...d.patch, humanReplied: answeredByPerson })
        storePost(post, `followup:${d.type}`, caseId)
        reportFor = caseId
      } else {
        const n = d.newCase
        const r = db.prepare(`INSERT INTO "case" (topic_id, post_number, reporter, kind, state, summary, area, urgency, facts, unknowns, asks, duplicate_of, created_at, updated_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(post.topicId, post.postNumber, post.author, n.kind, n.state, n.summary, n.area, n.urgency, JSON.stringify(n.facts), JSON.stringify(n.unknowns), n.asks, d.op === 'duplicate' ? d.of : null, iso, iso)
        caseId = Number(r.lastInsertRowid)
        storePost(post, 'case', caseId)
        if (d.op === 'duplicate') {
          patchCase(d.of, { affectedDelta: d.countsAsAffected ? 1 : 0 })
          reportFor = d.of
        } else {
          reportFor = caseId
        }
      }
      if (caseId) indexCase(caseId)
      if (reportFor) writeReport(db, cfg, reportFor)
      advance(post.postId)
    })

    if (d.reply) {
      const outcome = await sendReply(db, discourse, cfg, {
        ...d.reply, topicId: post.topicId, postNumber: post.postNumber, caseId, quote: post.text || '(picture)', quoteUser: post.author, listenOnly: pf.listenOnly,
      }, lapState, now())
      lapState.replies[outcome] = (lapState.replies[outcome] ?? 0) + 1
      // A question that did not go out is not being waited on. Without this the
      // reporter's next post, about anything, would be read as their answer to it.
      if (d.reply.kind === 'ask' && outcome !== 'sent' && q.caseById.get(caseId).state === 'waiting-reporter') {
        tx(db, () => {
          patchCase(caseId, { state: 'ready' })
          writeReport(db, cfg, caseId)
        })
      }
    }
    log({ post: post.postId, topic: post.topicId, kind: form.kind, op: d.op, case: caseId, reply: d.reply?.kind ?? null })
  }

  /** A reporter who edits their post to add what was asked for has answered. */
  async function checkEdits(lapState) {
    const waiting = db.prepare(`SELECT c.id, p.post_id, p.version FROM "case" c JOIN post p ON p.topic_id = c.topic_id AND p.post_number = c.post_number WHERE c.state = 'waiting-reporter'`).all()
    for (const w of waiting) {
      const fresh = await discourse.post(w.post_id)
      if (fresh.version > w.version && fresh.text) {
        db.prepare('UPDATE post SET version = ? WHERE post_id = ?').run(fresh.version, w.post_id)
        await handlePost({ ...fresh, edited: true }, lapState)
      }
    }
  }

  /** Reporters who never answered: hand over what there is, marked incomplete. */
  function sweepStale() {
    const stale = db.prepare(`SELECT id FROM "case" WHERE state = 'waiting-reporter' AND updated_at < ?`).all(daysAgo(cfg.staleDays))
    for (const { id } of stale) tx(db, () => {
      patchCase(id, { state: 'ready' })
      writeReport(db, cfg, id)
    })
    return stale.length
  }

  async function lap() {
    const lapState = { read: 0, byRule: 0, byModel: 0, errors: 0, sent: 0, replies: {}, stale: 0, stopped: null }
    if (cfg.mode === 'off') return { ...lapState, stopped: 'mode off' }

    // A reply caught mid-send by a crash may or may not have landed. A person looks.
    db.prepare(`UPDATE outbound SET status = 'unknown', reason = 'interrupted while sending' WHERE status = 'sending'`).run()

    let cursor = getKv(db, 'cursor')
    if (cursor === null) {
      cursor = await discourse.latestPostId()
      setKv(db, 'cursor', cursor)
      return { ...lapState, stopped: `first run: watching from post ${cursor}` }
    }
    try {
      await checkEdits(lapState)
      const posts = (await discourse.postsAfter(cursor)).slice(0, cfg.maxPostsPerLap)
      for (const post of posts) {
        lapState.read++
        await handlePost(post, lapState)
      }
    } catch (e) {
      if (!(e instanceof LapStopped)) throw e
      lapState.stopped = e.message
    }
    lapState.stale = sweepStale()
    setKv(db, 'last_lap', { at: now().toISOString(), ...lapState })
    return lapState
  }

  return { lap, handlePost, sweepStale }
}
