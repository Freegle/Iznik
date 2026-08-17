import { describe, it, expect, vi, beforeEach } from 'vitest'

const mockComposeStore = {
  group: null,
  postcode: null,
  email: null,
  all: [],
  messages: [],
  uploading: true,
  setPostcode: vi.fn(),
  setEmail: vi.fn(),
  prune: vi.fn(),
  add: vi.fn(() => 42),
  setType: vi.fn(),
  attachments: vi.fn(() => ({})),
  messageValid: vi.fn(() => true),
  noGroups: false,
  postcodeValid: true,
  message: vi.fn(),
  clearMessage: vi.fn(),
  submit: vi.fn(),
}

const mockGroupStoreGet = vi.fn()
const mockGroupStoreFetch = vi.fn()

let mockAuthUser = null
const mockLogin = vi.fn()
const mockSaveAndGet = vi.fn()

const mockMessageStore = {
  fetchByUser: vi.fn().mockResolvedValue([]),
  fetch: vi.fn(),
}

const mockTrackConversion = vi.fn()

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => mockComposeStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    get: mockGroupStoreGet,
    fetch: mockGroupStoreFetch,
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return mockAuthUser
    },
    login: mockLogin,
    saveAndGet: mockSaveAndGet,
  }),
}))

vi.mock('~/composables/useTrackConversion', () => ({
  trackConversion: (...args) => mockTrackConversion(...args),
}))

