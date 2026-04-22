// Split-stream logger shared between driver and actions.
//
//   out(msg)           — one terse line to screen + debug log, indented by the
//                        current group depth.
//   outAt(msg, d)      — same but at an explicit depth.
//   dbg(msg)           — debug log only; verbose dumps (raw LLM text, full
//                        action results, context snapshots).
//   outWarn(msg)       — screen stderr + debug log.
//   startGroup(label)  — opens a tree branch: prints the label at current
//                        depth and increments global depth so subsequent
//                        out() calls nest under it.
//   endGroup(summary)  — closes the current branch: prints a summary line
//                        (with duration) at the SAME depth as the start
//                        label and decrements global depth. The detail lines
//                        between start and end remain visible (scrollback);
//                        this is a structural tree, not an ANSI collapse.
//
// Indent convention:
//   depth 0 → FSM step headers ("→ step N: STATE")
//   depth 1 → reasoning / action calls within a step
//   depth 2 → sub-activity inside an action, e.g. delegate tool calls
//   depth 3+ → deeper nesting if ever needed
//
// Terminal behaviour:
//   Every line is ALSO written to /tmp/freegle-monitor/debug.log with an
//   ISO-8601 timestamp so post-hoc debugging has full fidelity even after the
//   screen scrolls away.
import { appendFileSync, mkdirSync } from 'node:fs'

export const DEBUG_LOG_PATH = '/tmp/freegle-monitor/debug.log'
try { mkdirSync('/tmp/freegle-monitor', { recursive: true }) } catch {}

function stamp(): string {
  return new Date().toISOString().slice(11, 19)
}

function indent(depth: number): string {
  return '   '.repeat(Math.max(0, depth))
}

// Global depth tracked by group start/end. Driver increments on step entry,
// decrements on exit. Delegate action increments further while streaming tool
// events. Because the FSM is serial (one step at a time, one action at a time)
// a global stack is safe here — no two groups are ever "open" concurrently.
const groupStack: Array<{ label: string; startMs: number; startDepth: number }> = []

export function currentDepth(): number {
  return groupStack.length
}

export function dbg(msg: string): void {
  try {
    appendFileSync(DEBUG_LOG_PATH, `${new Date().toISOString()} ${msg}\n`)
  } catch { /* best effort */ }
}

export function out(msg: string): void {
  outAt(msg, groupStack.length)
}

export function outAt(msg: string, depth: number): void {
  const line = `${stamp()} ${indent(depth)}${msg}`
  process.stdout.write(`${line}\n`)
  dbg(line)
}

export function outWarn(msg: string): void {
  const line = `${stamp()} ${indent(groupStack.length)}⚠ ${msg}`
  process.stderr.write(`${line}\n`)
  dbg(`WARN ${line}`)
}

export function truncate(s: string, max = 140): string {
  if (s.length <= max) return s
  return `${s.slice(0, max - 1)}…`
}

export function startGroup(label: string): void {
  const depth = groupStack.length
  outAt(label, depth)
  groupStack.push({ label, startMs: Date.now(), startDepth: depth })
}

export function endGroup(summary?: string): void {
  const g = groupStack.pop()
  if (!g) {
    // Protective: printing a close without an open is a driver bug, not a fatal.
    outAt(`⚠ endGroup without startGroup: ${summary ?? ''}`, 0)
    return
  }
  if (!summary) return // silent pop — caller didn't want a footer
  const dur = formatDuration(Date.now() - g.startMs)
  outAt(`└─ ${summary} (${dur})`, g.startDepth)
}

function formatDuration(ms: number): string {
  if (ms < 1000) return `${ms}ms`
  const s = ms / 1000
  if (s < 60) return `${s.toFixed(1)}s`
  const m = Math.floor(s / 60)
  const rem = Math.round(s - m * 60)
  return `${m}m${rem.toString().padStart(2, '0')}s`
}

