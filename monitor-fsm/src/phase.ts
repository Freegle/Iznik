// Backward-compatibility shim: phase detection has been moved to policy.ts.
// This file delegates to maintain the public API used by driver.ts and
// actions/index.ts.

export type Phase = 'analysis' | 'implementation'

export interface PhaseInfo {
  phase: Phase
  haikuModel: string
  heavyModel: string
  opusModel: string
  forced: boolean
  londonHour: number
  reason: string
}

import { DEFAULT_POLICY, getPhaseInfo as getPolicyPhaseInfo, modelForBrain, modelForDelegate } from './policy.js'

export function getPhaseInfo(now: Date = new Date()): PhaseInfo {
  const policyInfo = getPolicyPhaseInfo(DEFAULT_POLICY, now)
  return {
    phase: policyInfo.phase,
    haikuModel: policyInfo.haikuModel,
    heavyModel: policyInfo.heavyModel,
    opusModel: policyInfo.opusModel,
    forced: policyInfo.forced,
    londonHour: policyInfo.londonHour,
    reason: policyInfo.reason,
  }
}

export { modelForBrain, modelForDelegate } from './policy.js'
