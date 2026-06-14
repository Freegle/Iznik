/**
 * Tests for the pending-messages auto-refresh behaviour.
 *
 * When authStore.work increases (new pending messages arrived) the list must
 * refresh so the message becomes visible without a manual reload (Discourse
 * #9737) — EXCEPT while a modal is open (e.g. a moderator typing a rejection
 * reason), where re-rendering would unmount the modal and lose the draft. In
 * that case the refresh is deferred and applied as soon as the modal closes.
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

  // A new pending message arriving while a modal is open (Bootstrap sets <body>
  // overflow:hidden) must NOT refresh the list immediately — that would unmount
  // the modal and lose a moderator's part-typed rejection reason. The refresh
  // is deferred and applied as soon as the modal closes, so the message still
  // appears without a manual reload (reconciles Discourse #9737 with draft
  // preservation).
  it('defers the refresh while a modal is open and applies it on close', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother']

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // A modal opens (Bootstrap sets overflow:hidden on <body>).
    document.body.style.overflow = 'hidden'

    // A new pending message arrives while the modal is open.
    mockWork.value = { pending: 1, pendingother: 0, total: 1 }
    await nextTick()
    await flushPromises()

    // The list must NOT refresh yet — that would lose the open modal's draft.
    expect(mockFetchMessagesMT).not.toHaveBeenCalled()

    // The modal closes (Bootstrap clears the body overflow style).
    document.body.style.overflow = ''
    await nextTick()
    await flushPromises()

    // Now the deferred refresh is applied so the new message becomes visible.
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

  // Regression test for Discourse #9783 — badge/list mismatch when a message is
  // moved to Spam by ContentCheck (global concern keyword with action='block').
  // The left-nav red badge counts pending+spam, so work.spam increasing raises
  // the badge. But workType=['pending','pendingother'] excludes spam from workdetail,
  // so the watcher's JSON.stringify comparison sees no change and skips getMessages.
  // Result: red badge with an empty pending list.
  it('does not refresh when spam increases and workType excludes spam (documents the bug)', async () => {
    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother'] // buggy: excludes 'spam'

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // A ContentCheck 'block' action moves the message to Spam collection.
    mockWork.value = { pending: 0, pendingother: 0, spam: 1, total: 1 }

    await nextTick()
    await flushPromises()

    // workdetail = {pending:0, pendingother:0, total:0} — unchanged because spam
    // is not in workType → watcher skips → list not refreshed (the bug).
    expect(mockFetchMessagesMT).not.toHaveBeenCalled()
  })

  // Fix for Discourse #9783: adding 'spam' to workType ensures workdetail.total
  // increases when work.spam increases, so the watcher fires and getMessages
  // refreshes the list (which already shows Spam-collection messages since PR #709).
  it('calls getMessages when spam count increases and workType includes spam', async () => {
    mockWork.value = { pending: 0, pendingother: 0, spam: 0, total: 0 }

    const { setupModMessages } = await import(
      '~/modtools/composables/useModMessages'
    )
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother', 'spam'] // fixed

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // A ContentCheck 'block' action moves the message to Spam collection.
    mockWork.value = { pending: 0, pendingother: 0, spam: 1, total: 1 }

    await nextTick()
    await flushPromises()

    // workdetail = {pending:0, pendingother:0, spam:1, total:1} — changed → watcher
    // fires → getMessages called → list shows the Spam message.
    expect(mockFetchMessagesMT).toHaveBeenCalled()
  })
})
