import { describe, it, expect, vi, beforeEach } from 'vitest'

const experiment = vi.hoisted(() => ({ arm: null, calls: [] }))
vi.mock('~/composables/useExperiment', () => ({
  useExperiment: (name) => {
    experiment.calls.push(name)
    return experiment.arm
  },
}))

const {
  RATING_NUDGE_EXPERIMENT,
  RATING_NUDGE_GAP,
  RATING_NUDGE_SKIP_GAP,
  RATING_NUDGE_MAX,
  ratingNudgeOpen,
  ratingNudgePeople,
  shownRecently,
  recordShown,
  skippedUsers,
  recordSkipped,
  considerRatingNudge,
  removeFromNudge,
  closeRatingNudge,
  resetRatingNudge,
} = await import('~/composables/useRatingNudge')

const PEOPLE = [
  { userid: 11, msgid: 101, subject: 'OFFER: Sofa', item: 'Sofa', gave: true },
  { userid: 12, msgid: 102, subject: 'OFFER: Lamp', item: 'Lamp', gave: false },
]

function ctx(over = {}) {
  return {
    loggedIn: true,
    path: '/browse',
    query: {},
    native: false,
    modtools: false,
    fetchUnrated: vi.fn().mockResolvedValue(PEOPLE),
    ...over,
  }
}

