#!/usr/bin/env -S node --disable-warning=ExperimentalWarning
// Command line for the first-line support service. See ../README.md.

import { join } from 'node:path'
import { loadConfig } from '../src/config.mjs'
import { getKv, openDb, setKv } from '../src/db.mjs'
import { createDiscourse } from '../src/discourse.mjs'
import { ingestChanges, ingestDocs, ingestWiki, searchKb } from '../src/kb/index.mjs'
import { createPipeline } from '../src/pipeline.mjs'
import { runEval } from '../eval/run.mjs'

const [cmd, ...args] = process.argv.slice(2)
const cfg = loadConfig()
const db = () => openDb(join(cfg.stateDir, 'frontline.db'))
const out = (o) => console.log(typeof o === 'string' ? o : JSON.stringify(o, null, 2))

async function model() {
  if (cfg.llm === 'cli') return (await import('../src/llm/claude-cli.mjs')).createClaudeCli(cfg)
  return (await import('../src/llm/anthropic.mjs')).createAnthropic(cfg)
}

const commands = {
  /** Read what is new and deal with it. Run this from cron or a timer. */
  async lap() {
    const d = db()
    const result = await createPipeline({ db: d, cfg, discourse: createDiscourse(cfg), ask: await model(), log: (l) => console.error(JSON.stringify(l)) }).lap()
    out({ mode: cfg.mode, ...result })
    // A lap that stopped early is a fault somebody should see, so cron mails it.
    if (result.stopped && !result.stopped.startsWith('first run')) process.exitCode = 2
  },

  /** Rebuild the search index: sync [docs|wiki|changes], or all three. */
  async sync() {
    const d = db()
    const which = args.length ? args : ['docs', 'wiki', 'changes']
    const done = {}
    if (which.includes('docs')) done.docs = ingestDocs(d, cfg)
    if (which.includes('changes')) done.changes = ingestChanges(d, cfg)
    if (which.includes('wiki')) done.wiki = await ingestWiki(d, cfg)
    out(done)
  },

  /** Start the cursor N posts back, to try the service on recent posts. */
  async backfill() {
    const d = db()
    const latest = await createDiscourse(cfg).latestPostId()
    const from = Math.max(0, latest - Number(args[0] ?? 100))
    setKv(d, 'cursor', from)
    out(`cursor set to ${from} (latest is ${latest})`)
  },

  status() {
    const d = db()
    const day = new Date().toISOString().slice(0, 10)
    out({
      mode: cfg.mode,
      model: cfg.model,
      cursor: getKv(d, 'cursor'),
      lastLap: getKv(d, 'last_lap'),
      cases: d.prepare('SELECT kind, state, COUNT(*) AS n FROM "case" GROUP BY kind, state').all(),
      posts: d.prepare(`SELECT CASE WHEN disposition LIKE 'skipped:%' OR disposition LIKE 'signal:%' THEN 'settled by rule or skipped' ELSE 'read by the model' END AS how, COUNT(*) AS n FROM post GROUP BY how`).all(),
      replies: d.prepare('SELECT kind, status, COUNT(*) AS n FROM outbound GROUP BY kind, status').all(),
      tokensToday: d.prepare(`SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input, COALESCE(SUM(cache_read),0) AS cacheRead, COALESCE(SUM(cache_write),0) AS cacheWrite, COALESCE(SUM(output_tokens),0) AS output FROM usage WHERE substr(at,1,10) = ?`).get(day),
      index: d.prepare('SELECT source, COUNT(*) AS sections FROM kb GROUP BY source').all(),
    })
  },

  /** What is waiting for a person. */
  queue() {
    const d = db()
    out({
      casesForAPerson: d.prepare(`SELECT id, kind, summary, topic_id, post_number FROM "case" WHERE state = 'needs-human' AND human_replied = 0`).all(),
      repliesThatMayNotHaveLanded: d.prepare(`SELECT id, topic_id, post_number, kind, reason FROM outbound WHERE status = 'unknown'`).all(),
      repliesRefused: d.prepare(`SELECT id, topic_id, post_number, kind, reason FROM outbound WHERE status = 'refused'`).all(),
      postsTheModelCouldNotRead: d.prepare(`SELECT post_id, topic_id, post_number FROM post WHERE disposition = 'error'`).all(),
      unsureSkips: d.prepare(`SELECT post_id, topic_id, post_number FROM post WHERE disposition = 'skipped:other-unsure' ORDER BY post_id DESC LIMIT 20`).all(),
    })
  },

  /** Replies written but not sent, with why. */
  drafts() {
    for (const r of db().prepare(`SELECT id, topic_id, post_number, kind, reason, body FROM outbound WHERE status = 'draft' ORDER BY id`).all()) {
      out(`--- draft ${r.id}: ${r.kind} to ${cfg.discourseBase}/t/${r.topic_id}/${r.post_number} (${r.reason})\n${r.body}\n`)
    }
  },

  /** Send one draft by hand: send <id>. */
  async send() {
    const d = db()
    const r = d.prepare(`SELECT * FROM outbound WHERE id = ? AND status = 'draft'`).get(Number(args[0]))
    if (!r) throw new Error('no such draft')
    d.prepare(`UPDATE outbound SET status = 'sending' WHERE id = ?`).run(r.id)
    const postId = await createDiscourse(cfg).reply(r.topic_id, r.body, r.post_number)
    d.prepare(`UPDATE outbound SET status = 'sent', reason = 'sent by hand', discourse_post_id = ? WHERE id = ?`).run(postId, r.id)
    // A question sent by hand is now being waited on, like any other.
    if (r.kind === 'ask' && r.case_id) d.prepare(`UPDATE "case" SET state = 'waiting-reporter', asks = MAX(asks, 1), updated_at = ? WHERE id = ? AND state = 'ready'`).run(new Date().toISOString(), r.case_id)
    out(`sent as post ${postId}`)
  },

  /** Try a search of the index: search <words>. Costs nothing. */
  search() {
    out(searchKb(db(), args.join(' '), { sources: ['wiki', 'docs', 'changes'], minShared: 1, limit: 5 }).map((r) => ({ score: Number(r.score.toFixed(1)), source: r.source, title: r.title, heading: r.heading, url: r.url })))
  },

  /** Run the made-up test posts through the real model and score the result. */
  async eval() {
    out(await runEval({ cfg, ask: await model() }))
  },
}

if (!commands[cmd]) {
  console.error(`usage: frontline <${Object.keys(commands).join('|')}>`)
  process.exit(64)
}
try {
  await commands[cmd]()
} catch (e) {
  console.error(`frontline ${cmd}: ${e.message}`)
  process.exit(1)
}
