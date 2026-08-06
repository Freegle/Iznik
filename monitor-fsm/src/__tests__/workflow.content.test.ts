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

  // Use lastIndexOf on the bare condition — the loop-breaker block references
  // `currentState === 'DIAGNOSE_BUG'` earlier in the file, so the *last*
  // occurrence is the re-entry stale-clearing block we want to assert on.
  it('checks diagnosisMismatchReason before clearing', () => {
    const idx = driverTs.lastIndexOf("current.currentState === 'DIAGNOSE_BUG'")
    const block = driverTs.slice(idx, idx + 600)
    expect(block).toContain('diagnosisMismatchReason')
  })

  it('clears _action_search_code on mismatch re-entry', () => {
    const idx = driverTs.lastIndexOf("current.currentState === 'DIAGNOSE_BUG'")
    const block = driverTs.slice(idx, idx + 600)
    expect(block).toContain('_action_search_code: null')
  })

  it('clears _action_check_existing_prs on mismatch re-entry', () => {
    const idx = driverTs.lastIndexOf("current.currentState === 'DIAGNOSE_BUG'")
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

  it('calls close_extra_prs after adversarial_review_pr (batch design)', () => {
    // In the parallel batch flow, each successful bug's PR is created and
    // adversarially reviewed per-bug, THEN close_extra_prs runs ONCE at the end
    // with the full set of kept PR numbers (expectedPrNumbers). It must therefore
    // come AFTER the per-bug review, not before.
    const closeIdx = prompt.indexOf('close_extra_prs')
    const reviewIdx = prompt.indexOf('adversarial_review_pr')
    expect(reviewIdx).toBeGreaterThan(0)
    expect(closeIdx).toBeGreaterThan(reviewIdx)
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
    expect(actionsTs).toContain('git log origin/<branch> -1 --format=%H')
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

// ── Topic discovery must not lose slow threads ────────────────────────────
//
// discover_active_topics is the ONLY thing that decides which Discourse topics
// get a triage delegate (workflow.json lists it, and PARALLEL_FIX_BUGS builds one
// task per topic with hasNew). It used to fetch a single page of /latest.json, so a
// topic that was posted to and then slipped below the top ~30 by activity before the
// next run was never fetched, its cursor never advanced, and the member's report was
// dropped silently. Seen live: topic 9481 ("Testing please") sat at cursor 630 while
// members posted up to 635.

describe('discover_active_topics — paginated activity scan', () => {
  it('walks several pages of /latest.json rather than one', () => {
    expect(actionsTs).toContain('latest.json?order=activity&page=')
    expect(actionsTs).toContain('for page in range(')
  })

  it('no longer relies on a single per_page fetch for discovery', () => {
    // Scoped to this action's own body: the unused fetch_new_posts action further up
    // the file still has the old single-page fetch. It is dead code (nothing in
    // workflow.json or driver.ts calls it), so it cannot drop reports - but it does
    // still contain the string, so a whole-file assertion here would be misleading.
    const start = actionsTs.indexOf("name: 'discover_active_topics'")
    expect(start).toBeGreaterThan(-1)
    const next = actionsTs.indexOf("name: '", start + 40)
    const body = actionsTs.slice(start, next === -1 ? undefined : next)
    expect(body).not.toContain('per_page=')
    expect(body).toContain('latest.json?order=activity&page=')
  })

  it('exposes the page count as a tunable parameter', () => {
    expect(actionsTs).toContain('latestPages')
  })

  it('de-dupes topics seen on more than one page', () => {
    expect(actionsTs).toContain('seen[t[\'id\']]')
  })

  it('stops early when a page comes back empty', () => {
    expect(actionsTs).toMatch(/if not batch:\s*\n\s*break/)
  })
})

// ── Fix agents must look for earlier attempts at the same bug ─────────────
//
// The monitor's own pr table only records PRs IT opened, so a fix that a human
// wrote and closed is invisible to it. messages.heldby was consequently "fixed"
// five times across topics 9904 and 9970 without the real cause being addressed.

describe('PARALLEL_FIX_BUGS prompt — prior-attempts guard', () => {
  const prompt: string = workflow.states.PARALLEL_FIX_BUGS.prompt

  it('has a prior-attempts step', () => {
    expect(prompt).toContain('PRIOR-ATTEMPTS CHECK')
  })

  it('runs before the diagnose step, not after', () => {
    expect(prompt.indexOf('PRIOR-ATTEMPTS CHECK')).toBeLessThan(
      prompt.indexOf('STEP 2 — DIAGNOSE')
    )
  })

  it('searches GitHub for closed PRs, not just the monitor DB', () => {
    expect(prompt).toContain('gh pr list --repo Freegle/Iznik --state all')
  })

  it('treats a closed-unmerged prior attempt as the signal to read', () => {
    expect(prompt).toContain('CLOSED-but-NOT-MERGED')
  })

  it('requires the PR description to record what it found either way', () => {
    expect(prompt).toContain('No prior attempt found for')
    expect(prompt).toContain('Prior attempt(s): #')
  })

  it('tells the agent a repeated attempt means widening past the reported surface', () => {
    expect(prompt).toContain('Laravel batch jobs')
  })
})

describe('PARALLEL_ANALYZE_AND_FIX prompt — the PR fix delegate gets one turn', () => {
  const prompt: string = workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt

  // A delegate once did the whole diagnosis, kicked the Laravel and Go suites
  // off in the background, then ended its turn to "wait for the notifications".
  // A `claude -p` one-shot has no next turn, so it exited having pushed
  // nothing — which cost the focus PR an attempt and, before the router fix,
  // starved Discourse triage for the rest of the iteration.
  it('tells the delegate it has exactly one turn', () => {
    expect(prompt).toContain('YOU GET EXACTLY ONE TURN')
  })

  it('forbids ending the turn while background work is pending', () => {
    expect(prompt).toContain('never end your turn while anything is pending')
  })

  it('requires blocking on background commands within the same turn', () => {
    expect(prompt).toContain('block until it finishes inside this same turn')
  })

  it('makes pushing before finishing the explicit bar', () => {
    expect(prompt).toContain('PUSH BEFORE YOU FINISH')
  })

  it('warns that a run without a push counts as a failed attempt', () => {
    expect(prompt).toContain('counts as a failed attempt')
  })
})

describe('driver.ts — red-CI invariant tracks the router skip rule', () => {
  const driverTs = readFileSync(join(__dirname, '../driver.ts'), 'utf8')

  // The hard invariant drags any red PR back to CHECK_CI. Its suppression set
  // must match ci_router_decide's skip rule, or the two oscillate: the router
  // skips a PR that pushed nothing, the instance goes past the gate, the
  // invariant re-adds it, the router skips it again — until the step cap.
  it('suppresses PRs whose attempt pushed nothing, not just terminal records', () => {
    const idx = driverTs.indexOf('const attemptsRed')
    expect(idx).toBeGreaterThan(-1)
    const block = driverTs.slice(idx, idx + 700)
    expect(block).toContain('a.terminal || a.pushed !== true')
  })

  it('feeds that skip set into the red-PR check', () => {
    expect(driverTs).toContain('realRedPRCheck(skipSet)')
  })
})

describe('actions.ts — ci_router_decide budget and skip rules', () => {
  it('drops a PR from re-picking once its attempt pushed nothing', () => {
    const idx = actionsTs.indexOf('const attemptedNums')
    expect(idx).toBeGreaterThan(-1)
    const block = actionsTs.slice(idx, idx + 300)
    expect(block).toContain('a.terminal || a.pushed !== true')
  })

  it('charges the fix budget per dispatch rather than per router visit', () => {
    expect(actionsTs).toContain('pr_fix_dispatched_')
    expect(actionsTs).toContain('already dispatched this iteration')
  })

  it('clears the dispatch marker when a PR goes green', () => {
    const idx = actionsTs.indexOf('is green — reset fix attempt counter')
    expect(idx).toBeGreaterThan(-1)
    expect(actionsTs.slice(idx - 400, idx)).toContain('pr_fix_dispatched_')
  })
})
