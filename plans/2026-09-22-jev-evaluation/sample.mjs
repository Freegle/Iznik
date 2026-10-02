import fs from 'fs'
const SD = process.env.SD
const all = JSON.parse(fs.readFileSync(`${SD}/topmatches.json`, 'utf8'))
// Deterministic shuffle so the sample is reproducible.
let seed = 20260922
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff }
const BANDS = [[0.90, 0.95], [0.85, 0.90], [0.80, 0.85], [0.75, 0.80]]
const PER = 40
const pop = {}
const sample = []
for (const [lo, hi] of BANDS) {
  const inBand = all.filter(r => r.cos >= lo && r.cos < hi)
  pop[`${lo}`] = inBand.length
  const shuffled = inBand.map(r => [rnd(), r]).sort((a, b) => a[0] - b[0]).map(x => x[1])
  for (const r of shuffled.slice(0, PER)) sample.push({ ...r, band: `${lo}-${hi}` })
}
// Present in random order with an opaque id so labelling cannot see the band.
const presented = sample.map(r => [rnd(), r]).sort((a, b) => a[0] - b[0]).map((x, i) => ({ id: i + 1, ...x[1] }))
fs.writeFileSync(`${SD}/sample.json`, JSON.stringify({ pop, sample: presented }, null, 1))
const clip = (s, n) => (s || '').replace(/\s+/g, ' ').slice(0, n)
for (const r of presented) {
  console.log(`[${r.id}] WANTED: ${clip(r.wanted, 90)}`)
  if (r.wantedBody) console.log(`      w-body: ${clip(r.wantedBody, 110)}`)
  console.log(`      OFFER : ${clip(r.offer, 90)}`)
  if (r.offerBody) console.log(`      o-body: ${clip(r.offerBody, 110)}`)
}
console.error(JSON.stringify(pop))
