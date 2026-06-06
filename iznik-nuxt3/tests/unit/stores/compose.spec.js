import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockMessagePut = vi.fn()
const mockJoinAndPost = vi.fn()
const mockImagePost = vi.fn()
const mockMessageSubmit = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      put: mockMessagePut,
      joinAndPost: mockJoinAndPost,
      submit: mockMessageSubmit,
    },
    image: {
      post: mockImagePost,
    },
  }),
}))

const mockMessageFetch = vi.fn()
const mockMessageUpdate = vi.fn()
const mockMessagePatch = vi.fn()
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: mockMessageFetch,
    update: mockMessageUpdate,
    patch: mockMessagePatch,
  }),
}))

const mockSetAuth = vi.fn()
let mockAuthUser = null
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    setAuth: mockSetAuth,
    user: mockAuthUser,
    loggedInEver: false,
  }),
}))

describe('compose store', () => {
  let useComposeStore

  beforeEach(async () => {
    vi.clearAllMocks()
    mockAuthUser = null
    setActivePinia(createPinia())
    const mod = await import('~/stores/compose')
    useComposeStore = mod.useComposeStore
  })

  describe('initial state', () => {
    it('starts with correct defaults', () => {
      const store = useComposeStore()
      expect(store.email).toBeNull()
      expect(store.postcode).toBeNull()
      expect(store.group).toBeNull()
      expect(store.messages).toEqual([])
      expect(store.attachmentBump).toBe(1)
      expect(store._progress).toBe(1)
      expect(store.max).toBe(4)
      expect(store.uploading).toBe(false)
      expect(store.lastSubmitted).toBe(0)
    })
  })

  describe('init', () => {
    it('sets config and api', () => {
      const store = useComposeStore()
      store.init({ public: {} })
      expect(store.config).toEqual({ public: {} })
      expect(store.$api).toBeDefined()
    })
  })

  describe('setEmail', () => {
    it('sets email and timestamp', () => {
      const store = useComposeStore()
      store.setEmail('test@example.com')
      expect(store.email).toBe('test@example.com')
      expect(store.emailAt).toBeGreaterThan(0)
    })
  })

  describe('setPostcode', () => {
    it('strips groupsnear to minimal fields', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      store.setPostcode({
        id: 1,
        name: 'SW1A 1AA',
        groupsnear: [
          {
            id: 10,
            nameshort: 'Westminster',
            namedisplay: 'Westminster Freegle',
            settings: { closed: false, someOther: true },
            extraField: 'removed',
          },
        ],
      })

      expect(store.postcode.name).toBe('SW1A 1AA')
      expect(store.postcode.groupsnear[0]).toEqual({
        id: 10,
        nameshort: 'Westminster',
        namedisplay: 'Westminster Freegle',
        settings: { closed: false },
      })
      expect(store.postcode.groupsnear[0].extraField).toBeUndefined()
      logSpy.mockRestore()
    })

    it('does nothing when postcode has no groupsnear', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.setPostcode({ id: 1, name: 'SW1A' })
      expect(store.postcode).toBeNull()
      logSpy.mockRestore()
    })
  })

  describe('createDraft — bulk offer (clearance)', () => {
    it('forwards bulkitems/bulkslots/accessinstructions and filters blank-name items', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 5 }
      store.group = 10
      mockMessagePut.mockResolvedValueOnce({ id: 999 })

      const id = await store.createDraft(
        {
          type: 'Offer',
          item: 'Office clearance',
          description: 'desc',
          availablenow: 1,
          attachments: [],
          bulkitems: [
            {
              name: 'Desk',
              quantity: 2,
              condition: 'Good',
              attachments: [7, 9],
            },
            { name: '   ', quantity: 1 }, // blank name — must be dropped
          ],
          bulkslots: ['Tue 10am'],
          accessinstructions: 'Side gate',
        },
        'me@example.com'
      )

      expect(id).toBe(999)
      expect(mockMessagePut).toHaveBeenCalledTimes(1)
      const sent = mockMessagePut.mock.calls[0][0]
      expect(sent.bulkitems).toEqual([
        {
          name: 'Desk',
          quantity: 2,
          condition: 'Good',
          dimensions: null,
          photourl: null,
          description: null,
          attachments: [7, 9],
        },
      ])
      expect(sent.bulkslots).toEqual(['Tue 10am'])
      expect(sent.accessinstructions).toBe('Side gate')
    })

    it('omits the bulk fields entirely for an ordinary single-item post', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 5 }
      mockMessagePut.mockResolvedValueOnce({ id: 1 })

      await store.createDraft(
        {
          type: 'Offer',
          item: 'Chair',
          description: '',
          availablenow: 1,
          attachments: [],
        },
        'me@example.com'
      )

      const sent = mockMessagePut.mock.calls[0][0]
      expect(sent.bulkitems).toBeUndefined()
      expect(sent.bulkslots).toBeUndefined()
      expect(sent.accessinstructions).toBeUndefined()
    })
  })

  describe('add / ensureMessage', () => {
    it('adds a new message and returns its id', () => {
      const store = useComposeStore()
      const id = store.add()
      expect(id).toBe(0)
      expect(store.messages[0]).toEqual({ id: 0 })
    })

    it('adds multiple messages with sequential ids', () => {
      const store = useComposeStore()
      store.add()
      const id2 = store.add()
      expect(id2).toBe(1)
      expect(store.messages).toHaveLength(2)
    })

    it('ensureMessage does not overwrite existing', () => {
      const store = useComposeStore()
      store.messages[0] = { id: 0, type: 'Offer', item: 'Sofa' }
      store.ensureMessage(0)
      expect(store.messages[0].item).toBe('Sofa')
    })
  })

  describe('setMessage', () => {
    it('stores message with savedAt and savedBy', () => {
      const store = useComposeStore()
      store.add()
      store.setMessage(0, { id: 0, type: 'Offer' }, { id: 42 })
      expect(store.messages[0].savedAt).toBeGreaterThan(0)
      expect(store.messages[0].savedBy).toBe(42)
    })

    it('tracks lastSubmitted for submitted messages', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.add()
      store.setMessage(0, { id: 100, submitted: true }, null)
      expect(store.lastSubmitted).toBe(100)
      logSpy.mockRestore()
    })

    it('keeps max lastSubmitted', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.add()
      store.add()
      store.setMessage(0, { id: 200, submitted: true }, null)
      store.setMessage(1, { id: 100, submitted: true }, null)
      expect(store.lastSubmitted).toBe(200)
      logSpy.mockRestore()
    })
  })

  describe('field setters', () => {
    let store

    beforeEach(() => {
      store = useComposeStore()
      store.add()
    })

    it('setType sets type and savedAt', () => {
      store.setType({ id: 0, type: 'Wanted' })
      expect(store.messages[0].type).toBe('Wanted')
      expect(store.messages[0].savedAt).toBeGreaterThan(0)
    })

    it('setItem sets item and savedAt', () => {
      store.setItem({ id: 0, item: 'Sofa' })
      expect(store.messages[0].item).toBe('Sofa')
    })

    it('setAvailableNow sets availablenow', () => {
      store.setAvailableNow(0, 1)
      expect(store.messages[0].availablenow).toBe(1)
    })

    it('setDeliveryPossible sets deliveryPossible', () => {
      store.setDeliveryPossible(0, true)
      expect(store.messages[0].deliveryPossible).toBe(true)
    })

    it('setDeadline sets deadline', () => {
      store.setDeadline(0, '2026-05-01')
      expect(store.messages[0].deadline).toBe('2026-05-01')
    })

    it('setAiDeclined sets aiDeclined', () => {
      store.setAiDeclined(0, true)
      expect(store.messages[0].aiDeclined).toBe(true)
    })

    it('setDescription sets description', () => {
      store.setDescription({ id: 0, description: 'Good condition' })
      expect(store.messages[0].description).toBe('Good condition')
    })
  })

  describe('attachment management', () => {
    let store

    beforeEach(() => {
      store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.add()
      logSpy.mockRestore()
    })

    it('addAttachment appends to message', () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.addAttachment({ id: 0, attachment: { id: 10, path: 'img.jpg' } })
      expect(store.messages[0].attachments).toHaveLength(1)
      expect(store.attachmentBump).toBe(2)
      logSpy.mockRestore()
    })

    it('addAttachment initializes attachments array if missing', () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.messages[0].attachments = undefined
      store.addAttachment({ id: 0, attachment: { id: 10 } })
      expect(store.messages[0].attachments).toHaveLength(1)
      logSpy.mockRestore()
    })

    it('setAttachmentsForMessage replaces all attachments', () => {
      store.setAttachmentsForMessage(0, [{ id: 1 }, { id: 2 }])
      expect(store.messages[0].attachments).toHaveLength(2)
    })

    it('removeAttachment filters by photoid', () => {
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.messages[0].attachments = [{ id: 10 }, { id: 20 }, { id: 30 }]
      store.removeAttachment({ id: 0, photoid: 20 })
      expect(store.messages[0].attachments).toHaveLength(2)
      expect(store.messages[0].attachments.map((a) => a.id)).toEqual([10, 30])
      logSpy.mockRestore()
    })
  })

  // A photo is pushed into the attachments with uploading:true BEFORE the
  // upload finishes, and this store persists to localStorage - so an
  // interrupted upload (reload, navigation, abort) leaves uploading:true on
  // disk with nothing left running to clear it. On the mobile give flow that
  // permanently disabled Next, hid the delete control and hid Skip, locking
  // the member out of posting on that device until they cleared site data.
  describe('sanitiseRestoredAttachments', () => {
    let store

    beforeEach(() => {
      store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.add()
      logSpy.mockRestore()
    })

    it('drops an attachment left mid-upload with no server id', () => {
      // Its preview is a blob: URL that died with the previous page, and it
      // has no server id, so there is nothing recoverable to show.
      store.messages[0].attachments = [
        {
          tempId: 'temp-1',
          preview: 'blob:https://www.ilovefreegle.org/dead',
          uploading: true,
          progress: 100,
          error: false,
        },
      ]

      store.sanitiseRestoredAttachments()

      expect(store.messages[0].attachments).toEqual([])
    })

    it('clears uploading on an attachment that did reach the server', () => {
      store.messages[0].attachments = [
        {
          id: 45447044,
          path: 'https://x/img.jpg',
          uploading: true,
          progress: 100,
        },
      ]

      store.sanitiseRestoredAttachments()

      expect(store.messages[0].attachments).toHaveLength(1)
      expect(store.messages[0].attachments[0].uploading).toBe(false)
      expect(store.messages[0].attachments[0].id).toBe(45447044)
    })

    it('leaves completed attachments untouched', () => {
      const done = [
        { id: 1, path: 'a.jpg' },
        { id: 2, path: 'b.jpg' },
      ]
      store.messages[0].attachments = [...done]

      store.sanitiseRestoredAttachments()

      expect(store.messages[0].attachments).toEqual(done)
    })

    it('keeps the good photos when only one was mid-upload', () => {
      store.messages[0].attachments = [
        { id: 1, path: 'a.jpg' },
        { tempId: 'temp-9', preview: 'blob:dead', uploading: true },
        { id: 2, path: 'b.jpg' },
      ]

      store.sanitiseRestoredAttachments()

      expect(store.messages[0].attachments.map((a) => a.id)).toEqual([1, 2])
    })

    it('bumps attachmentBump so the UI re-reads', () => {
      const before = store.attachmentBump
      store.messages[0].attachments = [{ tempId: 't', uploading: true }]

      store.sanitiseRestoredAttachments()

      expect(store.attachmentBump).toBeGreaterThan(before)
    })

    it('copes with messages that have no attachments', () => {
      store.messages[0].attachments = undefined
      store.messages.push(null)

      expect(() => store.sanitiseRestoredAttachments()).not.toThrow()
    })
  })

  describe('deleteMessage', () => {
    it('removes message by id', () => {
      const store = useComposeStore()
      store.messages = [
        { id: 0, type: 'Offer' },
        { id: 1, type: 'Wanted' },
      ]
      store.deleteMessage(0)
      expect(store.messages).toHaveLength(1)
      expect(store.messages[0].id).toBe(1)
    })
  })

  describe('clearMessages', () => {
    it('resets messages to empty array', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0 }, { id: 1 }]
      store.clearMessages()
      expect(store.messages).toEqual([])
    })
  })

  describe('clearMessage', () => {
    it('resets one message to empty, keeping its id and type', () => {
      const store = useComposeStore()
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'bed and chair',
          description: 'oak',
          attachments: [{ id: 1 }],
        },
        { id: 1, type: 'Wanted', item: 'a bench' },
      ]
      store.clearMessage(0)
      // Message 0 is reset to just id + type; others are untouched.
      expect(store.messages[0].id).toBe(0)
      expect(store.messages[0].type).toBe('Offer')
      expect(store.messages[0].item).toBeUndefined()
      expect(store.messages[0].description).toBeUndefined()
      expect(store.messages[0].attachments).toBeUndefined()
      expect(store.messages[1]).toEqual({
        id: 1,
        type: 'Wanted',
        item: 'a bench',
      })
    })

    it('does nothing when the id is not present', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0, type: 'Offer', item: 'x' }]
      store.clearMessage(99)
      expect(store.messages[0].item).toBe('x')
    })
  })

  describe('calculateSteps', () => {
    it('counts 2 steps per new draft (id < 0)', () => {
      const store = useComposeStore()
      store.messages = [{ id: -1, type: 'Offer', submitted: false }]
      store.calculateSteps('Offer')
      // 2 steps + 2 extra = 4
      expect(store.max).toBe(4)
      expect(store._progress).toBe(1)
    })

    it('counts 3 steps per repost (id >= 0)', () => {
      const store = useComposeStore()
      store.messages = [{ id: 5, type: 'Offer', submitted: false }]
      store.calculateSteps('Offer')
      // 3 steps + 2 extra = 5
      expect(store.max).toBe(5)
    })

    it('skips submitted messages', () => {
      const store = useComposeStore()
      store.messages = [{ id: -1, type: 'Offer', submitted: true }]
      store.calculateSteps('Offer')
      // 0 steps + 2 extra = 2
      expect(store.max).toBe(2)
    })

    it('skips messages of different type', () => {
      const store = useComposeStore()
      store.messages = [{ id: -1, type: 'Wanted', submitted: false }]
      store.calculateSteps('Offer')
      expect(store.max).toBe(2)
    })
  })

  describe('prune', () => {
    it('converts non-array messages to empty array', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.messages = { bad: 'data' }
      store.prune()
      expect(store.messages).toEqual([])
      logSpy.mockRestore()
    })

    it('removes null/falsy entries', () => {
      const store = useComposeStore()
      store.messages = [null, { id: 1, savedAt: Date.now() }, undefined]
      store.prune()
      expect(store.messages).toHaveLength(1)
    })

    it('removes messages older than 7 days', () => {
      const store = useComposeStore()
      const oldTime = Date.now() - 8 * 24 * 60 * 60 * 1000
      store.messages = [{ id: 0, savedAt: oldTime }]
      store.prune()
      expect(store.messages).toHaveLength(0)
    })

    it('sets savedAt for messages without it', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0 }]
      store.prune()
      expect(store.messages[0].savedAt).toBeGreaterThan(0)
    })

    it('removes messages saved by a different user', () => {
      const store = useComposeStore()
      mockAuthUser = { id: 42 }
      store.messages = [{ id: 0, savedAt: Date.now(), savedBy: 99 }]
      store.prune()
      expect(store.messages).toHaveLength(0)
    })

    it('keeps messages saved by current user', () => {
      const store = useComposeStore()
      mockAuthUser = { id: 42 }
      store.messages = [{ id: 0, savedAt: Date.now(), savedBy: 42 }]
      store.prune()
      expect(store.messages).toHaveLength(1)
    })
  })

  describe('markSubmitted', () => {
    it('marks message as submitted and clears attachments', () => {
      const store = useComposeStore()
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      store.add()
      store.markSubmitted(0, { id: 42 })
      expect(store.messages[0].submitted).toBe(true)
      expect(store.messages[0].item).toBeNull()
      expect(store.messages[0].description).toBeNull()
      expect(store.messages[0].attachments).toEqual([])
      logSpy.mockRestore()
    })
  })

  describe('submitSingle (single-call submit)', () => {
    it('throws when no postcode set', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      await expect(
        store.submitSingle({ type: 'Offer', item: 'Test' }, 'a@b.com')
      ).rejects.toThrow('No postcode')
    })

    it('posts the whole message in one call with attachments inline by externaluid', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessageSubmit.mockResolvedValue({ id: 99, groupid: 10 })

      const ret = await store.submitSingle(
        {
          type: 'Offer',
          item: 'Sofa',
          description: 'Good condition',
          availablenow: 1,
          attachments: [{ ouruid: 'uid-a' }, { ouruid: 'uid-b' }],
        },
        'test@example.com',
        { deadline: '2026-07-01', deliverypossible: false }
      )

      expect(ret).toEqual({ id: 99, groupid: 10 })
      // Exactly one API call — no draft, no POST /image, no JoinAndPost.
      expect(mockMessageSubmit).toHaveBeenCalledTimes(1)
      expect(mockMessagePut).not.toHaveBeenCalled()
      expect(mockJoinAndPost).not.toHaveBeenCalled()
      expect(mockImagePost).not.toHaveBeenCalled()
      expect(mockMessageSubmit).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'Offer',
          item: 'Sofa',
          textbody: 'Good condition',
          groupid: 10,
          locationid: 123,
          availablenow: 1,
          email: 'test@example.com',
          deadline: '2026-07-01',
          deliverypossible: false,
          attachments: [{ externaluid: 'uid-a' }, { externaluid: 'uid-b' }],
        }),
        expect.any(Function)
      )
    })

    it('suppresses the AI illustration when a real photo is present', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessageSubmit.mockResolvedValue({ id: 1, groupid: 10 })

      await store.submitSingle(
        {
          type: 'Offer',
          item: 'Sofa',
          attachments: [
            { ouruid: 'real-photo' },
            { ouruid: 'ai-img', externalmods: { ai: true } },
          ],
        },
        'test@example.com'
      )

      const payload = mockMessageSubmit.mock.calls[0][0]
      expect(payload.attachments).toEqual([{ externaluid: 'real-photo' }])
    })

    it('includes the AI illustration when there is no real photo', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessageSubmit.mockResolvedValue({ id: 1, groupid: 10 })

      await store.submitSingle(
        {
          type: 'Wanted',
          item: 'Sofa',
          attachments: [{ ouruid: 'ai-img', externalmods: { ai: true } }],
        },
        'test@example.com'
      )

      const payload = mockMessageSubmit.mock.calls[0][0]
      expect(payload.attachments).toEqual([
        { externaluid: 'ai-img', externalmods: { ai: true } },
      ])
    })

    it('stores auth tokens returned for a new user', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessageSubmit.mockResolvedValue({
        id: 1,
        groupid: 10,
        jwt: 'jwt-x',
        persistent: { id: 5 },
        newuser: true,
        newpassword: 'pw',
      })

      const ret = await store.submitSingle(
        { type: 'Offer', item: 'Sofa', attachments: [] },
        'new@example.com'
      )

      expect(mockSetAuth).toHaveBeenCalledWith('jwt-x', { id: 5 })
      expect(ret.newpassword).toBe('pw')
    })
  })

  describe('deferred submit / resume after login', () => {
    it('setPendingSubmit stores the intent (persisted with the store)', () => {
      const store = useComposeStore()
      const msg = { type: 'Offer', item: 'Sofa' }
      store.setPendingSubmit(msg, 'a@b.com', { deadline: '2026-07-01' })
      expect(store.pendingSubmit).toEqual({
        message: msg,
        email: 'a@b.com',
        options: { deadline: '2026-07-01' },
      })
    })

    it('resumePendingSubmit fires the deferred submit once and clears the flag', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessageSubmit.mockResolvedValue({ id: 42, groupid: 10 })
      store.setPendingSubmit(
        { type: 'Offer', item: 'Sofa', attachments: [{ ouruid: 'u1' }] },
        'a@b.com'
      )

      const ret = await store.resumePendingSubmit()

      expect(ret).toEqual({ id: 42, groupid: 10 })
      expect(mockMessageSubmit).toHaveBeenCalledTimes(1)
      expect(store.pendingSubmit).toBeNull()
      // After resuming, the user lands on My Posts (matching freegleIt).
      expect(navigateTo).toHaveBeenCalledWith({ name: 'myposts' })
      // A second call is a no-op (fires exactly once).
      expect(await store.resumePendingSubmit()).toBeNull()
      expect(mockMessageSubmit).toHaveBeenCalledTimes(1)
    })

    it('resumePendingSubmit is a no-op when nothing is pending', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      expect(await store.resumePendingSubmit()).toBeNull()
      expect(mockMessageSubmit).not.toHaveBeenCalled()
    })

    it('deferSubmit captures the composing post and its options', () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.email = 'a@b.com'
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          submitted: false,
          deadline: '2026-07-01',
          deliveryPossible: true,
          attachments: [{ ouruid: 'u1' }],
        },
      ]

      store.deferSubmit('Offer')

      expect(store.pendingSubmit.email).toBe('a@b.com')
      expect(store.pendingSubmit.message.item).toBe('Sofa')
      expect(store.pendingSubmit.options).toEqual({
        deadline: new Date('2026-07-01').toISOString(),
        deliverypossible: true,
      })
    })

    it('deferSubmit does nothing when there is no matching post', () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.messages = [{ id: 0, type: 'Wanted', item: 'x', submitted: false }]
      store.deferSubmit('Offer')
      expect(store.pendingSubmit).toBeNull()
    })

    it('deferSubmit ignores reposts and already-submitted posts', () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.messages = [
        { id: 0, type: 'Offer', item: 'a', submitted: true },
        { id: 1, type: 'Offer', item: 'b', submitted: false, repostof: 5 },
      ]
      store.deferSubmit('Offer')
      expect(store.pendingSubmit).toBeNull()
    })
  })

  describe('submit() — new post uses the single call', () => {
    it('routes a freshly-composed post through submitSingle, not the draft dance', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          description: 'Good condition',
          availablenow: 1,
          submitted: false,
          deadline: '2026-07-01',
          deliveryPossible: true,
          attachments: [{ ouruid: 'uid-a' }],
        },
      ]
      mockMessageSubmit.mockResolvedValue({
        id: 99,
        groupid: 10,
        newuser: true,
        newpassword: 'pw',
      })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      const results = await store.submit({ type: 'Offer' })

      // Single call — old draft/image/JoinAndPost path is not used for new posts.
      expect(mockMessageSubmit).toHaveBeenCalledTimes(1)
      expect(mockMessagePut).not.toHaveBeenCalled()
      expect(mockJoinAndPost).not.toHaveBeenCalled()
      expect(mockImagePost).not.toHaveBeenCalled()

      // Options threaded through: deadline normalised to ISO, delivery passed on.
      const payload = mockMessageSubmit.mock.calls[0][0]
      expect(payload.deadline).toBe(new Date('2026-07-01').toISOString())
      expect(payload.deliverypossible).toBe(true)
      expect(payload.attachments).toEqual([{ externaluid: 'uid-a' }])

      // Return contract preserved for freegleIt (id/groupid/newuser/newpassword).
      expect(results).toEqual([
        { id: 99, groupid: 10, newuser: true, newpassword: 'pw' },
      ])

      // The store is cleared after a successful submit.
      expect(store.messages).toEqual([])

      logSpy.mockRestore()
    })

    it('still uses the old draft path for reposts (back-compat)', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          submitted: false,
          repostof: 99,
          attachments: [{ id: 5 }],
        },
      ]
      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      // Repost keeps the preserved multi-step path; submitSingle is not used.
      expect(mockMessageSubmit).not.toHaveBeenCalled()
      expect(mockMessagePatch).toHaveBeenCalledTimes(1)
      expect(mockJoinAndPost).toHaveBeenCalledTimes(1)
      // The existing photo already has a numeric id, so no materialisation.
      expect(mockImagePost).not.toHaveBeenCalled()

      logSpy.mockRestore()
    })

    it('materialises an inline (Phase-5) photo added during a repost', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          submitted: false,
          repostof: 99,
          // A new photo added via PhotoUploader carries only the inline uid.
          attachments: [{ ouruid: 'uid-new' }],
        },
      ]
      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      mockImagePost.mockResolvedValue({ id: 321 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      // The inline photo is materialised and its id reaches the PATCH payload.
      expect(mockImagePost).toHaveBeenCalledWith({
        externaluid: 'uid-new',
        externalmods: undefined,
      })
      expect(mockMessagePatch.mock.calls[0][0].attachments).toEqual([321])

      logSpy.mockRestore()
    })

    it('materialises an AI illustration on repost when there is no real photo', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          submitted: false,
          repostof: 99,
          attachments: [
            { ouruid: 'ai-uid', externalmods: { ai: true } },
          ],
        },
      ]
      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      mockImagePost.mockResolvedValue({ id: 654 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      // AI illustration kept (materialised) since there is no real photo.
      expect(mockImagePost).toHaveBeenCalledWith({
        externaluid: 'ai-uid',
        externalmods: { ai: true },
      })
      expect(mockMessagePatch.mock.calls[0][0].attachments).toEqual([654])

      logSpy.mockRestore()
    })

    it('suppresses the AI illustration on repost when a real inline photo is present', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10
      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Sofa',
          submitted: false,
          repostof: 99,
          attachments: [
            { ouruid: 'real-uid' },
            { ouruid: 'ai-uid', externalmods: { ai: true } },
          ],
        },
      ]
      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      mockImagePost.mockResolvedValue({ id: 777 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      // Only the real photo is materialised; the AI illustration is dropped.
      expect(mockImagePost).toHaveBeenCalledTimes(1)
      expect(mockImagePost).toHaveBeenCalledWith({
        externaluid: 'real-uid',
        externalmods: undefined,
      })
      expect(mockMessagePatch.mock.calls[0][0].attachments).toEqual([777])

      logSpy.mockRestore()
    })
  })

  describe('createDraft', () => {
    it('throws when not initialized', async () => {
      const store = useComposeStore()
      await expect(
        store.createDraft({ type: 'Offer', item: 'Test' }, 'a@b.com')
      ).rejects.toThrow('not initialized')
    })

    it('throws when no postcode set', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      await expect(
        store.createDraft({ type: 'Offer', item: 'Test' }, 'a@b.com')
      ).rejects.toThrow('No postcode')
    })

    it('creates draft with regular attachments', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessagePut.mockResolvedValue({ id: 99 })

      const id = await store.createDraft(
        {
          type: 'Offer',
          item: 'Sofa',
          description: 'Good condition',
          availablenow: 1,
          attachments: [{ id: 5 }, { id: 6 }],
        },
        'test@example.com'
      )

      expect(id).toBe(99)
      expect(mockMessagePut).toHaveBeenCalledWith(
        expect.objectContaining({
          collection: 'Draft',
          locationid: 123,
          messagetype: 'Offer',
          item: 'Sofa',
          attachments: [5, 6],
          groupid: 10,
          email: 'test@example.com',
        })
      )
    })

    it('handles AI illustration attachments when no real photo present', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      mockImagePost.mockResolvedValue({ id: 77 })
      mockMessagePut.mockResolvedValue({ id: 99 })

      await store.createDraft(
        {
          type: 'Offer',
          item: 'Test',
          attachments: [{ ouruid: 'abc123', externalmods: { ai: true } }],
        },
        'test@example.com'
      )

      expect(mockImagePost).toHaveBeenCalledWith({
        externaluid: 'abc123',
        externalmods: { ai: true },
      })
      expect(mockMessagePut).toHaveBeenCalledWith(
        expect.objectContaining({ attachments: [77] })
      )
    })

    it('stores auth tokens when returned', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      mockMessagePut.mockResolvedValue({
        id: 99,
        jwt: 'token123',
        persistent: 'persist456',
      })

      await store.createDraft({ type: 'Offer', item: 'Test' }, 'test@a.com')
      expect(mockSetAuth).toHaveBeenCalledWith('token123', 'persist456')
    })
  })

  describe('submitDraft', () => {
    it('calls joinAndPost and fetches message', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })

      const result = await store.submitDraft(99, 'test@a.com', {
        deadline: '2026-05-01',
      })

      expect(mockJoinAndPost).toHaveBeenCalledWith(
        99,
        'test@a.com',
        expect.objectContaining({ deadline: '2026-05-01' })
      )
      expect(mockMessageFetch).toHaveBeenCalledWith(99, true)
      expect(result).toEqual({ groupid: 10 })
      logSpy.mockRestore()
    })
  })

  describe('submit', () => {
    it('sends the deadline as a plain date, not an ISO datetime', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockMessagePut.mockResolvedValue({ id: 77 })
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })

      store.setEmail('test@a.com')
      store.setPostcode({
        id: 5,
        name: 'SW1A 1AA',
        groupsnear: [
          { id: 10, nameshort: 'Westminster', namedisplay: 'Westminster' },
        ],
      })
      const id = store.add()
      store.setType({ id, type: 'Offer' })
      store.setItem({ id, item: 'Table' })
      store.setDeadline(id, '2026-09-03')

      await store.submit({ type: 'Offer' })

      // messages.deadline is a DATE column: under strict sql_mode MySQL
      // rejects an ISO datetime ("2026-09-03T00:00:00.000Z") outright, and
      // the give-flow deadline was silently lost (Discourse #9481).
      expect(mockJoinAndPost).toHaveBeenCalledWith(
        77,
        'test@a.com',
        expect.objectContaining({ deadline: '2026-09-03' })
      )
      logSpy.mockRestore()
    })
  })

  describe('backToDraft', () => {
    it('calls RejectToDraft and increments progress', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      mockMessageUpdate.mockResolvedValue({})

      const initialProgress = store._progress
      await store.backToDraft(42)

      expect(mockMessageUpdate).toHaveBeenCalledWith({
        id: 42,
        action: 'RejectToDraft',
      })
      expect(store._progress).toBe(initialProgress + 1)
      logSpy.mockRestore()
    })
  })

  describe('updateIt', () => {
    it('patches message and increments progress', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      mockMessagePatch.mockResolvedValue({})

      const initialProgress = store._progress
      await store.updateIt(1, 100, 'Offer', 'Sofa', 'Good', [5], 1, 10)

      expect(mockMessagePatch).toHaveBeenCalledWith({
        id: 1,
        locationid: 100,
        messagetype: 'Offer',
        item: 'Sofa',
        textbody: 'Good',
        attachments: [5],
        groupid: 10,
        availablenow: 1,
      })
      expect(store._progress).toBe(initialProgress + 1)
    })
  })

  describe('message getter', () => {
    it('returns message by index with id set', () => {
      const store = useComposeStore()
      store.messages = [{ type: 'Offer', item: 'Sofa' }]
      const m = store.message(0)
      expect(m.item).toBe('Sofa')
      expect(m.id).toBe(0)
    })

    it('returns undefined for missing index', () => {
      const store = useComposeStore()
      expect(store.message(5)).toBeUndefined()
    })
  })

  describe('all getter', () => {
    it('returns all messages with default Offer and Wanted', () => {
      const store = useComposeStore()
      const all = store.all
      expect(all).toHaveLength(2)
      expect(all[0].type).toBe('Offer')
      expect(all[1].type).toBe('Wanted')
    })

    it('does not add default Offer when one exists', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0, type: 'Offer', item: 'Sofa' }]
      const all = store.all
      const offers = all.filter((m) => m.type === 'Offer')
      expect(offers).toHaveLength(1)
      expect(offers[0].item).toBe('Sofa')
    })

    it('adds missing Wanted when only Offer exists', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0, type: 'Offer' }]
      const all = store.all
      const wanteds = all.filter((m) => m.type === 'Wanted')
      expect(wanteds).toHaveLength(1)
    })

    it('skips null entries', () => {
      const store = useComposeStore()
      store.messages = [null, { id: 1, type: 'Offer' }]
      const all = store.all
      const offers = all.filter((m) => m.type === 'Offer')
      expect(offers).toHaveLength(1)
    })
  })

  describe('attachments getter', () => {
    it('returns attachments for message', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0, attachments: [{ id: 10 }] }]
      expect(store.attachments(0)).toHaveLength(1)
    })

    it('returns empty array when no attachments', () => {
      const store = useComposeStore()
      store.messages = [{ id: 0 }]
      expect(store.attachments(0)).toEqual([])
    })
  })

  describe('progress getter', () => {
    it('returns percentage progress', () => {
      const store = useComposeStore()
      store._progress = 2
      store.max = 4
      // min(2, 3) * 100 / 4 = 50
      expect(store.progress).toBe(50)
    })

    it('caps at max - 1', () => {
      const store = useComposeStore()
      store._progress = 10
      store.max = 4
      // min(10, 3) * 100 / 4 = 75
      expect(store.progress).toBe(75)
    })
  })

  describe('messageValid getter', () => {
    it('returns true when message has item and description', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: 'Sofa', description: 'Good condition' },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(true)
    })

    it('returns true when message has item and real photos', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: 'Sofa', attachments: [{ id: 1 }] },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(true)
    })

    it('returns false when item is missing', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: '', description: 'Good condition' },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('returns false when only AI photos and no description', () => {
      const store = useComposeStore()
      store.messages = [
        {
          type: 'Offer',
          item: 'Sofa',
          attachments: [{ id: 1, externalmods: { ai: true } }],
        },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('returns false when no messages', () => {
      const store = useComposeStore()
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('returns false when the item is purely numeric', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: '123', description: 'Good condition' },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('allows an item that merely contains a number', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: '3 chairs', description: 'Good condition' },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(true)
    })

    it('returns false when the item is a content-free catch-all', () => {
      const store = useComposeStore()
      store.messages = [
        { type: 'Offer', item: 'anything', description: 'Good condition' },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('returns false when the description is purely numeric and there are no photos', () => {
      const store = useComposeStore()
      store.messages = [{ type: 'Offer', item: 'Sofa', description: '24' }]
      expect(store.messageValid({ value: 'Offer' })).toBe(false)
    })

    it('allows a purely-numeric description when there are real photos', () => {
      const store = useComposeStore()
      store.messages = [
        {
          type: 'Offer',
          item: 'Sofa',
          description: '24',
          attachments: [{ id: 1 }],
        },
      ]
      expect(store.messageValid({ value: 'Offer' })).toBe(true)
    })
  })

  describe('postcodeValid getter', () => {
    it('returns name when postcode set', () => {
      const store = useComposeStore()
      store.postcode = { name: 'SW1A 1AA' }
      expect(store.postcodeValid).toBe('SW1A 1AA')
    })

    it('returns falsy when no postcode', () => {
      const store = useComposeStore()
      expect(store.postcodeValid).toBeFalsy()
    })
  })

  describe('noGroups getter', () => {
    it('returns true when no groupsnear', () => {
      const store = useComposeStore()
      store.postcode = { name: 'AB1' }
      expect(store.noGroups).toBe(true)
    })

    it('returns false when groups exist', () => {
      const store = useComposeStore()
      store.postcode = { name: 'AB1', groupsnear: [{ id: 1 }] }
      expect(store.noGroups).toBe(false)
    })

    it('returns true when no postcode', () => {
      const store = useComposeStore()
      expect(store.noGroups).toBe(true)
    })
  })

  describe('AI image suppressed when user uploads own photo', () => {
    it('excludes AI-generated image from submission when user has uploaded their own real photo', async () => {
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      mockImagePost.mockResolvedValue({ id: 77 })
      mockMessagePut.mockResolvedValue({ id: 99 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})
      const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      await store.createDraft(
        {
          type: 'Offer',
          item: 'Old sofa',
          attachments: [
            { ouruid: 'ai-uid-abc', externalmods: { ai: true } },
            { id: 5 },
          ],
        },
        'user@example.com'
      )

      const callArgs = mockMessagePut.mock.calls[0][0]
      // CORRECT: only the user's real photo; AI image must be absent
      expect(callArgs.attachments).not.toContain(77)
      expect(callArgs.attachments).toEqual([5])

      logSpy.mockRestore()
      errSpy.mockRestore()
    })
  })

  describe('AI image suppressed when user uploads own photo (repost path)', () => {
    it('excludes AI-generated image from repost update payload when user has uploaded their own real photo', async () => {
      // Bug: the submit() repost path (message.repostof set) blindly pushes all
      // attachment .id values into attids without the hasRealPhoto suppression
      // logic that createDraft() has. An AI attachment stored with id 'ai-abc'
      // (string) ends up in the PATCH payload alongside the real photo id.
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10

      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Old sofa',
          submitted: false,
          repostof: 99,
          attachments: [
            {
              id: 'ai-abc',
              ouruid: 'ai-uid-xyz',
              externalmods: { ai: true },
              isAiIllustration: true,
            },
            { id: 5 },
          ],
        },
      ]

      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      const patchArgs = mockMessagePatch.mock.calls[0][0]
      // CORRECT: only real photo id 5; AI string id must be absent
      expect(patchArgs.attachments).not.toContain('ai-abc')
      expect(patchArgs.attachments).toEqual([5])

      logSpy.mockRestore()
    })
  })

  describe('AI illustration preserved in repost path (no real photo)', () => {
    it('converts AI illustration to real attachment via image.post() when reposting with no real photo', async () => {
      // Regression: repost path (message.repostof set) silently drops AI illustrations
      // when there is no real user photo. The PATCH payload ends up with no attachments,
      // so the AI image is permanently lost. The fix: call image.post(ouruid) in the
      // repost path, just as createDraft already does.
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.email = 'test@example.com'
      store.group = 10

      store.messages = [
        {
          id: 0,
          type: 'Offer',
          item: 'Old sofa',
          submitted: false,
          repostof: 99,
          attachments: [
            {
              id: null,
              ouruid: 'ai-uid-xyz',
              externalmods: { ai: true },
              isAiIllustration: true,
            },
          ],
        },
      ]

      mockImagePost.mockResolvedValue({ id: 88 })
      mockMessageUpdate.mockResolvedValue({})
      mockMessagePatch.mockResolvedValue({})
      mockJoinAndPost.mockResolvedValue({ groupid: 10 })
      const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {})

      await store.submit({ type: 'Offer' })

      // image.post() must be called to create a real server-side attachment
      expect(mockImagePost).toHaveBeenCalledWith({
        externaluid: 'ai-uid-xyz',
        externalmods: { ai: true },
      })
      // The resulting real numeric id must appear in the PATCH payload
      const patchArgs = mockMessagePatch.mock.calls[0][0]
      expect(patchArgs.attachments).toContain(88)
      // No non-numeric strings in attachments
      patchArgs.attachments.forEach((id) => {
        expect(typeof id).toBe('number')
      })

      logSpy.mockRestore()
    })

    it('uses pre-created numeric id directly when AI attachment already has one (no double image.post())', async () => {
      // When details.vue calls image.post() eagerly and stores the real numeric id,
      // createDraft should use that id directly without calling image.post() again.
      const store = useComposeStore()
      store.init({ public: {} })
      store.postcode = { id: 123 }
      store.group = 10
      mockMessagePut.mockResolvedValue({ id: 99 })

      await store.createDraft(
        {
          type: 'Offer',
          item: 'Test',
          attachments: [
            {
              id: 55, // real numeric id already created by details.vue
              ouruid: 'abc123',
              externalmods: { ai: true },
              isAiIllustration: true,
            },
          ],
        },
        'test@example.com'
      )

      // image.post() must NOT be called again (would double-create the attachment)
      expect(mockImagePost).not.toHaveBeenCalled()
      // The existing numeric id must be in the PUT payload
      expect(mockMessagePut).toHaveBeenCalledWith(
        expect.objectContaining({ attachments: [55] })
      )
    })
  })
})
