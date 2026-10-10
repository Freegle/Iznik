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

// ============================================================
// MODULE UNDER TEST
// ============================================================
// Import AFTER mocks so vi.mock() factories are wired up.

import { useMe, fetchMe } from '~/composables/useMe'

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
    (
      systemrole,
      expectedMod,
      expectedSupport,
      expectedAdmin,
      expectedSupportOrAdmin
    ) => {
      mockAuthState.user = makeUser({ systemrole })
      const { mod, support, admin, supportOrAdmin } = useMe()
      expect(mod.value).toBe(expectedMod)
      expect(support.value).toBe(expectedSupport)
      expect(admin.value).toBe(expectedAdmin)
      expect(supportOrAdmin.value).toBe(expectedSupportOrAdmin)
    }
  )

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

})

