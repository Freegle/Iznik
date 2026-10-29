/**
 * The Pending page's "Hide posts held by others" switch hides, on the client,
 * the loaded posts held by a moderator other than the signed-in one. A post I
 * hold myself stays. The hidden ones are counted so the page can say so, and
 * hiding must not stop the list loading more: `messages` (what loadMore
 * compares `show` against) stays complete.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref, effectScope } from 'vue'

const ME = 10
const OTHER = 20

const mockUser = ref({ id: ME })
const storeMessages = ref([])

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get work() {
      return { pending: 0, pendingother: 0 }
    },
    get user() {
      return mockUser.value
    },
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    clearContext: vi.fn(),
    clear: vi.fn(),
    fetchMessagesMT: vi.fn().mockResolvedValue([]),
    get all() {
      return storeMessages.value
    },
    getByGroup: (gid) =>
      storeMessages.value.filter((m) =>
        m.groups.some((g) => g.groupid === gid)
      ),
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

function post(id, heldby, extra = {}) {
  return {
    id,
    groups: [
      {
        groupid: 1,
        collection: 'Pending',
        arrival: new Date(2026, 0, 1, 0, id).toISOString(),
        ...(heldby === undefined ? {} : { heldby }),
        ...extra,
      },
    ],
  }
}

describe('useModMessages - hide posts held by others', () => {
  let scope
  let mm

  async function setup() {
    const { setupModMessages } =
      await import('~/modtools/composables/useModMessages')
    scope.run(() => {
      mm = setupModMessages(true)
      mm.collection.value = 'Pending'
    })
    mm.show.value = storeMessages.value.length
  }

  beforeEach(() => {
    scope = effectScope()
    vi.resetModules()
    mockUser.value = { id: ME }
    storeMessages.value = [
      post(1), // free
      post(2, OTHER), // held by someone else (Go API: numeric id)
      post(3, ME), // held by me
      post(4, { id: OTHER, displayname: 'Other Mod' }), // PHP API: object
    ]
  })

  afterEach(() => {
    scope.stop()
    vi.resetModules()
  })

  const ids = (list) => list.map((m) => m.id).sort()

  it('shows every post when the switch is off', async () => {
    await setup()

    expect(mm.hideHeldByOthers.value).toBe(false)
    expect(ids(mm.visibleMessages.value)).toEqual([1, 2, 3, 4])
    expect(mm.hiddenHeldCount.value).toBe(0)
  })

  it('hides posts held by others and keeps my own and free ones when on', async () => {
    await setup()
    mm.hideHeldByOthers.value = true

    expect(ids(mm.visibleMessages.value)).toEqual([1, 3])
    expect(mm.hiddenHeldCount.value).toBe(2)
  })

  it('leaves the loaded list complete so loading more is not stalled', async () => {
    await setup()
    mm.hideHeldByOthers.value = true

    expect(ids(mm.messages.value)).toEqual([1, 2, 3, 4])
  })

  it('has nothing visible, but still counts them, when every loaded post is held by others', async () => {
    storeMessages.value = [post(2, OTHER), post(4, OTHER)]
    await setup()
    mm.hideHeldByOthers.value = true

    expect(mm.visibleMessages.value).toEqual([])
    expect(mm.messages.value).toHaveLength(2)
    expect(mm.hiddenHeldCount.value).toBe(2)
  })

  it('judges the copy on the chosen community, not another one', async () => {
    const both = post(5)
    both.groups.push({
      groupid: 2,
      collection: 'Pending',
      arrival: both.groups[0].arrival,
      heldby: OTHER,
    })
    storeMessages.value = [both]
    await setup()
    mm.hideHeldByOthers.value = true

    mm.groupid.value = 1
    expect(ids(mm.visibleMessages.value)).toEqual([5])

    mm.groupid.value = 2
    expect(mm.visibleMessages.value).toEqual([])
    expect(mm.hiddenHeldCount.value).toBe(1)
  })

  it('hides nothing when nobody is signed in', async () => {
    mockUser.value = null
    await setup()
    mm.hideHeldByOthers.value = true

    expect(ids(mm.visibleMessages.value)).toEqual([1, 2, 3, 4])
    expect(mm.hiddenHeldCount.value).toBe(0)
  })

  it('is not cleared when the page resets the shared list state', async () => {
    await setup()
    mm.hideHeldByOthers.value = true

    const { setupModMessages } =
      await import('~/modtools/composables/useModMessages')
    scope.run(() => setupModMessages(true))

    expect(mm.hideHeldByOthers.value).toBe(true)
  })
})
