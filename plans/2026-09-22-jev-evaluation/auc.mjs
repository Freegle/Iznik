import fs from 'fs'
const SD = process.env.SD
const { sample } = JSON.parse(fs.readFileSync(`${SD}/sample.json`, 'utf8'))
const labels = {}
for (const t of fs.readFileSync(`${SD}/labels.txt`, 'utf8').split(/\s+/).filter(Boolean)) labels[+t.slice(0, -1)] = t.slice(-1) === 'Y'
const jev = Object.fromEntries(JSON.parse(fs.readFileSync(`${SD}/jev_run1.json`, 'utf8')).map(r => [r.id, r.noul]))
const byId = Object.fromEntries(sample.map(r => [r.id, r]))

// AUC by rank (ties averaged) — threshold-free separation of good from junk.
const auc = sc => {
  const rows = sample.map(r => ({ s: sc(r), y: labels[r.id] })).sort((a, b) => a.s - b.s)
  let rank = 0, sumPos = 0, nPos = 0, nNeg = 0
  while (rank < rows.length) {
    let j = rank; while (j < rows.length && rows[j].s === rows[rank].s) j++
    const avg = (rank + 1 + j) / 2
    for (let k = rank; k < j; k++) { if (rows[k].y) { sumPos += avg; nPos++ } else nNeg++ }
    rank = j
  }
  return (sumPos - nPos * (nPos + 1) / 2) / (nPos * nNeg)
}
console.log(`AUC cosine ${auc(r => r.cos).toFixed(3)}   AUC jev ${auc(r => jev[r.id]).toFixed(3)}   (n=${sample.length}, 88 relevant)`)

console.log('\nTop band (0.90-0.95) pairs I labelled JUNK — is that fair?')
for (const r of sample.filter(r => r.band === '0.9-0.95' && !labels[r.id])) {
  console.log(` cos ${r.cos} jev ${jev[r.id].toFixed(2)}  W: ${r.wanted.slice(0, 55)} | O: ${r.offer.slice(0, 55)}`)
}
console.log('\nBiggest disagreements: Jev high, I said junk')
for (const r of sample.filter(r => jev[r.id] >= 0.35 && !labels[r.id]).sort((a, b) => jev[b.id] - jev[a.id]).slice(0, 8))
  console.log(` jev ${jev[r.id].toFixed(2)} cos ${r.cos}  W: ${r.wanted.slice(0, 50)} | O: ${r.offer.slice(0, 50)}`)
console.log('\nBiggest disagreements: Jev low, I said relevant')
for (const r of sample.filter(r => jev[r.id] < 0.15 && labels[r.id]).sort((a, b) => jev[a.id] - jev[b.id]).slice(0, 10))
  console.log(` jev ${jev[r.id].toFixed(2)} cos ${r.cos}  W: ${r.wanted.slice(0, 50)} | O: ${r.offer.slice(0, 50)}`)
