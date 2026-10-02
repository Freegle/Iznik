import fs from 'fs'
const SD = process.env.SD
const rows = JSON.parse(fs.readFileSync(`${SD}/${process.env.IN}`, 'utf8'))
const T = parseFloat(process.env.T || '0.5')
// Positive class = reject, matching llm-modbot's convention.
const score = pred => {
  let tp = 0, fp = 0, tn = 0, fn = 0
  for (const r of rows) {
    const p = pred(r), y = r.expected === 'reject'
    if (p && y) tp++; else if (p && !y) fp++; else if (!p && !y) tn++; else fn++
  }
  const acc = 100 * (tp + tn) / rows.length, prec = 100 * tp / (tp + fp), rec = 100 * tp / (tp + fn)
  return { acc, prec, rec, f1: 2 * prec * rec / (prec + rec), tp, fp, tn, fn }
}
const f = (n) => (isNaN(n) ? '  -  ' : n.toFixed(1).padStart(5))
const line = (name, s) => console.log(`${name.padEnd(34)} ${f(s.acc)} ${f(s.prec)} ${f(s.rec)} ${f(s.f1)}    ${s.tp} ${s.fp} ${s.tn} ${s.fn}`)
console.log(`n=${rows.length} (${rows.filter(r=>r.expected==='reject').length} reject / ${rows.filter(r=>r.expected==='approve').length} approve)`)
console.log('decider'.padEnd(34) + '   acc  prec   rec    F1    tp fp tn fn')
if (rows[0].prev_pred) line('fine-tuned 3B (v3, recorded)', score(r => r.prev_pred === 'reject'))
line(`jev >= ${T}`, score(r => r.noul >= T))
for (const t of [0.3, 0.4, 0.6, 0.7, 0.8]) line(`  jev >= ${t}`, score(r => r.noul >= t))

// Which rejection reasons does it actually catch? Text-decidable vs not.
const EXTERNAL = new Set(['duplicate', 'out_of_area', 'repeat_too_soon'])
console.log('\ncaught by jev >= 0.5, by rejection reason')
const cats = {}
for (const r of rows.filter(r => r.expected === 'reject')) {
  cats[r.category] ??= { n: 0, hit: 0 }
  cats[r.category].n++; if (r.noul >= T) cats[r.category].hit++
}
for (const [c, v] of Object.entries(cats).sort((a, b) => b[1].n - a[1].n))
  console.log(`  ${c.padEnd(18)} ${String(v.hit).padStart(3)}/${String(v.n).padEnd(3)} ${(100*v.hit/v.n).toFixed(0).padStart(3)}%  ${EXTERNAL.has(c) ? 'needs facts outside the text' : 'decidable from the text'}`)
const dec = rows.filter(r => r.expected === 'reject' && !EXTERNAL.has(r.category))
const ext = rows.filter(r => r.expected === 'reject' && EXTERNAL.has(r.category))
console.log(`\n  text-decidable rejects caught: ${dec.filter(r=>r.noul>=T).length}/${dec.length} (${(100*dec.filter(r=>r.noul>=T).length/dec.length).toFixed(0)}%)`)
console.log(`  external-fact rejects caught:  ${ext.filter(r=>r.noul>=T).length}/${ext.length} (${(100*ext.filter(r=>r.noul>=T).length/ext.length).toFixed(0)}%)`)
