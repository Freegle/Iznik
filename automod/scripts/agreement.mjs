// Nobody here is ground truth: moderators, Claude and Jev are three fallible judges. For each
// group of questions this fits a two-class latent model (Dawid-Skene, by EM, missing votes
// allowed) and estimates each judge's sensitivity (says yes when it is) and specificity (says
// no when it is not), then lists the posts where the judges disagree, for a person to look at.
//
// Usage: node scripts/agreement.mjs claude.jsonl jev.json chart.json [--out=review.json]
//   claude.jsonl: scripts/claude-cli-label.mjs output ({id, outcome, reason_title, rules, claude})
//   jev.json:     per-post Jev probabilities ({id, jev: {NODE: p}})
import { readFileSync, writeFileSync } from 'node:fs'

const [claudeFile, jevFile, chartFile] = process.argv.slice(2)
const outArg = process.argv.find((a) => a.startsWith('--out='))
const chart = JSON.parse(readFileSync(chartFile, 'utf8'))
const claudeRows = readFileSync(claudeFile, 'utf8').trim().split('\n').map((l) => JSON.parse(l))
const jevById = new Map(JSON.parse(readFileSync(jevFile, 'utf8')).map((p) => [p.id, p.jev]))

// Question groups that a moderator's recorded reason can speak to.
const GROUPS = {
  vague: { nodes: ['VAGUE_TEXT'], reason: /vague|anything|various/i },
  money: { nodes: ['SELLING'], reason: /sell|money|donation/i },
  'borrow/swap': { nodes: ['LOAN', 'SWAP'], reason: /borrow|swap|loan/i },
  'not an item': { nodes: ['NOT_AN_ITEM'], reason: /landfill/i },
  animals: { nodes: ['ANIMALS_OFFER', 'ANIMALS_WANTED'], reason: /pet|animal/i },
  medicine: { nodes: ['MEDICINE', 'MEDICINE_OTC', 'MEDICINE_ANIMAL'], reason: /medic/i },
}
const TEXT_REASON = new RegExp(Object.values(GROUPS).map((g) => g.reason.source).join('|'), 'i')

const rulesOf = (p) => (typeof p.rules === 'string' ? JSON.parse(p.rules || '{}') || {} : p.rules || {})
const allowed = (node, rules) => {
  const rule = chart.states[node]?.check?.rule
  return rule && rules[rule] === true
}
const threshold = (node) => chart.states[node]?.check?.threshold ?? 0.7

function votes(p, group) {
  const rules = rulesOf(p)
  const jev = jevById.get(p.id) || {}
  const nodes = group.nodes.filter((n) => !allowed(n, rules))
  const claude = p.claude ? (nodes.some((n) => p.claude[n]?.answer === 'yes') ? 1 : 0) : null
  const jevVote = nodes.length ? (nodes.some((n) => (jev[n] ?? 0) >= threshold(n)) ? 1 : 0) : 0
  let mod = null
  if (p.outcome === 'approved') mod = 0
  else if (group.reason.test(p.reason_title || '')) mod = 1
  else if (!TEXT_REASON.test(p.reason_title || '')) mod = null
  return { mod, claude, jev: jevVote }
}

// Binary Dawid-Skene with missing votes.
function fit(items, judges, iters = 200) {
  let pi = 0.2
  const se = Object.fromEntries(judges.map((j) => [j, 0.8]))
  const sp = Object.fromEntries(judges.map((j) => [j, 0.9]))
  let post = []
  for (let it = 0; it < iters; it++) {
    post = items.map((v) => {
      let a = pi
      let b = 1 - pi
      for (const j of judges) {
        if (v[j] === null || v[j] === undefined) continue
        a *= v[j] ? se[j] : 1 - se[j]
        b *= v[j] ? 1 - sp[j] : sp[j]
      }
      return a / (a + b)
    })
    pi = post.reduce((s, x) => s + x, 0) / items.length
    for (const j of judges) {
      let tp = 0.5, pos = 1, tn = 0.5, neg = 1
      items.forEach((v, i) => {
        if (v[j] === null || v[j] === undefined) return
        pos += post[i]
        neg += 1 - post[i]
        if (v[j]) tp += post[i]
        else tn += 1 - post[i]
      })
      se[j] = tp / pos
      sp[j] = tn / neg
    }
  }
  return { pi, se, sp, post }
}

const judges = ['mod', 'claude', 'jev']
const posts = claudeRows.filter((p) => p.claude && jevById.has(p.id))
const review = []
const pct = (x) => `${(100 * x).toFixed(0)}%`
console.log(`${posts.length} posts judged by all three. Rejections were sampled by the moderator's reason, so prevalence is inflated and moderator sensitivity flattered.\n`)
console.log('group            yes(mod/claude/jev)  sensitivity mod/claude/jev   specificity mod/claude/jev   split posts')
for (const [name, group] of Object.entries(GROUPS)) {
  const items = posts.map((p) => votes(p, group))
  const { se, sp, post } = fit(items, judges)
  const yes = (j) => items.filter((v) => v[j] === 1).length
  let split = 0
  items.forEach((v, i) => {
    const cast = judges.map((j) => v[j]).filter((x) => x !== null && x !== undefined)
    if (new Set(cast).size > 1) {
      split++
      const p = posts[i]
      review.push({ group: name, id: p.id, votes: v, posterior: +post[i].toFixed(2), outcome: p.outcome, reason: p.reason_title, subject: p.subject, body: (p.body || '').slice(0, 300) })
    }
  })
  console.log(`${name.padEnd(16)} ${`${yes('mod')}/${yes('claude')}/${yes('jev')}`.padEnd(20)} ${judges.map((j) => pct(se[j])).join(' / ').padEnd(28)} ${judges.map((j) => pct(sp[j])).join(' / ').padEnd(28)} ${split}`)
}
if (outArg) {
  writeFileSync(outArg.slice(6), JSON.stringify(review, null, 2))
  console.log(`\n${review.length} split judgements written for review`)
}
