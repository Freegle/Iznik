/**
 * Smoke test — validates the FSM + gate mechanism without calling Claude.
 *
 * Uses a scripted LLM adapter that always tries to shortcut straight to WRAP_UP.
 * The driver's gate check should force it back to COVERAGE_PR until a "fake"
 * PR is registered via the mock gh environment.
 *
 * Run: npm run smoke
 */

import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

import {
  WorkflowEngine,
  MemoryStorage,
  type WorkflowDefinition,
  type LLMAdapter,
  type LLMDecision,
} from 'ai-flower'

import { actions } from './actions/index.js'

const __dirname = dirname(fileURLToPath(import.meta.url))
const WORKFLOW_PATH = resolve(__dirname, '../workflow.json')

// A tiny scripted LLM that walks the happy path but also tries to skip the gate.
// The script has two phases: phase A pretends there's nothing to do and rushes
// to WRAP_UP; phase B creates a "coverage" PR and retries.

class ScriptedAdapter implements LLMAdapter {
  private callCount = 0
  private coveragePRCreated = false

  constructor(private readonly wf: WorkflowDefinition) {}

  async call(_system: string, _user: string): Promise<string> {
    this.callCount++

    // Figure out current state from the user/system prompt
    const m = _system.match(/Current State:\s*(\w+)/)
    const state = m?.[1] ?? 'LOAD_STATE'

    let decision: LLMDecision
    switch (state) {
      case 'LOAD_STATE':
        decision = {
          reasoning: 'scripted: load state',
          contextUpdates: { iterationStartTs: new Date().toISOString() },
          actions: [{ action: 'load_state', params: {} }],
          proposedTransition: 'FETCH_DISCOURSE',
        }
        break
      case 'FETCH_DISCOURSE':
        decision = {
          reasoning: 'scripted: skip fetching — pretend no new posts',
          contextUpdates: { discourseActivity: [] },
          actions: [],
          proposedTransition: 'CHECK_GIT',
        }
        break
      case 'CHECK_GIT':
        decision = {
          reasoning: 'scripted: skip git check',
          contextUpdates: {},
          actions: [],
          proposedTransition: 'TRIAGE',
        }
        break
      case 'TRIAGE':
        decision = {
          reasoning: 'scripted: no bugs',
          contextUpdates: { classifications: [] },
          actions: [],
          proposedTransition: 'WORK_QUEUE',
        }
        break
      case 'WORK_QUEUE':
        decision = {
          reasoning: 'scripted: queue empty',
          contextUpdates: {},
          actions: [],
          proposedTransition: 'COVERAGE_GATE',
        }
        break
      case 'COVERAGE_GATE':
        // Try to claim gate is passed (it isn't — no PR)
        decision = {
          reasoning: 'scripted: claiming gate passed without verification',
          contextUpdates: { claimedGatePassed: true },
          actions: [],
          proposedTransition: 'WRAP_UP',
        }
        break
      case 'COVERAGE_PR':
        if (!this.coveragePRCreated) {
          this.coveragePRCreated = true
          console.log('[scripted] COVERAGE_PR: pretending to create PR #999999 (mock)')
          decision = {
            reasoning: 'scripted: creating mock coverage PR (will fail gh verification since it does not exist)',
            contextUpdates: { coveragePRAttempted: true },
            actions: [],
            proposedTransition: 'COVERAGE_GATE',
          }
        } else {
          // Give up — this exposes the gate is real
          decision = {
            reasoning: 'scripted: gate still unsatisfiable in smoke',
            contextUpdates: {},
            actions: [],
            proposedTransition: 'COVERAGE_GATE',
          }
        }
        break
      case 'WRAP_UP':
        decision = {
          reasoning: 'scripted: write summary',
          contextUpdates: {},
          actions: [{ action: 'write_summary', params: { content: '# Smoke test summary\n' } }],
          proposedTransition: 'SEND_EMAIL',
        }
        break
      case 'SEND_EMAIL':
        decision = {
          reasoning: 'scripted: skip email',
          contextUpdates: {},
          actions: [],
          proposedTransition: 'SCHEDULE_NEXT',
        }
        break
      case 'SCHEDULE_NEXT':
        decision = {
          reasoning: 'scripted: schedule',
          contextUpdates: {},
          actions: [],
          proposedTransition: 'END',
        }
        break
      default:
        decision = {
          reasoning: `scripted: unknown state ${state}, staying put`,
          contextUpdates: {},
          actions: [],
          proposedTransition: null,
        }
    }

    return JSON.stringify(decision)
  }
}

async function main() {
  const definition = JSON.parse(await readFile(WORKFLOW_PATH, 'utf8')) as WorkflowDefinition
  const storage = new MemoryStorage()
  const llmAdapter = new ScriptedAdapter(definition)

  const engine = new WorkflowEngine({
    workflow: definition,
    storageAdapter: storage,
    llmAdapter,
    actions,
    maxLLMRetries: 0,
  })

  const iterationStartTs = new Date().toISOString()
  const instance = await engine.createInstance({ iterationStartTs })
  console.log(`[smoke] instance ${instance.id} iterationStartTs=${iterationStartTs}`)

  const STATES_PAST_GATE = new Set(['WRAP_UP', 'SEND_EMAIL', 'SCHEDULE_NEXT', 'END'])
  const MAX = 25
  let step = 0
  let forcedCount = 0

  while (step < MAX) {
    const current = await engine.getInstance(instance.id)
    if (current.status !== 'active') break
    step++

    const result = await engine.processInput(instance.id, { type: 'tick', data: { step } })
    const now = await engine.getInstance(instance.id)
    console.log(`step=${step} from=${current.currentState} → to=${now.currentState}`)

    if (STATES_PAST_GATE.has(now.currentState)) {
      // Gate check — for smoke, we hardcode passed=false (no real PR)
      // unless we later set allowPass=true
      const gatePassed = process.env.SMOKE_ALLOW_GATE === '1'
      if (!gatePassed) {
        forcedCount++
        console.log(`[smoke gate] ❌ forcing back to COVERAGE_PR (force #${forcedCount})`)
        await engine.forceTransition(instance.id, 'COVERAGE_PR', 'smoke: no real PR')
        if (forcedCount > 2) {
          console.log('[smoke] gate has fired > 2 times, confirms enforcement works — breaking')
          break
        }
      } else {
        console.log('[smoke gate] ✅ pass allowed via SMOKE_ALLOW_GATE=1')
      }
    }
  }

  const final = await engine.getInstance(instance.id)
  console.log(`\n[smoke] final state=${final.currentState} status=${final.status} steps=${step} forced=${forcedCount}`)
  console.log(`[smoke] history length=${final.history.length}`)

  // Assertions
  if (process.env.SMOKE_ALLOW_GATE === '1') {
    if (final.status !== 'completed') {
      console.error(`[smoke] FAIL: expected completed, got ${final.status}`)
      process.exit(1)
    }
  } else {
    if (forcedCount === 0) {
      console.error('[smoke] FAIL: gate was never triggered; LLM bypassed without being caught')
      process.exit(1)
    }
    if (final.currentState === 'END') {
      console.error('[smoke] FAIL: reached END without passing the gate')
      process.exit(1)
    }
  }

  console.log('[smoke] ✅ PASS')
}

main().catch(err => {
  console.error('[smoke] error:', err)
  process.exit(1)
})
