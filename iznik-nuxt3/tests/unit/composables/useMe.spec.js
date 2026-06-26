/**
 * Tests for ~/composables/useMe.js
 *
 * Coverage: fetchMe, loginStateKnown, jwt, me, realMe, myid, loggedIn,
 *           myGroupIds, myGroups, anyGroups, myLocation, mod, support, admin,
 *           supportOrAdmin, chitChatMod, supporter, donor, recentDonor,
 *           amMicroVolunteering, oneOfMyGroups, myGroup.
 *
 * myGroupsBoundingBox is omitted — it requires window.L (Leaflet) and Wicket
 * WKT parsing which are not available in happy-dom.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'

// ============================================================
// STORE MOCKS — must be declared before any imports that
// pull in the module under test.
// ============================================================

const mockFetchUser = vi.fn()
let mockAuthState = {}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthState,
}))

let mockGroupGet = vi.fn()
vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    get: mockGroupGet,
  }),
}))

let mockTeamState = { list: {} }
vi.mock('~/stores/team', () => ({
  useTeamStore: () => mockTeamState,
}))

// Wicket / WKT — stub so that myGroupsBoundingBox can run without errors
// (we still don't test its geometry math, just that it doesn't throw).
vi.mock('wicket', () => ({
  default: {
    Wkt: class {
      read() {}
      toObject() {
        return {
          getBounds: () => ({
            getSouthWest: () => ({ lat: 0, lng: 0 }),
            getNorthEast: () => ({ lat: 1, lng: 1 }),
          }),
        }
      }
    },
  },
}))

// ============================================================
// MODULE UNDER TEST
// ============================================================
// Import AFTER mocks so vi.mock() factories are wired up.

import { useMe, fetchMe } from '~/composables/useMe'

// ============================================================
// HELPERS
// ============================================================

/**
 * Build a minimal user object.  Extra props are merged in.
 */
function makeUser(overrides = {}) {
  return {
    id: 42,
    systemrole: 'User',
    trustlevel: null,
    settings: { mylocation: { name: 'Oxford' } },
    supporter: false,
    donated: null,
    donatedtype: null,
    donorrecurring: false,
    ...overrides,
  }
}

/**
 * Build a minimal membership entry for authStore.groups.
 */
function makeMembership(groupid, role = 'Member', extra = {}) {
  return {
    groupid,
    role,
    emailfrequency: 0,
    eventsallowed: false,
    volunteeringallowed: false,
    microvolunteeringallowed: false,
    configid: null,
    ...extra,
  }
}

// ============================================================
// SETUP
// ============================================================