describe('useCompose setup()/clearItem()/postcodeClear()/freegleIt()', () => {
  let mod

  beforeEach(async () => {
    vi.clearAllMocks()
    vi.stubGlobal('useRoute', () => ({ query: {} }))
    mockComposeStore.group = null
    mockComposeStore.postcode = null
    mockComposeStore.email = null
    mockComposeStore.all = []
    mockComposeStore.messages = []
    mockComposeStore.noGroups = false
    mockComposeStore.postcodeValid = true
    mockAuthUser = null
    mockGroupStoreGet.mockReturnValue(null)
    mod = await import('~/composables/useCompose.js')
  })

  describe('setup()', () => {
    it('seeds a blank message of the type when none exists yet', () => {
      mockComposeStore.messages = []
      mod.setup('Offer')

      expect(mockComposeStore.add).toHaveBeenCalled()
      expect(mockComposeStore.setType).toHaveBeenCalledWith({
        id: 42,
        type: 'Offer',
      })
    })

    it('does not seed a message when one of the type already exists', () => {
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      expect(mockComposeStore.add).not.toHaveBeenCalled()
    })

    it('resets uploading and prunes old messages', () => {
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      expect(mockComposeStore.uploading).toBe(false)
      expect(mockComposeStore.prune).toHaveBeenCalled()
    })

    it('sets loggedIn true and loads own active posts when a user is present', async () => {
      mockAuthUser = { id: 99, email: 'me@example.com' }
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mockMessageStore.fetchByUser.mockResolvedValue([{ id: 5 }])

      const api = mod.setup('Offer')

      expect(api.loggedIn.value).toBe(true)
      await Promise.resolve()
      await Promise.resolve()
      expect(mockMessageStore.fetchByUser).toHaveBeenCalledWith(99, true)
    })

    it('sets loggedIn false when there is no user', () => {
      mockAuthUser = null
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')
      expect(api.loggedIn.value).toBe(false)
    })

    it('fetches the group when the compose store already has one set', () => {
      mockComposeStore.group = 55
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')
      expect(mockGroupStoreFetch).toHaveBeenCalledWith(55)
    })

    it('takes the initial postcode from the route query when present', () => {
      vi.stubGlobal('useRoute', () => ({ query: { postcode: 'SW1A 1AA' } }))
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')
      expect(api.initialPostcode.value).toBe('SW1A 1AA')
    })

    it('falls back to the compose store postcode name when no route query', () => {
      mockComposeStore.postcode = { name: 'EH1 1AA' }
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')
      expect(api.initialPostcode.value).toBe('EH1 1AA')
    })

    describe('group computed', () => {
      it('resolves via the group store when a group id is set', () => {
        mockComposeStore.group = 7
        mockGroupStoreGet.mockReturnValue({ id: 7, settings: {} })
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.group.value).toEqual({ id: 7, settings: {} })
      })

      it('is null when no group id is set', () => {
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.group.value).toBeNull()
      })

      it('setting the group writes back to the compose store', () => {
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        api.group.value = 12
        expect(mockComposeStore.group).toBe(12)
      })
    })

    describe('postcode computed', () => {
      it('get/set delegate to the compose store', () => {
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        api.postcode.value = { name: 'X' }
        expect(mockComposeStore.setPostcode).toHaveBeenCalledWith({
          name: 'X',
        })
      })
    })

    describe('email computed', () => {
      it('uses the compose store email when present', () => {
        mockComposeStore.email = 'stored@example.com'
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.email.value).toBe('stored@example.com')
      })

      it('falls back to the logged-in user email when the store has none', () => {
        mockAuthUser = { id: 1, email: 'user@example.com' }
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.email.value).toBe('user@example.com')
      })

      it('setting the email delegates to setEmail', () => {
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        api.email.value = 'new@example.com'
        expect(mockComposeStore.setEmail).toHaveBeenCalledWith(
          'new@example.com'
        )
      })
    })

    describe('ids computed', () => {
      // composeStore.messages is a sparse array indexed BY id (messages[id] = message),
      // not a plain list -- mirror that here rather than pushing at index 0.
      const byId = (...msgs) => {
        const arr = []
        msgs.forEach((m) => {
          arr[m.id] = m
        })
        return arr
      }

      it('includes only messages of the right type that exist in the messages array', () => {
        mockAuthUser = { id: 1 }
        mockComposeStore.messages = byId(
          { id: 1, type: 'Offer' },
          { id: 2, type: 'Offer' },
          { id: 3, type: 'Wanted' }
        )
        mockComposeStore.all = [
          { id: 1, type: 'Offer' },
          { id: 2, type: 'Offer' },
          { id: 3, type: 'Wanted' },
          { id: 4, type: 'Offer' }, // not in messages[] -- excluded
        ]
        const api = mod.setup('Offer')
        expect(api.ids.value).toEqual([1, 2])
      })

      it('excludes messages saved by a different user', () => {
        mockAuthUser = { id: 1 }
        mockComposeStore.messages = byId({ id: 1, type: 'Offer' })
        mockComposeStore.all = [{ id: 1, type: 'Offer', savedBy: 999 }]
        const api = mod.setup('Offer')
        expect(api.ids.value).toEqual([])
      })

      it('includes a message saved by the current user', () => {
        mockAuthUser = { id: 1 }
        mockComposeStore.messages = byId({ id: 1, type: 'Offer' })
        mockComposeStore.all = [{ id: 1, type: 'Offer', savedBy: 1 }]
        const api = mod.setup('Offer')
        expect(api.ids.value).toEqual([1])
      })
    })

    describe('closed computed', () => {
      it('reflects the current group settings', () => {
        mockComposeStore.group = 7
        mockGroupStoreGet.mockReturnValue({ id: 7, settings: { closed: true } })
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.closed.value).toBe(true)
      })

      it('is undefined when there is no group', () => {
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.closed.value).toBeUndefined()
      })
    })

    describe('notblank computed', () => {
      it('is true when a message of this type has an item name', () => {
        mockComposeStore.all = [{ id: 1, type: 'Offer', item: 'Sofa' }]
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.notblank.value).toBeTruthy()
      })

      it('is true when a message of this type has attachments', () => {
        mockComposeStore.attachments.mockReturnValue({ a: {}, b: {} })
        mockComposeStore.all = [{ id: 1, type: 'Offer' }]
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.notblank.value).toBeTruthy()
      })

      it('is false when there are no messages', () => {
        mockComposeStore.all = []
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.notblank.value).toBe(false)
      })

      it('is false when the only message is a different type', () => {
        mockComposeStore.all = [{ id: 1, type: 'Wanted', item: 'Bike' }]
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.notblank.value).toBe(false)
      })
    })

    describe('emailIsntOurs computed', () => {
      it('is false when there is no email at all', () => {
        mockComposeStore.email = null
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.emailIsntOurs.value).toBe(false)
      })

      it('is true when logged out with an email set', () => {
        mockComposeStore.email = 'a@b.com'
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.emailIsntOurs.value).toBe(true)
      })

      it('is false when logged in and the email matches one of the user emails', () => {
        mockAuthUser = { id: 1, emails: [{ email: 'A@B.com' }] }
        mockComposeStore.email = 'a@b.com'
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.emailIsntOurs.value).toBe(false)
      })

      it('is true when logged in and the email matches none of the user emails', () => {
        mockAuthUser = { id: 1, emails: [{ email: 'other@example.com' }] }
        mockComposeStore.email = 'a@b.com'
        mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
        const api = mod.setup('Offer')
        expect(api.emailIsntOurs.value).toBe(true)
      })
    })
  })

  describe('clearItem', () => {
    it('clears an existing message by id', () => {
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')
      mockComposeStore.message.mockReturnValue({ id: 1 })

      mod.clearItem(1)

      expect(mockComposeStore.clearMessage).toHaveBeenCalledWith(1)
    })

    it('seeds a blank message of the current type when no id is given and none exists', () => {
      mockComposeStore.messages = []
      mod.setup('Offer')
      mockComposeStore.add.mockReturnValue(77)

      mod.clearItem()

      expect(mockComposeStore.add).toHaveBeenCalled()
      expect(mockComposeStore.setType).toHaveBeenCalledWith({
        id: 77,
        type: 'Offer',
      })
    })

    it('does nothing when no id given but a message of the type already exists', () => {
      mockComposeStore.messages = [{ id: 5, type: 'Offer' }]
      mod.setup('Offer')
      mockComposeStore.add.mockClear()

      mod.clearItem()

      expect(mockComposeStore.add).not.toHaveBeenCalled()
    })
  })

  describe('postcodeClear', () => {
    it('nulls both postcode and group', () => {
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      mod.postcodeClear()

      expect(mockComposeStore.setPostcode).toHaveBeenCalledWith(null)
      expect(mockComposeStore.group).toBeNull()
    })
  })

  describe('freegleIt error classification', () => {
    const router = { push: vi.fn().mockResolvedValue(undefined) }

    it('flags unvalidatedEmail on an "Unvalidated email" error', async () => {
      mockComposeStore.submit.mockRejectedValue(
        new Error('Unvalidated email address')
      )
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(api.unvalidatedEmail.value).toBe(true)
      expect(api.submitting.value).toBe(false)
    })

    it('flags notAllowed on a "Not allowed to post on this group" error', async () => {
      mockComposeStore.submit.mockRejectedValue(
        new Error('Not allowed to post on this group')
      )
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(api.notAllowed.value).toBe(true)
    })

    it('flags wentWrong for any other error message', async () => {
      mockComposeStore.submit.mockRejectedValue(new Error('server exploded'))
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(api.wentWrong.value).toBe(true)
    })

    it('flags wentWrong when the rejection has no message at all', async () => {
      mockComposeStore.submit.mockRejectedValue({})
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(api.wentWrong.value).toBe(true)
    })

    it('tracks a Give conversion and navigates to myposts on success', async () => {
      mockComposeStore.submit.mockResolvedValue([{ id: 1, groupid: 5 }])
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(mockTrackConversion).toHaveBeenCalledWith('Give an Item')
      expect(router.push).toHaveBeenCalledWith(
        expect.objectContaining({ name: 'myposts' })
      )
    })

    it('tracks a Find conversion for a Wanted post', async () => {
      mockComposeStore.submit.mockResolvedValue([])
      mockComposeStore.messages = [{ id: 1, type: 'Wanted' }]
      mod.setup('Wanted')

      await mod.freegleIt('Wanted', router)

      expect(mockTrackConversion).toHaveBeenCalledWith('Find an Item')
    })

    it('also tracks registration when submit created a new user', async () => {
      mockComposeStore.submit.mockResolvedValue([
        { id: 1, newuser: true, newpassword: 'pw' },
      ])
      mockComposeStore.postcode = null
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(mockLogin).toHaveBeenCalledWith(
        expect.objectContaining({ password: 'pw' })
      )
      expect(mockTrackConversion).toHaveBeenCalledWith('Register with Website')
    })

    it('saves the postcode to the new user settings when one was set', async () => {
      mockComposeStore.submit.mockResolvedValue([
        { id: 1, newuser: true, newpassword: 'pw' },
      ])
      mockComposeStore.postcode = { id: 9, name: 'X' }
      mockAuthUser = { id: 1, settings: {} }
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      mod.setup('Offer')

      await mod.freegleIt('Offer', router)

      expect(mockSaveAndGet).toHaveBeenCalledWith(
        expect.objectContaining({
          settings: expect.objectContaining({
            mylocation: { id: 9, name: 'X' },
          }),
        })
      )
    })

    it('throws when router.push reports a navigation failure', async () => {
      const failingRouter = {
        push: vi.fn().mockResolvedValue({ type: 4 }),
      }
      mockComposeStore.submit.mockResolvedValue([])
      mockComposeStore.messages = [{ id: 1, type: 'Offer' }]
      const api = mod.setup('Offer')

      await mod.freegleIt('Offer', failingRouter)

      // The throw inside the try block is caught by the outer catch,
      // which then classifies it as a generic failure.
      expect(api.wentWrong.value).toBe(true)
    })
  })
})
