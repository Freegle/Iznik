/**
 * monitor-fsm driver — runs the freegle-monitor FSM using ai-flower.
 *
 * The host-side enforcement loop. Each processInput call is validated by the
 * TransitionValidator (structural — does the transition exist?), but the
 * substantive "must have a real PR to pass the gate" check is enforced HERE.
 *
 * Algorithm:
 *   1. Load workflow.json, create engine with ClaudeCodeAdapter + actions.
 *   2. Create an instance.
 *   3. Loop: while instance.status === 'active', call processInput with a
 *      tick input. After each call, run the gate check. If the LLM transitioned
 *      into WRAP_UP (or anything past COVERAGE_GATE) without a real PR, force
 *      the instance back to COVERAGE_GATE.
 *   4. Stop when status === 'completed' or hard step-cap is reached.
 */

// Silence the misleading ClaudeCodeAdapter warning before its module is loaded.
// The warning reads like a fatal ("This adapter requires running from within a
// Claude Code session"), but in practice the adapter authenticates through the
// local `claude` CLI (subscription auth) and works fine standalone. The adapter
// only checks `process.env.CLAUDECODE` at construction to decide whether to
// print the warning — setting it here suppresses the warning without changing
// behaviour. Must be set BEFORE the adapter import.
if (!process.env.CLAUDECODE) process.env.CLAUDECODE = '1'

import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'

import { out, outWarn, dbg, truncate, startGroup, endGroup, summarizeActionResult, summarizeReasoning, humanizeState, humanizeAction } from './log.js'
import { getPhaseInfo, modelForBrain, modelForDelegate } from './phase.js'

import {
  WorkflowEngine,
  JSONFileStorage,
  type LLMAdapter,
  type WorkflowDefinition,
  type WorkflowInstance,
} from 'ai-flower'
import { ClaudeCodeAdapter } from 'ai-flower/adapters/claude-code'

import { actions } from './actions/index.js'
import { getDb, startIteration, endIteration } from './db/index.js'
import { renderAllViews } from './db/views.js'
import { putStatusPost } from './db/discourse-status.js'

const exec = promisify(execFile)

const __dirname = dirname(fileURLToPath(import.meta.url))
const WORKFLOW_PATH = resolve(__dirname, '../workflow.json')
const INSTANCE_STORE = resolve(__dirname, '../instance-store.json')

const MAX_STEPS = 40 // hard cap; real iterations should settle in ~15-25

const STATES_PAST_GATE = new Set(['WRAP_UP', 'SEND_EMAIL', 'SCHEDULE_NEXT', 'END'])

interface GateResult {
  passed: boolean
  count: number
  prs: Array<{ number: number; title: string; createdAt: string }>
  checkedSince: string
}

async function realGateCheck(iterationStartTs: string): Promise<GateResult> {
  try {
    const sinceIso = iterationStartTs
    const since = new Date(sinceIso)

    // Path A: new PRs created since iterationStartTs (Discourse fixes, coverage PRs, Sentry PRs).
    const { stdout } = await exec('gh', [
      'pr', 'list',
      '--repo', 'Freegle/Iznik',
      '--author', '@me',
      '--state', 'all',
      '--limit', '50',
      '--json', 'number,title,createdAt',
    ], { maxBuffer: 10 * 1024 * 1024 })
    const all = JSON.parse(stdout) as Array<{ number: number; title: string; createdAt: string }>
    const recent = all.filter(p => new Date(p.createdAt) >= since)
    if (recent.length > 0) {
      return { passed: true, count: recent.length, prs: recent, checkedSince: sinceIso }
    }

    // Path B: new commits pushed to existing open @me PRs since iterationStartTs
    // (FIX_OPEN_PR_CI route — fixing CI on an existing PR is productive work too).
    try {
      const { stdout: openOut } = await exec('gh', [
        'pr', 'list',
        '--repo', 'Freegle/Iznik',
        '--author', '@me',
        '--state', 'open',
        '--limit', '30',
        '--json', 'number,title,updatedAt,headRefName',
      ], { maxBuffer: 10 * 1024 * 1024 })
      const open = JSON.parse(openOut) as Array<{ number: number; title: string; updatedAt: string; headRefName: string }>
      const updated = open.filter(p => new Date(p.updatedAt) >= since)
      if (updated.length > 0) {
        return {
          passed: true,
          count: updated.length,
          prs: updated.map(p => ({ number: p.number, title: `(commit pushed) ${p.title}`, createdAt: p.updatedAt })),
          checkedSince: sinceIso,
        }
      }
    } catch (err: any) {
      console.error('[gate] open-PR updatedAt check failed:', err.message)
    }

    // Path C: new commits on master since iterationStartTs authored by @me
    // (FIX_MASTER_CI route — direct master pushes to unblock CI).
    try {
      const { stdout: logOut } = await exec('git', [
        '-C', '/home/edward/FreegleDockerWSL',
        'log', 'master',
        '--author', 'edwh',
        '--since', sinceIso,
        '--pretty=format:%H|%s|%aI',
      ], { maxBuffer: 10 * 1024 * 1024 })
      const lines = logOut.split('\n').map(l => l.trim()).filter(Boolean)
      if (lines.length > 0) {
        const commits = lines.map(l => {
          const [sha, subject, iso] = l.split('|')
          return { number: 0, title: `(master push) ${sha.slice(0, 9)} ${subject ?? ''}`, createdAt: iso ?? sinceIso }
        })
        return { passed: true, count: commits.length, prs: commits, checkedSince: sinceIso }
      }
    } catch (err: any) {
      console.error('[gate] master-log check failed:', err.message)
    }

    return { passed: false, count: 0, prs: [], checkedSince: sinceIso }
  } catch (err: any) {
    console.error('[gate] real check failed:', err.message)
    return { passed: false, count: 0, prs: [], checkedSince: iterationStartTs }
  }
}

