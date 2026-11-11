export const meta = {
  name: 'ux-ideas-backtest',
  description: 'Backtest the UX idea generator: ideas from a past snapshot against the changes made afterwards, recall per funnel, improve the prompt until the bar is met',
  whenToUse: 'After changing claude-agent-sdk/ux-ideas-prompt.js, or to check the generator still rediscovers what the team went on to build. Needs snapshots from scripts/ux-ideas-snapshot.sh; give some of them as heldOut to get a score the improver never saw.',
  phases: [
    { title: 'Truth', detail: 'summarise each later commit as a member-visible change, or exclude it' },
    { title: 'Generate', detail: 'the generator answers its own prompt over the snapshot' },
    { title: 'Match', detail: 'strict and generous judges, refuters on the disputed ones' },
    { title: 'Improve', detail: 'rewrite the prompt builder from what was missed, keep the best version, then go again' },
    { title: 'Held out', detail: 'score the kept prompt once on snapshots the improver never saw' },
  ],
}

// args: { snapshots: [{dir, flow, date}], heldOut?: [{dir, flow, date}], cli, builder, bar?, rounds?, ideas? }
// snapshots are scored every round and shown to the improver. heldOut ones are scored once, at
// the end, with the best prompt, and the improver never sees them: that figure is the headline.
const snaps = args.snapshots
const heldOut = args.heldOut || []
const bar = args.bar ?? 0.25
const maxRounds = args.rounds ?? 3
const cli = args.cli
const builder = args.builder

const TRUTH_SCHEMA = {
  type: 'object',
  properties: {
    total: { type: 'number', description: 'the number of entries in the "later" array of truth.json, counted, not estimated' },
    items: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          sha: { type: 'string' },
          subject: { type: 'string' },
          visible: { type: 'boolean', description: 'true when a member would notice the change in the interface or its behaviour; false for a pure bug fix nobody asked for, refactor, test, lint, telemetry, tooling or infrastructure change' },
          kind: { type: 'string', enum: ['feature', 'removal', 'copy', 'resilience', 'flow', 'default', 'device', 'naming', 'fix', 'other'] },
          statement: { type: 'string', description: 'For a visible change: one sentence, as an idea would be phrased ("Show X on the Y step so that Z"). Empty otherwise.' },
        },
        required: ['sha', 'subject', 'visible', 'kind', 'statement'],
      },
    },
  },
  required: ['total', 'items'],
}

const IDEAS_SCHEMA = {
  type: 'object',
  properties: {
    journey: { type: 'array', items: { type: 'object', properties: { screen: { type: 'string' }, asks: { type: 'string' }, friction: { type: 'string' } }, required: ['screen', 'asks', 'friction'] } },
    problems: { type: 'array', items: { type: 'object', properties: { id: { type: 'number' }, title: { type: 'string' }, where: { type: 'string' }, source: { type: 'string' }, evidence: { type: 'string' } }, required: ['id', 'title', 'where', 'source', 'evidence'] } },
    ideas: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          problem_id: { type: 'number' },
          type: { type: 'string', enum: ['fix', 'experiment'] },
          title: { type: 'string' },
          category: { type: 'string' },
          change: { type: 'string' },
          rationale: { type: 'string' },
          expected: { type: 'string' },
          expected_points: { type: 'number' },
          confidence: { type: 'number' },
          locality: { type: 'number' },
          risk: { type: 'string' },
          files: { type: 'array', items: { type: 'string' } },
          like_tried: { type: 'string' },
        },
        required: ['problem_id', 'type', 'title', 'category', 'change', 'rationale', 'expected', 'expected_points', 'confidence', 'locality', 'risk', 'files'],
      },
    },
  },
  required: ['journey', 'problems', 'ideas'],
}

const MATCH_SCHEMA = {
  type: 'object',
  properties: {
    matches: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          truth_index: { type: 'number' },
          matched: { type: 'boolean' },
          idea_index: { type: 'number', description: 'index of the matching idea, or -1' },
          reason: { type: 'string' },
        },
        required: ['truth_index', 'matched', 'idea_index', 'reason'],
      },
    },
  },
  required: ['matches'],
}

const VERDICT_SCHEMA = {
  type: 'object',
  properties: { refuted: { type: 'boolean' }, reason: { type: 'string' } },
  required: ['refuted', 'reason'],
}

