// Command-line access to the grounding reads and the evidence record, for the fix
// agents delegate_parallel_tasks starts. Those agents are separate processes with a
// shell, not the FSM brain, so they cannot call query_live_db / query_loki as
// actions. This runs the same handlers, with the same read-only rules, and records
// every read made for a bug in its local evidence record (see evidence.ts).
//
//   node dist/ground.js db "<SELECT ...>" "<what hypothesis this checks>" --bug <topic>/<post>
//   node dist/ground.js loki '<LogQL>' [sinceHours] --bug <topic>/<post>
//   node dist/ground.js note <topic>/<post> "<what the reads showed>"
//   node dist/ground.js evidence-line <topic>/<post>
//   node dist/ground.js check-pr <file with the PR description> <topic>/<post>
//
// Output is JSON on stdout, except evidence-line, which prints the line itself.
// evidence-line and check-pr exit 1 when the evidence would be refused.
import { readFileSync } from 'node:fs'
import { groundingActions } from './grounding.js'
import { appendEvidence, assessPrEvidence, evidenceLine, parseBugRef } from './evidence.js'

async function run(name: string, params: Record<string, unknown>) {
  const action = groundingActions.find(a => a.name === name)!
  return action.handler(params, {})
}

const usage = () => {
  console.error('usage: ground.js db "<sql>" "<purpose>" --bug T/P | loki "<logql>" [sinceHours] --bug T/P | note T/P "<text>" | evidence-line T/P | check-pr <file> T/P')
  return 2
}

export async function main(argv: string[]): Promise<number> {
  const bugAt = argv.indexOf('--bug')
  const bug = bugAt >= 0 ? parseBugRef(argv[bugAt + 1]) : null
  const args = bugAt >= 0 ? argv.filter((_, i) => i !== bugAt && i !== bugAt + 1) : argv
  const [cmd, a, b] = args

  if ((cmd === 'db' || cmd === 'loki') && a) {
    const result: any = cmd === 'db'
      ? await run('query_live_db', { sql: a, purpose: b ?? '' })
      : await run('query_loki', { query: a, sinceHours: b ? Number(b) : undefined })
    if (bug) {
      appendEvidence(bug.topic, bug.post, {
        kind: cmd, query: a, purpose: cmd === 'db' ? b : undefined,
        available: result?.available === true, source: cmd === 'db' ? 'prod' : result?.source,
        rowCount: result?.rowCount ?? result?.count, result,
      })
    }
    console.log(JSON.stringify(result, null, 1))
    return 0
  }
  if (cmd === 'note' && parseBugRef(a) && b) {
    const ref = parseBugRef(a)!
    appendEvidence(ref.topic, ref.post, { kind: 'note', text: b })
    console.log(JSON.stringify({ recorded: true }))
    return 0
  }
  if (cmd === 'evidence-line' && parseBugRef(a)) {
    const ref = parseBugRef(a)!
    const line = evidenceLine(ref.topic, ref.post)
    if (!line) {
      console.error(`no usable evidence for ${a}: it needs at least one production read made with --bug ${a}, and a note of what it showed`)
      return 1
    }
    console.log(line)
    return 0
  }
  if (cmd === 'check-pr' && a && parseBugRef(b)) {
    const ref = parseBugRef(b)!
    const r = assessPrEvidence(readFileSync(a, 'utf8'), ref.topic, ref.post)
    console.log(JSON.stringify(r, null, 1))
    return r.ok ? 0 : 1
  }
  return usage()
}

if (process.argv[1] && /ground\.js$/.test(process.argv[1])) {
  main(process.argv.slice(2)).then(code => process.exit(code))
}
