import { describe, it, expect, vi, beforeEach } from 'vitest'

import { useBulkOfferUpdate } from '~/composables/useBulkOfferUpdate'

// Fake v2 API injected via $api.message.
const api = vi.hoisted(() => ({
  fetchBulkEditOffer: vi.fn(),
  updateBulkEditItem: vi.fn(),
}))

vi.mock('#imports', () => ({
  useNuxtApp: () => ({ $api: { message: api } }),
}))

const offer = () => ({
  ret: 0,
  id: 5,
  subject: 'Office clearance',
  items: [
    { id: 10, name: 'Cabinet', quantity: 2, available: true },
    { id: 11, name: 'Desk', quantity: 1, available: true },
  ],
})

describe('useBulkOfferUpdate', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.fetchBulkEditOffer.mockResolvedValue(offer())
    api.updateBulkEditItem.mockResolvedValue({
      ret: 0,
      items: [
        { id: 10, name: 'Cabinet', quantity: 2, available: false },
        { id: 11, name: 'Desk', quantity: 1, available: true },
      ],
    })
  })

  it('loads the offer by its token', async () => {
    const c = useBulkOfferUpdate('tok123')
    await c.load()
    expect(api.fetchBulkEditOffer).toHaveBeenCalledWith('tok123')
    expect(c.loading.value).toBe(false)
    expect(c.notFound.value).toBe(false)
    expect(c.offer.value.items).toHaveLength(2)
    expect(c.offer.value.subject).toBe('Office clearance')
  })

  it('flags not-found for an invalid or withdrawn link', async () => {
    api.fetchBulkEditOffer.mockResolvedValue({ ret: 1 })
    const c = useBulkOfferUpdate('bad')
    await c.load()
    expect(c.notFound.value).toBe(true)
  })

  it('flags not-found when the fetch throws', async () => {
    api.fetchBulkEditOffer.mockRejectedValue(new Error('network'))
    const c = useBulkOfferUpdate('tok')
    await c.load()
    expect(c.notFound.value).toBe(true)
    expect(c.loading.value).toBe(false)
  })

  it('sends an availability change and re-renders from the response', async () => {
    const c = useBulkOfferUpdate('tok123')
    await c.load()
    await c.onUpdate({ itemid: 10, available: false })
    expect(api.updateBulkEditItem).toHaveBeenCalledWith('tok123', 10, {
      available: false,
    })
    expect(c.offer.value.items.find((i) => i.id === 10).available).toBe(false)
    expect(c.savingId.value).toBe(null)
  })

  it('sends a quantity change', async () => {
    const c = useBulkOfferUpdate('tok123')
    await c.load()
    await c.onUpdate({ itemid: 11, quantity: 3 })
    expect(api.updateBulkEditItem).toHaveBeenCalledWith('tok123', 11, {
      quantity: 3,
    })
  })

  it('surfaces a save error when the update returns a non-zero ret', async () => {
    api.updateBulkEditItem.mockResolvedValue({ ret: 1 })
    const c = useBulkOfferUpdate('tok123')
    await c.load()
    await c.onUpdate({ itemid: 10, available: false })
    expect(c.saveError.value).toBe(true)
  })

  it('re-syncs from the server when a save throws', async () => {
    const c = useBulkOfferUpdate('tok123')
    await c.load()
    api.fetchBulkEditOffer.mockClear()
    api.updateBulkEditItem.mockRejectedValueOnce(new Error('boom'))
    await c.onUpdate({ itemid: 10, available: false })
    expect(c.saveError.value).toBe(true)
    expect(api.fetchBulkEditOffer).toHaveBeenCalled()
  })
})
