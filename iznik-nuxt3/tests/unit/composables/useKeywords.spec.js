/**
 * The Offer/Wanted labels a community has renamed (settings.keywords) must show
 * in the type dropdowns on ModMessage and ModStdMessageModal, and must follow
 * the community currently selected in the queue.
 *
 * The options used to come from a module-level computed whose getter called
 * setupModMessages() - a setup function invoked from inside a computed, which
 * re-ran on every group change. These tests pin the behaviour so the wiring can
 * be simplified without changing what a moderator sees.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { nextTick } from 'vue'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ work: null }) }))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    clearContext: vi.fn(),
    clear: vi.fn(),
    fetchMessagesMT: vi.fn().mockResolvedValue([]),
    get all() {
      return []
    },
    getByGroup: vi.fn(() => []),
    get context() {
      return null
    },
    list: {},
  }),
}))
vi.mock('@/stores/misc', () => ({
  useMiscStore: () => ({
    deferGetMessages: false,
    get: vi.fn(() => undefined),
  }),
}))

describe('useKeywords typeOptions', () => {
  beforeEach(() => {
    vi.resetModules()
  })

  afterEach(() => {
    vi.resetModules()
  })

  it('falls back to OFFER and WANTED when no community is selected', async () => {
    const { setupKeywords } = await import('~/composables/useKeywords')
    const { typeOptions } = setupKeywords()

    expect(typeOptions.value.map((o) => o.value)).toEqual(['Offer', 'Wanted'])
    expect(typeOptions.value.map((o) => o.text)).toEqual(['OFFER', 'WANTED'])
  })

  it("uses the selected community's renamed keywords, and follows a change of community", async () => {
    const { setupKeywords } = await import('~/composables/useKeywords')
    const { setupModMessages } =
      await import('~/modtools/composables/useModMessages')
    const { typeOptions } = setupKeywords()
    const { group } = setupModMessages()

    group.value = {
      id: 1,
      settings: { keywords: { offer: 'GIVING AWAY', wanted: 'LOOKING FOR' } },
    }
    await nextTick()
    expect(typeOptions.value.map((o) => o.text)).toEqual([
      'GIVING AWAY',
      'LOOKING FOR',
    ])

    group.value = { id: 2, settings: { keywords: { offer: 'FREE' } } }
    await nextTick()
    expect(typeOptions.value.map((o) => o.text)).toEqual(['FREE', 'WANTED'])
  })
})
