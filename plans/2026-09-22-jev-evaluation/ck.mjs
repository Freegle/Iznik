import fs from 'fs'
const SD = process.env.SD, KEY = process.env.TYPESAFE_API_KEY
const load = f => {
  const lines = fs.readFileSync(`${SD}/${f}`, 'utf8').split('\n')
  const h = lines[0].split('\t'), i = n => h.indexOf(n), seen = new Set(), out = []
  for (let k = 1; k < lines.length; k++) {
    if (!lines[k]) continue
    const c = lines[k].split('\t')
    if (c.length < 7 || seen.has(c[i('msgid')])) continue
    seen.add(c[i('msgid')])
    out.push({ msgid: c[i('msgid')], cat: c[i('cat')], detail: c[i('detail')], collection: c[i('collection')],
               type: c[i('type')], subj: c[i('subj')], body: c[i('body')] === 'NULL' ? '' : c[i('body')] })
  }
  return out
}
const pos = load('ck_pos.tsv'), neg = load('ck_neg.tsv')
const cases = [...pos, ...neg]
console.error(`${pos.length} caught (rejected/spam), ${neg.length} approved, ${cases.length} unique posts`)

let calls = 0, lat = [], tokIn = 0
const ask = async (body, tries = 0) => {
  const t0 = Date.now()
  let r
  try { r = await fetch('https://api.typesafe.ai/v1/systemone', { method: 'POST', headers: { Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' }, body: JSON.stringify(body) }) }
  catch (e) { if (tries < 5) { await new Promise(s => setTimeout(s, 500 * 2 ** tries)); return ask(body, tries + 1) } throw e }
  if ((r.status === 429 || r.status >= 500) && tries < 5) { await new Promise(s => setTimeout(s, 500 * 2 ** tries)); return ask(body, tries + 1) }
  if (!r.ok) throw new Error(`${r.status} ${(await r.text()).slice(0, 200)}`)
  const j = await r.json(); lat.push(Date.now() - t0); calls++; tokIn += j.usage.input_tokens
  return j
}
const clean = s => (s || '').replace(/\s+/g, ' ').replace(/Fair Chance Policy:.*/i, '').trim()
const judge = c => ask({
  model: 'jev-latest',
  state: { post_type: c.type, subject: clean(c.subj), description: clean(c.body) || '(none)', keyword_that_fired: c.detail, concern_category: c.cat },
  questions: {
    // What ContentEmbeddingService is trying to work out with prototype sentences.
    genuine: { type: 'noul',
      instructions: 'A keyword filter held this Freegle post because of the keyword shown. Freegle is for giving away unwanted household things for free. Is this a genuine instance of the concern the keyword is looking for?',
      criteria: { true: 'The post really is the concerning thing: a real weapon, real prescription or controlled medicine, a real hazardous substance, or a real scam', false: 'It is an ordinary household item that happens to use the same word, such as a glue gun, a kitchen knife, a medicine cabinet or pool chemicals' } },
    // The decision we would actually be automating.
    needs_human: { type: 'noul',
      instructions: 'Would a Freegle volunteer moderator reject this post rather than let it through?',
      criteria: { true: 'A moderator would reject it', false: 'A moderator would approve it' } }
  }
})
const out = []
let next = 0
await Promise.all(Array.from({ length: 8 }, async () => {
  while (next < cases.length) {
    const c = cases[next++]
    const j = await judge(c)
    out.push({ msgid: c.msgid, cat: c.cat, detail: c.detail, caught: c.collection !== 'Approved',
               subj: c.subj, genuine: j.answers.genuine.noul, needs: j.answers.needs_human.noul })
  }
}))
fs.writeFileSync(`${SD}/ck_jev.json`, JSON.stringify(out))
lat.sort((a, b) => a - b)
console.error(`${calls} calls, mean ${Math.round(lat.reduce((a,b)=>a+b,0)/lat.length)}ms, ${tokIn} input tokens`)
