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

  it('still requires ANALYSIS_COMPLETE=reproduction done', () => {
    expect(prompt).toContain('ANALYSIS_COMPLETE=reproduction done')
  })

  it('passes affected_function from diagnosisBrief into the task', () => {
    expect(prompt).toContain('diagnosisBrief.affected_function')
  })

  it('does not instruct delegate to fix the bug', () => {
    expect(prompt).toContain('Do NOT fix the bug')
  })
})

// ── DIAGNOSE_BUG: symbol-level localization ───────────────────────────────

describe('DIAGNOSE_BUG prompt — symbol-level localization', () => {
  const prompt: string = workflow.states.DIAGNOSE_BUG.prompt

  it('includes SYMBOL-LEVEL LOCALIZATION heading in step 3', () => {
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

  it('still requires check_existing_prs call in step 2', () => {
    expect(prompt).toContain('check_existing_prs')
  })

  it('still requires multi-hypothesis step', () => {
    expect(prompt).toContain('MULTI-HYPOTHESIS STEP')
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
