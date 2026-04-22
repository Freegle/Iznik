// Peak / off-peak phase detection.
//
// Subscription quota resets on a rolling 5-hour window and the weekly cap is
// tight during European daytime, so running Sonnet-grade reasoning all day
// burns the allowance and leaves us unable to act on red CI in the evening
// (observed 2026-04-22: quota exhausted mid-iteration during FIX_OPEN_PR_CI).
//
// Two phases:
//
//   analysis       — off-peak. Heavy reasoning is OK. We do the expensive
//                    work: triage Discourse bugs, run Sentry investigation,
//                    design fixes, open GitHub issues with evidence + plan.
//                    Model: the session's default (Sonnet/Opus).
//
//   implementation — peak. Budget-conscious. We only do work that's already
//                    been designed: fix red CI on @me PRs, pick up issues
//                    labelled `ready-to-fix` and produce PRs, write coverage.
//                    Model: Haiku for both FSM decisions and delegate.
//
// Default hours (Europe/London local time — user is UK-based and subscription
// resets are printed in London time):
//   13:00–19:00 London → implementation (peak) [= 05:00–11:00 PT]
//   19:00–13:00 London → analysis       (off-peak)
//
// Override via env:
//   MONITOR_PHASE=analysis|implementation — force one phase regardless of time
//   MONITOR_PEAK_HOURS=HHstart-HHend      — override peak window in London hours
//                                            e.g. "07-23" = 07:00–23:00 peak
//
// Keep this file dependency-free so it can be imported from both driver.ts
// (FSM brain) and actions/index.ts (delegate model selection).

export type Phase = 'analysis' | 'implementation'

export interface PhaseInfo {
  phase: Phase
  /** Haiku model id — use for implementation-phase FSM brain and delegate. */
  haikuModel: string
  /** Heavy model id — Sonnet-class for analysis-phase FSM brain and delegate. */
  heavyModel: string
  /** True when MONITOR_PHASE forced the decision rather than time of day. */
  forced: boolean
  /** London local hour at decision time (for logging). */
  londonHour: number
  /** Human-readable reason we landed on this phase. */
  reason: string
}

const DEFAULT_HAIKU = 'claude-haiku-4-5-20251001'
const DEFAULT_HEAVY = 'sonnet'  // subscription default resolves to current Sonnet

function londonHour(now: Date): number {
  const s = new Intl.DateTimeFormat('en-GB', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'Europe/London',
  }).format(now)
  const n = parseInt(s, 10)
  return Number.isFinite(n) ? n : now.getUTCHours()
}

function parsePeakWindow(spec: string | undefined): { start: number; end: number } {
  const m = (spec ?? '').match(/^(\d{1,2})-(\d{1,2})$/)
  if (!m) return { start: 13, end: 19 }
  const start = Math.max(0, Math.min(23, parseInt(m[1], 10)))
  const end = Math.max(0, Math.min(24, parseInt(m[2], 10)))
  return { start, end }
}

function inWindow(hour: number, start: number, end: number): boolean {
  if (start <= end) return hour >= start && hour < end
  // wrap-around window (e.g. 22-08 = 22..24 + 0..8)
  return hour >= start || hour < end
}

export function getPhaseInfo(now: Date = new Date()): PhaseInfo {
  const forcedEnv = process.env.MONITOR_PHASE
  const lon = londonHour(now)
  const haikuModel = process.env.MONITOR_HAIKU_MODEL || DEFAULT_HAIKU
  const heavyModel = process.env.MONITOR_HEAVY_MODEL || DEFAULT_HEAVY

  if (forcedEnv === 'analysis' || forcedEnv === 'implementation') {
    return {
      phase: forcedEnv,
      haikuModel,
      heavyModel,
      forced: true,
      londonHour: lon,
      reason: `forced by MONITOR_PHASE=${forcedEnv}`,
    }
  }

  const { start, end } = parsePeakWindow(process.env.MONITOR_PEAK_HOURS)
  const isPeak = inWindow(lon, start, end)
  return {
    phase: isPeak ? 'implementation' : 'analysis',
    haikuModel,
    heavyModel,
    forced: false,
    londonHour: lon,
    reason: `London ${lon.toString().padStart(2, '0')}:00 ${isPeak ? 'inside' : 'outside'} peak window ${start}-${end}`,
  }
}

/** Which model should the FSM brain (ai-flower LLMAdapter) use this iteration? */
export function modelForBrain(p: PhaseInfo): string {
  return p.phase === 'implementation' ? p.haikuModel : p.heavyModel
}

/** Which model should a freshly-spawned delegate use by default? */
export function modelForDelegate(p: PhaseInfo): string {
  return p.phase === 'implementation' ? p.haikuModel : p.heavyModel
}