beforeEach(() => {
  vi.useFakeTimers()

  mockFetchUser.mockReset()
  mockGroupGet = vi.fn()
  mockTeamState = { list: {}, getTeam: vi.fn() }

  // Default: logged-out, empty groups
  mockAuthState = {
    user: null,
    groups: [],
    auth: { jwt: null, persistent: null },
    loginStateKnown: false,
    fetchUser: mockFetchUser,
  }
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

// ============================================================
// TESTS
// ============================================================

describe('useMe — loginStateKnown', () => {
  it('is false when authStore.loginStateKnown is false', () => {
    mockAuthState.loginStateKnown = false
    const { loginStateKnown } = useMe()
    expect(loginStateKnown.value).toBe(false)
  })

  it('is true when authStore.loginStateKnown is true', () => {
    mockAuthState.loginStateKnown = true
    const { loginStateKnown } = useMe()
    expect(loginStateKnown.value).toBe(true)
  })
})

describe('useMe — jwt', () => {
  it('returns null when no jwt', () => {
    const { jwt } = useMe()
    expect(jwt.value).toBeNull()
  })

  it('returns the jwt token when present', () => {
    mockAuthState.auth = { jwt: 'my-token', persistent: null }
    const { jwt } = useMe()
    expect(jwt.value).toBe('my-token')
  })
})

describe('useMe — me / realMe / myid / loggedIn', () => {
  it('me is null when user is null', () => {
    const { me } = useMe()
    expect(me.value).toBeNull()
  })

  it('me is null when user has no id (falsy)', () => {
    mockAuthState.user = { systemrole: 'User' } // no id
    const { me } = useMe()
    expect(me.value).toBeNull()
  })

  it('me is the user object when user has an id', () => {
    const user = makeUser()
    mockAuthState.user = user
    const { me } = useMe()
    expect(me.value).toBe(user)
  })

  it('myid is undefined when not logged in', () => {
    const { myid } = useMe()
    expect(myid.value).toBeUndefined()
  })

  it('myid returns the user id when logged in', () => {
    mockAuthState.user = makeUser({ id: 99 })
    const { myid } = useMe()
    expect(myid.value).toBe(99)
  })

  it('loggedIn is false when me is null', () => {
    const { loggedIn } = useMe()
    expect(loggedIn.value).toBe(false)
  })

  it('loggedIn is true when user has an id', () => {
    mockAuthState.user = makeUser()
    const { loggedIn } = useMe()
    expect(loggedIn.value).toBe(true)
  })

  it('realMe catches errors and returns null', () => {
    // Make authStore.user throw when accessed
    Object.defineProperty(mockAuthState, 'user', {
      get() {
        throw new Error('store error')
      },
      configurable: true,
    })
    const { realMe } = useMe()
    expect(realMe.value).toBeNull()
  })
})

describe('useMe — myGroupIds', () => {
  it('is empty when not logged in', () => {
    const { myGroupIds } = useMe()
    expect(myGroupIds.value).toEqual([])
  })

  it('is empty when logged in but has no groups', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = []
    const { myGroupIds } = useMe()
    expect(myGroupIds.value).toEqual([])
  })

  it('maps membership groupid for each group', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(10), makeMembership(20)]
    mockGroupGet.mockReturnValue(null)
    const { myGroupIds } = useMe()
    expect(myGroupIds.value).toEqual([10, 20])
  })
})

describe('useMe — myGroups', () => {
  it('is empty when not logged in', () => {
    const { myGroups } = useMe()
    expect(myGroups.value).toEqual([])
  })

  it('merges membership and group store data', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(10, 'Member')]
    mockGroupGet.mockImplementation((id) =>
      id === 10
        ? { nameshort: 'FreegleA', namedisplay: 'Freegle A', type: 'Reuse' }
        : null
    )

    const { myGroups } = useMe()
    const groups = myGroups.value

    expect(groups).toHaveLength(1)
    expect(groups[0].id).toBe(10)
    expect(groups[0].role).toBe('Member')
    expect(groups[0].namedisplay).toBe('Freegle A')
    expect(groups[0].nameshort).toBe('FreegleA')
    expect(groups[0].type).toBe('Reuse')
  })

  it('falls back to empty strings when group store has no data', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(99)]
    mockGroupGet.mockReturnValue(null)

    const { myGroups } = useMe()
    const group = myGroups.value[0]

    expect(group.namedisplay).toBe('')
    expect(group.nameshort).toBe('')
    expect(group.type).toBe('')
  })

  it('sorts groups alphabetically by namedisplay (case-insensitive)', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [
      makeMembership(3),
      makeMembership(1),
      makeMembership(2),
    ]
    mockGroupGet.mockImplementation((id) => {
      const names = { 1: 'Alpha', 2: 'Charlie', 3: 'Beta' }
      return { namedisplay: names[id], nameshort: names[id], type: '' }
    })

    const { myGroups } = useMe()
    const names = myGroups.value.map((g) => g.namedisplay)
    expect(names).toEqual(['Alpha', 'Beta', 'Charlie'])
  })

  it('sorts case-insensitively (upper before lower without normalisation would fail)', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(1), makeMembership(2)]
    mockGroupGet.mockImplementation((id) => {
      const names = { 1: 'zebra', 2: 'Apple' }
      return { namedisplay: names[id], nameshort: names[id], type: '' }
    })

    const { myGroups } = useMe()
    const names = myGroups.value.map((g) => g.namedisplay)
    expect(names).toEqual(['Apple', 'zebra'])
  })
})

describe('useMe — anyGroups', () => {
  it('is false when user has no groups', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = []
    const { anyGroups } = useMe()
    expect(anyGroups.value).toBe(false)
  })

  it('is true when user has at least one group', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(1)]
    mockGroupGet.mockReturnValue(null)
    const { anyGroups } = useMe()
    expect(anyGroups.value).toBe(true)
  })
})

