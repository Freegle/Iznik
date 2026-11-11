import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Mock dependent stores
const mockUserStore = {
  list: {},
  fetchMultiple: vi.fn().mockResolvedValue(undefined),
}

const mockMemberStore = {
  list: {},
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => mockMemberStore,
}))

const mockPatch = vi.fn().mockResolvedValue({})
const mockAdd = vi.fn().mockResolvedValue({})
const mockDel = vi.fn().mockResolvedValue({})

vi.mock('~/api', () => ({
  default: () => ({
    spammers: {
      fetch: vi.fn().mockResolvedValue({ spammers: [], context: null }),
      patch: mockPatch,
      add: mockAdd,
      del: mockDel,
    },
  }),
}))

vi.mock('~/composables/useMe', () => ({
  fetchMe: vi.fn().mockResolvedValue(undefined),
}))

describe('spammer store', () => {
  let spammerStore

  beforeEach(async () => {
    setActivePinia(createPinia())
    mockUserStore.list = {}
    mockMemberStore.list = {}
    mockPatch.mockClear()
    mockAdd.mockClear()
    mockDel.mockClear()

    // Import fresh store after mocks are set
    const { useSpammerStore } = await import('~/stores/spammer')
    spammerStore = useSpammerStore()
  })

  describe('addAll', () => {
    it('includes spam_users.id in member.spammer so PATCH calls send correct id', () => {
      // Simulate a spammer row returned by GET /spammers.
      // item.id is the spam_users.id — required by PATCH /modtools/spammers.
      const spammerItem = {
        id: 42, // spam_users.id — this is what PATCH needs
        userid: 100,
        collection: 'PendingAdd',
        reason: 'Sent spam',
        added: '2026-04-04T00:00:00Z',
        byuserid: null,
        heldby: null,
        heldat: null,
      }

      // Simulate userStore having fetched the user.
      mockUserStore.list[100] = {
        id: 100,
        displayname: 'Test User',
        email: 'test@example.com',
      }

      spammerStore.addAll([spammerItem])

      // The assembled member in memberStore must have spammer.id set to
      // spam_users.id (42), not undefined. Without this, PATCH /modtools/spammers
      // receives id=0 and returns 400 "Missing id".
      expect(mockMemberStore.list[100].spammer.id).toBe(42)
      expect(spammerStore.list[0].user.spammer.id).toBe(42)
    })

    it('includes spam_users.id in spammer object for fallback (user not in store)', () => {
      // When userStore doesn't have the user, fallback path is used.
      const spammerItem = {
        id: 99,
        userid: 200,
        collection: 'PendingAdd',
        reason: 'Suspected spam',
        added: '2026-04-04T00:00:00Z',
        byuserid: null,
      }

      // No user in userStore — triggers fallback branch
      mockUserStore.list = {}

      spammerStore.addAll([spammerItem])

      expect(mockMemberStore.list[200].spammer.id).toBe(99)
      expect(spammerStore.list[0].user.spammer.id).toBe(99)
    })

    it('propagates heldby into member object so Hold button hides after hold', () => {
      // Bug: addAll() was not setting item.heldby on the member object.
      // Template uses !member.heldby to show/hide Hold/Confirm/Reject buttons.
      // Without this fix, held entries always show Hold button again after re-fetch.
      const spammerItem = {
        id: 42,
        userid: 100,
        collection: 'PendingAdd',
        reason: 'Sent spam',
        added: '2026-04-04T00:00:00Z',
        byuserid: null,
        heldby: 999, // held by moderator with id 999
        heldat: '2026-04-07T10:00:00Z',
      }

      mockUserStore.list[100] = {
        id: 100,
        displayname: 'Test User',
        email: 'test@example.com',
      }

      spammerStore.addAll([spammerItem])

      const member = mockMemberStore.list[100]
      // member.heldby must be set so template's !member.heldby is falsy
      // and Hold/Confirm/Reject buttons are hidden.
      expect(member.heldby).toBe(999)
      expect(member.spammer.heldby).toBe(999)
      expect(member.spammer.heldat).toBe('2026-04-07T10:00:00Z')
    })

    it('sets heldby to null when entry is not held', () => {
      const spammerItem = {
        id: 42,
        userid: 100,
        collection: 'PendingAdd',
        reason: 'Sent spam',
        added: '2026-04-04T00:00:00Z',
        byuserid: null,
        heldby: null,
        heldat: null,
      }

      mockUserStore.list[100] = {
        id: 100,
        displayname: 'Test User',
        email: 'test@example.com',
      }

      spammerStore.addAll([spammerItem])

      const member = mockMemberStore.list[100]
      expect(member.heldby).toBeNull()
      expect(member.spammer.heldby).toBeNull()
    })
  })

  describe('confirm', () => {
    it('sends the spam_users.id to PATCH so the Go API does not return 400', async () => {
      // Set up the store with a spammer (as if fetched from API).
      mockUserStore.list[100] = {
        id: 100,
        displayname: 'Test User',
        email: 'test@example.com',
      }
      spammerStore.addAll([
        {
          id: 42,
          userid: 100,
          collection: 'PendingAdd',
          reason: 'Sent spam',
          added: '2026-04-04T00:00:00Z',
          byuserid: null,
        },
      ])

      await spammerStore.confirm({ id: 42, userid: 100 })

      // The PATCH must receive id=42 (spam_users.id), not 0 or undefined.
      // id=0 causes Go API to return 400 "Missing id".
      expect(mockPatch).toHaveBeenCalledWith(
        expect.objectContaining({ id: 42 })
      )
    })

    it('does not call PATCH when id is missing (guard prevents 400)', async () => {
      await spammerStore.confirm({ id: null, userid: 100 })
      expect(mockPatch).not.toHaveBeenCalled()
    })
  })
})

