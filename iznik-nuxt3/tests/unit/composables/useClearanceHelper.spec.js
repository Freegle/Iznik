import { describe, it, expect } from 'vitest'
import {
  HELPER_STATE_META,
  helperStateLabel,
  helperStateVariant,
  helperStateGroup,
  isOutreachState,
  formatScore,
  summariseItemStates,
} from '~/composables/useClearance'

describe('useClearance — Freegle Helper FSM helpers', () => {
  it('maps every FSM state to a label, variant and group', () => {
    for (const state of Object.keys(HELPER_STATE_META)) {
      expect(typeof helperStateLabel(state)).toBe('string')
      expect(helperStateLabel(state).length).toBeGreaterThan(0)
      expect(typeof helperStateVariant(state)).toBe('string')
      expect(['allocated', 'pool', 'outreach', 'inactive']).toContain(
        helperStateGroup(state)
      )
    }
  })

  it('groups allocated/decision/outreach/inactive correctly', () => {
    expect(helperStateGroup('ALLOCATED')).toBe('allocated')
    expect(helperStateGroup('CONFIRMED')).toBe('allocated')
    expect(helperStateGroup('COLLECTED')).toBe('allocated')
    expect(helperStateGroup('QUALIFIED')).toBe('pool')
    expect(helperStateGroup('GATHERING')).toBe('outreach')
    expect(helperStateGroup('NEW')).toBe('outreach')
    expect(helperStateGroup('ESCALATED')).toBe('outreach')
    expect(helperStateGroup('TIMED_OUT')).toBe('inactive')
    expect(helperStateGroup('WITHDRAWN')).toBe('inactive')
    expect(helperStateGroup('REJECTED')).toBe('inactive')
  })

  it('isOutreachState is true only for outreach states', () => {
    expect(isOutreachState('GATHERING')).toBe(true)
    expect(isOutreachState('NEW')).toBe(true)
    expect(isOutreachState('QUALIFIED')).toBe(false)
    expect(isOutreachState('ALLOCATED')).toBe(false)
  })

  it('falls back gracefully for unknown states', () => {
    expect(helperStateLabel('WAT')).toBe('WAT')
    expect(helperStateLabel(null)).toBe('Unknown')
    expect(helperStateVariant('WAT')).toBe('secondary')
    expect(helperStateGroup('WAT')).toBe('outreach')
  })

  it('formats scores as rounded integers, blank when absent', () => {
    expect(formatScore(87.5)).toBe('88')
    expect(formatScore('42')).toBe('42')
    expect(formatScore(0)).toBe('0')
    expect(formatScore(null)).toBe('')
    expect(formatScore(undefined)).toBe('')
    expect(formatScore('')).toBe('')
    expect(formatScore('nope')).toBe('')
  })

  it('summarises item states into FSM-group counts', () => {
    const states = [
      { state: 'ALLOCATED' },
      { state: 'COLLECTED' },
      { state: 'QUALIFIED' },
      { state: 'GATHERING' },
      { state: 'NEW' },
      { state: 'REJECTED' },
    ]
    expect(summariseItemStates(states)).toEqual({
      allocated: 2,
      pool: 1,
      outreach: 2,
      inactive: 1,
      total: 6,
    })
    expect(summariseItemStates([])).toEqual({
      allocated: 0,
      pool: 0,
      outreach: 0,
      inactive: 0,
      total: 0,
    })
  })
})