const IMPROVE_SCHEMA = {
  type: 'object',
  properties: {
    changed: { type: 'boolean' },
    summary: { type: 'string' },
    patterns_missed: { type: 'array', items: { type: 'string' } },
  },
  required: ['changed', 'summary', 'patterns_missed'],
}


const SHELL_SCHEMA = {
  type: 'object',
  properties: { ok: { type: 'boolean' }, output: { type: 'string' } },
  required: ['ok', 'output'],
}

const COPY_SCHEMA = {
  type: 'object',
  properties: {
    copied: { type: 'array', items: { type: 'string' }, description: 'added lines or phrases that reproduce a missed change, its screen, its wording or its domain specifics' },
  },
  required: ['copied'],
}

// Workflow scripts have no file access, so file copies go through a small agent.
async function sh(label, command) {
  const r = await agent(`Run exactly this command with Bash, change nothing else, and return whether it exited 0 and its output: ${command}`, { label, schema: SHELL_SCHEMA })
  if (!r || !r.ok) throw new Error(`${label} failed: ${(r && r.output) || 'no answer'}`)
  return r.output
}

// ---------------------------------------------------------------- truth, once per snapshot

const everySnap = snaps.concat(heldOut)
const truthPrompt = (s, only) =>
  `Read ${s.dir}/truth.json: the commits made to the "${s.flow}" area of Freegle's member site AFTER ${s.date} (up to its "until" date), each with subject, body, stat and a diff excerpt. Read nothing else.
${only ? `\nReturn ONLY the commits whose sha is NOT in this list: ${only.join(', ')}. total is still the number of entries in "later".\n` : ''}
For EVERY commit decide whether a member would notice it in the interface or its behaviour (visible: true), as opposed to a pure bug fix nobody asked for, a refactor, test, lint, telemetry, tooling or infrastructure change (visible: false). A fix that changes what a member sees on an unhappy path (an upload that no longer sticks, a form that no longer blanks) IS visible. Give each a kind. For every visible one write ONE sentence phrased as an idea a product team might have written beforehand: "Show X on the Y step so that Z", "Remove X because Y", "Call X 'Y' instead". Keep the sentence to what the diff shows; do not add intent that is not there.

Return every commit in the order given, none left out. Use the sha exactly as written in the file.`

const truths = await parallel(
  everySnap.map((s) => () =>
    agent(truthPrompt(s), { label: `truth:${s.flow}@${s.date}`, phase: 'Truth', schema: TRUTH_SCHEMA, effort: 'high' })
  )
)

// The truth agent must return every commit, or the denominator quietly shrinks. Ask for the ones
// it left out, twice at most, and say so in the result when some are still missing.
const truthBy = {}
const truthGaps = []
for (let i = 0; i < everySnap.length; i++) {
  const s = everySnap[i]
  const first = truths[i] || { total: null, items: [] }
  const bySha = new Map()
  for (const t of first.items || []) if (t && t.sha && !bySha.has(t.sha)) bySha.set(t.sha, t)
  const total = Number.isInteger(first.total) ? first.total : null
  for (let attempt = 0; attempt < 2 && (total === null || bySha.size < total); attempt++) {
    const more = await agent(truthPrompt(s, [...bySha.keys()]), { label: `truth-rest:${s.flow}@${s.date}`, phase: 'Truth', schema: TRUTH_SCHEMA, effort: 'high' })
    const before = bySha.size
    for (const t of (more && more.items) || []) if (t && t.sha && !bySha.has(t.sha)) bySha.set(t.sha, t)
    if (bySha.size === before) break
  }
  const items = [...bySha.values()].map((t, idx) => ({ ...t, truth_index: idx }))
  truthBy[s.dir] = items
  if (total === null || items.length < total) truthGaps.push({ flow: s.flow, date: s.date, returned: items.length, total })
  const visible = items.filter((t) => t.visible)
  log(`${s.flow}@${s.date}: ${visible.length} member-visible changes of ${items.length} commits${total !== null && items.length < total ? ` (file has ${total}: INCOMPLETE)` : ''}`)
}

// ---------------------------------------------------------------- one scored pass

