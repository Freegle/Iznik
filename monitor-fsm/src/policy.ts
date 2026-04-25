// Centralized policy configuration for model selection, timing, and review rules.
//
// All decisions about which model to use, when to do what work, and review
// requirements are consolidated here to prevent scattered "spaghetti" code.

export interface PolicyConfig {
  /** Haiku model for implementation-phase FSM brain and delegate. */
  haikuModel: string
  /** Heavy model (Sonnet-class) for analysis-phase FSM brain and delegate. */
  heavyModel: string
  /** Opus model for adversarial PR review. */
  opusModel: string
  /** Peak hours start (0-24, London time). Implementation phase uses Haiku during peak. */
  peakHourStart: number
  /** Peak hours end (0-24, London time). */
  peakHourEnd: number
}

const DEFAULT_HAIKU = 'claude-haiku-4-5-20251001'
const DEFAULT_SONNET = 'sonnet' // subscription default resolves to current Sonnet
const DEFAULT_OPUS = 'claude-opus-4-7'

export const DEFAULT_POLICY: PolicyConfig = {
  haikuModel: process.env.MONITOR_HAIKU_MODEL || DEFAULT_HAIKU,
  heavyModel: process.env.MONITOR_HEAVY_MODEL || DEFAULT_SONNET,
  opusModel: process.env.MONITOR_OPUS_MODEL || DEFAULT_OPUS,
  peakHourStart: 13, // 13:00 London
  peakHourEnd: 19, // 19:00 London
}

export interface PhaseInfo {
  phase: 'analysis' | 'implementation'
  haikuModel: string
  heavyModel: string
  opusModel: string
  forced: boolean
  londonHour: number
  reason: string
}

function londonHour(now: Date): number {
  const s = new Intl.DateTimeFormat('en-GB', {
    hour: 'numeric',
    hour12: false,
    timeZone: 'Europe/London',
  }).format(now)
  const n = parseInt(s, 10)
  return Number.isFinite(n) ? n : now.getUTCHours()
}

export function getPhaseInfo(policy: PolicyConfig = DEFAULT_POLICY, now: Date = new Date()): PhaseInfo {
  const forcedEnv = process.env.MONITOR_PHASE
  const lon = londonHour(now)

  if (forcedEnv === 'analysis' || forcedEnv === 'implementation') {
    return {
      phase: forcedEnv,
      haikuModel: policy.haikuModel,
      heavyModel: policy.heavyModel,
      opusModel: policy.opusModel,
      forced: true,
      londonHour: lon,
      reason: `forced by MONITOR_PHASE=${forcedEnv}`,
    }
  }

  const isPeak = lon >= policy.peakHourStart && lon < policy.peakHourEnd
  return {
    phase: isPeak ? 'implementation' : 'analysis',
    haikuModel: policy.haikuModel,
    heavyModel: policy.heavyModel,
    opusModel: policy.opusModel,
    forced: false,
    londonHour: lon,
    reason: `London ${lon.toString().padStart(2, '0')}:00 ${isPeak ? 'inside' : 'outside'} peak window ${policy.peakHourStart}-${policy.peakHourEnd}`,
  }
}

/** Which model should the FSM brain use this iteration? */
export function modelForBrain(p: PhaseInfo): string {
  // Analysis phase: Opus for best reasoning quality on complex triage/dispatch decisions.
  // Implementation (peak) phase: Haiku for cost and speed on mechanical CI-fix loops.
  return p.phase === 'implementation' ? p.haikuModel : p.opusModel
}

/** Which model should a freshly-spawned delegate use by default? */
export function modelForDelegate(p: PhaseInfo): string {
  return p.phase === 'implementation' ? p.haikuModel : p.heavyModel
}

/** Which model should do adversarial PR review? Always Opus (high quality). */
export function modelForAdversarialReview(p: PhaseInfo): string {
  return p.opusModel
}
