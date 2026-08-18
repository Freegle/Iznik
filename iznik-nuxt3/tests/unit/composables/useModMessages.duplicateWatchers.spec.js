/**
 * setupModMessages() carries its own warning: "Do not include any watch in here
 * as a separate watch is called for each time setupModMessages() is called".
 * It then registers watch(workdetail), a MutationObserver and
 * watch(modtoolsediting) on every call - and it is called by the page, by
 * ModMessages.vue, and (from a module-level computed, so once per group change)
 * by useKeywords.js. Every watch(workdetail) that fires runs a full
 * clear-then-refetch, so one work-count tick produced a burst of identical
 * listing requests: measured in production at typically 2 and up to 13, growing
 * with the number of group filters a moderator had used that session.
 *
 * Only the page owns the queue, and only the page passes reset=true.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick, effectScope } from 'vue'
import { flushPromises } from '@vue/test-utils'

const mockWork = ref({ pending: 0, pendingother: 0, total: 0 })

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get work() {
      return mockWork.value
    },
  }),
}))

const mockFetchMessagesMT = vi.fn()
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    clearContext: vi.fn(),
    clear: vi.fn(),
    fetchMessagesMT: mockFetchMessagesMT,
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

describe('useModMessages - one refresh per work change', () => {
  // In the app setupModMessages() runs inside a component's setup(), so its
  // watchers belong to that component's scope and die with it. A bare unit
  // test has no scope, so they would leak into the next test - which is the
  // same hazard the code has if it is ever called outside a component. Run
  // each case in its own scope and stop it afterwards.
  let scope

  beforeEach(() => {
    scope = effectScope()
    vi.clearAllMocks()
    vi.resetModules()
    mockWork.value = { pending: 0, pendingother: 0, total: 0 }
    mockFetchMessagesMT.mockResolvedValue([])
    document.body.style.overflow = ''
  })

  afterEach(() => {
    scope.stop()
    vi.resetModules()
    document.body.style.overflow = ''
  })

  it('refetches once when the page, its list component and useKeywords have all called setup', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )

    scope.run(() => {
      // The page owns the queue and resets the shared state.
      const { workType, collection } = setupModMessages(true)
      collection.value = 'Pending'
      workType.value = ['pending', 'pendingother']

      // ModMessages.vue, then useKeywords.js's module-level computed re-running
      // after a group change. Neither owns the queue.
      setupModMessages()
      setupModMessages()
    })

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    mockWork.value = { pending: 1, pendingother: 0, total: 1 }
    await nextTick()
    await flushPromises()

    expect(mockFetchMessagesMT).toHaveBeenCalledTimes(1)
  })

  it('still refreshes when only the owning page has called setup', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    scope.run(() => {
      const { workType, collection } = setupModMessages(true)
      collection.value = 'Pending'
      workType.value = ['pending', 'pendingother']
    })

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    mockWork.value = { pending: 2, pendingother: 0, total: 2 }
    await nextTick()
    await flushPromises()

    expect(mockFetchMessagesMT).toHaveBeenCalledTimes(1)
  })
})
