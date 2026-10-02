// Command-line access to the grounding reads and the PR description check, for the
// fix agents delegate_parallel_tasks starts. Those agents are separate processes with
// a shell, not the FSM brain, so they cannot call query_live_db / query_loki as
// actions. This runs the same handlers, with the same read-only rules.
//
//   node dist/ground.js db "<SELECT ...>" "<what hypothesis this checks>"
//   node dist/ground.js loki '<LogQL>' [sinceHours]
//   node dist/ground.js check-pr <file with the PR description>
//
// Output is JSON on stdout. check-pr exits 1 when the description would be refused.
import { readFileSync } from 'node:fs'
import { groundingActions } from './grounding.js'
import { assessPrEvidence } from './evidence.js'

async function run(name: string, params: Record<string, unknown>) {
  const action = groundingActions.find(a => a.name === name)!
  return action.handler(params, {})
}

export async function main(argv: string[]): Promise<number> {
  const [cmd, a, b] = argv
  if (cmd === 'db' && a) {
    console.log(JSON.stringify(await run('query_live_db', { sql: a, purpose: b ?? '' }), null, 1))
    return 0
  }
  if (cmd === 'loki' && a) {
    console.log(JSON.stringify(await run('query_loki', { query: a, sinceHours: b ? Number(b) : undefined }), null, 1))
    return 0
  }
  if (cmd === 'check-pr' && a) {
    const r = assessPrEvidence(readFileSync(a, 'utf8'))
    console.log(JSON.stringify(r, null, 1))
    return r.ok ? 0 : 1
  }
  console.error('usage: ground.js db "<sql>" "<purpose>" | loki "<logql>" [sinceHours] | check-pr <file>')
  return 2
}

if (process.argv[1] && /ground\.js$/.test(process.argv[1])) {
  main(process.argv.slice(2)).then(code => process.exit(code))
}