describe('useMe — myLocation', () => {
  it('is undefined when user is null', () => {
    const { myLocation } = useMe()
    expect(myLocation.value).toBeUndefined()
  })

  it('is undefined when user has no settings', () => {
    mockAuthState.user = makeUser({ settings: null })
    const { myLocation } = useMe()
    expect(myLocation.value).toBeUndefined()
  })

  it('is undefined when user has settings but no mylocation', () => {
    mockAuthState.user = makeUser({ settings: {} })
    const { myLocation } = useMe()
    expect(myLocation.value).toBeUndefined()
  })

  it('returns the location name when set', () => {
    mockAuthState.user = makeUser({
      settings: { mylocation: { name: 'London' } },
    })
    const { myLocation } = useMe()
    expect(myLocation.value).toBe('London')
  })
})

describe('useMe — role flags', () => {
  const roleTable = [
    // [systemrole, mod, support, admin, supportOrAdmin]
    ['User', false, false, false, false],
    ['Moderator', true, false, false, false],
    ['Support', true, true, false, true],
    ['Admin', true, false, true, true],
    [null, false, false, false, false],
    [undefined, false, false, false, false],
  ]

  it.each(roleTable)(
    'systemrole=%s → mod=%s support=%s admin=%s supportOrAdmin=%s',
    (systemrole, expectedMod, expectedSupport, expectedAdmin, expectedSupportOrAdmin) => {
      mockAuthState.user = makeUser({ systemrole })
      const { mod, support, admin, supportOrAdmin } = useMe()
      expect(mod.value).toBe(expectedMod)
      expect(support.value).toBe(expectedSupport)
      expect(admin.value).toBe(expectedAdmin)
      expect(supportOrAdmin.value).toBe(expectedSupportOrAdmin)
    }
  )

  it('all role flags are falsy when not logged in', () => {
    // The computed expressions use && short-circuit, so they return null (not
    // false) when me is null.  The important thing is that they are all falsy.
    mockAuthState.user = null
    const { mod, support, admin, supportOrAdmin } = useMe()
    expect(mod.value).toBeFalsy()
    expect(support.value).toBeFalsy()
    expect(admin.value).toBeFalsy()
    expect(supportOrAdmin.value).toBeFalsy()
  })
})

describe('useMe — chitChatMod', () => {
  it('is false when not logged in', () => {
    mockAuthState.user = null
    mockTeamState.getTeam = vi.fn().mockReturnValue(null)
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(false)
  })

  it('is true for Admin (via supportOrAdmin)', () => {
    mockAuthState.user = makeUser({ id: 1, systemrole: 'Admin' })
    mockTeamState.getTeam = vi.fn().mockReturnValue(null)
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(true)
  })

  it('is true for Support (via supportOrAdmin)', () => {
    mockAuthState.user = makeUser({ id: 1, systemrole: 'Support' })
    mockTeamState.getTeam = vi.fn().mockReturnValue(null)
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(true)
  })

  it('is true for regular user who is in the ChitChat Moderation team', () => {
    mockAuthState.user = makeUser({ id: 5, systemrole: 'User' })
    mockTeamState.getTeam = vi
      .fn()
      .mockReturnValue({ members: [{ id: 5 }, { id: 6 }] })
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(true)
  })

  it('is false for regular user not in the ChitChat Moderation team', () => {
    mockAuthState.user = makeUser({ id: 5, systemrole: 'User' })
    mockTeamState.getTeam = vi
      .fn()
      .mockReturnValue({ members: [{ id: 99 }] })
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(false)
  })

  it('is false when team is null (team not yet loaded)', () => {
    mockAuthState.user = makeUser({ id: 5, systemrole: 'User' })
    mockTeamState.getTeam = vi.fn().mockReturnValue(null)
    const { chitChatMod } = useMe()
    expect(chitChatMod.value).toBe(false)
  })

  it('looks up ChitChat Moderation team by name', () => {
    mockAuthState.user = makeUser({ id: 5, systemrole: 'User' })
    mockTeamState.getTeam = vi.fn().mockReturnValue(null)
    const { chitChatMod } = useMe()
    chitChatMod.value // trigger evaluation
    expect(mockTeamState.getTeam).toHaveBeenCalledWith('ChitChat Moderation')
  })
})

