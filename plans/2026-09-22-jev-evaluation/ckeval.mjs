import fs from 'fs'
const SD = process.env.SD
const rows = JSON.parse(fs.readFileSync(`${SD}/ck_jev.json`, 'utf8'))
// Population: 6,491 original-post ConcernKeyword holds in 12 months, 218 caught.
// Positives are a census; negatives are a 591 sample of 6,273, so weight them up.
const POP_NEG = 6273, POP_POS = 218
const W = r => r.caught ? POP_POS / rows.filter(x => x.caught).length : POP_NEG / rows.filter(x => !x.caught).length
const wneg = POP_NEG / rows.filter(r => !r.caught).length, wpos = POP_POS / rows.filter(r => r.caught).length

const score = keep => {  // keep = "send to a moderator"
  let tp = 0, fp = 0, fn = 0, tn = 0
  for (const r of rows) {
    const w = r.caught ? wpos : wneg
    if (keep(r)) { r.caught ? tp += w : fp += w } else { r.caught ? fn += w : tn += w }
  }
  return { tp, fp, fn, tn, prec: 100 * tp / (tp + fp), rec: 100 * tp / (tp + fn), held: tp + fp }
}
const f = n => (isNaN(n) ? '  -  ' : n.toFixed(1).padStart(5))
const line = (name, s) => console.log(`${name.padEnd(30)} ${Math.round(s.held).toString().padStart(6)} ${f(s.prec)} ${f(s.rec)}   ${Math.round(s.fn).toString().padStart(5)}`)
console.log('Per year, all 6,491 original-post concern-keyword holds (218 really needed a moderator)')
console.log('rule'.padEnd(30) + '   holds  prec   rec   missed')
line('today: hold every match', score(() => true))
for (const t of [0.2, 0.3, 0.4, 0.5]) line(`jev needs_human >= ${t}`, score(r => r.needs >= t))
for (const t of [0.2, 0.3, 0.5]) line(`jev genuine >= ${t}`, score(r => r.genuine >= t))
for (const t of [0.15, 0.2, 0.3]) line(`either >= ${t}`, score(r => Math.max(r.needs, r.genuine) >= t))

// AUC, threshold-free
const auc = sc => {
  const rs = rows.map(r => ({ s: sc(r), y: r.caught, w: r.caught ? wpos : wneg })).sort((a, b) => a.s - b.s)
  let i = 0, sumPos = 0, nP = 0, nN = 0
  while (i < rs.length) {
    let j = i; while (j < rs.length && rs[j].s === rs[i].s) j++
    const avg = (i + 1 + j) / 2
    for (let k = i; k < j; k++) { if (rs[k].y) { sumPos += avg; nP++ } else nN++ }
    i = j
  }
  return (sumPos - nP * (nP + 1) / 2) / (nP * nN)
}
console.log(`\nAUC  needs_human ${auc(r => r.needs).toFixed(3)}   genuine ${auc(r => r.genuine).toFixed(3)}   (n=${rows.length})`)
console.log('\nby concern category (raw sampled counts, not weighted)')
const cats = {}
for (const r of rows) { cats[r.cat] ??= { n: 0, caught: 0, flagged: 0, caughtFlagged: 0 }
  const c = cats[r.cat]; c.n++; if (r.caught) c.caught++
  if (r.needs >= 0.3) { c.flagged++; if (r.caught) c.caughtFlagged++ } }
for (const [k, v] of Object.entries(cats).sort((a,b) => b[1].n - a[1].n))
  console.log(`  ${k.padEnd(22)} n=${String(v.n).padStart(3)} caught=${String(v.caught).padStart(3)}  jev>=0.3 flags ${String(v.flagged).padStart(3)}, of which really caught ${v.caughtFlagged}`)
