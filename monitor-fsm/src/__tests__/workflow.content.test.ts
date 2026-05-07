import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const workflowPath = join(__dirname, '../../workflow.json')
const actionsPath = join(__dirname, '../actions/index.ts')

const workflow = JSON.parse(readFileSync(workflowPath, 'utf8'))
const actionsTs = readFileSync(actionsPath, 'utf8')

// ── REPRODUCE_BUG: AssertFlip two-step protocol ───────────────────────────

describe('REPRODUCE_BUG prompt — AssertFlip strategy', () => {
  const prompt: string = workflow.states.REPRODUCE_BUG.prompt

  it('uses AssertFlip framing, not direct failing-test instruction', () => {
    expect(prompt).toContain('AssertFlip')
    expect(prompt).not.toContain('Write a failing automated test that demonstrates')
  })

  it('includes STEP 1: write a test that passes on buggy behaviour', () => {
    expect(prompt).toContain('STEP 1')
    expect(prompt).toContain('PASSES on the BUGGY (current) behaviour')
  })

  it('includes STEP 2: invert assertions', () => {
    expect(prompt).toContain('STEP 2')
    expect(prompt).toContain('Invert every assertion')
  })

  it('requires BUGGY_TEST_PASSES= marker from Step 1', () => {
    expect(prompt).toContain('BUGGY_TEST_PASSES=yes')
  })

  it('still requires TEST_FAILED= marker for Step 2 output', () => {
    expect(prompt).toContain('TEST_FAILED=')
  })

  it('still requires TEST_FILE= and TEST_COMMAND= markers', () => {
    expect(prompt).toContain('TEST_FILE=')
    expect(prompt).toContain('TEST_COMMAND=')
  })

  it('instructs delegate to push test branch and emit COMMIT_PUSHED — no separate PR', () => {
    expect(prompt).toContain('COMMIT_PUSHED=<sha>')
    expect(prompt).toContain('Do NOT open a PR')
  })

  it('uses same branch naming as IMPLEMENT_FIX so it can be found', () => {
    expect(prompt).toContain('fix/<featureArea-slug')
    expect(prompt).toContain('IMPLEMENT_FIX')
  })

  it('passes affected_function from diagnosisBrief into the task', () => {
    expect(prompt).toContain('diagnosisBrief.affected_function')
  })

  it('does not instruct delegate to fix the bug', () => {
    expect(prompt).toContain('Do NOT fix the bug')
  })
})

// ── DIAGNOSE_BUG: two-phase gather/diagnose pattern ──────────────────────

describe('DIAGNOSE_BUG prompt — two-phase structure', () => {
  const prompt: string = workflow.states.DIAGNOSE_BUG.prompt

  it('has a PHASE 1 (GATHER) section gated on _action_search_code absent', () => {
    expect(prompt).toContain('PHASE 1')
    expect(prompt).toContain('_action_search_code is NOT set')
  })

  it('has a PHASE 2 (DIAGNOSE) section gated on _action_search_code present', () => {
    expect(prompt).toContain('PHASE 2')
    expect(prompt).toContain('_action_search_code IS set')
  })

  it('Phase 1 calls check_existing_prs action', () => {
    const phase1 = prompt.split('PHASE 2')[0]
    expect(phase1).toContain('check_existing_prs')
  })

  it('Phase 1 calls search_code action', () => {
    const phase1 = prompt.split('PHASE 2')[0]
    expect(phase1).toContain('search_code')
  })

  it('Phase 1 sets proposedTransition to null to stay in the state', () => {
    const phase1 = prompt.split('PHASE 2')[0]
    expect(phase1).toContain('"proposedTransition": null')
  })

  it('Phase 2 reads _action_search_code results from context', () => {
    const phase2 = prompt.split('PHASE 2')[1]
    expect(phase2).toContain('_action_search_code')
  })

  it('Phase 2 reads _action_check_existing_prs results from context', () => {
    const phase2 = prompt.split('PHASE 2')[1]
    expect(phase2).toContain('_action_check_existing_prs')
  })

  it('Phase 2 sets proposedTransition to REPRODUCE_BUG', () => {
    const phase2 = prompt.split('PHASE 2')[1]
    expect(phase2).toContain('"proposedTransition": "REPRODUCE_BUG"')
  })

  it('Phase 2 has empty actions (no tool calls in final output)', () => {
    const phase2 = prompt.split('PHASE 2')[1]
    expect(phase2).toContain('"actions": []')
  })

  it('PHASE 1 appears before PHASE 2 in the prompt', () => {
    const p1Idx = prompt.indexOf('PHASE 1')
    const p2Idx = prompt.indexOf('PHASE 2')
    expect(p1Idx).toBeLessThan(p2Idx)
  })
})

