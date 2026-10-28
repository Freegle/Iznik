import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const mockMe = { value: null }
const mockSupportOrAdmin = { value: false }
const mockFetchMe = vi.fn()
const mockGetModGroups = vi.fn()
const mockFetchMod = vi.fn()
const mockSetBadgeCount = vi.fn()
const modGroupList = {}
const mockModGroupGet = vi.fn()
const mockMiscStore = {
  workTimer: null,
  deferGetMessages: false,
  modtoolsediting: false,
}

vi.mock('@/stores/misc', () => ({ useMiscStore: () => mockMiscStore }))
vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => ({
    list: modGroupList,
    get: mockModGroupGet,
    getModGroups: mockGetModGroups,
  }),
}))
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    supportOrAdmin: mockSupportOrAdmin,
    fetchMe: mockFetchMe,
  }),
}))
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ setBadgeCount: mockSetBadgeCount }),
}))
vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: () => ({ fetchMod: mockFetchMod }),
}))

async function load() {
  const { useModMe } = await import('~/modtools/composables/useModMe')
  return useModMe()
}

let play

beforeEach(() => {
  vi.useFakeTimers()
  vi.spyOn(console, 'log').mockImplementation(() => {})
  play = vi.fn().mockResolvedValue(undefined)
  global.Audio = class {
    play() {
      return play()
    }
  }
  mockMe.value = null
  mockSupportOrAdmin.value = false
  mockFetchMod.mockReset().mockResolvedValue({})
  mockGetModGroups.mockReset().mockResolvedValue(undefined)
  mockFetchMe.mockReset()
  mockSetBadgeCount.mockReset()
  mockModGroupGet.mockReset()
  Object.keys(modGroupList).forEach((k) => delete modGroupList[k])
  mockMiscStore.workTimer = null
  mockMiscStore.deferGetMessages = false
  mockMiscStore.modtoolsediting = false
  document.body.style.overflow = ''
  globalThis.__mockAuthStore = {
    work: null,
    user: { settings: {} },
    member: vi.fn(() => null),
    groups: [],
  }
  globalThis.__mockChatStore = { unreadCount: 0 }
  vi.resetModules()
})

afterEach(() => {
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.restoreAllMocks()
  delete globalThis.__mockAuthStore
  delete globalThis.__mockChatStore
})

describe('useModMe permissions', () => {
  it.each([
    ['hasPermissionNewsletter', 'Newsletter'],
    ['hasPermissionSpamAdmin', 'SpamAdmin'],
    ['hasPermissionGiftAid', 'GiftAid'],
    ['hasPermissionClearance', 'Clearance'],
  ])('%s is true only with the %s permission', async (prop, perm) => {
    const m = await load()
    mockMe.value = { permissions: [perm] }
    expect(m[prop].value).toBeTruthy()
  })

  it.each([
    ['no me', null],
    ['no permissions list', {}],
    ['other permission', { permissions: ['Other'] }],
    ['empty permissions', { permissions: [] }],
  ])('permission is falsy with %s', async (_n, me) => {
    mockMe.value = me
    const m = await load()
    expect(m.hasPermissionNewsletter.value).toBeFalsy()
    expect(m.hasPermissionSpamAdmin.value).toBeFalsy()
    expect(m.hasPermissionGiftAid.value).toBeFalsy()
    expect(m.hasPermissionClearance.value).toBeFalsy()
  })
})

describe('useModMe onTeam', () => {
  it.each([
    ['support/admin always', true, null, 'Partnerships', true],
    [
      'member of team',
      false,
      { teams: ['Partnerships'] },
      'Partnerships',
      true,
    ],
    [
      'member of other team',
      false,
      { teams: ['Other'] },
      'Partnerships',
      false,
    ],
    ['no teams', false, {}, 'Partnerships', false],
    ['no me', false, null, 'Partnerships', false],
  ])('%s', async (_n, sa, me, team, expected) => {
    mockSupportOrAdmin.value = sa
    mockMe.value = me
    const m = await load()
    expect(m.onTeam(team)).toBe(expected)
    expect(m.onPartnershipsTeam.value).toBe(expected)
  })
})

describe('useModMe groups', () => {
  it('myModGroups lists the store values', async () => {
    modGroupList[1] = { id: 1 }
    modGroupList[2] = { id: 2 }
    const m = await load()
    expect(m.myModGroups.value).toEqual([{ id: 1 }, { id: 2 }])
  })

  it('myModGroup delegates to the store', async () => {
    mockModGroupGet.mockReturnValue({ id: 5 })
    const m = await load()
    expect(m.myModGroup(5)).toEqual({ id: 5 })
    expect(mockModGroupGet).toHaveBeenCalledWith(5)
  })

  it.each([
    ['Moderator', true],
    ['Owner', true],
    ['Member', false],
    [null, false],
  ])('amAModOn is %s => %s', async (role, expected) => {
    globalThis.__mockAuthStore.member = vi.fn(() => role)
    const m = await load()
    expect(m.amAModOn(1)).toBe(expected)
  })
})