interface RedPRCheck {
  redPRs: Array<{ number: number; title: string; url: string; failedChecks: Array<{ context: string; state: string; url: string }> }>
}

/**
 * Independent host-side verification that no PR I authored has red CI.
 *
 * The FSM already has the check_my_open_pr_ci action and FIX_OPEN_PR_CI state, but
 * the LLM could still propose WRAP_UP in a moment of hallucination. This function
 * is the belt-and-braces: called every step while the instance is past the gate,
 * it force-transitions back to ROUTER whenever any redPR exists. The LLM cannot
 * escape this — there's no prompt to persuade.
 *
 * Pass `terminalPRNumbers` to exclude PRs the FSM has given up on (loop-breaker
 * terminal records). Without this, the hard-invariant ping-pongs with ROUTER:
 * ROUTER sees terminal PR and skips it → past the gate → hard-invariant re-adds it →
 * ROUTER skips it again → infinite oscillation.
 */
async function realRedPRCheck(terminalPRNumbers: Set<number> = new Set()): Promise<RedPRCheck> {
  try {
    const { stdout: listOut } = await exec('gh', [
      'pr', 'list',
      '--repo', 'Freegle/Iznik',
      '--author', '@me',
      '--state', 'open',
      '--limit', '30',
      '--json', 'number,title,url',
    ], { maxBuffer: 10 * 1024 * 1024 })
    const rawPRs = JSON.parse(listOut) as Array<{ number: number; title: string; url: string }>
    const prs = rawPRs.filter(p => !terminalPRNumbers.has(p.number))
    const redPRs: RedPRCheck['redPRs'] = []
    for (const pr of prs) {
      // `gh pr checks` uses exit code as a SIGNAL: 0=all green, 1=has failures,
      // 2/8=pending. node's execFile rejects on any non-zero — but err.stdout
      // is still populated. We MUST parse stdout in both success AND failure
      // cases; treating non-zero as "command failed → no red" was silently
      // masking genuine red CI (coveralls fail, CircleCI fail, etc.) and
      // letting the iteration reach END past the red-pr guard.
      let chkOut = ''
      let parseable = true
      try {
        const ok = await exec('gh', ['pr', 'checks', String(pr.number), '--repo', 'Freegle/Iznik'], { maxBuffer: 10 * 1024 * 1024 })
        chkOut = ok.stdout
      } catch (err: any) {
        if (typeof err?.stdout === 'string' && err.stdout.length > 0) {
          // Expected path for a red or pending PR — use the stdout we got.
          chkOut = err.stdout
        } else {
          // Genuine transport error (auth, network, rate-limit). Do NOT
          // silently treat as "no red" — that's the exact failure mode we're
          // fixing. Surface as an explicit red entry so the guard routes back
          // to CHECK_CI / ROUTER and the human sees the problem.
          console.error(`[red-pr] gh pr checks ${pr.number} transport error — treating as RED (fail-closed):`, err.message ?? err)
          redPRs.push({
            number: pr.number,
            title: pr.title,
            url: pr.url,
            failedChecks: [{ context: 'gh-pr-checks-error', state: 'error', url: '' }],
          })
          parseable = false
        }
      }
      if (!parseable) continue
      const failed: Array<{ context: string; state: string; url: string }> = []
      for (const rawLine of chkOut.split('\n')) {
        const line = rawLine.trim()
        if (!line) continue
        const cols = line.split('\t')
        const name = cols[0] ?? ''
        const state = (cols[1] ?? '').toLowerCase()
        const url = cols[3] ?? ''
        if (/pages.?changed|header rules|redirect rules/i.test(name) && /skipping/i.test(state)) continue
        if (/^(fail|failure|cancelled|canceled|timed.?out|error)$/.test(state)) {
          failed.push({ context: name, state, url })
        }
      }
      if (failed.length > 0) redPRs.push({ number: pr.number, title: pr.title, url: pr.url, failedChecks: failed })
    }
    return { redPRs }
  } catch (err: any) {
    console.error('[red-pr] list failed:', err.message)
    return { redPRs: [] }
  }
}

