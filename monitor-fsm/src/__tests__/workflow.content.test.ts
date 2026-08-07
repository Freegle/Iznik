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
  // skips a PR already attempted this iteration, the instance goes past the
  // gate, the invariant re-adds it, the router skips it again — to the cap.
  it('suppresses every PR already attempted this iteration', () => {
    const idx = driverTs.indexOf('const attemptsRed')
    expect(idx).toBeGreaterThan(-1)
    const block = driverTs.slice(idx, idx + 700)
    expect(block).toContain('attemptsRed.map(a => a.prNumber)')
  })

  it('feeds that skip set into the red-PR check', () => {
    expect(driverTs).toContain('realRedPRCheck(skipSet)')
  })
})

describe('actions.ts — ci_router_decide budget and skip rules', () => {
  it('drops a PR from re-picking once it has been attempted this iteration', () => {
    const idx = actionsTs.indexOf('const attemptedNums')
    expect(idx).toBeGreaterThan(-1)
    const block = actionsTs.slice(idx, idx + 300)
    expect(block).toContain('attempts.map(a => a.prNumber)')
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

describe('PARALLEL_ANALYZE_AND_FIX prompt — triage must emit the post number', () => {
  const prompt: string = workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt

  // persist_classifications keys discourse_bug on (topic, post) and silently
  // skips anything missing either. On 2026-08-06 the triage delegate returned
  // Michael's ModTools report with no post field, so WORK_ROUTER dispatched
  // "10010.undefined" and the bug was never recorded.
  it('requires the post number as a classification field', () => {
    const idx = prompt.indexOf('STEP 6. Classify each fetched post')
    expect(idx).toBeGreaterThan(-1)
    const block = prompt.slice(idx, idx + 700)
    expect(block).toContain('- post:')
    expect(block).toContain('post_stream.posts[].post_number')
  })

  it('requires the topic id too', () => {
    const idx = prompt.indexOf('STEP 6. Classify each fetched post')
    expect(prompt.slice(idx, idx + 700)).toContain('- topic:')
  })

  it('spells out that a classification without it is discarded', () => {
    const idx = prompt.indexOf('STEP 6. Classify each fetched post')
    expect(prompt.slice(idx, idx + 700)).toContain('DISCARDED')
  })
})

describe('driver.ts — PARSE_ONLY stops before fixing states', () => {
  const driverTs = readFileSync(join(__dirname, '../driver.ts'), 'utf8')

  // PARSE_ONLY=1 is meant to end after WORK_ROUTER. On 2026-08-06 a PARSE_ONLY
  // run went on to enter PARALLEL_FIX_BUGS and launch three real fix delegates,
  // because the guard only inspected currentState while WORK_ROUTER's action
  // had returned _transition PARALLEL_FIX_BUGS.
  it('considers transitions the step proposed, not just currentState', () => {
    const idx = driverTs.indexOf('const proposedNext')
    expect(idx).toBeGreaterThan(-1)
    const block = driverTs.slice(idx, idx + 500)
    expect(block).toContain('_transition')
    expect(block).toContain('candidateStates')
  })

  it('stops on any candidate state outside the analysis set', () => {
    expect(driverTs).toContain('const offender = candidateStates.find(s => !ANALYSIS_STATES.has(s))')
  })

  it('still bypasses FIX_MASTER_CI rather than stopping on it', () => {
    expect(driverTs).toContain("candidateStates.includes('FIX_MASTER_CI')")
  })
})

describe('PARALLEL_ANALYZE_AND_FIX prompt — Discourse auth header', () => {
  const prompt: string = workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt

  // Verified live 2026-08-06: `Api-Key` alone returns 200, while
  // `User-Api-Key` + `Api-Username` returns 403. Six of twenty-one triage
  // delegates lost their topic entirely to that 403.
  it('uses the Api-Key header for topic fetches', () => {
    expect(prompt).toContain('curl -s -H "Api-Key: <key>"')
  })

  it('never tells a delegate to send the rejected User-Api-Key pair', () => {
    expect(prompt).not.toContain('-H "User-Api-Key')
    expect(prompt).not.toContain('Api-Username: Edward_Hibbert')
  })

  it('warns at the key-fetch step that the pair is rejected', () => {
    expect(prompt).toContain('REJECTED with HTTP 403')
  })
})

describe('delegate boilerplate — never write into the human main checkout', () => {
  // A coverage delegate did its work correctly in its isolated worktree, read
  // "the worktree is deleted when you return, so anything not pushed is lost",
  // and preserved it by copying the file into /home/edward/FreegleDockerWSL —
  // where it sat untracked on someone else's branch, failing their Laravel run
  // (2026-08-07, PafServiceTest.php). A rational response to the warning, so
  // the warning has to close it off explicitly.
  it('forbids writing into the main checkout, in every worktree preamble', () => {
    const preambles = actionsTs.split('ISOLATED git worktree').slice(1)
    expect(preambles.length).toBeGreaterThan(0)
    for (const p of preambles) {
      const block = p.slice(0, 1400)
      expect(block).toContain('NEVER write, copy or move anything into /home/edward/FreegleDockerWSL')
    }
  })

  it('says pushing is the only way to keep work', () => {
    expect(actionsTs).toContain('PUSHING is how you keep work, and it is the ONLY way')
  })

  it('makes losing unpushed work the intended outcome, not one to route around', () => {
    expect(actionsTs).toContain('that is the intended outcome, not a problem to route around')
  })
})

describe('PARALLEL_ANALYZE_AND_FIX — questions are answered alongside the fixing', () => {
  const prompt: string = workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt

  // Answering a question opens no PR and puts nothing on the CI runner, so it
  // competes with nothing. Routing it after bugs and Sentry (as WORK_ROUTER
  // alone did) left someone waiting on a fix with no bearing on their question.
  it('dispatches one task per unanswered question in the same batch', () => {
    expect(prompt).toContain("(D) One task per unanswered question (id: 'question-<topic>-<post>')")
    expect(prompt).toContain('_action_list_unanswered_questions.questions')
  })

  it('reads the unanswered questions in this state', () => {
    expect(workflow.states.PARALLEL_ANALYZE_AND_FIX.readActions).toContain('list_unanswered_questions')
  })

  it('exempts question answers from the onlyFixPR gate', () => {
    const idx = prompt.indexOf('CRITICAL GATE')
    const gate = prompt.slice(idx, idx + 1200)
    expect(gate).toContain('AND section (D)')
    expect(gate).toContain('Section (D) is exempt')
    // The gate must still skip triage and Sentry — those DO create PRs.
    expect(gate).toContain('SKIP sections (B) and (C) entirely')
  })

  it('still tells the delegate not to write its own caveat', () => {
    expect(prompt).toContain('Do NOT write a caveat or an apology — one is appended automatically')
  })
})

describe('COLLATE_RESULTS — question answers become approval drafts', () => {
  const prompt: string = workflow.states.COLLATE_RESULTS.prompt

  it('handles question- results', () => {
    expect(prompt).toContain("id starts with 'question-'")
    expect(prompt).toContain('queue_question_reply_drafts')
  })

  it('can call the queueing action from this state', () => {
    expect(workflow.states.COLLATE_RESULTS.writeActions).toContain('queue_question_reply_drafts')
  })

  it('insists each answer is mapped back to its own question', () => {
    expect(prompt).toContain('never guess which answer belongs to which question')
  })

  it('keeps posting human-gated', () => {
    expect(prompt).toContain('never post to Discourse yourself')
  })
})

describe('postDiscourseReply — a test run can never reach the live forum', () => {
  // Test fixtures use small integer topic ids, and small integers are REAL
  // topics. On 2026-08-07 a suite run posted an answer onto a seven-year-old
  // thread (topic 523, "How do I block a member") where real volunteers saw it.
  // Fixtures have moved out of that range, but that only protects fixtures
  // someone remembered to move. The guard stops the whole class.
  it('refuses to post when running under a test runner', () => {
    const idx = actionsTs.indexOf('export async function postDiscourseReply')
    expect(idx).toBeGreaterThan(-1)
    const body = actionsTs.slice(idx, idx + 3000)
    expect(body).toContain("process.env.VITEST || process.env.NODE_ENV === 'test'")
    expect(body).toContain('refusing to post to Discourse from a test run')
  })

  // The only permitted bypass is for the tests that exercise this function's own
  // retry handling against a stubbed fetch, where nothing leaves the machine.
  it('allows exactly one opt-out, and only for a stubbed fetch', () => {
    expect(actionsTs).toContain('allowInTests?: boolean')
    expect(actionsTs).toContain('!opts.allowInTests &&')
    // Nothing outside the retry test may use it.
    const users = actionsTs.split('allowInTests').length - 1
    expect(users).toBeLessThanOrEqual(3) // type, doc comment, and the guard
  })

  it('checks that BEFORE anything that could reach the network', () => {
    const idx = actionsTs.indexOf('export async function postDiscourseReply')
    const body = actionsTs.slice(idx, idx + 3000)
    const guard = body.indexOf('refusing to post to Discourse from a test run')
    const fetchAt = body.indexOf('fetch(')
    expect(guard).toBeGreaterThan(-1)
    if (fetchAt > -1) expect(guard).toBeLessThan(fetchAt)
  })
})

describe('question delegates — do not repeat an answer someone already gave', () => {
  // Of the first three answers drafted for real, one was already answered
  // correctly by a volunteer (and the asker had said thanks) and another
  // duplicated its sibling in the same thread. Posting all three unattended
  // would have been noise, and repeating a volunteer implies their answer was
  // not trusted. Both prompts that dispatch question work carry the check.
  const prompts = [
    workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt,
    workflow.states.ANSWER_QUESTIONS.prompt,
  ]

  it('tells the delegate to read the later replies first', () => {
    for (const p of prompts) {
      expect(p).toContain('Check whether someone has ALREADY answered it')
      expect(p).toContain('Read every reply that comes after the question')
    }
  })

  it('says stay quiet when it is already answered', () => {
    for (const p of prompts) {
      expect(p).toContain('Output ANSWER= with nothing after it and stop')
    }
  })

  it('permits a reply only when the existing answer is wrong or incomplete', () => {
    for (const p of prompts) {
      expect(p).toContain('WRONG or INCOMPLETE')
    }
  })

  it('forbids naming anyone as wrong, and asks for credit where due', () => {
    for (const p of prompts) {
      expect(p).toContain('never name them as wrong')
      expect(p).toContain('credit whoever got it right')
    }
  })
})

describe('question reply caveat — says up front that it is an AI', () => {
  const src = readFileSync(join(__dirname, '../question-reply.ts'), 'utf8')

  it('opens by identifying itself as an AI response', () => {
    expect(src).toContain("This is an AI response - so it may have got the wrong end of the stick")
  })

  it('still detects its own caveat so it is never doubled up', () => {
    expect(src).toContain("t.includes('this is an ai response')")
  })
})

describe('already-answered check counts our own earlier reply', () => {
  // The first live run of this got it wrong: the rule said "answered by someone
  // ELSE", so a delegate looked at a thread we had answered an hour earlier,
  // decided that did not count, and posted a near-identical second reply.
  const prompts = [
    workflow.states.PARALLEL_ANALYZE_AND_FIX.prompt,
    workflow.states.ANSWER_QUESTIONS.prompt,
  ]

  it('counts any answer already in the thread, including its own', () => {
    for (const p of prompts) {
      expect(p).toContain('INCLUDING an earlier reply posted by you')
      expect(p).not.toContain('completely by someone else?')
    }
  })

  it('says two near-identical AI replies are worse than none', () => {
    for (const p of prompts) {
      expect(p).toContain('two near-identical AI replies in it is worse than one with none')
    }
  })

  it('requires reading the whole thread, not just the question', () => {
    for (const p of prompts) {
      expect(p).toContain('Read the WHOLE thread before deciding')
    }
  })
})
