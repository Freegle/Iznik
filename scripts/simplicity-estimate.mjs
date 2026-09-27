// Estimates the end-state size of the code once every file that still mentions the group model
// has been treated the way the already-treated files were.
// Usage: node scripts/simplicity-estimate.mjs [base-ref=origin/master]
// Method, per tier: take the files at the base that mentioned the group model ("affected").
// Those now clean or deleted are "done": their shrink rate (lines removed / lines at base) is
// measured. The rate is applied to the current lines of the affected files that still mention
// the model to estimate what is still to go. Unaffected files are assumed unchanged from now.
import { execSync } from 'node:child_process'
import { readFileSync, existsSync } from 'node:fs'
const BASE = process.argv[2] || 'origin/master'
const git = (c) => execSync(`git ${c}`, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
const MODEL = /\bgroupid\b|\bmemberships\b|messages_groups|MessageGroups?\b|myGroups|useGroupStore|useGroupless|\bnameshort\b|\brippled_in\b|\bgroups\b/
const TIERS = [
  ['Go API', ['iznik-server-go'], /\.go$/, /_test\.go$|swagger\/docs/],
  ['Batch (Laravel)', ['iznik-batch/app', 'iznik-batch/routes', 'iznik-batch/config'], /\.php$/, /\/tests\//],
  ['Member site (Nuxt)', ['iznik-nuxt3/components', 'iznik-nuxt3/pages', 'iznik-nuxt3/composables', 'iznik-nuxt3/stores', 'iznik-nuxt3/api', 'iznik-nuxt3/layouts', 'iznik-nuxt3/middleware', 'iznik-nuxt3/plugins'], /\.(vue|js|ts|mjs)$/, /\/tests\/|api\/index\.js$/],
  ['ModTools (Nuxt)', ['iznik-nuxt3/modtools'], /\.(vue|js|ts|mjs)$/, /\/tests\//],
  ['Spatial + routing (Go)', ['iznik-spatial-go', 'iznik-routing-go'], /\.go$/, /_test\.go$/],
  ['Tests (all tiers)', ['iznik-server-go/test', 'iznik-batch/tests', 'iznik-nuxt3/tests/unit', 'iznik-spatial-go', 'iznik-routing-go'], /(_test\.go|Test\.php|\.spec\.js|\.test\.js)$/, /__NONE__/],
]
const loc = (s) => s.split('\n').filter(l => l.trim()).length
const pct = (a, b) => b ? `${Math.round(a * 100 / b)}%` : 'n/a'
console.log(`End-state estimate: ${BASE} (before) versus now versus the estimated end.\n`)
console.log(`| Tier | Lines before | Lines now | Now vs before | Affected files: done / still to do | Shrink rate on done files: edited only / incl. deleted | Estimated end (range) | End vs before (range) |`)
console.log(`|---|---|---|---|---|---|---|---|`)
let totB = 0, totN = 0, totE = 0, totL = 0
for (const [name, paths, inc, exc] of TIERS) {
  const baseFiles = git(`ls-tree -r --name-only ${BASE} -- ${paths.join(' ')}`).split('\n').filter(f => inc.test(f) && !exc.test(f))
  const nowFiles = git(`ls-files -co --exclude-standard -- ${paths.join(' ')}`).split('\n').filter(f => f && existsSync(f) && inc.test(f) && !exc.test(f))
  let linesBefore = 0, linesNow = 0
  let doneBase = 0, doneNow = 0, todoNow = 0, nDone = 0, nTodo = 0, editBase = 0, editNow = 0
  const nowSet = new Set(nowFiles)
  const nowLoc = new Map()
  for (const f of nowFiles) { const s = readFileSync(f, 'utf8'); nowLoc.set(f, { loc: loc(s), model: MODEL.test(s) }); linesNow += nowLoc.get(f).loc }
  for (const f of baseFiles) {
    let s = ''; try { s = git(`show ${BASE}:${JSON.stringify(f)}`) } catch { continue }
    const b = loc(s); linesBefore += b
    if (!MODEL.test(s)) continue
    const cur = nowSet.has(f) ? nowLoc.get(f) : null
    if (!cur || !cur.model) { doneBase += b; doneNow += cur ? cur.loc : 0; nDone++; if (cur) { editBase += b; editNow += cur.loc } }
    else { todoNow += cur.loc; nTodo++ }
  }
  // Files that never mentioned the model at base but do now (new files that carry it) count as to-do too.
  for (const f of nowFiles) if (!baseFiles.includes(f) && nowLoc.get(f).model) { todoNow += nowLoc.get(f).loc; nTodo++ }
  const rate = doneBase ? (doneBase - doneNow) / doneBase : 0          // deletions and edits together: upper bound on removal
  const rateEdit = editBase ? (editBase - editNow) / editBase : 0     // surviving edited files only: lower bound
  const est = Math.round(linesNow - todoNow * rate)
  const estLow = Math.round(linesNow - todoNow * rateEdit)
  totB += linesBefore; totN += linesNow; totE += est; totL += estLow
  console.log(`| ${name} | ${linesBefore} | ${linesNow} | ${pct(linesNow - linesBefore, linesBefore)} | ${nDone} / ${nTodo} | ${Math.round(rateEdit * 100)}% / ${Math.round(rate * 100)}% | ${estLow} to ${est} | ${pct(estLow - linesBefore, linesBefore)} to ${pct(est - linesBefore, linesBefore)} |`)
}
console.log(`| All | ${totB} | ${totN} | ${pct(totN - totB, totB)} | | | ${totL} to ${totE} | ${pct(totL - totB, totB)} to ${pct(totE - totB, totB)} |`)
console.log(`\nAssumptions: files not yet treated shrink the way the treated ones did; files that never mentioned the model stay as they are now. The low end applies the rate measured on files that were edited and survived; the high end includes the files that were deleted outright. The remainder is mostly large files that are only partly about communities (the Go message package, the incoming mail service, the background task command), so the truth is nearer the low end.`)