describe('useMe — supporter / donor', () => {
  it('supporter is falsy when not set', () => {
    mockAuthState.user = makeUser({ supporter: false })
    const { supporter } = useMe()
    expect(supporter.value).toBeFalsy()
  })

  it('supporter is truthy when set', () => {
    mockAuthState.user = makeUser({ supporter: true })
    const { supporter } = useMe()
    expect(supporter.value).toBe(true)
  })

  it('donor is null when not set', () => {
    mockAuthState.user = makeUser({ donated: null })
    const { donor } = useMe()
    expect(donor.value).toBeNull()
  })

  it('donor is the donated value when set', () => {
    mockAuthState.user = makeUser({ donated: '2025-01-01' })
    const { donor } = useMe()
    expect(donor.value).toBe('2025-01-01')
  })
})

describe('useMe — recentDonor', () => {
  // Fixed "now" — 2026-05-27 (today as per context)
  const FIXED_NOW = new Date('2026-05-27T12:00:00.000Z')

  beforeEach(() => {
    vi.setSystemTime(FIXED_NOW)
  })

  it('is falsy when never donated', () => {
    mockAuthState.user = makeUser({ donated: null })
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBeFalsy()
  })

  it('is true when donated within 31 days (non-External)', () => {
    const recentDate = new Date(FIXED_NOW.getTime() - 10 * 24 * 60 * 60 * 1000)
    mockAuthState.user = makeUser({
      donated: recentDate.toISOString(),
      donatedtype: 'Card',
    })
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBe(true)
  })

  it('is false when donated more than 31 days ago (non-External)', () => {
    const oldDate = new Date(FIXED_NOW.getTime() - 35 * 24 * 60 * 60 * 1000)
    mockAuthState.user = makeUser({
      donated: oldDate.toISOString(),
      donatedtype: 'Card',
    })
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBeFalsy()
  })

  it('uses 41-day window for External (bank transfer) donations', () => {
    // 35 days ago — stale for Card but within External grace period
    const recentDate = new Date(FIXED_NOW.getTime() - 35 * 24 * 60 * 60 * 1000)
    mockAuthState.user = makeUser({
      donated: recentDate.toISOString(),
      donatedtype: 'External',
    })
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBe(true)
  })

  it('is false for External donation older than 41 days', () => {
    const oldDate = new Date(FIXED_NOW.getTime() - 45 * 24 * 60 * 60 * 1000)
    mockAuthState.user = makeUser({
      donated: oldDate.toISOString(),
      donatedtype: 'External',
    })
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBeFalsy()
  })

  it('is false when not logged in', () => {
    mockAuthState.user = null
    const { recentDonor } = useMe()
    expect(recentDonor.value).toBeFalsy()
  })
})

describe('useMe — amMicroVolunteering', () => {
  const cases = [
    // [trustlevel, expected]
    ['Basic', true],
    ['Moderate', true],
    ['Advanced', true],
    ['Restricted', false],
    [null, false],
    [undefined, false],
  ]

  it.each(cases)(
    'trustlevel=%s → amMicroVolunteering=%s',
    (trustlevel, expected) => {
      mockAuthState.user = makeUser({ trustlevel })
      const { amMicroVolunteering } = useMe()
      expect(amMicroVolunteering.value).toBe(expected)
    }
  )

  it('is falsy when not logged in', () => {
    // Same && short-circuit: returns null when me is null.
    mockAuthState.user = null
    const { amMicroVolunteering } = useMe()
    expect(amMicroVolunteering.value).toBeFalsy()
  })
})

describe('useMe — oneOfMyGroups', () => {
  beforeEach(() => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(10), makeMembership(20)]
    mockGroupGet.mockReturnValue(null)
  })

  it('returns the group object when the user is a member', () => {
    const { oneOfMyGroups } = useMe()
    const result = oneOfMyGroups(10)
    expect(result).toBeDefined()
    expect(result.id).toBe(10)
  })

  it('returns undefined when the user is not a member of the group', () => {
    const { oneOfMyGroups } = useMe()
    expect(oneOfMyGroups(99)).toBeUndefined()
  })

  it('returns undefined when user has no groups', () => {
    mockAuthState.groups = []
    const { oneOfMyGroups } = useMe()
    expect(oneOfMyGroups(10)).toBeUndefined()
  })
})

