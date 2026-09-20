// Where have fixes concentrated? Reads git history to estimate how much maintenance went into
// code that is configurable per community (and into the community model at all), versus the rest,
// and which things were fixed repeatedly.
// Usage: node scripts/maintainability-history.mjs [base-ref=origin/master] [since=2024-01-01]
// Heuristics, stated in the output: a "fix" is a non-merge commit whose subject reads like one, or
// that names a Discourse or Sentry issue; a commit "touches settings code" when its changed lines
// mention community settings or rules; it "touches the community model" when a changed file
// mentioned groupid, memberships or messages_groups at the base ref.
import { execSync } from 'node:child_process'
const [,, BASE = 'origin/master', SINCE = '2024-01-01'] = process.argv
const git = (cmd) => execSync(`git ${cmd}`, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 })
const CODE_PATHS = ['iznik-server-go', 'iznik-batch/app', 'iznik-batch/routes', 'iznik-nuxt3/components', 'iznik-nuxt3/pages',
  'iznik-nuxt3/composables', 'iznik-nuxt3/stores', 'iznik-nuxt3/api', 'iznik-nuxt3/modtools']
const isCode = (f) => /\.(go|php|vue|js|ts|mjs)$/.test(f) && !/_test\.go$|\/tests?\/|\.spec\.|\.test\.|swagger\/docs|api\/index\.js$/.test(f)
const SETTINGS = /groups?\.settings|->settings\b|\bsettings\.(reposts|duplicates|spammers|map|keywords|chaseups|autoapprove|moderated|closed|relevant|newsletter|communityevents|volunteering|engagement|includearea|includepc|showchat|businesscards|allowedits|joiners|mentored|nearbygroups|region|welcomemail|rules)|\brules\.(alcohol|animals|weapons|firearms|medications|tickets|tobacco|vaping|porn|restrict|fullymoderated|limit|carseats|knives|gas|copyright|declare|allowloans|chinese|contact|pond|waste|other)|ourPostingStatus|\bModSettings|getSetting\(|group\.settings|groupSettings|\$group->settings|JSON_EXTRACT\(settings/
const MODEL = /\bgroupid\b|\bmemberships\b|messages_groups|MessageGroup|myGroups|useGroupStore|\bgroups\b\./
const FIX = /\b(fix|fixes|fixed|fixing|bug|broken|regression|wrong|incorrect|fail|fails|failing|not working|crash|error|repair|restore|missing)\b|discourse|sentry|#\d{4,5}/i

// 1. Files at BASE, classified
const baseFiles = git(`ls-tree -r --name-only ${BASE} -- ${CODE_PATHS.join(' ')}`).split('\n').filter(isCode)
const loc = { all: 0, settings: 0, model: 0 }
const fileClass = new Map() // file -> {settings, model, loc}
for (const f of baseFiles) {
  let body = ''
  try { body = git(`show ${BASE}:${JSON.stringify(f)}`) } catch { continue }
  const lines = body.split('\n').filter(l => l.trim()).length
  const s = SETTINGS.test(body), m = s || MODEL.test(body)
  fileClass.set(f, { settings: s, model: m, loc: lines })
  loc.all += lines; if (s) loc.settings += lines; if (m) loc.model += lines
}

// 2. Commits in the window touching code
const log = git(`log ${BASE} --since=${SINCE} --no-merges --date=short --format='%H|%ad|%s' -- ${CODE_PATHS.join(' ')}`)
const commits = log.split('\n').filter(Boolean).map(l => { const [h, d, ...s] = l.split('|'); return { h, d, s: s.join('|') } })
const stats = { commits: 0, fixes: 0, fixSettings: 0, fixModel: 0, fixNeither: 0, allSettings: 0, allModel: 0 }
const perFile = new Map() // file -> fixes
const byIssue = new Map() // discourse id -> [{h,d,s,settings,model}]
const perYear = {}
for (const c of commits) {
  const files = git(`show --name-only --format= ${c.h} -- ${CODE_PATHS.join(' ')}`).split('\n').filter(isCode)
  if (!files.length) continue
  stats.commits++
  const y = c.d.slice(0, 4); perYear[y] ??= { commits: 0, fixes: 0, fixSettings: 0, fixModel: 0 }; perYear[y].commits++
  let touchesSettings = false
  try {
    const diff = git(`show --unified=0 --format= ${c.h} -- ${files.map(f => JSON.stringify(f)).join(' ')}`)
    touchesSettings = diff.split('\n').some(l => /^[+-][^+-]/.test(l) && SETTINGS.test(l))
  } catch {}
  const touchesModel = touchesSettings || files.some(f => fileClass.get(f)?.model)
  if (touchesSettings) stats.allSettings++
  if (touchesModel) stats.allModel++
  const isFix = FIX.test(c.s)
  if (!isFix) continue
  stats.fixes++; perYear[y].fixes++
  if (touchesSettings) { stats.fixSettings++; perYear[y].fixSettings++ }
  if (touchesModel) { stats.fixModel++; perYear[y].fixModel++ } else stats.fixNeither++
  for (const f of files) perFile.set(f, (perFile.get(f) || 0) + 1)
  const ids = [...c.s.matchAll(/(?:discourse[^\d]{0,25}|#)(\d{4,5})\b/gi)].map(m => m[1])
  for (const id of new Set(ids)) { byIssue.set(id, byIssue.get(id) || []); byIssue.get(id).push({ ...c, settings: touchesSettings, model: touchesModel }) }
}

const pct = (a, b) => b ? `${Math.round(a * 100 / b)}%` : 'n/a'
const per1k = (n, l) => l ? (n * 1000 / l).toFixed(2) : 'n/a'
console.log(`# Maintenance history: where the fixes went\n`)
console.log(`Base ${BASE}, non-merge commits since ${SINCE} that touch code (not tests). ${stats.commits} commits, ${stats.fixes} of them fixes by the subject-line heuristic.\n`)
console.log(`## Share of the code versus share of the fixes\n`)
console.log(`| Area | Lines at base | Share of code | Fix commits touching it | Share of fixes | Fixes per 1,000 lines |`)
console.log(`|---|---|---|---|---|---|`)
console.log(`| Code that reads community settings or rules | ${loc.settings} | ${pct(loc.settings, loc.all)} | ${stats.fixSettings} | ${pct(stats.fixSettings, stats.fixes)} | ${per1k(stats.fixSettings, loc.settings)} |`)
console.log(`| Code that touches the community model at all (includes the row above) | ${loc.model} | ${pct(loc.model, loc.all)} | ${stats.fixModel} | ${pct(stats.fixModel, stats.fixes)} | ${per1k(stats.fixModel, loc.model)} |`)
console.log(`| Everything else | ${loc.all - loc.model} | ${pct(loc.all - loc.model, loc.all)} | ${stats.fixNeither} | ${pct(stats.fixNeither, stats.fixes)} | ${per1k(stats.fixNeither, loc.all - loc.model)} |`)
console.log(`| All code | ${loc.all} | 100% | ${stats.fixes} | 100% | ${per1k(stats.fixes, loc.all)} |`)
console.log(`\nAll commits (not just fixes): ${pct(stats.allSettings, stats.commits)} touched settings code and ${pct(stats.allModel, stats.commits)} touched the community model.\n`)
console.log(`## By year\n`)
console.log(`| Year | Commits | Fixes | Fixes touching settings code | Fixes touching the community model |`)
console.log(`|---|---|---|---|---|`)
for (const y of Object.keys(perYear).sort()) { const p = perYear[y]; console.log(`| ${y} | ${p.commits} | ${p.fixes} | ${p.fixSettings} (${pct(p.fixSettings, p.fixes)}) | ${p.fixModel} (${pct(p.fixModel, p.fixes)}) |`) }
console.log(`\n## Things fixed more than once (same Discourse or issue number in two or more fix commits)\n`)
const repeated = [...byIssue.entries()].filter(([, v]) => v.length >= 2).sort((a, b) => b[1].length - a[1].length)
const repSettings = repeated.filter(([, v]) => v.some(c => c.settings)).length
const repModel = repeated.filter(([, v]) => v.some(c => c.model)).length
console.log(`${repeated.length} issues had two or more fix commits; ${repModel} of those touched the community model (${repSettings} touched settings or rules code). The ${byIssue.size} issues with any fix commit: ${[...byIssue.values()].filter(v => v.some(c => c.model)).length} touched the community model.\n`)
console.log(`| Issue | Fix commits | Community model? | Settings? | First | Last | Latest subject |`)
console.log(`|---|---|---|---|---|---|---|`)
for (const [id, v] of repeated.slice(0, 40)) {
  const ds = v.map(c => c.d).sort()
  console.log(`| #${id} | ${v.length} | ${v.some(c => c.model) ? 'yes' : ''} | ${v.some(c => c.settings) ? 'yes' : ''} | ${ds[0]} | ${ds[ds.length - 1]} | ${v[0].s.replace(/\|/g, '/').slice(0, 90)} |`)
}
console.log(`\n## Files with the most fix commits\n`)
console.log(`| File | Fix commits | Lines at base | Community model? | Settings? |`)
console.log(`|---|---|---|---|---|`)
for (const [f, n] of [...perFile.entries()].sort((a, b) => b[1] - a[1]).slice(0, 30)) {
  const c = fileClass.get(f); console.log(`| ${f} | ${n} | ${c?.loc ?? ''} | ${c?.model ? 'yes' : ''} | ${c?.settings ? 'yes' : ''} |`)
}
console.log(`\n## Caveats\n`)
console.log(`- "Fix" is read from the commit subject, so refactors that mention "fix" count and silent fixes do not.`)
console.log(`- A commit touches settings code when a changed line mentions a community setting or rule; it touches the community model when any changed file mentioned groupid, memberships or messages_groups at the base. Big files attract fixes for reasons other than community configuration.`)
console.log(`- The window is this repository's history only; earlier history in the separate repositories is not counted.`)
console.log(`- Correlation, not cause: the community model is where the most-used code lives too.`)