describe('spammer store bulk actions', () => {
  let spammerStore

  beforeEach(async () => {
    setActivePinia(createPinia())
    mockUserStore.list = {}
    mockMemberStore.list = {}
    mockPatch.mockReset()
    mockPatch.mockResolvedValue({})
    mockAdd.mockReset()
    mockAdd.mockResolvedValue({})
    const { useSpammerStore } = await import('~/stores/spammer')
    spammerStore = useSpammerStore()
  })

  function seedPending(ids) {
    spammerStore.addAll(
      ids.map((id) => ({
        id,
        userid: 1000 + id,
        collection: 'PendingAdd',
        reason: 'Sent spam',
        added: '2026-10-24T00:00:00Z',
        byuserid: null,
      }))
    )
  }

  describe('confirmMany', () => {
    it('PATCHes every selected row to Spammer and removes them from the list', async () => {
      seedPending([1, 2, 3])

      const result = await spammerStore.confirmMany([
        { id: 1, userid: 1001 },
        { id: 2, userid: 1002 },
        { id: 3, userid: 1003 },
      ])

      expect(mockPatch).toHaveBeenCalledTimes(3)
      for (const id of [1, 2, 3]) {
        expect(mockPatch).toHaveBeenCalledWith({
          id,
          userid: 1000 + id,
          collection: 'Spammer',
        })
      }
      expect(result.done).toHaveLength(3)
      expect(result.failed).toHaveLength(0)
      expect(spammerStore.getList('PendingAdd')).toHaveLength(0)
    })

    it('runs at most five requests at once', async () => {
      seedPending([1, 2, 3, 4, 5, 6, 7])
      let inFlight = 0
      let peak = 0
      mockPatch.mockImplementation(
        () =>
          new Promise((resolve) => {
            inFlight++
            peak = Math.max(peak, inFlight)
            setTimeout(() => {
              inFlight--
              resolve({})
            }, 1)
          })
      )

      await spammerStore.confirmMany(
        [1, 2, 3, 4, 5, 6, 7].map((id) => ({ id, userid: 1000 + id }))
      )

      expect(mockPatch).toHaveBeenCalledTimes(7)
      expect(peak).toBe(5)
    })

    it('reports a row held by another moderator as failed and keeps the rest', async () => {
      seedPending([1, 2])
      mockPatch.mockImplementation(({ id }) => {
        if (id === 2) {
          const e = new Error('409')
          e.response = {
            status: 409,
            data: { heldby: 77, heldbyname: 'Pat' },
          }
          return Promise.reject(e)
        }
        return Promise.resolve({})
      })

      const result = await spammerStore.confirmMany([
        { id: 1, userid: 1001 },
        { id: 2, userid: 1002 },
      ])

      expect(result.done.map((d) => d.id)).toEqual([1])
      expect(result.failed).toHaveLength(1)
      expect(result.failed[0].item.id).toBe(2)
      expect(result.failed[0].error.heldByOtherMod).toBe(true)
      expect(result.failed[0].error.message).toContain('Pat')
      // The confirmed one left the list; the held one stays for the page to
      // refetch with its own search, which heldConflict asks it to do.
      expect(spammerStore.byId(1)).toBeNull()
      expect(spammerStore.byId(2)).not.toBeNull()
      expect(result.heldConflict).toBe(true)
    })

    it('skips items with no spam_users id rather than sending id=0', async () => {
      seedPending([1])
      const result = await spammerStore.confirmMany([
        { id: 1, userid: 1001 },
        { id: null, userid: 1002 },
      ])
      expect(mockPatch).toHaveBeenCalledTimes(1)
      expect(result.done).toHaveLength(1)
    })
  })

  describe('reportMany', () => {
    it('POSTs each userid as Pending Add with the shared reason and keeps the new ids', async () => {
      mockAdd.mockImplementation(({ userid }) =>
        Promise.resolve({ ret: 0, id: userid * 10 })
      )
      const result = await spammerStore.reportMany({
        userids: [5, 6],
        reason: 'Same name, 50 accounts',
      })

      expect(mockAdd).toHaveBeenCalledTimes(2)
      expect(mockAdd).toHaveBeenCalledWith({
        userid: 5,
        collection: 'PendingAdd',
        reason: 'Same name, 50 accounts',
      })
      expect(result.done).toEqual([5, 6])
      expect(result.skipped).toEqual([])
      expect(result.values.get(5)).toBe(50)
      expect(result.failed).toHaveLength(0)
    })

    it('never adds directly as a confirmed spammer', async () => {
      mockAdd.mockResolvedValue({ ret: 0, id: 1 })
      await spammerStore.reportMany({ userids: [5], reason: 'r' })
      for (const call of mockAdd.mock.calls) {
        expect(call[0].collection).toBe('PendingAdd')
      }
    })

    it('reports a member the server left alone as skipped, not done', async () => {
      // id 0 is the server saying a spam_users row already exists.
      mockAdd.mockImplementation(({ userid }) =>
        Promise.resolve({ ret: 0, id: userid === 6 ? 0 : 99 })
      )
      const result = await spammerStore.reportMany({
        userids: [5, 6, 7],
        reason: 'r',
      })
      expect(result.done).toEqual([5, 7])
      expect(result.skipped).toEqual([6])
    })

    it('returns the userids that failed so the moderator can retry them', async () => {
      mockAdd.mockImplementation(({ userid }) =>
        userid === 6
          ? Promise.reject(new Error('boom'))
          : Promise.resolve({ ret: 0, id: 1 })
      )

      const result = await spammerStore.reportMany({
        userids: [5, 6, 7],
        reason: 'r',
      })

      expect(result.done).toEqual([5, 7])
      expect(result.failed.map((f) => f.item)).toEqual([6])
    })
  })
})