describe('useRatingNudge', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    resetRatingNudge()
    experiment.arm = 'b'
    experiment.calls = []
  })

  it('names the switch the manifest lists', () => {
    expect(RATING_NUDGE_EXPERIMENT).toBe('rate-on-return')
  })

  describe('shownRecently and recordShown', () => {
    it('is false until recorded, then true for the gap', () => {
      expect(shownRecently()).toBe(false)
      recordShown(1000)
      expect(shownRecently(1000 + RATING_NUDGE_GAP - 1)).toBe(true)
      expect(shownRecently(1000 + RATING_NUDGE_GAP)).toBe(false)
    })

    it('reads a time kept in localStorage from an earlier page view', () => {
      localStorage.setItem('freegle_rating_nudge_last', String(5000))
      expect(shownRecently(5000 + 10)).toBe(true)
    })

    it('falls back to the session when local storage is blocked', () => {
      const real = Object.getOwnPropertyDescriptor(window, 'localStorage')
      Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get() {
          throw new Error('blocked')
        },
      })
      try {
        recordShown(7000)
        expect(sessionStorage.getItem('freegle_rating_nudge_last')).toBe('7000')
        expect(shownRecently(7000 + 10)).toBe(true)
      } finally {
        if (real) Object.defineProperty(window, 'localStorage', real)
      }
    })

    it('does not throw when all storage is blocked, and still holds for the page view', () => {
      const get = vi
        .spyOn(Storage.prototype, 'getItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      const set = vi
        .spyOn(Storage.prototype, 'setItem')
        .mockImplementation(() => {
          throw new Error('blocked')
        })
      try {
        expect(() => recordShown(9000)).not.toThrow()
        expect(shownRecently(9000 + 10)).toBe(true)
        expect(() => recordSkipped(11, 9000)).not.toThrow()
        expect(skippedUsers(9000 + 10)).toEqual({ 11: 9000 })
      } finally {
        get.mockRestore()
        set.mockRestore()
      }
    })
  })

  describe('skipped people', () => {
    it('remembers a skip for the skip gap and then forgets it', () => {
      recordSkipped(11, 1000)
      expect(skippedUsers(1000 + 10)).toEqual({ 11: 1000 })
      expect(skippedUsers(1000 + RATING_NUDGE_SKIP_GAP)).toEqual({})
    })

    it('keeps earlier skips when adding one', () => {
      recordSkipped(11, 1000)
      recordSkipped(12, 2000)
      expect(Object.keys(skippedUsers(3000))).toEqual(['11', '12'])
    })

    it('treats something that is not JSON as nobody skipped', () => {
      localStorage.setItem('freegle_rating_nudge_skipped', 'not json')
      expect(skippedUsers()).toEqual({})
    })
  })

  describe('considerRatingNudge', () => {
    it('fetches, reads the arm, records the visit and opens in arm b', async () => {
      const c = ctx()
      expect(await considerRatingNudge(c)).toBe('b')
      expect(c.fetchUnrated).toHaveBeenCalledTimes(1)
      expect(experiment.calls).toEqual(['rate-on-return'])
      expect(ratingNudgeOpen.value).toBe(true)
      expect(ratingNudgePeople.value).toEqual(PEOPLE)
      expect(shownRecently()).toBe(true)
    })

    it('in control fetches, reads the arm and records the visit, but shows nothing', async () => {
      experiment.arm = 'control'
      const c = ctx()
      expect(await considerRatingNudge(c)).toBe('control')
      expect(c.fetchUnrated).toHaveBeenCalledTimes(1)
      expect(experiment.calls).toEqual(['rate-on-return'])
      expect(ratingNudgeOpen.value).toBe(false)
      expect(shownRecently()).toBe(true)
    })

    it('with no arm configured is control', async () => {
      experiment.arm = null
      expect(await considerRatingNudge(ctx())).toBe('control')
      expect(ratingNudgeOpen.value).toBe(false)
    })

    it('only looks once per page view', async () => {
      const c = ctx()
      await considerRatingNudge(c)
      expect(await considerRatingNudge(c)).toBe(null)
      expect(c.fetchUnrated).toHaveBeenCalledTimes(1)
    })

    it.each([
      ['signed out', { loggedIn: false }],
      ['in the app', { native: true }],
      ['on ModTools', { modtools: true }],
      ['mid-flow on /give', { path: '/give/mobile/photos' }],
      ['in a chat from an email', { path: '/chats/123' }],
      ['on a login link', { query: { k: 'abc' } }],
    ])('does not fetch or read the arm %s', async (_, over) => {
      const c = ctx(over)
      expect(await considerRatingNudge(c)).toBe(null)
      expect(c.fetchUnrated).not.toHaveBeenCalled()
      expect(experiment.calls).toEqual([])
      expect(shownRecently()).toBe(false)
    })

    it('does not fetch again within the gap', async () => {
      recordShown()
      const c = ctx()
      expect(await considerRatingNudge(c)).toBe(null)
      expect(c.fetchUnrated).not.toHaveBeenCalled()
    })

    it('with nobody to rate reads no arm and records nothing, so the day is not used up', async () => {
      const c = ctx({ fetchUnrated: vi.fn().mockResolvedValue([]) })
      expect(await considerRatingNudge(c)).toBe(null)
      expect(experiment.calls).toEqual([])
      expect(shownRecently()).toBe(false)
    })

    it('leaves out people the member skipped, and caps the list', async () => {
      recordSkipped(11)
      const many = []
      for (let i = 20; i < 30; i++)
        many.push({ userid: i, msgid: i, gave: true })
      const c = ctx({
        fetchUnrated: vi.fn().mockResolvedValue([...PEOPLE, ...many]),
      })
      expect(await considerRatingNudge(c)).toBe('b')
      expect(ratingNudgePeople.value).toHaveLength(RATING_NUDGE_MAX)
      expect(ratingNudgePeople.value[0].userid).toBe(12)
    })

    it('with everyone skipped reads no arm', async () => {
      recordSkipped(11)
      recordSkipped(12)
      expect(await considerRatingNudge(ctx())).toBe(null)
      expect(experiment.calls).toEqual([])
    })

    it('stands aside when another modal is already open, reading no arm', async () => {
      document.body.classList.add('modal-open')
      try {
        const c = ctx()
        expect(await considerRatingNudge(c)).toBe(null)
        expect(c.fetchUnrated).toHaveBeenCalledTimes(1)
        expect(experiment.calls).toEqual([])
        expect(shownRecently()).toBe(false)
      } finally {
        document.body.classList.remove('modal-open')
      }
    })

    it('a failed fetch is nothing to ask', async () => {
      const c = ctx({
        fetchUnrated: vi.fn().mockRejectedValue(new Error('no')),
      })
      expect(await considerRatingNudge(c)).toBe(null)
      expect(experiment.calls).toEqual([])
      expect(shownRecently()).toBe(false)
    })

    it('ignores entries with no user id', async () => {
      const c = ctx({
        fetchUnrated: vi.fn().mockResolvedValue([{ msgid: 1 }, null]),
      })
      expect(await considerRatingNudge(c)).toBe(null)
    })
  })

  describe('the open list', () => {
    it('removeFromNudge drops one person and closeRatingNudge clears it', async () => {
      await considerRatingNudge(ctx())
      removeFromNudge(11)
      expect(ratingNudgePeople.value.map((p) => p.userid)).toEqual([12])
      closeRatingNudge()
      expect(ratingNudgeOpen.value).toBe(false)
      expect(ratingNudgePeople.value).toEqual([])
    })
  })
})