describe('useModMe checkWork', () => {
  it('checkWorkDeferGetMessages sets defer then clears it after the check', async () => {
    const m = await load()
    m.checkWorkDeferGetMessages()
    expect(mockMiscStore.deferGetMessages).toBe(true)
    await vi.advanceTimersByTimeAsync(0)
    expect(mockMiscStore.deferGetMessages).toBe(false)
  })

  it('skips the refresh while editing unless forced, but still polls lockdown', async () => {
    mockMiscStore.modtoolsediting = true
    const m = await load()
    await m.checkWork()
    expect(mockFetchMe).not.toHaveBeenCalled()
    expect(mockFetchMod).toHaveBeenCalledTimes(1)
    await m.checkWork(true)
    expect(mockFetchMe).toHaveBeenCalledWith(true, true)
  })

  it('sets the title, badge and reschedules itself', async () => {
    mockFetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.work = { total: 4 }
    })
    globalThis.__mockChatStore.unreadCount = 150
    const m = await load()
    await m.checkWork(true)
    expect(document.title).toBe('(103) ModTools')
    expect(mockSetBadgeCount).toHaveBeenCalledWith(103)
    expect(mockMiscStore.workTimer).toBeTruthy()
  })

  it('uses the plain title when there is no work', async () => {
    mockFetchMe.mockImplementation(() => {
      globalThis.__mockAuthStore.work = { total: 0 }
    })
    const m = await load()
    await m.checkWork(true)
    expect(document.title).toBe('ModTools')
  })

  it('clears an existing timer', async () => {
    const spy = vi.spyOn(global, 'clearTimeout')
    mockMiscStore.workTimer = setTimeout(() => {}, 1000)
    const m = await load()
    await m.checkWork(true)
    expect(spy).toHaveBeenCalled()
  })

  it('survives a lockdown fetch failure', async () => {
    mockFetchMod.mockRejectedValue(new Error('boom'))
    const m = await load()
    await m.checkWork(true)
    expect(mockMiscStore.deferGetMessages).toBe(false)
  })

  describe('beep gating after baseline', () => {
    async function twoChecks(prep) {
      mockFetchMe.mockImplementationOnce(() => {
        globalThis.__mockAuthStore.work = { total: 1 }
      })
      const m = await load()
      await m.checkWork(true)
      prep()
      mockFetchMe.mockImplementationOnce(() => {
        globalThis.__mockAuthStore.work = { total: 9 }
      })
      await m.checkWork(true)
      return m
    }

    it('beeps by default', async () => {
      await twoChecks(() => {})
      expect(play).toHaveBeenCalledTimes(1)
    })

    it('does not beep when a modal is open', async () => {
      await twoChecks(() => {
        document.body.style.overflow = 'hidden'
      })
      expect(play).not.toHaveBeenCalled()
    })

    it('does not beep when playbeep is false', async () => {
      await twoChecks(() => {
        globalThis.__mockAuthStore.user = { settings: { playbeep: false } }
      })
      expect(play).not.toHaveBeenCalled()
    })

    it('beeps when playbeep is true', async () => {
      await twoChecks(() => {
        globalThis.__mockAuthStore.user = { settings: { playbeep: true } }
      })
      expect(play).toHaveBeenCalledTimes(1)
    })

    it('beeps when user has no settings', async () => {
      await twoChecks(() => {
        globalThis.__mockAuthStore.user = {}
      })
      expect(play).toHaveBeenCalledTimes(1)
    })

    it('does not beep when there is no user', async () => {
      await twoChecks(() => {
        globalThis.__mockAuthStore.user = null
      })
      expect(play).not.toHaveBeenCalled()
    })

    it('survives play() rejecting', async () => {
      play.mockRejectedValue(new Error('blocked'))
      await twoChecks(() => {})
      await vi.advanceTimersByTimeAsync(0)
      expect(play).toHaveBeenCalled()
    })

    it('resetCheckWork suppresses the next beep again', async () => {
      const m = await twoChecks(() => {})
      expect(play).toHaveBeenCalledTimes(1)
      m.resetCheckWork()
      mockFetchMe.mockImplementationOnce(() => {
        globalThis.__mockAuthStore.work = { total: 50 }
      })
      await m.checkWork(true)
      expect(play).toHaveBeenCalledTimes(1)
    })
  })
})
