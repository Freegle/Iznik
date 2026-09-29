import fs from 'fs'
const SD = process.env.SD, PORT = process.env.SPORT || '3200'
// Prototypes copied verbatim from ContentEmbeddingService::PROTOTYPES.
const P = JSON.parse(fs.readFileSync(`${SD}/prototypes.json`, 'utf8'))
const MARGIN = 0.05
const embed = async texts => {
  const r = await fetch(`http://localhost:${PORT}/embed`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ texts }) })
  if (!r.ok) throw new Error(`sidecar ${r.status}`)
  return (await r.json()).embeddings
}
const cos = (a, b) => { let d = 0, na = 0, nb = 0; for (let i = 0; i < a.length; i++) { d += a[i] * b[i]; na += a[i] * a[i]; nb += b[i] * b[i] } return d / Math.sqrt(na * nb) }
const mean = (v, others) => others.reduce((s, o) => s + cos(v, o), 0) / others.length

const jev = JSON.parse(fs.readFileSync(`${SD}/ck_jev.json`, 'utf8'))
const raw = {}
for (const f of ['ck_pos.tsv', 'ck_neg.tsv']) {
  const lines = fs.readFileSync(`${SD}/${f}`, 'utf8').split('\n'), h = lines[0].split('\t')
  for (let k = 1; k < lines.length; k++) { if (!lines[k]) continue; const c = lines[k].split('\t'); if (c.length < 7) continue
    raw[c[h.indexOf('msgid')]] = { subj: c[h.indexOf('subj')], body: c[h.indexOf('body')] === 'NULL' ? '' : c[h.indexOf('body')] } }
}
const cats = Object.keys(P)
const cases = jev.filter(r => cats.includes(r.cat) && raw[r.msgid])
console.error(`${cases.length} posts in the four categories prototypes cover (${cases.filter(c => c.caught).length} really needed a moderator)`)
const proto = {}
for (const c of cats) { const e = await embed([...P[c].concerning, ...P[c].innocent]); proto[c] = { con: e.slice(0, P[c].concerning.length), inn: e.slice(P[c].concerning.length) } }
const out = []
for (let i = 0; i < cases.length; i += 32) {
  const chunk = cases.slice(i, i + 32)
  const vecs = await embed(chunk.map(c => `${raw[c.msgid].subj} ${raw[c.msgid].body}`.trim()))
  chunk.forEach((c, k) => {
    const con = mean(vecs[k], proto[c.cat].con), inn = mean(vecs[k], proto[c.cat].inn)
    out.push({ ...c, con, inn, gap: inn - con, suppressed: inn > con + MARGIN })
  })
}
fs.writeFileSync(`${SD}/proto_out.json`, JSON.stringify(out))
const n = out.length, caught = out.filter(o => o.caught).length
const sup = out.filter(o => o.suppressed)
console.log(`\nToday's prototype rule (suppress if innocent > concerning + ${MARGIN}):`)
console.log(`  suppresses ${sup.length}/${n} holds; of those ${sup.filter(o => o.caught).length} really needed a moderator`)
console.log(`  so it still sends ${n - sup.length} to a moderator and misses ${sup.filter(o => o.caught).length} of ${caught} catches`)
const auc = sc => { const rs = out.map(o => ({ s: sc(o), y: o.caught })).sort((a, b) => a.s - b.s)
  let i = 0, sp = 0, nP = 0, nN = 0
  while (i < rs.length) { let j = i; while (j < rs.length && rs[j].s === rs[i].s) j++
    const av = (i + 1 + j) / 2; for (let k = i; k < j; k++) { if (rs[k].y) { sp += av; nP++ } else nN++ } i = j }
  return (sp - nP * (nP + 1) / 2) / (nP * nN) }
console.log(`\nSeparating "really needed a moderator" from "approved", same ${n} posts:`)
console.log(`  prototype gap (concerning - innocent)  AUC ${auc(o => -o.gap).toFixed(3)}`)
console.log(`  jev "is this a genuine concern"        AUC ${auc(o => o.genuine).toFixed(3)}`)
console.log(`  jev "would a moderator reject it"      AUC ${auc(o => o.needs).toFixed(3)}`)
// Volume-matched: let each rule send the same number to a moderator.
const target = n - sup.length
const byScore = (sc) => [...out].sort((a, b) => sc(b) - sc(a)).slice(0, target)
const hit = rows => rows.filter(r => r.caught).length
console.log(`\nIf each rule sends exactly ${target} of ${n} to a moderator, catches found (of ${caught}):`)
console.log(`  prototype gap  ${hit(byScore(o => -o.gap))}`)
console.log(`  jev genuine    ${hit(byScore(o => o.genuine))}`)
console.log(`  jev needs      ${hit(byScore(o => o.needs))}`)