/**
 * Repair common Claude JSON shape errors before ai-flower validates.
 *
 * Seen in the wild:
 *   - `contextUpdates: "{...}"` (stringified object) — validator wants object
 *   - `actions: "[{...}]"` (stringified array) — validator wants array
 *   - leading/trailing markdown fences (ai-flower strips these, but only the
 *     first and last — if Claude wraps in ```json ... ``` twice it breaks)
 *
 * We only rewrite fields we're confident about. If parsing fails at any point,
 * return the input unchanged so ai-flower's own error surfaces.
 */
function sanitizeLLMDecision(raw: string): string {
  const cleaned = raw
    .replace(/^```(?:json)?\s*/m, '')
    .replace(/\s*```\s*$/m, '')
    .trim()
  let parsed: any
  try {
    parsed = JSON.parse(cleaned)
  } catch {
    return raw
  }
  if (typeof parsed !== 'object' || parsed === null) return raw

  let changed = false
  if (typeof parsed.contextUpdates === 'string') {
    try {
      const inner = JSON.parse(parsed.contextUpdates)
      if (typeof inner === 'object' && inner !== null) {
        parsed.contextUpdates = inner
        changed = true
      }
    } catch { /* leave as-is, validator will reject */ }
  }
  if (parsed.contextUpdates === undefined || parsed.contextUpdates === null) {
    parsed.contextUpdates = {}
    changed = true
  }
  if (typeof parsed.actions === 'string') {
    try {
      const inner = JSON.parse(parsed.actions)
      if (Array.isArray(inner)) {
        parsed.actions = inner
        changed = true
      }
    } catch { /* leave */ }
  }
  if (parsed.actions === undefined) {
    parsed.actions = []
    changed = true
  }

  return changed ? JSON.stringify(parsed) : raw
}

function logInstance(i: WorkflowInstance, note: string) {
  // Instance state changes at every tick — too noisy for screen. Debug log only.
  dbg(`${note} state=${i.currentState} status=${i.status} history=${i.history.length}`)
}

