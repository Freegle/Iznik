import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import {
  postcodeSelect,
  makeCanSubmit,
  loadOwnActivePosts,
} from '~/composables/useCompose.js'

let mockPostcode = null
const mockSetPostcode = vi.fn((pc) => {
  mockPostcode = pc
})

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    get postcode() {
      return mockPostcode
    },
    set postcode(v) {
      mockPostcode = v
    },
    setPostcode: mockSetPostcode,
    messageValid: vi.fn(() => true),
    postcodeValid: true,
    uploading: false,
    clearMessages: vi.fn(),
    setMessage: vi.fn(),
    setAttachmentsForMessage: vi.fn(),
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetchMessages: vi.fn(),
    all: [],
  }),
}))

describe('postcodeSelect', () => {
  beforeEach(() => {
    mockPostcode = null
    mockSetPostcode.mockClear()
  })

  const makePC = (id) => ({
    id,
    name: 'TEST 1AA',
  })

  it('sets the postcode when none is currently set', () => {
    const pc = makePC(1)
    postcodeSelect(pc)
    expect(mockSetPostcode).toHaveBeenCalledWith(pc)
  })

  it('sets the postcode when it differs from the one stored', () => {
    mockPostcode = { id: 5, name: 'OLD 1AA' }
    const pc = makePC(99)
    postcodeSelect(pc)
    expect(mockSetPostcode).toHaveBeenCalledWith(pc)
  })

  it('does nothing when the postcode id is unchanged', () => {
    mockPostcode = { id: 42, name: 'TEST 1AA' }
    const pc = makePC(42)
    postcodeSelect(pc)
    expect(mockSetPostcode).not.toHaveBeenCalled()
  })
})

describe('makeCanSubmit', () => {
  const makeRefs = (overrides = {}) => ({
    messageValid: ref(true),
    loggedIn: ref(false),
    emailValid: ref(false),
    emailBelongsToSomeoneElse: ref(false),
    postcodeValid: ref(null),
    requirePostcode: false,
    ...overrides,
  })

  // ── whoami-style (no postcode requirement) ──────────────────────────────

  it('returns false when logged in but message is empty skeleton (the core bug)', () => {
    const refs = makeRefs({ loggedIn: ref(true), messageValid: ref(false) })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('returns true when logged in and message is valid', () => {
    const refs = makeRefs({ loggedIn: ref(true), messageValid: ref(true) })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(true)
  })

  it('returns true when logged out, email valid, not belonging to someone else, and message valid', () => {
    const refs = makeRefs({
      loggedIn: ref(false),
      emailValid: ref(true),
      emailBelongsToSomeoneElse: ref(false),
      messageValid: ref(true),
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(true)
  })

  it('returns false when logged out and email belongs to someone else', () => {
    const refs = makeRefs({
      loggedIn: ref(false),
      emailValid: ref(true),
      emailBelongsToSomeoneElse: ref(true),
      messageValid: ref(true),
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('returns false when logged out and email is invalid', () => {
    const refs = makeRefs({
      loggedIn: ref(false),
      emailValid: ref(false),
      messageValid: ref(true),
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('returns false when logged out, valid email, but message is empty', () => {
    const refs = makeRefs({
      loggedIn: ref(false),
      emailValid: ref(true),
      messageValid: ref(false),
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  // ── whereami-style (requirePostcode: true) ──────────────────────────────

  it('returns false when postcode required but missing, even with valid message and login', () => {
    const refs = makeRefs({
      loggedIn: ref(true),
      messageValid: ref(true),
      postcodeValid: ref(null),
      requirePostcode: true,
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('returns true when postcode required, postcode valid, logged in, message valid', () => {
    const refs = makeRefs({
      loggedIn: ref(true),
      messageValid: ref(true),
      postcodeValid: ref('SW1A 1AA'),
      requirePostcode: true,
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(true)
  })

  it('returns false when logged in but skeleton message with postcode requirement', () => {
    const refs = makeRefs({
      loggedIn: ref(true),
      messageValid: ref(false),
      postcodeValid: ref('SW1A 1AA'),
      requirePostcode: true,
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('is reactive — updates when messageValid changes', () => {
    const messageValid = ref(false)
    const refs = makeRefs({ loggedIn: ref(true), messageValid })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
    messageValid.value = true
    expect(canSubmit.value).toBe(true)
  })
})

describe('loadOwnActivePosts', () => {
  it('pulls each active post into list as a full message so duplicate detection has item names', async () => {
    const messageStore = {
      fetchByUser: vi.fn().mockResolvedValue([{ id: 10 }, { id: 11 }]),
      fetch: vi.fn(),
    }
    await loadOwnActivePosts(messageStore, 123)
    // active=true so the query returns the member's still-open posts...
    expect(messageStore.fetchByUser).toHaveBeenCalledWith(123, true)
    // ...and each is fetched in full (the summary lacks item.name, list has it).
    expect(messageStore.fetch).toHaveBeenCalledWith(10)
    expect(messageStore.fetch).toHaveBeenCalledWith(11)
  })

  it('does nothing when logged out', async () => {
    const messageStore = { fetchByUser: vi.fn(), fetch: vi.fn() }
    await loadOwnActivePosts(messageStore, undefined)
    expect(messageStore.fetchByUser).not.toHaveBeenCalled()
    expect(messageStore.fetch).not.toHaveBeenCalled()
  })

  it('tolerates a null result from fetchByUser', async () => {
    const messageStore = {
      fetchByUser: vi.fn().mockResolvedValue(null),
      fetch: vi.fn(),
    }
    await loadOwnActivePosts(messageStore, 123)
    expect(messageStore.fetch).not.toHaveBeenCalled()
  })
})
