/**
 * Tests for the pending-messages auto-refresh behaviour.
 *
 * The Pending messages page must call getMessages() whenever authStore.work
 * changes (new pending messages arrived), even if a modal is open at the time.
 *
 * Discourse #9737: "A red alert appears in Pending messages but no message is
 * visible without a manual page refresh."
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'
import { flushPromises } from '@vue/test-utils'

// ── Reactive auth-store mock ──────────────────────────────────────────────────
// The watch(workdetail, …) inside setupModMessages tracks authStore.work via
// Vue's reactivity.  A plain variable assignment is NOT reactive and would
// never trigger the watcher.  Using a getter over a ref lets Vue track the
// dependency correctly.
const mockWork = ref({ pending: 0, pendingother: 0, total: 0 })

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    // getter so Vue's computed(workdetail) can track the ref dependency
    get work() {
      return mockWork.value
    },
  }),
}))

// ── Message store mock ────────────────────────────────────────────────────────
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

// ── Misc store mock (deferGetMessages = false → no suppression) ───────────────
vi.mock('@/stores/misc', () => ({
  useMiscStore: () => ({
    deferGetMessages: false,
    get: vi.fn(() => undefined),
  }),
}))

// ─────────────────────────────────────────────────────────────────────────────

describe('useModMessages — pending list auto-refresh', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockWork.value = { pending: 0, pendingother: 0, total: 0 }
    mockFetchMessagesMT.mockResolvedValue([])
    // Ensure body overflow is clear between tests
    document.body.style.overflow = ''
  })

  afterEach(() => {
    vi.resetModules()
    document.body.style.overflow = ''
  })

  it('calls getMessages when pending count increases with no modal open', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother']

    // Allow the initial watch trigger (workType change) to settle
    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // A new pending message arrives
    mockWork.value = { pending: 1, pendingother: 0, total: 1 }

    await nextTick()
    await flushPromises()

    expect(mockFetchMessagesMT).toHaveBeenCalled()
  })

  // Regression test for Discourse #9737: the pending list must re-fetch even
  // when a modal is open (body.style.overflow === 'hidden').  Previously the
  // overflow check blocked the fetch, leaving the badge count correct but the
  // list stale until the next manual page refresh.
  it('calls getMessages when pending count increases even while a modal is open', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother']

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // Simulate any open modal (Bootstrap sets overflow:hidden on <body>)
    document.body.style.overflow = 'hidden'

    // A new pending message arrives while the modal is open
    mockWork.value = { pending: 1, pendingother: 0, total: 1 }

    await nextTick()
    await flushPromises()

    // The list must still be re-fetched regardless of body overflow state
    expect(mockFetchMessagesMT).toHaveBeenCalled()
  })

  it('does not call getMessages when pending count is unchanged', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother']

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // Count does not change — same values, new object (what a real fetchUser returns)
    mockWork.value = { pending: 0, pendingother: 0, total: 0 }

    await nextTick()
    await flushPromises()

    // No new work → should NOT trigger a re-fetch
    expect(mockFetchMessagesMT).not.toHaveBeenCalled()
  })
})
