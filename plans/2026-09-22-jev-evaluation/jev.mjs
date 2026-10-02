import fs from 'fs'
const SD = process.env.SD
const KEY = process.env.TYPESAFE_API_KEY
const RUN = process.env.RUN || '1'
const { sample } = JSON.parse(fs.readFileSync(`${SD}/sample.json`, 'utf8'))
const CONC = 8
let tokIn = 0, tokOut = 0, calls = 0, lat = []

const ask = async (body, tries = 0) => {
  const t0 = Date.now()
  let r
  try {
    r = await fetch('https://api.typesafe.ai/v1/systemone', {
      method: 'POST', headers: { Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
  } catch (e) {
    if (tries < 5) { await new Promise(s => setTimeout(s, 500 * 2 ** tries)); return ask(body, tries + 1) }
    throw e
  }
  if ((r.status === 429 || r.status >= 500) && tries < 5) {
    await new Promise(s => setTimeout(s, 500 * 2 ** tries)); return ask(body, tries + 1)
  }
  if (!r.ok) throw new Error(`${r.status} ${(await r.text()).slice(0, 200)}`)
  const j = await r.json()
  lat.push(Date.now() - t0); calls++; tokIn += j.usage.input_tokens; tokOut += j.usage.output_tokens
  return j
}

const clean = s => (s || '').replace(/\s+/g, ' ').replace(/Fair Chance Policy:.*/i, '').trim()

// Same judgement, same information, as the blind human labelling.
const judge = r => ask({
  model: 'jev-latest',
  state: {
    wanted_post: clean(r.wanted),
    wanted_details: clean(r.wantedBody) || '(none)',
    offer_post: clean(r.offer),
    offer_details: clean(r.offerBody) || '(none)'
  },
  questions: {
    glad: {
      type: 'noul',
      instructions: 'A Freegle member posted the WANTED. We are considering emailing them, unasked, to tell them the OFFER is available nearby. Would they be glad to receive that email?',
      criteria: {
        true: 'The offer is the item they asked for, or a close enough substitute that they would want to know about it',
        false: 'The offer is a different item, or fails a requirement they stated, so the email would be junk to them'
      }
    }
  }
})

const out = []
let next = 0
await Promise.all(Array.from({ length: CONC }, async () => {
  while (next < sample.length) {
    const r = sample[next++]
    const j = await judge(r)
    out.push({ id: r.id, cos: r.cos, band: r.band, noul: j.answers.glad.noul })
  }
}))
out.sort((a, b) => a.id - b.id)
fs.writeFileSync(`${SD}/jev_run${RUN}.json`, JSON.stringify(out))
lat.sort((a, b) => a - b)
const mean = lat.reduce((a, b) => a + b, 0) / lat.length
console.error(`run ${RUN}: ${calls} calls, mean ${Math.round(mean)}ms, p95 ${lat[Math.floor(lat.length * 0.95)]}ms, in ${tokIn} out ${tokOut} tokens`)