// ─── Action result summarizer ───────────────────────────────────────────────
// Every action gets one human-readable sentence (no JSON). For unknown actions
// we fall back to "ok" — the full payload is always in debug.log for anyone
// who needs to dig.
export function summarizeActionResult(action: string, result: unknown): string {
  if (result === undefined || result === null) return 'ok'
  const r = result as Record<string, any>
  switch (action) {
    case 'load_state': {
      const topics = r.state?.topics ?? {}
      const n = Object.keys(topics).length
      const lastEmail = r.state?.last_email_sent
      return lastEmail
        ? `${n} tracked topic${n === 1 ? '' : 's'}, last email ${timeSince(lastEmail)}`
        : `${n} tracked topic${n === 1 ? '' : 's'}, no email sent yet`
    }
    case 'fetch_discourse': {
      const posts = Array.isArray(r.posts) ? r.posts.length : 0
      const seen = r.topicsSeen ? Object.keys(r.topicsSeen).length : 0
      return `${posts} new post${posts === 1 ? '' : 's'} across ${seen} topic${seen === 1 ? '' : 's'}`
    }
    case 'git_log_today': {
      const total = typeof r.totalCommits === 'number'
        ? r.totalCommits
        : (Array.isArray(r.commits) ? r.commits.length : undefined)
      if (typeof total === 'number') return `${total} commit${total === 1 ? '' : 's'} in last 3 days`
      return 'ok'
    }
    case 'check_master_ci': {
      const failing = r.failing === true
      const name = r.latestRun?.name ?? r.latestRun?.conclusion ?? ''
      return failing ? `master FAILING${name ? ' (' + name + ')' : ''}` : 'master green'
    }
    case 'check_my_open_pr_ci': {
      const red = Array.isArray(r.redPRs) ? r.redPRs.length : 0
      const pending = Array.isArray(r.pendingPRs) ? r.pendingPRs.length : 0
      const allGreen = r.allGreen === true
      if (allGreen) return 'all @me PRs green'
      const redList = red > 0 ? r.redPRs.map((p: any) => `#${p.number}`).join(',') : ''
      const pieces: string[] = []
      if (red > 0) pieces.push(`${red} red${redList ? ' (' + redList + ')' : ''}`)
      if (pending > 0) pieces.push(`${pending} pending`)
      return pieces.length ? pieces.join(', ') : 'no @me PRs'
    }
    case 'check_sentry': {
      const issues = Array.isArray(r.issues) ? r.issues : []
      if (issues.length === 0) return '0 issues'
      const byProject: Record<string, number> = {}
      for (const i of issues) {
        const p = i.project ?? 'unknown'
        byProject[p] = (byProject[p] ?? 0) + 1
      }
      const parts = Object.entries(byProject).map(([p, n]) => `${n} ${p}`)
      return `${issues.length} unresolved (${parts.join(', ')})`
    }
    case 'read_user_feedback': {
      const entries = Array.isArray(r.entries) ? r.entries.length : 0
      return `${entries} feedback entr${entries === 1 ? 'y' : 'ies'}`
    }
    case 'search_code': {
      const matches = Array.isArray(r.matches) ? r.matches.length : 0
      return `${matches} file${matches === 1 ? '' : 's'} matched`
    }
    case 'fetch_ci_failure_logs': {
      const bytes = typeof r.bytes === 'number'
        ? r.bytes
        : (typeof r.logs === 'string' ? r.logs.length : undefined)
      return typeof bytes === 'number' ? `${formatBytes(bytes)} of logs` : 'ok'
    }
    case 'verify_pr_created': {
      const count = r.count ?? 0
      const passed = r.passed === true
      return passed ? `count=${count} (passed)` : `count=${count} (not yet)`
    }
    case 'create_pr': {
      if (r.verified === false) return `#${r.pr?.number ?? '?'} not verified`
      const fe = r.frontendOnly === true ? 'frontend-only' : 'backend/mixed'
      return `#${r.pr?.number ?? '?'} verified (${fe}${r.deployPreviewUrl ? ', preview' : ''})`
    }
    case 'post_discourse_reply_draft': {
      const draft = r.draft ?? {}
      return `draft queued for ${draft.topic ?? '?'}.${draft.post ?? '?'} → @${draft.username ?? '?'}`
    }
    case 'write_summary': {
      return `${r.bytes ?? '?'} bytes → ${r.written ?? 'summary.md'}`
    }
    case 'send_email': {
      if (r.skipped === true) return `skipped (${r.reason ?? 'unknown'})`
      return `sent`
    }
    case 'schedule_wakeup': {
      return r.delaySeconds ? `wake in ${r.delaySeconds}s` : 'ok'
    }
    case 'read_sentry_issues': {
      return `loaded ${r.issueId ?? '?'}`
    }
    default: {
      // Unknown action — show the first 80 chars of the JSON so we at least see
      // SOMETHING, but this should be rare. Add the action to the switch above
      // when you see one of these.
      const json = JSON.stringify(r)
      return truncate(json, 80)
    }
  }
}

// ─── Reasoning summarizer ───────────────────────────────────────────────────
// The LLM tends to restate what the state prompt already says ("In LOAD_STATE.
// I need to call load_state to read monitor state, then propose transition to
// FETCH_DISCOURSE"). That's noise. Only print reasoning for states where it
// reflects a real decision, and even there strip common boilerplate prefixes.
const REASONING_STATES = new Set([
  'CI_ROUTER', 'WORK_ROUTER', 'ROUTER', // both old and new router names
  'PICK_DISCOURSE_BUG',
  'VERIFY_DISCOURSE_BUG_FIX',
  'COVERAGE_GATE',
  'TRIAGE',
])
export function summarizeReasoning(stateName: string, reasoning: string): string | null {
  if (!REASONING_STATES.has(stateName)) return null
  let r = reasoning.replace(/\s+/g, ' ').trim()
  r = r.replace(/^(In \w+\.\s*)/i, '')
  r = r.replace(/^(I (?:need to|will|'ll|'m going to) )/i, '')
  r = r.replace(/^Executing step \d+[:.]?\s*/i, '')
  return truncate(r, 180)
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n}B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)}KB`
  return `${(n / 1024 / 1024).toFixed(1)}MB`
}

function timeSince(iso: string): string {
  const then = new Date(iso).getTime()
  if (isNaN(then)) return iso
  const sec = Math.round((Date.now() - then) / 1000)
  if (sec < 60) return `${sec}s ago`
  const min = Math.round(sec / 60)
  if (min < 60) return `${min}m ago`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h ago`
  const day = Math.round(hr / 24)
  return `${day}d ago`
}