async function scorePass(list, round, tag) {
  const results = await pipeline(
    list,
    (s) =>
      agent(
        `You are the UX idea generator under test. Everything you may look at is inside ${s.dir}: brief.json, and the member site's source as it was on ${s.date} under ${s.dir}/code (repository paths, no git history). Do NOT open ${s.dir}/truth.json (it holds the answers and reading it voids the test), do not run git, and do not read anything outside ${s.dir}: the rest of this machine has code from after ${s.date}.

1. Run: node ${cli} ${s.dir}/brief.json   and read the printed prompt in full. It is the prompt the live generator gets, with the code folder and the area's recent commit subjects. The snapshot has no figures, survey answers, member problems, reports or open issues, so those sections print as (none): work from the code and the history only.
2. Run: node ${cli} ${s.dir}/brief.json --schema   to see the output schema the live generator uses.
3. Do what the prompt says, as the live generator would: read the flow's files under ${s.dir}/code in full with your tools and follow their imports, list the problems the code shows before any solution, then propose and type the solutions. Return ${args.ideas || 40} ideas via the structured output, with the problems they solve.

Base everything on the code and history as they were on ${s.date}, not on how you think the site looks today.`,
        { label: `generate:${tag}:${s.flow}@${s.date}#${round}`, phase: 'Generate', schema: IDEAS_SCHEMA, effort: 'high' }
      ),
    async (gen, s) => {
      const truth = truthBy[s.dir].filter((t) => t.visible)
      if (!gen || !truth.length) return { s, gen, truth, matched: [], recall: null }
      const ideasText = gen.ideas.map((i, k) => `[${k}] ${i.title} (${i.category}): ${i.change} Rationale: ${i.rationale}`).join('\n')
      const truthText = truth.map((t) => `[${t.truth_index}] (${t.kind}) ${t.statement}  <- commit ${t.sha}: ${t.subject}`).join('\n')
      const judge = (lens) =>
        agent(
          `${lens}

IDEAS the generator produced for the "${s.flow}" area as of ${s.date}:
${ideasText}

CHANGES the team actually made afterwards (ground truth):
${truthText}

For EVERY ground-truth change, say whether one of the ideas anticipates it, which one (index), and why in one sentence. Return one entry per change, in order.`,
          { label: `judge:${tag}:${s.flow}@${s.date}#${round}`, phase: 'Match', schema: MATCH_SCHEMA, effort: 'high' }
        )
      const [strict, generous] = await parallel([
        () => judge('You are a STRICT judge. An idea anticipates a later change only if a developer handed that idea would plausibly have produced that change: the same screen or control, the same mechanism, the same intent. Different mechanism for the same problem is NOT a match. Default to not matched when unsure.'),
        () => judge('You are a GENEROUS judge. An idea anticipates a later change if it addresses the same member problem in the same part of the flow, even if the mechanism or wording differs. A removal idea matches a later removal of the same thing. Default to not matched only when the idea is about something else entirely.'),
      ])
      const sMap = {}
      for (const m of (strict && strict.matches) || []) sMap[m.truth_index] = m
      const gMap = {}
      for (const m of (generous && generous.matches) || []) gMap[m.truth_index] = m

      // Disputed: generous yes, strict no. One refuter decides; refuted means not matched.
      const disputed = truth.filter((t) => gMap[t.truth_index] && gMap[t.truth_index].matched && !(sMap[t.truth_index] && sMap[t.truth_index].matched))
      const verdicts = await parallel(
        disputed.map((t) => () =>
          agent(
            `Try to REFUTE this claimed match. Ground-truth change: "${t.statement}" (commit ${t.sha}: ${t.subject}). Claimed matching idea: "${(gen.ideas[gMap[t.truth_index].idea_index] || {}).title}: ${(gen.ideas[gMap[t.truth_index].idea_index] || {}).change}". Generous judge's reason: ${gMap[t.truth_index].reason}. Refute if the idea would not have led a developer to make substantially this change in this part of the flow. Default refuted=true if uncertain.`,
            { label: `refute:${tag}:${s.flow}@${s.date}#${round}`, phase: 'Match', schema: VERDICT_SCHEMA }
          )
        )
      )
      const matched = []
      const missed = []
      truth.forEach((t) => {
        const strictYes = sMap[t.truth_index] && sMap[t.truth_index].matched
        const di = disputed.findIndex((d) => d.truth_index === t.truth_index)
        const generousHeld = di >= 0 && verdicts[di] && verdicts[di].refuted === false
        if (strictYes || generousHeld) {
          matched.push({ ...t, idea: (sMap[t.truth_index] && sMap[t.truth_index].matched ? sMap[t.truth_index] : gMap[t.truth_index]).idea_index, via: strictYes ? 'strict' : 'generous+unrefuted' })
        } else {
          missed.push({ ...t, note: (sMap[t.truth_index] && sMap[t.truth_index].reason) || '' })
        }
      })
      const lenient = truth.filter((t) => gMap[t.truth_index] && gMap[t.truth_index].matched).length
      return { s, gen, truth, matched, missed, lenient, recall: matched.length / truth.length }
    }
  )

  const scored = results.filter(Boolean).filter((r) => r.recall !== null)
  const totalTruth = scored.reduce((n, r) => n + r.truth.length, 0)
  const totalMatched = scored.reduce((n, r) => n + r.matched.length, 0)
  const recall = totalTruth ? totalMatched / totalTruth : 0
  const lenientTotal = scored.reduce((n, r) => n + (r.lenient || 0), 0)
  const byKind = {}
  for (const r of scored) {
    for (const t of r.truth) { byKind[t.kind] ??= { truth: 0, matched: 0 }; byKind[t.kind].truth++ }
    for (const m of r.matched) byKind[m.kind].matched++
  }
  const ideaTypes = {}
  for (const r of scored) for (const i of (r.gen && r.gen.ideas) || []) ideaTypes[i.type] = (ideaTypes[i.type] || 0) + 1
  const perFlow = scored.map((r) => ({ flow: r.s.flow, date: r.s.date, truth: r.truth.length, matched: r.matched.length, recall: r.recall, matchedList: r.matched.map((m) => `${m.sha} ${m.subject} <- idea ${m.idea} (${m.via})`), missed: r.missed.map((m) => `${m.sha} [${m.kind}] ${m.statement}`), ideas: r.gen ? r.gen.ideas.map((i) => `${i.category}: ${i.title}`) : [] }))
  const lenientRecall = totalTruth ? lenientTotal / totalTruth : 0
  log(`${tag} round ${round}: recall ${(recall * 100).toFixed(0)}% (${totalMatched} of ${totalTruth} later changes anticipated), lenient ${(lenientRecall * 100).toFixed(0)}%`)
  log('by kind: ' + Object.entries(byKind).map(([k, v]) => `${k} ${v.matched}/${v.truth}`).join(', '))

  return { round, recall, lenientRecall, matched: totalMatched, truth: totalTruth, byKind, ideaTypes, perFlow }
}

