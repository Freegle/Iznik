import fs from 'fs'
const SD = process.env.SD
const BOX = 0.15, SLACK = 0.02, DIM = 256

const lines = fs.readFileSync(`${SD}/pool.tsv`, 'utf8').split('\n')
const hdr = lines[0].split('\t')
const col = n => hdr.indexOf(n)
const posts = []
for (let i = 1; i < lines.length; i++) {
  if (!lines[i]) continue
  const f = lines[i].split('\t')
  if (f.length < 8 || f[col('se')].length !== DIM * 8) continue
  const b = Buffer.from(f[col('se')], 'hex')
  const v = new Float32Array(DIM)
  for (let j = 0; j < DIM; j++) v[j] = b.readFloatLE(j * 4)
  posts.push({
    msgid: +f[col('msgid')], type: f[col('type')], fromuser: +f[col('fromuser')],
    lat: +f[col('lat')], lng: +f[col('lng')],
    subj: f[col('subj')], body: f[col('body')] === 'NULL' ? '' : f[col('body')], v
  })
}
const offers = posts.filter(p => p.type === 'Offer')
const wanteds = posts.filter(p => p.type === 'Wanted')
console.error(`pool: ${offers.length} offers, ${wanteds.length} wanteds`)

const dot = (a, b) => { let s = 0; for (let j = 0; j < DIM; j++) s += a[j] * b[j]; return s }

// Replicates PostMatches: source Offer -> best opposite-type candidate in box,
// excluding the source author. Score is the subject cosine only.
const out = []
for (const o of offers) {
  let best = null, bestCos = -2
  for (const w of wanteds) {
    if (w.fromuser === o.fromuser) continue
    if (w.lat < o.lat - BOX - SLACK || w.lat > o.lat + BOX + SLACK) continue
    if (w.lng < o.lng - BOX - SLACK || w.lng > o.lng + BOX + SLACK) continue
    const c = dot(o.v, w.v)
    if (c > bestCos) { bestCos = c; best = w }
  }
  if (best) out.push({
    offerid: o.msgid, offer: o.subj, offerBody: o.body,
    wantedid: best.msgid, wanted: best.subj, wantedBody: best.body,
    cos: +bestCos.toFixed(4)
  })
}
fs.writeFileSync(`${SD}/topmatches.json`, JSON.stringify(out))
const bands = [[0.95, 9], [0.90, 0.95], [0.85, 0.90], [0.80, 0.85], [0.75, 0.80], [0.60, 0.75], [-2, 0.60]]
console.error(`\nsources with a match: ${out.length}`)
for (const [lo, hi] of bands) {
  const n = out.filter(r => r.cos >= lo && r.cos < hi).length
  console.error(`  ${lo === -2 ? '<0.60' : lo.toFixed(2) + '-' + (hi === 9 ? '1.00' : hi.toFixed(2))}  ${n}`)
}
