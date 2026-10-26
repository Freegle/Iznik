// Close reports whose reporter has since said, in the same topic, that it works.
//
// persist_classifications now does this as posts arrive (closeReportConfirmedByReporter). Reports
// already stuck open from before (10226/12, fixed by hand and confirmed by the reporter) need one
// pass over their topics. Dry run by default; --apply closes them.
//
// The match is a phrase check, so read the dry run before applying: a reporter's "thanks" in passing,
// or "fixed" about a different problem in the same thread, also matches. --only=TOPIC/POST,... closes
// just the ones you have checked.
//
//   npx tsx scripts/close-confirmed-reports.ts [--apply] [--only=10226/12,9027/3]
import { readFileSync } from 'node:fs'
import { getDb, closeReportConfirmedByReporter } from '../src/db/index.js'
import { DISCOURSE_BASE, PROFILE_PATH } from '../src/discourse.js'
import { retestConfirmsFix } from '../src/actions/index.js'

const apply = process.argv.includes('--apply')
const onlyArg = process.argv.find(a => a.startsWith('--only='))
const only = onlyArg ? new Set(onlyArg.slice(7).split(',').map(s => s.trim())) : null
const key = (JSON.parse(readFileSync(PROFILE_PATH, 'utf8')) as { auth_pairs: Array<{ user_api_key: string }> }).auth_pairs[0].user_api_key

interface Post { post_number: number; username: string; cooked: string }

// Discourse puts the wait for a 429 in the body, not a Retry-After header.
async function topicPosts(topic: number): Promise<Post[]> {
  for (let attempt = 0; attempt < 6; attempt++) {
    const resp = await fetch(`${DISCOURSE_BASE}/t/${topic}.json?print=true`, { headers: { 'Api-Key': key } })
    if (resp.status === 429) {
      const body = await resp.json().catch(() => ({})) as { extras?: { wait_seconds?: number } }
      await new Promise(r => setTimeout(r, ((body.extras?.wait_seconds ?? 10) + 1) * 1000))
      continue
    }
    if (!resp.ok) return []
    const json = await resp.json() as { post_stream?: { posts?: Post[] } }
    return json.post_stream?.posts ?? []
  }
  return []
}

// Quotes of other posts (aside for a Discourse quote, blockquote for a pasted one) are not the reporter's words.
const text = (html: string) => html.replace(/<aside[\s\S]*?<\/aside>/g, ' ').replace(/<blockquote[\s\S]*?<\/blockquote>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

const db = getDb()
const live = db.prepare(`
  SELECT topic, post, reporter, excerpt FROM discourse_bug
  WHERE state IN ('open','investigating','deferred','needs-detail') AND reporter IS NOT NULL
  ORDER BY topic, post
`).all() as Array<{ topic: number; post: number; reporter: string; excerpt: string | null }>

const byTopic = new Map<number, typeof live>()
for (const r of live) byTopic.set(r.topic, [...(byTopic.get(r.topic) ?? []), r])

let found = 0
for (const [topic, reports] of byTopic) {
  const posts = await topicPosts(topic)
  for (const r of reports) {
    // The reporter's latest later post decides: a confirmation followed by "it's back" stays open.
    const later = posts.filter(p => p.post_number > r.post && p.username === r.reporter)
    const last = later[later.length - 1]
    if (!last || !retestConfirmsFix(text(last.cooked))) continue
    if (only && !only.has(`${topic}/${r.post}`)) continue
    found++
    console.log(`${topic}/${r.post} ${r.reporter}: "${(r.excerpt ?? '').slice(0, 70)}" <- post ${last.post_number}: "${text(last.cooked).slice(0, 90)}"`)
    if (apply) closeReportConfirmedByReporter(db, topic, last.post_number, r.reporter)
  }
}
console.log(`${found} report(s) ${apply ? 'closed' : 'would be closed (dry run; --apply to close)'} of ${live.length} live`)
