import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { postcodeSelect, makeCanSubmit } from '~/composables/useCompose.js'

let mockGroup = null
let mockPostcode = null
const mockSetPostcode = vi.fn()

vi.mock('~/stores/compose', () => ({
  useComposeStore: () => ({
    get group() {
      return mockGroup
    },
    set group(v) {
      mockGroup = v
    },
    get postcode() {
      return mockPostcode
    },
    set postcode(v) {
      mockPostcode = v
    },
    setPostcode: mockSetPostcode,
    messageValid: vi.fn(() => true),
    noGroups: false,
    postcodeValid: true,
    uploading: false,
    clearMessages: vi.fn(),
    setMessage: vi.fn(),
    setAttachmentsForMessage: vi.fn(),
  }),
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => ({
    get: vi.fn(),
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
    mockGroup = null
    mockPostcode = null
    mockSetPostcode.mockReset()
  })

  const makePC = (id, groupIds) => ({
    id,
    name: 'TEST 1AA',
    groupsnear: groupIds.map((gid) => ({ id: gid, nameshort: `Group${gid}` })),
  })

  it('sets group to first nearby group on fresh compose when no group previously set', () => {
    const pc = makePC(1, [100, 200])
    postcodeSelect(pc)
    expect(mockGroup).toBe(100)
  })

  it('preserves explicitly-set group when not in groupsnear (repost scenario)', () => {
    mockGroup = 55
    // New postcode id (different from current) triggers the outer condition
    const pc = makePC(99, [69615, 200])  // Group 55 not in list
    postcodeSelect(pc)
    expect(mockGroup).toBe(55)  // Must not be overridden to 69615
  })

  it('preserves explicitly-set group when it is in groupsnear', () => {
    mockGroup = 55
    const pc = makePC(99, [55, 200])  // Group 55 IS in list
    postcodeSelect(pc)
    expect(mockGroup).toBe(55)
  })

  it('does not change group when groupsnear is empty array', () => {
    mockGroup = 55
    const pc = makePC(99, [])
    postcodeSelect(pc)
    expect(mockGroup).toBe(55)
  })

  it('skips all logic when same postcode id with existing groupsnear', () => {
    const pc = makePC(42, [69615])
    mockPostcode = { id: 42, name: 'TEST 1AA', groupsnear: [{ id: 69615 }] }
    mockGroup = 55
    postcodeSelect(pc)
    expect(mockSetPostcode).not.toHaveBeenCalled()
    expect(mockGroup).toBe(55)
  })
})

describe('makeCanSubmit', () => {
  const makeRefs = (overrides = {}) => ({
    messageValid: ref(true),
    loggedIn: ref(false),
    emailValid: ref(false),
    emailBelongsToSomeoneElse: ref(false),
    postcodeValid: ref(null),
    closed: ref(false),
    noGroups: ref(false),
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

  it('returns false when group is closed, even with valid postcode, message and login', () => {
    const refs = makeRefs({
      loggedIn: ref(true),
      messageValid: ref(true),
      postcodeValid: ref('SW1A 1AA'),
      closed: ref(true),
      requirePostcode: true,
    })
    const canSubmit = makeCanSubmit(refs)
    expect(canSubmit.value).toBe(false)
  })

  it('returns false when no groups nearby, even with valid postcode, message and login', () => {
    const refs = makeRefs({
      loggedIn: ref(true),
      messageValid: ref(true),
      postcodeValid: ref('SW1A 1AA'),
      noGroups: ref(true),
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