async function main() {
  const definition = JSON.parse(await readFile(WORKFLOW_PATH, 'utf8')) as WorkflowDefinition

  const storage = new JSONFileStorage(INSTANCE_STORE)
  await storage.saveWorkflow(definition)

  // Decide phase ONCE at iteration start so the brain and delegate agree.
  // Re-deciding mid-iteration would let a 08:00 rollover swap models halfway
  // through a FIX_OPEN_PR_CI and surprise the delegate.
  const phaseInfo = getPhaseInfo()
  out(`phase: ${phaseInfo.phase.toUpperCase()} (${phaseInfo.reason})`)
  out(`model: brain=${modelForBrain(phaseInfo)} delegate=${modelForDelegate(phaseInfo)}`)
  // Stash on env so nested subprocesses (delegate action) pick up the same
  // decision without us having to thread it through every action handler.
  process.env.MONITOR_ACTIVE_PHASE = phaseInfo.phase
  process.env.MONITOR_ACTIVE_DELEGATE_MODEL = modelForDelegate(phaseInfo)
  process.env.MONITOR_ACTIVE_BRAIN_MODEL = modelForBrain(phaseInfo)

  const innerAdapter = new ClaudeCodeAdapter({ maxTokens: 8192, model: modelForBrain(phaseInfo) })
  // Retry-aware wrapper. ai-flower retries on validation failure by calling us
  // again with the same (system, user) pair. We detect that repetition and
  // prepend an escalating JSON-only preamble with a worked example. The
  // observed failure mode is the LLM replying in prose ("Looking at the…");
  // this steers it back to the envelope. Sanitization still runs for
  // stringified-field repairs.
  let lastPromptKey = ''
  let attempt = 0
  const llmAdapter: LLMAdapter = {
    async call(system, user) {
      const key = `${system}\u0000${user}`
      if (key === lastPromptKey) {
        attempt++
      } else {
        attempt = 0
        lastPromptKey = key
      }

      let effectiveUser = user
      if (attempt >= 1) {
        const example = JSON.stringify({
          reasoning: 'short explanation of your decision',
          contextUpdates: {},
          actions: [],
          proposedTransition: 'TARGET_STATE',
        }, null, 2)
        const preamble =
          `Your previous reply was not valid JSON. Return EXACTLY one JSON ` +
          `object with these four keys and nothing else — no prose before or ` +
          `after, no markdown code fences, no JSON-stringified fields. ` +
          `Example shape:\n\n${example}\n\n---\n\n`
        effectiveUser = preamble + user
        out(`llm retry (attempt ${attempt + 1}) — re-prompting for valid JSON`)
      }

      const raw = await innerAdapter.call(system, effectiveUser)
      dbg(`[llm raw attempt=${attempt + 1}] ${raw.length} chars:\n${raw}\n[/llm raw]`)
      // Claude subscription quota exhausted — the adapter returns the literal
      // limit message as a short plain-English string. Without explicit
      // detection, it fails JSON parsing, the driver force-transitions to
      // COVERAGE_GATE, and the *next* step re-hits the same quota, force-
      // transitioning again, burning the entire remaining step budget. Throw
      // a distinct error so the step loop can abort immediately.
      if (/you['’]ve hit your (?:usage )?limit/i.test(raw.trim())) {
        throw new Error(`CLAUDE_QUOTA_EXHAUSTED: ${raw.trim().slice(0, 200)}`)
      }
      const repaired = sanitizeLLMDecision(raw)
      if (repaired !== raw) {
        dbg('llm sanitize repaired stringified fields in LLM output')
      }
      return repaired
    },
  }

  const engine = new WorkflowEngine({
    workflow: definition,
    storageAdapter: storage,
    llmAdapter,
    actions,
    maxLLMRetries: 2,
  })

  // Fresh instance per driver run — iterations should not resume.
  const iterationStartTs = new Date().toISOString()
  const instance = await engine.createInstance({ iterationStartTs, phase: phaseInfo.phase })
  out(`starting iteration ${instance.id.slice(0, 8)} at ${iterationStartTs}`)
  logInstance(instance, 'start')

  // Open a DB iteration row so every run is logged with start/end/outcome.
  const db = getDb()
  const iterationId = startIteration(db, iterationStartTs)
  dbg(`iteration db id=${iterationId}`)

  // Track which Discourse bugs have been picked as currentBug this iteration.
  // If the same (topic, post) pair is picked twice, the iteration is looping:
  // VERIFY must have failed to append a deferred entry to bugsFixed. We
  // force-defer the duplicate to break the loop, regardless of what the LLM
  // does. Key is `${topic}.${post}`.
  const pickedBugs = new Set<string>()

  // Track how many times FIX_OPEN_PR_CI has been entered per-PR this iteration.
  // A subagent that doesn't push a commit leaves the PR "red with no new commit",
  // which ROUTER interprets as "keep trying". Without a cap, this loops forever.
  // After 2 entries with no commit, we force-add an "effective" openPRFixAttempt
  // record that ROUTER's rule skips (distinct number already in openPRFixAttempts).
  const openPRFixEntries = new Map<number, number>()

  let step = 0
  while (step < MAX_STEPS) {
    const current = await engine.getInstance(instance.id)
    if (current.status === 'completed') {
      out('iteration complete')
      break
    }
    if (current.status !== 'active') {
      out(`instance status=${current.status} — stopping`)
      break
    }

    step++
    // Flag the step header with whether it costs an LLM call. Tool / start-with-
    // readActions → deterministic code, no tokens. Agent → LLM decides what to
    // call next and usually costs one call; write-action agent states (DELEGATE_*,
    // FIX_*, WRITE_COVERAGE) ALSO spawn a delegate subprocess that itself burns
    // tokens on top of the FSM brain call.
    const headerStateDef: any = (definition.states as any)[current.currentState]
    const headerIsTool = headerStateDef?.nodeType === 'tool'
      || (headerStateDef?.nodeType === 'start' && Array.isArray(headerStateDef?.readActions) && headerStateDef.readActions.length > 0)
    const headerTag = headerIsTool ? '[tool]' : '[LLM]'
    startGroup(`→ step ${step}: ${humanizeState(current.currentState)} ${headerTag}`)
    try {

    // ─── TOOL NODE FAST-PATH ───
    // ai-flower declares 'tool' as a NodeType but the engine doesn't implement
    // it — we do it here. Tool nodes are pure data-gather: execute all
    // readActions, stash each result in context as _action_<name>, then
    // force-transition along the single outgoing edge. NO LLM turn.
    //
    // This is the entire point of marking CHECK_CI / LOAD_STATE / FETCH_DISCOURSE
    // etc. as 'tool' — we save one LLM call per state, which on an ~8-state
    // iteration adds up to roughly half the tokens with zero functional change.
    const stateDef: any = (definition.states as any)[current.currentState]
    if (stateDef?.nodeType === 'tool') {
      const readActions: string[] = stateDef.readActions ?? []
      const writeActions: string[] = stateDef.writeActions ?? []
      const toolActions = [...readActions, ...writeActions]
      const outgoing = (definition.transitions ?? []).filter(t => t.from === current.currentState)
      if (outgoing.length === 0) {
        outWarn(`tool node ${current.currentState} has no outgoing transitions — falling back to LLM`)
      } else {
        const ctxUpdates: Record<string, unknown> = {}
        const ctxNow: any = current.context ?? {}
        // Actions read context to get prior results (e.g. fetch_discourse reads
        // _action_load_state). Merge as we go so later actions see earlier ones.
        const runningCtx = { ...ctxNow }
        let branchTarget: string | null = null
        for (const actionName of toolActions) {
          const def = actions.find(a => a.name === actionName)
          if (!def) {
            outWarn(`tool node ${current.currentState}: action ${actionName} not found`)
            continue
          }
          try {
            const res: any = await def.handler({}, runningCtx)
            const key = `_action_${actionName}`
            ctxUpdates[key] = res
            runningCtx[key] = res
            out(`· ${humanizeAction(actionName)} → ${summarizeActionResult(actionName, res)}`)
            dbg(`tool action ${actionName} full result: ${JSON.stringify(res)}`)
            // Branch signal: an action can return `_transition` to steer the
            // state machine along a specific outgoing edge. Used by branching
            // tool nodes (e.g. COVERAGE_GATE → WRAP_UP/WRITE_COVERAGE/CI_ROUTER).
            if (res && typeof res._transition === 'string') {
              branchTarget = res._transition
            }
          } catch (err: any) {
            outWarn(`tool action ${actionName} threw: ${err.message ?? err}`)
          }
        }
        if (Object.keys(ctxUpdates).length > 0) {
          await engine.updateContext(instance.id, ctxUpdates)
        }
        let target: string
        if (branchTarget) {
          const edge = outgoing.find(t => t.to === branchTarget)
          if (!edge) {
            outWarn(`tool node ${current.currentState}: action requested _transition=${branchTarget} but no outgoing edge matches — using first edge`)
            target = outgoing[0].to
          } else {
            target = branchTarget
          }
        } else if (outgoing.length === 1) {
          target = outgoing[0].to
        } else {
          outWarn(`tool node ${current.currentState} has ${outgoing.length} outgoing transitions and no _transition signal — using first edge`)
          target = outgoing[0].to
        }
        await engine.forceTransition(instance.id, target, `Tool node ${current.currentState} auto-executed ${toolActions.length} action(s); → ${target}`)
        // Done — skip the LLM path. The step's try/finally will call endGroup().
        continue
      }
    }

    // ─── STALE ACTION CLEARING ───
    // When entering a new bug's cycle at PICK_DISCOURSE_BUG, clear per-bug
    // action results from the previous bug. Otherwise the VERIFY step for the
    // NEW bug can be confused by a stale successful `_action_create_pr` from
    // the PREVIOUS bug and incorrectly claim success.
    if (current.currentState === 'PICK_DISCOURSE_BUG') {
      const staleKeys = [
        '_action_delegate_to_coder',
        '_action_create_pr',
        '_action_post_discourse_reply_draft',
        '_action_search_code',
      ]
      const ctxNow: any = current.context ?? {}
      const toClear: Record<string, unknown> = {}
      for (const k of staleKeys) {
        if (k in ctxNow) toClear[k] = null
      }
      if (Object.keys(toClear).length > 0) {
        dbg(`clearing stale action keys on PICK_DISCOURSE_BUG entry: ${Object.keys(toClear).join(', ')}`)
        await engine.updateContext(instance.id, toClear)
      }
    }

    // ─── FIX_OPEN_PR_CI loop-breaker ───
    // Cap re-entries per PR. ROUTER's rule "keep trying if no new commit yet"
    // will otherwise loop forever on a subagent that can't push. After 2
    // entries with the same target PR, force-route past this PR by marking
    // the attempt in openPRFixAttempts with a terminal record.
    let fixOpenPRTarget: { number: number } | undefined
    if (current.currentState === 'FIX_OPEN_PR_CI') {
      const ctxNow: any = current.context ?? {}
      const redPRs: Array<{ number: number }> = ctxNow?._action_check_my_open_pr_ci?.redPRs ?? []
      const attempts: Array<{ prNumber: number }> = Array.isArray(ctxNow.openPRFixAttempts) ? ctxNow.openPRFixAttempts : []
      const attempted = new Set(attempts.map(a => a.prNumber))
      const target = redPRs.find(p => !attempted.has(p.number)) ?? redPRs[0]
      fixOpenPRTarget = target
      if (target && typeof target.number === 'number') {
        const prev = openPRFixEntries.get(target.number) ?? 0
        const nextCount = prev + 1
        openPRFixEntries.set(target.number, nextCount)
        if (nextCount > 2) {
          outWarn(`loop-breaker: PR #${target.number} tried ${nextCount} times without a new commit — giving up on this PR`)
          const terminalAttempts = [
            ...attempts,
            { prNumber: target.number, attemptedAt: new Date().toISOString(), terminal: true, reason: 'loop-breaker: 2 attempts without a pushed commit' },
          ]
          // Also strip the PR from _action_check_my_open_pr_ci.redPRs so
          // CI_ROUTER's "keep trying if no new commit" heuristic can't re-pick it.
          const currentCI = ctxNow._action_check_my_open_pr_ci ?? {}
          const strippedRed = (currentCI.redPRs ?? []).filter((p: any) => p.number !== target.number)
          const updatedCI = { ...currentCI, redPRs: strippedRed }
          await engine.updateContext(instance.id, {
            openPRFixAttempts: terminalAttempts,
            _action_check_my_open_pr_ci: updatedCI,
          })
          await engine.forceTransition(
            instance.id,
            'CI_ROUTER',
            `Loop-breaker: PR #${target.number} attempted ${nextCount}x with no commit pushed; stripped from redPRs and forcing CI_ROUTER past this PR.`,
          )
          continue
        }
      }
    }

    try {
      const result = await engine.processInput(instance.id, {
        type: 'tick',
        data: { step, now: new Date().toISOString() },
      })
      logInstance(result.instance, `after step ${step}`)
      if (result.llmReasoning) {
        const cleaned = summarizeReasoning(current.currentState, result.llmReasoning)
        if (cleaned) out(`reason: ${cleaned}`)
      }
      // Actions that already emit their own start/end group lines (with tool
      // call children) should not get a redundant `· action → {...}` line
      // from the driver — it would appear below the group's summary footer
      // and muddy the tree.
      const selfLogging = new Set(['delegate_to_coder'])
      for (const a of result.actionsExecuted) {
        if (a.error) {
          outWarn(`${humanizeAction(a.action)} failed: ${truncate(a.error, 200)}`)
        } else {
          const summary = summarizeActionResult(a.action, a.result)
          if (!selfLogging.has(a.action)) {
            out(`· ${humanizeAction(a.action)}${summary ? ` → ${summary}` : ''}`)
          }
          dbg(`action ${a.action} full result: ${a.result === undefined ? '(undefined)' : JSON.stringify(a.result)}`)
        }
      }

      // ─── FIX_OPEN_PR_CI success auto-strip ───
      // If delegate_to_coder just ran in FIX_OPEN_PR_CI and pushed a commit
      // (PR_NUMBER / DIRECT_PUSH / COMMIT_PUSHED marker), strip the target PR
      // from _action_check_my_open_pr_ci.redPRs and record a successful attempt
      // in openPRFixAttempts. Without this, ROUTER keeps re-dispatching because
      // redPRs still lists the (now-pending) PR, the LLM re-enters FIX_OPEN_PR_CI,
      // and the loop-breaker penalises PRs that were never actually re-attempted.
      if (fixOpenPRTarget && typeof fixOpenPRTarget.number === 'number') {
        const delegateRun = result.actionsExecuted.find((a: any) => a.action === 'delegate_to_coder' && !a.error)
        const res: any = delegateRun?.result
        const pushed = res && (res.pushed === true || res.prNumber !== undefined || res.directPushSha !== undefined || res.commitPushedSha !== undefined)
        if (pushed) {
          const after = await engine.getInstance(instance.id)
          const ctxAfter: any = after.context ?? {}
          const currentCI = ctxAfter._action_check_my_open_pr_ci ?? {}
          const strippedRed = (currentCI.redPRs ?? []).filter((p: any) => p.number !== fixOpenPRTarget!.number)
          const updatedCI = { ...currentCI, redPRs: strippedRed }
          const existingAttempts = Array.isArray(ctxAfter.openPRFixAttempts) ? ctxAfter.openPRFixAttempts : []
          const alreadyRecorded = existingAttempts.some((a: any) => a.prNumber === fixOpenPRTarget!.number && a.pushed)
          const sha = res.commitPushedSha ?? res.directPushSha ?? null
          const nextAttempts = alreadyRecorded
            ? existingAttempts
            : [...existingAttempts, { prNumber: fixOpenPRTarget.number, attemptedAt: new Date().toISOString(), pushed: true, sha }]
          out(`PR #${fixOpenPRTarget.number} fix pushed (${sha?.slice(0, 9) ?? 'sha?'}) — marking resolved`)
          await engine.updateContext(instance.id, {
            openPRFixAttempts: nextAttempts,
            _action_check_my_open_pr_ci: updatedCI,
          })
        }
      }

      // ─── LOOP-BREAKER: detect duplicate Discourse bug picks ───
      // If PICK_DISCOURSE_BUG picks the same (topic, post) pair twice in one
      // iteration, VERIFY must have failed to append a deferred entry to
      // bugsFixed. Force-defer the duplicate to break the loop.
      //
      // Check ONLY on transitions OUT of PICK_DISCOURSE_BUG (i.e. a fresh pick
      // just happened). The previous version checked every step where
      // currentBug was set, which false-fired on the normal PICK → DELEGATE →
      // VERIFY flow and on multi-step DELEGATE actions (search_code followed
      // by delegate_to_coder in the next step).
      const midState = await engine.getInstance(instance.id)
      const justPicked = current.currentState === 'PICK_DISCOURSE_BUG' && midState.currentState !== 'PICK_DISCOURSE_BUG'
      if (justPicked) {
        const ctx: any = midState.context ?? {}
        const cb = ctx.currentBug
        if (cb && typeof cb.topic !== 'undefined' && typeof cb.post !== 'undefined') {
          const key = `${cb.topic}.${cb.post}`
          if (pickedBugs.has(key)) {
            outWarn(`loop-breaker: bug ${key} picked twice — force-deferring`)
            const existingFixed = Array.isArray(ctx.bugsFixed) ? ctx.bugsFixed : []
            const deferred = { ...cb, outcome: 'deferred', reason: 'loop-breaker: bug picked twice in one iteration, previous delegate likely failed silently' }
            await engine.updateContext(instance.id, {
              bugsFixed: [...existingFixed, deferred],
              currentBug: null,
            })
            await engine.forceTransition(
              instance.id,
              'WORK_ROUTER',
              `Loop-breaker: bug ${key} picked twice this iteration without being recorded in bugsFixed; force-appended deferred entry.`,
            )
          } else {
            pickedBugs.add(key)
          }
        }
      }
      // Keep midState available for subsequent checks below.

      // ─── THE GATE: the whole point of running on ai-flower ───
      // If the LLM transitioned past COVERAGE_GATE without a real PR existing
      // since iterationStartTs, force it back to COVERAGE_GATE.
      const post = await engine.getInstance(instance.id)
      if (STATES_PAST_GATE.has(post.currentState)) {
        const gate = await realGateCheck(iterationStartTs)
        if (!gate.passed) {
          outWarn(`PR gate: reached "${humanizeState(post.currentState)}" without opening a PR — retrying gate`)
          await engine.forceTransition(
            instance.id,
            'COVERAGE_GATE',
            `Gate enforcement: ${gate.count} PRs found since ${iterationStartTs}; need ≥ 1`,
          )
        } else {
          out(`PR gate: ${gate.count} PR(s) opened/updated this iteration — ${gate.prs.map(p => '#' + p.number).join(', ')}`)
        }
      }

      // ─── HARD INVARIANT: never wrap up while @me has red CI on open PRs ───
      // The LLM's ROUTER prompt already mandates FIX_OPEN_PR_CI as priority 2,
      // but we enforce it here too. If the instance is past the gate AND any
      // PR I authored has red CI, force back to ROUTER. The LLM can't rationalize
      // its way past this — there's no prompt, just a transition.
      const postRed = await engine.getInstance(instance.id)
      if (STATES_PAST_GATE.has(postRed.currentState)) {
        const ctxRed: any = postRed.context ?? {}
        const attemptsRed: Array<{ prNumber: number; terminal?: boolean }> = Array.isArray(ctxRed.openPRFixAttempts) ? ctxRed.openPRFixAttempts : []
        const terminalSet = new Set(attemptsRed.filter(a => a.terminal).map(a => a.prNumber))
        const red = await realRedPRCheck(terminalSet)
        if (red.redPRs.length > 0) {
          const summary = red.redPRs.map(p => `#${p.number} (${p.failedChecks.length} red)`).join(', ')
          outWarn(`red tests on ${summary} — returning to check automated tests`)
          await engine.forceTransition(
            instance.id,
            'CHECK_CI',
            `Red-CI enforcement: ${red.redPRs.length} open PR(s) authored by @me have failing checks: ${summary}. Re-running CHECK_CI to refresh context, then CI_ROUTER will dispatch to FIX_OPEN_PR_CI.`,
          )
        } else {
          dbg('red-pr: no open PRs I authored have red CI')
        }
      }
    } catch (err: any) {
      outWarn(`step ${step} error: ${err.message ?? err}`)
      // Claude subscription quota exhausted — retrying will produce the same
      // limit message. Abort the iteration; the next scheduled run will pick
      // up after the quota resets.
      if (err.message?.startsWith('CLAUDE_QUOTA_EXHAUSTED')) {
        outWarn('Claude subscription quota exhausted — aborting iteration')
        break
      }
      // Safety net: if the LLM produced invalid JSON across all retries, don't
      // leave the instance stuck mid-workflow. Force-transition to
      // COVERAGE_GATE so the iteration wraps up cleanly (WRAP_UP → SEND_EMAIL
      // → SCHEDULE_NEXT). A genuine stuck-loop exits via MAX_STEPS instead.
      if (err.message?.includes('failed validation after')) {
        outWarn('LLM produced invalid JSON after retries — skipping to COVERAGE_GATE')
        try {
          await engine.forceTransition(
            instance.id,
            'COVERAGE_GATE',
            'LLM JSON-validation failure after retries; skip to COVERAGE_GATE to end iteration.',
          )
        } catch (forceErr: any) {
          outWarn(`force-transition failed: ${forceErr.message ?? forceErr}`)
          break
        }
      }
    }
    } finally {
      endGroup()
    }
  }

  const finalInstance = await engine.getInstance(instance.id)
  out(`DONE status=${finalInstance.status} state=${finalInstance.currentState} steps=${step}`)
  dbg(`history ${finalInstance.history.length} events`)

  // Summary of what was actually done
  const allPRActions = finalInstance.history.flatMap(h => h.actionsExecuted.filter(a => a.action === 'create_pr' && !a.error))
  out(`create_pr succeeded: ${allPRActions.length}`)

  // Close the iteration row.
  const outcome = finalInstance.status === 'completed'
    ? 'completed'
    : (step >= MAX_STEPS ? 'timeout' : 'errored')
  endIteration(db, iterationId, outcome, step, allPRActions.length, `final state=${finalInstance.currentState}`)

  // Regenerate all views so /tmp/freegle-monitor/{summary.md, retest-drafts.md,
  // state.json} reflect the final DB state after this iteration.
  try {
    await renderAllViews(db)
    dbg('views regenerated from DB')
  } catch (err) {
    outWarn(`renderAllViews failed: ${err}`)
  }

  // Edit the Discourse "Monitor Status — Live Summary" wiki post so moderators
  // see the current picture without developer jargon. Optional — skipped if
  // SKIP_DISCOURSE_STATUS is set (local dev) or the API key is missing.
  if (!process.env.SKIP_DISCOURSE_STATUS) {
    try {
      const result = await putStatusPost(db)
      if (result.posted) {
        out(`Discourse status post updated (HTTP ${result.status})`)
      } else {
        outWarn(`Discourse status post NOT updated: ${result.reason ?? `HTTP ${result.status}`}`)
      }
    } catch (err) {
      outWarn(`putStatusPost threw: ${err}`)
    }
  }
}

main().catch(err => {
  outWarn(`fatal: ${err}`)
  process.exit(1)
})
