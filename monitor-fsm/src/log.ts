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