// ── DIAGNOSE_BUG: symbol-level localization ───────────────────────────────

describe('DIAGNOSE_BUG prompt — symbol-level localization', () => {
  const prompt: string = workflow.states.DIAGNOSE_BUG.prompt

  it('includes SYMBOL-LEVEL LOCALIZATION heading', () => {
    expect(prompt).toContain('SYMBOL-LEVEL LOCALIZATION')
  })

  it('instructs the LLM to record affected_function with file and line', () => {
    expect(prompt).toContain("affected_function: '<FunctionName> in <file>:<line>'")
  })

  it('includes affected_function in the diagnosisBrief schema output shape', () => {
    expect(prompt).toContain('"affected_function"')
    // It should appear in the JSON schema block, before "evidence"
    const afIdx = prompt.indexOf('"affected_function"')
    const evIdx = prompt.indexOf('"evidence"')
    expect(afIdx).toBeLessThan(evIdx)
  })

  it('still requires check_existing_prs', () => {
    expect(prompt).toContain('check_existing_prs')
  })

  it('still requires multi-hypothesis step', () => {
    expect(prompt).toContain('MULTI-HYPOTHESIS STEP')
  })
})

// ── driver.ts: DIAGNOSE_BUG re-entry clears stale search results ──────────

describe('driver.ts — DIAGNOSE_BUG re-entry stale clearing', () => {
  const driverTs = readFileSync(join(__dirname, '../driver.ts'), 'utf8')

  it('has a DIAGNOSE_BUG re-entry clearing block', () => {
    expect(driverTs).toContain("current.currentState === 'DIAGNOSE_BUG'")
  })

  it('checks diagnosisMismatchReason before clearing', () => {
    const idx = driverTs.indexOf("current.currentState === 'DIAGNOSE_BUG'")
    const block = driverTs.slice(idx, idx + 600)
    expect(block).toContain('diagnosisMismatchReason')
  })

  it('clears _action_search_code on mismatch re-entry', () => {
    const idx = driverTs.indexOf("current.currentState === 'DIAGNOSE_BUG'")
    const block = driverTs.slice(idx, idx + 600)
    expect(block).toContain('_action_search_code: null')
  })

  it('clears _action_check_existing_prs on mismatch re-entry', () => {
    const idx = driverTs.indexOf("current.currentState === 'DIAGNOSE_BUG'")
    const block = driverTs.slice(idx, idx + 600)
    expect(block).toContain('_action_check_existing_prs: null')
  })
})

// ── IMPLEMENT_FIX: git history check + scope constraint ──────────────────