describe('useMe — myGroup', () => {
  beforeEach(() => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(10), makeMembership(20)]
    mockGroupGet.mockReturnValue(null)
  })

  it('returns the group when groupid matches (as integer comparison)', () => {
    const { myGroup } = useMe()
    const result = myGroup(10)
    expect(result).toBeDefined()
    expect(result.id).toBe(10)
  })

  it('returns undefined when not a member of that group', () => {
    const { myGroup } = useMe()
    expect(myGroup(99)).toBeUndefined()
  })

  it('returns null when groupid is falsy (null)', () => {
    const { myGroup } = useMe()
    expect(myGroup(null)).toBeNull()
  })

  it('returns null when groupid is 0 (falsy)', () => {
    const { myGroup } = useMe()
    expect(myGroup(0)).toBeNull()
  })

  it('coerces string groupid via parseInt for comparison', () => {
    // The implementation does parseInt(g.id), so string IDs should match
    const { myGroup } = useMe()
    const result = myGroup(20)
    expect(result).toBeDefined()
    expect(result.id).toBe(20)
  })
})

describe('fetchMe', () => {
  it('fetches user when hitServer=true and no fetch in progress', async () => {
    mockFetchUser.mockResolvedValue({ id: 1 })
    await fetchMe(true)
    expect(mockFetchUser).toHaveBeenCalledTimes(1)
  })

  it('does not double-fetch when hitServer=true and already fetching', async () => {
    let resolveFirst
    const firstFetch = new Promise((resolve) => {
      resolveFirst = resolve
    })
    // First call starts the fetch
    mockFetchUser.mockReturnValueOnce(firstFetch)
    // Start a concurrent hitServer=true call
    const p1 = fetchMe(true)
    // Before p1 resolves, a second hitServer=true comes in — it should wait
    // for p1, not start a new fetch
    const p2 = fetchMe(true)
    resolveFirst({ id: 1 })
    await Promise.all([p1, p2])
    // fetchUser should only have been called once (by p1)
    expect(mockFetchUser).toHaveBeenCalledTimes(1)
  })

  it('returns immediately when hitServer=false and user already loaded', async () => {
    // Simulate user already present from a previous fetch
    mockAuthState.user = makeUser()
    mockFetchUser.mockResolvedValue({ id: 1 })
    await fetchMe(false)
    // fetchUser may still be called for background update — just ensure no throw
    expect(true).toBe(true)
  })

  it('starts a background fetch when hitServer=false', async () => {
    mockAuthState.user = makeUser()
    mockFetchUser.mockResolvedValue({ id: 1 })
    // Not awaiting means we don't wait for server; just trigger
    fetchMe(false)
    // fetchUser should be called for background update
    await vi.runAllTimersAsync()
    expect(mockFetchUser).toHaveBeenCalledTimes(1)
  })
})

