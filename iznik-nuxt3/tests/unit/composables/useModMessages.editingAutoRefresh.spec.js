/**
 * Discourse 10001/2: a moderator edits a Pending message in place
 * (ModMessage.vue's inline Edit/Save - not a Bootstrap modal) and clicks
 * Save; the row can vanish from the Pending list, reappearing only on a
 * full page reload.
 *
 * ModMessage.vue already sets miscStore.modtoolsediting while an in-place
 * edit is open ("do not check for work" - stores/misc.js), and that flag IS
 * wired into the periodic work-count poll (useModMe.js checkWork()). But the
 * pending-list auto-refresh guard in useModMessages.js only ever checked
 * whether a Bootstrap modal was open (<body> overflow:hidden) before
 * deciding to run a full getMessages() reload - it never checked
 * modtoolsediting. So a workdetail change landing while a moderator is
 * editing in place (or in the instant after Save flips modtoolsediting back
 * to false and the next work-poll tick catches up) triggers an unprotected
 * clear-and-refetch of the entire Pending list, unlike every other mutating
 * mod action (approve/reject/hold/etc, via checkWorkDeferGetMessages), which
 * IS covered by this same guard when a rejection-reason modal is open.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, nextTick } from 'vue'
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

const mockModtoolsediting = ref(false)
vi.mock('@/stores/misc', () => ({
  useMiscStore: () => ({
    deferGetMessages: false,
    get modtoolsediting() {
      return mockModtoolsediting.value
    },
    get: vi.fn(() => undefined),
  }),
}))

describe('useModMessages — pending list auto-refresh while inline-editing', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.resetModules()
    mockWork.value = { pending: 0, pendingother: 0, total: 0 }
    mockModtoolsediting.value = false
    mockFetchMessagesMT.mockResolvedValue([])
    document.body.style.overflow = ''
  })

  afterEach(() => {
    vi.resetModules()
    document.body.style.overflow = ''
  })

  it('defers the refresh while a message is being edited in place and applies it once editing finishes', async () => {
    const { setupModMessages } =
      await import('~/modtools/composables/useModMessages')
    const { workType, collection } = setupModMessages(true)
    collection.value = 'Pending'
    workType.value = ['pending', 'pendingother']

    await nextTick()
    await flushPromises()
    vi.clearAllMocks()

    // The moderator starts editing a Pending message in place (ModMessage.vue
    // startEdit() sets this).
    mockModtoolsediting.value = true

    // Meanwhile a work-count change lands - e.g. another moderator actions a
    // different pending message, or the queued work-poll catches up.
    mockWork.value = { pending: 1, pendingother: 0, total: 1 }
    await nextTick()
    await flushPromises()

    // Must NOT reload the list while the moderator is editing - that unmounts
    // the ModMessage they are working on and can drop it from the list.
    expect(mockFetchMessagesMT).not.toHaveBeenCalled()

    // Editing finishes (save() or cancelEdit() clears modtoolsediting).
    mockModtoolsediting.value = false
    await nextTick()
    await flushPromises()

    // The deferred refresh is now applied, same as a parked modal refresh is
    // applied as soon as the modal closes.
    expect(mockFetchMessagesMT).toHaveBeenCalled()
  })
})
