import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

const mockGroup = ref(null)

vi.mock('~/composables/useModMessages', () => ({
  setupModMessages: () => ({ group: mockGroup }),
}))

describe('setupKeywords', () => {
  beforeEach(() => {
    mockGroup.value = null
  })

  it('falls back to OFFER/WANTED when there is no group', async () => {
    const { setupKeywords } = await import('~/modtools/composables/useKeywords')
    const { typeOptions } = setupKeywords()

    expect(typeOptions.value).toEqual([
      { value: 'Offer', text: 'OFFER' },
      { value: 'Wanted', text: 'WANTED' },
    ])
  })

  it('falls back to OFFER/WANTED when the group has no keyword settings', async () => {
    const { setupKeywords } = await import('~/modtools/composables/useKeywords')
    mockGroup.value = { settings: {} }
    const { typeOptions } = setupKeywords()

    expect(typeOptions.value).toEqual([
      { value: 'Offer', text: 'OFFER' },
      { value: 'Wanted', text: 'WANTED' },
    ])
  })

  it('uses the group-specific keyword overrides when present', async () => {
    const { setupKeywords } = await import('~/modtools/composables/useKeywords')
    mockGroup.value = {
      settings: { keywords: { offer: 'GIVEAWAY', wanted: 'REQUEST' } },
    }
    const { typeOptions } = setupKeywords()

    expect(typeOptions.value).toEqual([
      { value: 'Offer', text: 'GIVEAWAY' },
      { value: 'Wanted', text: 'REQUEST' },
    ])
  })

  it('reacts to the group ref changing after the first read', async () => {
    const { setupKeywords } = await import('~/modtools/composables/useKeywords')
    mockGroup.value = { settings: { keywords: { offer: 'GIVE' } } }
    const { typeOptions } = setupKeywords()
    expect(typeOptions.value[0]).toEqual({ value: 'Offer', text: 'GIVE' })

    mockGroup.value = { settings: { keywords: { offer: 'DONATE' } } }
    expect(typeOptions.value[0]).toEqual({ value: 'Offer', text: 'DONATE' })
  })
})