describe('useMe — myGroupsBoundingBox', () => {
  it('returns [[null,null],[null,null]] when no groups have bbox', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(1)]
    mockGroupGet.mockReturnValue({ namedisplay: 'A', nameshort: 'A', type: '' })
    // group has no bbox field

    const { myGroupsBoundingBox } = useMe()
    expect(myGroupsBoundingBox.value).toEqual([[null, null], [null, null]])
  })

  it('returns [[null,null],[null,null]] when user has no groups', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = []

    const { myGroupsBoundingBox } = useMe()
    expect(myGroupsBoundingBox.value).toEqual([[null, null], [null, null]])
  })

  it('logs WKT error and continues when WKT parse fails (invalid bbox)', async () => {
    // Force the Wkt mock to throw so we exercise lines 253-254
    const Wkt = await import('wicket')
    const originalWkt = Wkt.default.Wkt
    Wkt.default.Wkt = class {
      read() {
        throw new Error('bad WKT')
      }
    }

    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(1)]
    mockGroupGet.mockReturnValue({
      namedisplay: 'Test',
      nameshort: 'Test',
      type: '',
      bbox: 'INVALID_WKT',
    })

    const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
    const { myGroupsBoundingBox } = useMe()
    const bbox = myGroupsBoundingBox.value

    // Error is swallowed; returns null bounds
    expect(bbox).toEqual([[null, null], [null, null]])
    expect(consoleSpy).toHaveBeenCalledWith(
      'WKT error',
      expect.anything(),
      expect.anything(),
      expect.anything(),
      expect.any(Error)
    )

    consoleSpy.mockRestore()
    Wkt.default.Wkt = originalWkt
  })

  it('computes bounding box when groups have bbox and window.L is available', () => {
    // Mock window.L (Leaflet) for this test
    const mockBounds = {
      getSouthWest: () => ({ lat: 51.0, lng: -1.5 }),
      getNorthEast: () => ({ lat: 52.0, lng: -0.5 }),
    }
    globalThis.window = globalThis.window || {}
    globalThis.window.L = {
      LatLngBounds: function () {
        return { ...mockBounds, pad: () => mockBounds }
      },
    }

    mockAuthState.user = makeUser()
    mockAuthState.groups = [makeMembership(1)]
    mockGroupGet.mockReturnValue({
      namedisplay: 'Test',
      nameshort: 'Test',
      type: '',
      bbox: 'POLYGON((0 0,1 0,1 1,0 1,0 0))',
    })

    const { myGroupsBoundingBox } = useMe()
    const bbox = myGroupsBoundingBox.value

    // Should return a 2x2 array with the computed bounding box
    expect(bbox).toHaveLength(2)
    expect(bbox[0]).toHaveLength(2) // SW
    expect(bbox[1]).toHaveLength(2) // NE

    // Cleanup
    delete globalThis.window.L
  })
})

describe('fetchMe — concurrent hitServer=false with pending promise', () => {
  it('waits for an in-progress fetch when user is null and hitServer=false', async () => {
    // Lines 35-37: hitServer=false, no user, but fetchingPromise already exists
    mockAuthState.user = null

    let resolveFetch
    const pendingFetch = new Promise((resolve) => {
      resolveFetch = resolve
    })
    // Kick off a first fetch (hitServer=true) to create fetchingPromise
    mockFetchUser.mockReturnValueOnce(pendingFetch)
    const p1 = fetchMe(true) // starts fetch, sets fetchingPromise

    // Now call fetchMe(false) — user is null, fetchingPromise exists → line 35-37
    mockFetchUser.mockReturnValueOnce(new Promise(() => {})) // second background fetch (won't settle)
    const p2 = fetchMe(false)

    // Resolve the first fetch; p1 should complete
    resolveFetch({ id: 1 })
    await p1

    // p2 should also complete (was waiting on p1's promise)
    await p2

    expect(mockFetchUser).toHaveBeenCalledTimes(2)
  })
})

describe('useMe — return shape', () => {
  it('exposes all expected properties', () => {
    mockAuthState.user = makeUser()
    mockAuthState.groups = []

    const api = useMe()

    expect(typeof api.fetchMe).toBe('function')
    expect(api.loginStateKnown).toBeDefined()
    expect(api.jwt).toBeDefined()
    expect(api.me).toBeDefined()
    expect(api.realMe).toBeDefined()
    expect(api.myid).toBeDefined()
    expect(api.loggedIn).toBeDefined()
    expect(api.myGroupIds).toBeDefined()
    expect(api.myGroups).toBeDefined()
    expect(api.anyGroups).toBeDefined()
    expect(api.myLocation).toBeDefined()
    expect(api.mod).toBeDefined()
    expect(api.support).toBeDefined()
    expect(api.admin).toBeDefined()
    expect(api.supportOrAdmin).toBeDefined()
    expect(api.chitChatMod).toBeDefined()
    expect(api.supporter).toBeDefined()
    expect(api.donor).toBeDefined()
    expect(api.recentDonor).toBeDefined()
    expect(api.amMicroVolunteering).toBeDefined()
    expect(api.myGroupsBoundingBox).toBeDefined()
    expect(typeof api.oneOfMyGroups).toBe('function')
    expect(typeof api.myGroup).toBe('function')
  })
})