// ---------------------------------------------------------------- rounds

// Each round scores the builder as it stands. Its text is copied aside straight after scoring, so
// a later edit that scores lower can be put back and the best version is the one that is kept.
const backups = `${snaps[0].dir}/../ux-ideas-builder-backups`
const backup = (r) => `${backups}/round${r}.js`
const rounds = []
let best = { round: 0, recall: -1 }

for (let round = 1; round <= maxRounds; round++) {
  const stats = await scorePass(snaps, round, 'tune')
  rounds.push(stats)
  await sh(`save-builder#${round}`, `mkdir -p ${backups} && cp ${builder} ${backup(round)}`)
  if (stats.recall > best.recall) best = { round, recall: stats.recall }
  log(`best so far: round ${best.round} at ${(best.recall * 100).toFixed(0)}%; bar ${(bar * 100).toFixed(0)}% (on the snapshots the improver sees)`)

  if (stats.recall >= bar || round === maxRounds) break

  // An edit that lowered recall is not built on: go back to the best version first.
  if (best.round !== round) {
    await sh(`restore-best#${round}`, `cp ${backup(best.round)} ${builder}`)
    log(`round ${round} scored lower than round ${best.round}; the builder is back to round ${best.round}'s text`)
  }

  phase('Improve')
  // The improver builds on the best version, so it is shown what that version missed.
  const basis = rounds[best.round - 1]
  const perFlow = basis.perFlow
  const missedAll = perFlow.flatMap((f) => f.missed.map((m) => `${f.flow}@${f.date}: ${m}`)).join('\n')
  const ideasAll = perFlow.map((f) => `${f.flow}@${f.date}:\n  ${f.ideas.join('\n  ')}`).join('\n')
  const improved = await agent(
    `The UX idea generator's prompt builder is ${builder}. In a backtest over past snapshots it anticipated ${basis.matched} of ${basis.truth} changes the team later made (recall ${(basis.recall * 100).toFixed(0)}%, bar ${(bar * 100).toFixed(0)}%).

What it MISSED (ground truth, by funnel and snapshot date):
${missedAll}

What it PRODUCED instead (titles by category):
${ideasAll}

Improve the prompt builder so a generator following it would find more of the kinds of change the team actually makes, WITHOUT copying any of the missed changes, their screens, their wording or their domain specifics into the prompt (that would be cheating: the prompt is also used live on code and reports the team has not acted on yet${heldOut.length ? ', and snapshots you have not been shown will be scored after you' : ''}; the edit is diffed and checked for copied text, and a version that scores lower is put back). Change method, not content: how to read reports, how to turn a report into a problem, how to audit code, how to type and rank. Allowed: better instructions about how to walk the journey, which unhappy paths to inspect in the code, which categories to weight, what "concrete" means, how to read the recent history, how to score. You may edit CATEGORIES and the text in buildIdeasPrompt. You must keep: the module require-free, every export, the IDEAS_SCHEMA shape, FLOW_PATHS, the problems-before-solutions order, and every line pinned by claude-agent-sdk/ux-ideas-prompt.test.js (run node --test there after editing and keep it green). Keep the prompt under 9,000 characters before the evidence sections. Edit the file in place with the Edit tool, then run: node -e "require('${builder}')" to prove it loads. Return whether you changed it, a summary, and the patterns of change the generator kept missing.`,
    { label: `improve#${round}`, phase: 'Improve', schema: IMPROVE_SCHEMA, effort: 'high' }
  )
  rounds[rounds.length - 1].improvement = improved
  if (!improved || !improved.changed) {
    log('the improver made no change; stopping')
    break
  }
}

