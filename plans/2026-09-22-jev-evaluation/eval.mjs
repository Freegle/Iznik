import fs from 'fs'
const SD = process.env.SD
const { pop, sample } = JSON.parse(fs.readFileSync(`${SD}/sample.json`, 'utf8'))
const labels = {}
for (const tok of fs.readFileSync(`${SD}/labels.txt`, 'utf8').split(/\s+/).filter(Boolean)) {
  labels[+tok.slice(0, -1)] = tok.slice(-1) === 'Y'
}
const runs = (process.env.RUNS || '1').split(',').map(n =>
  Object.fromEntries(JSON.parse(fs.readFileSync(`${SD}/jev_run${n}.json`, 'utf8')).map(r => [r.id, r.noul])))
const byId = Object.fromEntries(sample.map(r => [r.id, r]))
const BANDS = ['0.9-0.95', '0.85-0.9', '0.8-0.85', '0.75-0.8']
const W = { '0.9-0.95': pop['0.9'] / 40, '0.85-0.9': pop['0.85'] / 40, '0.8-0.85': pop['0.8'] / 40, '0.75-0.8': pop['0.75'] / 40 }

// Weighted confusion: each sampled pair stands for W[band] of the population.
const score = keep => {
  let tp = 0, fp = 0, fn = 0
  for (const r of sample) {
    const w = W[r.band], good = labels[r.id], k = keep(r)
    if (k && good) tp += w; else if (k && !good) fp += w; else if (!k && good) fn += w
  }
  const p = tp / (tp + fp), rc = tp / (tp + fn)
  return { p, rc, f1: 2 * p * rc / (p + rc), tp, fp, fn }
}
const pct = x => (100 * x).toFixed(1) + '%'
const row = (name, s) => `${name.padEnd(30)} ${pct(s.p).padStart(7)} ${pct(s.rc).padStart(7)} ${(2 * s.p * s.rc / (s.p + s.rc) * 100).toFixed(1).padStart(6)}   ${Math.round(s.fp).toString().padStart(5)} ${Math.round(s.fn).toString().padStart(6)}`

console.log('Weighted to the 1,610 sources whose best match scores 0.75-0.95')
console.log('decision rule'.padEnd(30) + '   prec  recall     F1   junk  missed')
console.log(row('production cos >= 0.85', score(r => r.cos >= 0.85)))
const mean = id => runs.reduce((a, m) => a + m[id], 0) / runs.length
console.log(row(`jev >= 0.5 (${runs.length} run${runs.length > 1 ? 's, mean' : ''})`, score(r => mean(r.id) >= 0.5)))
for (const t of [0.3, 0.4, 0.6, 0.7]) console.log(row(`  jev >= ${t}`, score(r => mean(r.id) >= t)))
for (const t of [0.80, 0.82, 0.87, 0.90]) console.log(row(`  cos >= ${t}`, score(r => r.cos >= t)))

console.log('\nper band (unweighted, 40 sampled each)')
console.log('band'.padEnd(12) + ' pop   relevant  cos>=.85 keeps  jev keeps  jev prec  jev recall')
for (const b of BANDS) {
  const rows = sample.filter(r => r.band === b)
  const rel = rows.filter(r => labels[r.id]).length
  const ck = rows.filter(r => r.cos >= 0.85).length
  const jk = rows.filter(r => mean(r.id) >= 0.5)
  const jtp = jk.filter(r => labels[r.id]).length
  console.log(`${b.padEnd(12)} ${String(Math.round(W[b] * 40)).padStart(4)}  ${String(rel).padStart(6)}/40 ${String(ck).padStart(11)} ${String(jk.length).padStart(10)} ${(jk.length ? pct(jtp / jk.length) : '-').padStart(10)} ${pct(jtp / rel).padStart(11)}`)
}