describe('IMPLEMENT_FIX prompt — git history check and single-PR scope', () => {
  const prompt: string = workflow.states.IMPLEMENT_FIX.prompt

  it('tells IMPLEMENT_FIX to check out existing reproduce branch, not create new', () => {
    expect(prompt).toContain('already pushed the failing test to this branch')
    expect(prompt).toContain('git checkout fix/')
    expect(prompt).toContain('Do NOT use -b')
  })

  it('includes GIT HISTORY CHECK step before the diagnosis brief', () => {
    expect(prompt).toContain('GIT HISTORY CHECK')
    expect(prompt).toContain('GIT_HISTORY=')
    const historyIdx = prompt.indexOf('GIT HISTORY CHECK')
    const briefIdx = prompt.indexOf('DIAGNOSIS BRIEF')
    expect(historyIdx).toBeLessThan(briefIdx)
  })

  it('instructs delegate to run git log for recent changes', () => {
    expect(prompt).toContain('git log --oneline --since=')
    expect(prompt).toContain('90 days ago')
  })

  it('includes SCOPE CONSTRAINT — ONE BRANCH, ONE PR', () => {
    expect(prompt).toContain('SCOPE CONSTRAINT')
    expect(prompt).toContain('ONE PR')
  })

  it('tells delegate to close extra PRs before returning', () => {
    expect(prompt).toContain('gh pr close')
    expect(prompt).toContain('outside scope')
  })

  it('scope constraint appears before BRANCH AND PR section', () => {
    const scopeIdx = prompt.indexOf('SCOPE CONSTRAINT')
    const branchIdx = prompt.indexOf('BRANCH AND PR')
    expect(scopeIdx).toBeGreaterThan(0)
    expect(scopeIdx).toBeLessThan(branchIdx)
  })
})

// ── VERIFY_DISCOURSE_BATCH: close_extra_prs ───────────────────────────────

describe('VERIFY_DISCOURSE_BATCH — close_extra_prs', () => {
  const state = workflow.states.VERIFY_DISCOURSE_BATCH
  const prompt: string = state.prompt

  it('lists close_extra_prs in writeActions', () => {
    expect(state.writeActions).toContain('close_extra_prs')
  })

  it('calls close_extra_prs with expectedPrNumber and iterationStartTs', () => {
    expect(prompt).toContain('close_extra_prs')
    expect(prompt).toContain('expectedPrNumber')
    expect(prompt).toContain('iterationStartTs')
  })

  it('calls close_extra_prs before adversarial_review_pr', () => {
    const closeIdx = prompt.indexOf('close_extra_prs')
    const reviewIdx = prompt.indexOf('adversarial_review_pr')
    expect(closeIdx).toBeLessThan(reviewIdx)
  })
})

// ── delegate boilerplate: PUSH_VERIFIED requirement ───────────────────────

describe('delegate_to_coder boilerplate — PUSH_VERIFIED marker', () => {
  // There are two boilerplate blocks in actions/index.ts (delegate_to_coder and
  // delegate_parallel_tasks). Both must contain the PUSH_VERIFIED requirement.
  const pushVerifiedSections = actionsTs
    .split('PUSH VERIFICATION')
    .slice(1) // everything after the first occurrence header

  it('appears in both delegate boilerplate blocks', () => {
    const count = (actionsTs.match(/PUSH VERIFICATION/g) ?? []).length
    expect(count).toBe(2)
  })

  it('instructs the delegate to emit PUSH_VERIFIED=<sha>', () => {
    expect(actionsTs).toContain('PUSH_VERIFIED=<sha>')
  })

  it('instructs the delegate to verify via git log origin/<branch>', () => {
    expect(actionsTs).toContain('git -C /home/edward/FreegleDockerWSL log origin/<branch> -1 --format=%H')
  })

  it('instructs the delegate to emit DELEGATE_FAILED= if verification fails', () => {
    expect(actionsTs).toContain('push not verified: local <local_sha> != remote <remote_sha>')
  })

  it('places PUSH VERIFICATION section before OUTPUT MARKERS in both blocks', () => {
    // Find all pairs of PUSH VERIFICATION / OUTPUT MARKERS positions
    const pvPositions: number[] = []
    const omPositions: number[] = []
    let idx = 0
    while ((idx = actionsTs.indexOf('PUSH VERIFICATION', idx)) !== -1) {
      pvPositions.push(idx)
      idx++
    }
    idx = 0
    while ((idx = actionsTs.indexOf('OUTPUT MARKERS', idx)) !== -1) {
      omPositions.push(idx)
      idx++
    }
    // Both PV blocks should precede their corresponding OM block
    expect(pvPositions.length).toBeGreaterThanOrEqual(2)
    expect(omPositions.length).toBeGreaterThanOrEqual(2)
    // First PV < first OM, second PV < second OM
    expect(pvPositions[0]).toBeLessThan(omPositions[0])
    expect(pvPositions[1]).toBeLessThan(omPositions[1])
  })
})
