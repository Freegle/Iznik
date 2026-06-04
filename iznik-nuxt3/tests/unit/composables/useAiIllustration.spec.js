import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { fetchAiIllustration } from '~/composables/useAiIllustration'

// ---------------------------------------------------------------------------
// Regression: give/mobile/details.vue used to store AI illustrations with a
// synthetic string id ('ai-' + Date.now()).  The Go API declares
// Attachments []uint64, so any string in that array causes HTTP 400 and
// traps the user in a permanent retry loop.
//
// Fix: fetchAiIllustration now calls POST /image immediately to get a real
// numeric server-side id, and stores that numeric id on the attachment.
// createDraft in compose.js uses the numeric id directly (no second POST).
// ---------------------------------------------------------------------------

const mockGetIllustration = vi.fn()
const mockImagePost = vi.fn()
const mockSetAttachmentsForMessage = vi.fn()

const fakeApi = {
  message: { getIllustration: mockGetIllustration },
  image: { post: mockImagePost },
}

const fakeComposeStore = {
  setAttachmentsForMessage: mockSetAttachmentsForMessage,
}

function makeRefs() {
  return {
    fetchingIllustration: ref(false),
    lastFetchedItem: ref(null),
  }
}

describe('fetchAiIllustration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('calls image.post() to get a real numeric id and stores it on the attachment', async () => {
    mockGetIllustration.mockResolvedValue({
      externaluid: 'uid-abc-123',
      url: 'https://images.example.com/uid-abc-123',
    })
    mockImagePost.mockResolvedValue({ id: 42 })

    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    // image.post() must be called with the externaluid from the illustration
    expect(mockImagePost).toHaveBeenCalledWith({
      externaluid: 'uid-abc-123',
      externalmods: { ai: true },
    })

    // The attachment stored in the compose store must have a real numeric id
    expect(mockSetAttachmentsForMessage).toHaveBeenCalledOnce()
    const [, storedAtts] = mockSetAttachmentsForMessage.mock.calls[0]
    expect(storedAtts).toHaveLength(1)
    const att = storedAtts[0]
    expect(att.id).toBe(42)
    expect(typeof att.id).toBe('number')
    expect(att.ouruid).toBe('uid-abc-123')
    expect(att.externalmods).toEqual({ ai: true })
    expect(att.isAiIllustration).toBe(true)
  })

  it('stores null id (not a synthetic string) when image.post() fails, so createDraft can retry', async () => {
    mockGetIllustration.mockResolvedValue({
      externaluid: 'uid-abc-123',
      url: 'https://images.example.com/uid-abc-123',
    })
    mockImagePost.mockRejectedValue(new Error('network error'))

    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    const { fetchingIllustration, lastFetchedItem } = makeRefs()

    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    // The attachment is still stored (so createDraft can retry via ouruid)
    expect(mockSetAttachmentsForMessage).toHaveBeenCalledOnce()
    const [, storedAtts] = mockSetAttachmentsForMessage.mock.calls[0]
    const att = storedAtts[0]

    // id must be null — never a synthetic 'ai-...' string
    expect(att.id).toBeNull()
    // ouruid is preserved so createDraft can fall back to image.post()
    expect(att.ouruid).toBe('uid-abc-123')

    errSpy.mockRestore()
  })

  it('does not store a synthetic ai- string id under any circumstance', async () => {
    mockGetIllustration.mockResolvedValue({
      externaluid: 'uid-xyz',
      url: 'https://images.example.com/uid-xyz',
    })
    mockImagePost.mockResolvedValue({ id: 77 })

    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'table',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    const [, storedAtts] = mockSetAttachmentsForMessage.mock.calls[0]
    storedAtts.forEach((att) => {
      // id must never be a string (the Go API only accepts uint64)
      if (att.id !== null && att.id !== undefined) {
        expect(typeof att.id).toBe('number')
      }
      // Specifically, no 'ai-...' prefix strings
      expect(typeof att.id === 'string' && att.id.startsWith('ai-')).toBe(false)
    })
  })

  it('does not fetch when a real photo is already present', async () => {
    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [{ id: 10, path: 'photo.jpg' }], // real photo present
      fetchingIllustration,
      lastFetchedItem,
    })

    expect(mockGetIllustration).not.toHaveBeenCalled()
    expect(mockSetAttachmentsForMessage).not.toHaveBeenCalled()
  })

  it('does not fetch when already fetching', async () => {
    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    fetchingIllustration.value = true

    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    expect(mockGetIllustration).not.toHaveBeenCalled()
  })

  it('does not re-fetch when the same item was already fetched', async () => {
    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    lastFetchedItem.value = 'sofa'

    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    expect(mockGetIllustration).not.toHaveBeenCalled()
  })

  it('resets fetchingIllustration to false after completion', async () => {
    mockGetIllustration.mockResolvedValue({
      externaluid: 'uid-abc',
      url: 'https://images.example.com/uid-abc',
    })
    mockImagePost.mockResolvedValue({ id: 1 })

    const { fetchingIllustration, lastFetchedItem } = makeRefs()
    await fetchAiIllustration({
      api: fakeApi,
      composeStore: fakeComposeStore,
      messageId: 0,
      itemName: 'sofa',
      attachments: [],
      fetchingIllustration,
      lastFetchedItem,
    })

    expect(fetchingIllustration.value).toBe(false)
  })
})
