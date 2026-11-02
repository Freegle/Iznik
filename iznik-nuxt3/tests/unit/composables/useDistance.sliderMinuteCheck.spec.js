import { describe, it, expect, vi, beforeEach } from 'vitest'

import { browseSliderMinuteCheck } from '~/composables/useDistance'

const authState = { user: null }

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => authState,
}))

describe('browseSliderMinuteCheck', () => {
  beforeEach(() => {
    authState.user = null
  })

  it.each([
    ['no user', null],
    ['no settings', {}],
    ['settings without a minutes budget', { settings: {} }],
    ['minutes budget of zero', { settings: { browseMaxMinutes: 0 } }],
    ['negative minutes budget', { settings: { browseMaxMinutes: -5 } }],
    ['string minutes budget', { settings: { browseMaxMinutes: '30' } }],
    ['null minutes budget', { settings: { browseMaxMinutes: null } }],
  ])('returns null (pure crow behaviour) for %s', (_label, user) => {
    authState.user = user
    expect(browseSliderMinuteCheck()).toBeNull()
  })

  it.each([
    [30, { roadmins: 10 }, true],
    [30, { roadmins: 30 }, true],
    [30, { roadmins: 31 }, false],
    [30, { roadmins: 0 }, true],
    [30, {}, null],
    [30, { roadmins: '5' }, null],
    [30, null, null],
    [30, undefined, null],
  ])('with a %i minute budget, %j gives %s', (budget, msg, expected) => {
    authState.user = { settings: { browseMaxMinutes: budget } }
    const check = browseSliderMinuteCheck()
    expect(typeof check).toBe('function')
    expect(check(msg)).toBe(expected)
  })
})
