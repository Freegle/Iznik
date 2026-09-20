// For every per-community setting the old groups table carried, report the majority value
// across live Freegle communities, so each setting can be frozen at that value.
// Usage: node scripts/group-settings-majority.mjs <groups-settings.tsv> [minShare=0.75]
// The TSV columns: id, nameshort, publish, onmap, mentored, haswelcome, showonyahoo, ontn, onlovejunk, overridemoderation,
// microvolunteering, microvolunteeringoptions JSON, autofunctionoverride, postvisibility, privategroup, licenserequired,
// welcomereview, listable, settings JSON, rules JSON
import { readFileSync } from 'node:fs'
const [,, file, minShareArg] = process.argv
const minShare = Number(minShareArg || 0.75)
const rows = readFileSync(file, 'utf8').split('\n').filter(Boolean).map(l => l.split('\t'))
const active = rows.filter(r => r[2] === '1') // publish = 1
const counts = {} // key -> Map(value -> n)
function bump(key, value) {
  const v = value === null || value === undefined ? 'null' : typeof value === 'object' ? JSON.stringify(value) : String(value)
  counts[key] ??= new Map()
  counts[key].set(v, (counts[key].get(v) || 0) + 1)
}
function flatten(prefix, obj) {
  for (const [k, v] of Object.entries(obj || {})) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(key, v)
    else bump(key, v)
  }
}
for (const r of active) {
  const cols = ['onmap', 'mentored', 'has welcome mail', 'showonyahoo', 'ontn', 'onlovejunk', 'overridemoderation', 'microvolunteering', null,
    'autofunctionoverride', 'postvisibility', 'privategroup', 'licenserequired', 'welcomereview', 'listable']
  cols.forEach((name, i) => { if (name) bump(`column ${name}`, r[3 + i]) })
  let mv = {}, s = {}, rules = {}
  try { mv = JSON.parse(r[11]) } catch {}
  try { s = JSON.parse(r[18]) } catch {}
  try { rules = JSON.parse(r[19]) } catch {}
  flatten('microvolunteeringoptions', mv)
  flatten('settings', s)
  flatten('rules', rules)
}
const n = active.length
console.log(`Live Freegle communities with publish = 1: ${n} (of ${rows.length} Freegle rows).`)
console.log(`A setting is "settled" when one value covers at least ${Math.round(minShare * 100)}% of communities that have it. Unset counts as the default.\n`)
console.log('| Setting | Communities with a value | Majority value | Share | Settled? | Other values seen |')
console.log('|---|---|---|---|---|---|')
const keys = Object.keys(counts).sort()
for (const key of keys) {
  const m = counts[key]
  const withValue = [...m.values()].reduce((a, b) => a + b, 0)
  // Free-text settings (welcome text, descriptions) are not behaviour: skip anything with many distinct long values.
  const distinct = [...m.entries()].sort((a, b) => b[1] - a[1])
  if (distinct.length > 12 && distinct[0][1] / withValue < 0.5) { console.log(`| ${key} | ${withValue} | (free text, ${distinct.length} distinct) | | n/a | |`); continue }
  const [top, topN] = distinct[0]
  // Unset communities take the majority value too, so the share is over all communities with the key,
  // and the "settled" test is against communities that set anything.
  const share = topN / withValue
  const others = distinct.slice(1, 4).map(([v, c]) => `${v.length > 30 ? v.slice(0, 27) + '...' : v} (${c})`).join(', ')
  console.log(`| ${key} | ${withValue} | ${top.length > 40 ? top.slice(0, 37) + '...' : top} | ${Math.round(share * 100)}% | ${share >= minShare ? 'yes' : 'NO'} | ${others} |`)
}
