/**
 * The server refuses a moderation action with 409 when a DIFFERENT moderator
 * holds the post (Discourse #9946 - a mod rejected a post 27 minutes after a
 * colleague held it, off a pending list his browser had fetched 90 minutes
 * earlier). The store must turn that into a re-fetch (so the "Held by X" banner
 * appears and the buttons hide) plus a tagged error naming the holder, rather
 * than letting a raw API error escape.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useMessageStore } from '~/stores/message'

const mockReject = vi.fn()
const mockFetchMT = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      reject: mockReject,
      fetchMT: mockFetchMT,
    },
  }),
}))

vi.mock('~/stores/auth', () => ({ useAuthStore: () => ({}) }))
vi.mock('~/stores/group', () => ({ useGroupStore: () => ({}) }))
vi.mock('~/stores/user', () => ({ useUserStore: () => ({ fetch: vi.fn() }) }))
vi.mock('~/stores/nearby', () => ({ useNearbyStore: () => ({}) }))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => ({}) }))

function heldConflict() {
  const e = new Error('API Error')
  e.response = {
    status: 409,
    data: {
      ret: 1,
      status: 'Held by another moderator',
      heldby: 25789138,
      heldbyname: 'Jos',
    },
  }
  return e
}

describe('message store - action refused because another mod holds the post', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('re-fetches the message and reports who is holding it', async () => {
    const store = useMessageStore()
    store.init({})

    mockReject.mockRejectedValue(heldConflict())
    mockFetchMT.mockResolvedValue({ id: 121087151, heldby: 25789138 })

    await expect(
      store.runHoldAware(121087151, () => mockReject())
    ).rejects.toThrow(/Jos is holding this post/)

    // Re-fetched, so the stale card is replaced by one that shows the hold.
    expect(mockFetchMT).toHaveBeenCalledWith({ id: 121087151 }, false)
    expect(store.list[121087151]).toEqual({ id: 121087151, heldby: 25789138 })
  })

  it('tags the error so the UI can show it rather than treating it as a fault', async () => {
    const store = useMessageStore()
    store.init({})

    mockReject.mockRejectedValue(heldConflict())
    mockFetchMT.mockResolvedValue({ id: 121087151, heldby: 25789138 })

    await expect(
      store.runHoldAware(121087151, () => mockReject())
    ).rejects.toMatchObject({ heldByOtherMod: true })
  })

  it('still reports the refusal when the follow-up re-fetch fails', async () => {
    const store = useMessageStore()
    store.init({})

    mockReject.mockRejectedValue(heldConflict())
    mockFetchMT.mockRejectedValue(new Error('network'))

    await expect(
      store.runHoldAware(121087151, () => mockReject())
    ).rejects.toThrow(/Jos is holding this post/)
  })

  it('passes other failures straight through', async () => {
    const store = useMessageStore()
    store.init({})

    const boom = new Error('Server exploded')
    boom.response = { status: 500, data: {} }
    mockReject.mockRejectedValue(boom)

    await expect(
      store.runHoldAware(121087151, () => mockReject())
    ).rejects.toThrow('Server exploded')
    expect(mockFetchMT).not.toHaveBeenCalled()
  })

  it('returns the result untouched when the action succeeds', async () => {
    const store = useMessageStore()
    store.init({})

    mockReject.mockResolvedValue('done')

    await expect(
      store.runHoldAware(121087151, () => mockReject())
    ).resolves.toBe('done')
    expect(mockFetchMT).not.toHaveBeenCalled()
  })
})