// Leave the best-scoring text in place, whichever round it came from.
const last = rounds[rounds.length - 1]
if (best.round !== last.round) {
  await sh('restore-best-final', `cp ${backup(best.round)} ${builder}`)
  log(`kept round ${best.round}'s builder (${(best.recall * 100).toFixed(0)}%); the last round scored ${(last.recall * 100).toFixed(0)}%`)
}

// What the kept builder changed against the first one, and whether any of it was copied from
// the missed changes it was shown.
let promptDiff = ''
let copiedFromMissed = []
if (best.round > 1) {
  promptDiff = await sh('diff-builder', `diff -u ${backup(1)} ${builder} || true`)
  const shown = rounds
    .slice(0, best.round - 1)
    .flatMap((r) => r.perFlow.flatMap((f) => f.missed.map((m) => `${f.flow}@${f.date}: ${m}`)))
    .join('\n')
  const check = await agent(
    `A prompt builder was edited by a process that was shown the changes below, which the generator missed. It must change METHOD only, never copy any of them. List every added line or phrase in the diff that reproduces one of these changes, its screen, its wording or its domain specifics. An empty list is a valid answer.

MISSED CHANGES SHOWN TO THE EDITOR:
${shown}

DIFF:
${promptDiff}`,
    { label: 'copy-check', phase: 'Improve', schema: COPY_SCHEMA, effort: 'high' }
  )
  copiedFromMissed = (check && check.copied) || []
  if (copiedFromMissed.length) log(`WARNING: the prompt edit seems to copy missed changes: ${copiedFromMissed.length} places; review promptDiff before keeping it`)
}

// ---------------------------------------------------------------- held out, once

let heldOutResult = null
if (heldOut.length) {
  phase('Held out')
  heldOutResult = await scorePass(heldOut, best.round, 'held-out')
  log(`HEADLINE: held-out recall ${(heldOutResult.recall * 100).toFixed(0)}% strict, ${(heldOutResult.lenientRecall * 100).toFixed(0)}% generous, on snapshots the improver never saw`)
}

return {
  // The headline is the held-out figure. tuneRecall is the best round on the snapshots the improver
  // was shown, so it is training recall once any improvement has run.
  heldOutRecall: heldOutResult ? heldOutResult.recall : null,
  heldOut: heldOutResult,
  tuneRecall: best.recall,
  bestRound: best.round,
  lastRoundRecall: last.recall,
  baselineRecall: rounds[0].recall,
  bar,
  rounds,
  truthGaps,
  promptDiff,
  copiedFromMissed,
}
