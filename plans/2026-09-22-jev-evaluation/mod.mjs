import fs from 'fs'
const SD = process.env.SD, KEY = process.env.TYPESAFE_API_KEY
const SET = process.env.SET, OUT = process.env.OUT
const cases = JSON.parse(fs.readFileSync(`${SD}/${SET}`, 'utf8'))
const CONC = 8
let tokIn = 0, calls = 0, lat = []

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

// Same information the fine-tuned models were given, same decision.
const judge = c => ask({
  model: 'jev-latest',
  state: c.state,
  questions: {
    reject: {
      type: 'noul',
      instructions: 'A Freegle volunteer moderator is reviewing this pending post before it goes out to the community. Would they reject it rather than approve it?',
      criteria: {
        true: 'A moderator would reject this post',
        false: 'A moderator would approve this post'
      }
    }
  }
})

const out = []
let next = 0
await Promise.all(Array.from({ length: CONC }, async () => {
  while (next < cases.length) {
    const c = cases[next++]
    const j = await judge(c)
    out.push({ msg_id: c.msg_id, expected: c.expected, category: c.category, prev_pred: c.prev_pred, noul: j.answers.reject.noul })
  }
}))
fs.writeFileSync(`${SD}/${OUT}`, JSON.stringify(out))
lat.sort((a, b) => a - b)
console.error(`${calls} calls, mean ${Math.round(lat.reduce((a,b)=>a+b,0)/lat.length)}ms, p95 ${lat[Math.floor(lat.length*0.95)]}ms, ${tokIn} input tokens`)
