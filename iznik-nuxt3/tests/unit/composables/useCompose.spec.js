import { describe, it, expect, vi, beforeEach } from 'vitest'
import { postcodeSelect } from '~/composables/useCompose.js'

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
