import fs from 'fs'
const SD = process.env.SD
const { pop, sample } = JSON.parse(fs.readFileSync(`${SD}/sample.json`, 'utf8'))
const labels = {}
for (const t of fs.readFileSync(`${SD}/labels.txt`, 'utf8').split(/\s+/).filter(Boolean)) labels[+t.slice(0,-1)] = t.slice(-1)==='Y'
const R = [1,2,3].map(n => Object.fromEntries(JSON.parse(fs.readFileSync(`${SD}/jev_run${n}.json`,'utf8')).map(r=>[r.id,r.noul])))
let maxSpread = 0, flips = 0, touched = 0, spreads = []
for (const r of sample) {
  const v = R.map(m => m[r.id]); const lo = Math.min(...v), hi = Math.max(...v)
  spreads.push(hi - lo); maxSpread = Math.max(maxSpread, hi - lo)
  const d = v.map(x => x >= 0.5); if (d.some(Boolean) && !d.every(Boolean)) flips++
  if (v.some(x => x >= 0.40 && x <= 0.60)) touched++
}
spreads.sort((a,b)=>a-b)
console.log(`spread across 3 runs: median ${spreads[80].toFixed(3)}, p95 ${spreads[152].toFixed(3)}, max ${maxSpread.toFixed(3)}`)
console.log(`decisions that flipped at 0.5: ${flips}/160 (${(100*flips/160).toFixed(1)}%); pairs touching the 0.40-0.60 band: ${touched}/160`)
// Volume-matched comparison: what does Jev pick if it keeps the same number production keeps?
const W = {'0.9-0.95':pop['0.9']/40,'0.85-0.9':pop['0.85']/40,'0.8-0.85':pop['0.8']/40,'0.75-0.8':pop['0.75']/40}
const mean = id => R.reduce((a,m)=>a+m[id],0)/3
const vol = keep => sample.filter(keep).reduce((a,r)=>a+W[r.band],0)
const prec = keep => { const k = sample.filter(keep); const tp = k.reduce((a,r)=>a+(labels[r.id]?W[r.band]:0),0); return tp/k.reduce((a,r)=>a+W[r.band],0) }
const rec = keep => { const tp = sample.filter(keep).reduce((a,r)=>a+(labels[r.id]?W[r.band]:0),0); const all = sample.reduce((a,r)=>a+(labels[r.id]?W[r.band]:0),0); return tp/all }
const prodVol = vol(r => r.cos >= 0.85)
let best = null
for (let t = 0.01; t <= 0.99; t += 0.01) { const v = vol(r => mean(r.id) >= t); if (!best || Math.abs(v-prodVol) < Math.abs(best.v-prodVol)) best = {t, v} }
console.log(`\nVolume-matched (both send ~${Math.round(prodVol)} of 1,610 emails):`)
console.log(`  production cos>=0.85 : precision ${(100*prec(r=>r.cos>=0.85)).toFixed(1)}%  recall ${(100*rec(r=>r.cos>=0.85)).toFixed(1)}%  volume ${Math.round(prodVol)}`)
console.log(`  jev>=${best.t.toFixed(2)}           : precision ${(100*prec(r=>mean(r.id)>=best.t)).toFixed(1)}%  recall ${(100*rec(r=>mean(r.id)>=best.t)).toFixed(1)}%  volume ${Math.round(best.v)}`)
