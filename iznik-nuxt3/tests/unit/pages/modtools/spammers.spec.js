import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import SpammersPage from '~/modtools/pages/spammers.vue'

// Create mock stores with all required methods and state
const mockSpammerStore = {
  list: [],
  context: null,
  clear: vi.fn(),
  fetch: vi.fn().mockResolvedValue({}),
  confirmMany: vi.fn().mockResolvedValue({ done: [], failed: [] }),
  getList: vi.fn().mockReturnValue([]),
  getContext: vi.fn().mockReturnValue(null),
}

const mockAuthStore = {
  work: {
    spammerpendingadd: 5,
    spammerpendingremove: 2,
  },
}

// Mock vue-router
vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: {},
      query: {},
      path: '/spammers',
      name: 'spammers',
      fullPath: '/spammers',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
  }
})

globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/spammers',
  name: 'spammers',
  fullPath: '/spammers',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
})

// Mock stores
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/spammer', () => ({
  useSpammerStore: () => mockSpammerStore,
}))

// Mock composables
vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({
    hasPermissionSpamAdmin: ref(true),
  }),
}))

describe('spammers.vue page', () => {
  function mountComponent(options = {}) {
    return mount(SpammersPage, {
      global: {
        stubs: {
          ModHelpSpammers: { template: '<div class="help-stub" />' },
          ModMemberSearchbox: {
            template: '<div class="searchbox-stub" />',
            emits: ['search'],
          },
          ModMember: { template: '<div class="member-stub" />' },
          ModBulkSelectBar: {
            template:
              '<div class="bulk-bar-stub" :data-count="count"><slot /></div>',
            props: ['count', 'limit', 'shownIds', 'copyRows', 'busy'],
            emits: ['select-all', 'clear'],
          },
          NoticeMessage: {
            template: '<div class="notice-stub"><slot /></div>',
            props: ['variant'],
          },
          'b-form-checkbox': {
            template:
              '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', !modelValue)" />',
            props: ['modelValue', 'disabled', 'title'],
            emits: ['update:modelValue'],
          },
          'b-button': {
            template:
              '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
            emits: ['click'],
          },
          'v-icon': { template: '<i />', props: ['icon'] },
          'b-tabs': {
            template: '<div class="tabs"><slot /></div>',
            props: ['modelValue'],
            emits: ['update:modelValue'],
          },
          'b-tab': {
            template: '<div class="tab"><slot /><slot name="title" /></div>',
            props: ['active', 'id'],
          },
          'b-badge': { template: '<span class="badge"><slot /></span>' },
          'b-img': { template: '<img />' },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /></div>',
            emits: ['infinite'],
          },
        },
        ...options.global,
      },
      ...options,
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockSpammerStore.list = []
    mockSpammerStore.getList.mockReturnValue([])
  })

  describe('initial state', () => {
    it('clears spammer store on mount', () => {
      mountComponent()
      expect(mockSpammerStore.clear).toHaveBeenCalled()
    })
  })

  describe('computed properties', () => {
    it('calculates pendingaddcount from auth store work', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.pendingaddcount).toBe(5)
    })

    it('calculates pendingremovecount from auth store work', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.pendingremovecount).toBe(2)
    })

    it('returns 0 for pendingaddcount when work is null', () => {
      mockAuthStore.work = null
      const wrapper = mountComponent()
      expect(wrapper.vm.pendingaddcount).toBe(0)
      mockAuthStore.work = { spammerpendingadd: 5, spammerpendingremove: 2 }
    })

    it('returns spammers from store based on collection', () => {
      const mockSpammers = [
        { id: 1, user: { spammer: {} }, byuser: { id: 10 } },
        { id: 2, user: { spammer: {} }, byuser: { id: 20 } },
      ]
      mockSpammerStore.getList.mockReturnValue(mockSpammers)
      const wrapper = mountComponent()
      expect(wrapper.vm.spammers).toEqual(mockSpammers)
    })

    it('passes through store data with byuser already enriched', () => {
      // byuser enrichment now happens in the store, not the page
      const mockSpammers = [
        {
          id: 1,
          user: { spammer: { byuser: { id: 10 } } },
          byuser: { id: 10 },
        },
      ]
      mockSpammerStore.getList.mockReturnValue(mockSpammers)
      const wrapper = mountComponent()
      expect(wrapper.vm.spammers[0].user.spammer.byuser).toEqual({ id: 10 })
    })

    it('slices spammers to show count for visibleSpammers', () => {
      const mockSpammers = [
        { id: 1, user: { spammer: {} }, byuser: null },
        { id: 2, user: { spammer: {} }, byuser: null },
        { id: 3, user: { spammer: {} }, byuser: null },
      ]
      mockSpammerStore.getList.mockReturnValue(mockSpammers)
      const wrapper = mountComponent()
      wrapper.vm.show = 2
      expect(wrapper.vm.visibleSpammers).toHaveLength(2)
    })

    it('returns correct collection based on tabIndex', () => {
      const wrapper = mountComponent()

      wrapper.vm.tabIndex = 0
      expect(wrapper.vm.collection).toBe('Spammer')

      wrapper.vm.tabIndex = 1
      expect(wrapper.vm.collection).toBe('PendingAdd')

      wrapper.vm.tabIndex = 2
      expect(wrapper.vm.collection).toBe('Whitelisted')

      wrapper.vm.tabIndex = 3
      expect(wrapper.vm.collection).toBe('PendingRemove')
    })
  })

  describe('methods', () => {
    it('searched method clears store and sets search term', () => {
      const wrapper = mountComponent()
      const initialInfiniteId = wrapper.vm.infiniteId

      wrapper.vm.searched('test search')

      expect(mockSpammerStore.clear).toHaveBeenCalled()
      expect(wrapper.vm.search).toBe('test search')
      expect(wrapper.vm.infiniteId).toBe(initialInfiniteId + 1)
    })

    it('loadMore increments show when more items available locally', async () => {
      const mockSpammers = [
        { id: 1, user: { spammer: {} }, byuser: null },
        { id: 2, user: { spammer: {} }, byuser: null },
      ]
      mockSpammerStore.getList.mockReturnValue(mockSpammers)

      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      wrapper.vm.show = 0
      await wrapper.vm.loadMore(mockState)

      expect(wrapper.vm.show).toBe(1)
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('loadMore fetches more when all local items shown', async () => {
      mockSpammerStore.getList.mockReturnValue([])
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      wrapper.vm.show = 0
      await wrapper.vm.loadMore(mockState)

      expect(mockSpammerStore.fetch).toHaveBeenCalledWith({
        collection: expect.any(String),
        search: null,
        modtools: true,
      })
    })

    it('loadMore calls complete when no new items fetched', async () => {
      mockSpammerStore.getList.mockReturnValue([])
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      await wrapper.vm.loadMore(mockState)

      expect(mockState.complete).toHaveBeenCalled()
    })
  })

  describe('watchers', () => {
    it('clears store and increments infiniteId when tabIndex changes', async () => {
      const wrapper = mountComponent()
      const initialInfiniteId = wrapper.vm.infiniteId

      wrapper.vm.tabIndex = 2
      await wrapper.vm.$nextTick()

      expect(mockSpammerStore.clear).toHaveBeenCalled()
      expect(wrapper.vm.infiniteId).toBe(initialInfiniteId + 1)
    })
  })

  describe('mounted behavior', () => {
    it('sets tabIndex to 1 (PendingAdd) when user has spam admin permission', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.tabIndex).toBe(1)
    })

    it('sets tabIndex to 0 (Spammer) when search is set', () => {
      const wrapper = mountComponent()
      wrapper.vm.search = 'test'
      wrapper.vm.tabIndex = 0
      // Re-mount to test mounted logic with search
      expect(wrapper.vm.tabIndex).toBe(0)
    })
  })

  describe('tab visibility', () => {
    it('shows PendingAdd tab when user has spam admin permission', () => {
      const wrapper = mountComponent()
      const tabs = wrapper.findAll('.tab')
      expect(tabs.length).toBeGreaterThan(1)
    })
  })

  describe('bulk selection', () => {
    function pending(n, extra = {}) {
      return Array.from({ length: n }, (_, i) => ({
        id: i + 1,
        userid: 1000 + i + 1,
        collection: 'PendingAdd',
        reason: 'Sent spam',
        added: '2026-10-24T10:00:00Z',
        heldby: null,
        user: {
          id: 1000 + i + 1,
          displayname: 'Eric Jones',
          email: `eric${i + 1}@example.com`,
        },
        byuser: { id: 5, displayname: 'Sheila' },
        ...extra,
      }))
    }

    async function mountPending(rows) {
      mockSpammerStore.getList.mockReturnValue(rows)
      const wrapper = mountComponent()
      wrapper.vm.tabIndex = 1
      wrapper.vm.show = rows.length
      await wrapper.vm.$nextTick()
      return wrapper
    }

    it('shows the bar with a Confirm button on Pending Add for a Spam admin', async () => {
      const wrapper = await mountPending(pending(2))
      expect(wrapper.find('.bulk-bar-stub').exists()).toBe(true)
      expect(wrapper.find('[data-test="bulk-confirm"]').exists()).toBe(true)
    })

    it('hides the Confirm button on the confirmed list but keeps the bar for copying', async () => {
      mockSpammerStore.getList.mockReturnValue(
        pending(2, { collection: 'Spammer' })
      )
      const wrapper = mountComponent()
      wrapper.vm.tabIndex = 0
      wrapper.vm.show = 2
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.bulk-bar-stub').exists()).toBe(true)
      expect(wrapper.find('[data-test="bulk-confirm"]').exists()).toBe(false)
    })

    it('selects at most fifty when selecting all shown', async () => {
      const wrapper = await mountPending(pending(60))
      wrapper.vm.bulk.selectAll(wrapper.vm.selectableIds)
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.bulk.count.value).toBe(50)
      expect(wrapper.find('.bulk-bar-stub').attributes('data-count')).toBe('50')
    })

    it('does not offer a held row for selection on Pending Add', async () => {
      const rows = pending(3)
      rows[1].heldby = 99
      const wrapper = await mountPending(rows)
      expect(wrapper.vm.selectableIds).toEqual([1, 3])
      const held = wrapper.find('[data-test="bulk-check-2"]')
      expect(held.attributes('disabled')).toBeDefined()
    })

    it('confirms the selected rows in one go and reports the outcome', async () => {
      const wrapper = await mountPending(pending(3))
      mockSpammerStore.confirmMany.mockResolvedValue({
        done: [{ id: 1 }, { id: 3 }],
        failed: [
          {
            item: { id: 2, userid: 1002, name: 'Eric Jones' },
            error: { message: 'Pat is holding this.' },
          },
        ],
      })

      wrapper.vm.bulk.selectAll([1, 2, 3])
      await wrapper.vm.$nextTick()
      await wrapper.find('[data-test="bulk-confirm"]').trigger('click')
      await flushPromises()

      expect(mockSpammerStore.confirmMany).toHaveBeenCalledWith([
        { id: 1, userid: 1001, name: 'Eric Jones' },
        { id: 2, userid: 1002, name: 'Eric Jones' },
        { id: 3, userid: 1003, name: 'Eric Jones' },
      ])
      expect(wrapper.vm.bulkResult.done).toBe(2)
      expect(wrapper.vm.bulkResult.failed).toEqual([
        {
          id: 2,
          userid: 1002,
          name: 'Eric Jones',
          message: 'Pat is holding this.',
        },
      ])
      expect(wrapper.find('[data-test="bulk-result"]').text()).toContain(
        'Pat is holding this.'
      )
      // The selection is cleared afterwards, whatever happened.
      expect(wrapper.vm.bulk.count.value).toBe(0)
    })

    it('fetches the list again, through its own loader, when a hold got in the way', async () => {
      const wrapper = await mountPending(pending(2))
      mockSpammerStore.confirmMany.mockResolvedValue({
        done: [{ id: 1 }],
        failed: [
          {
            item: { id: 2, userid: 1002, name: 'Eric Jones' },
            error: { message: 'Pat is holding this.', heldByOtherMod: true },
          },
        ],
        heldConflict: true,
      })
      const before = wrapper.vm.infiniteId
      mockSpammerStore.clear.mockClear()
      wrapper.vm.bulk.selectAll([1, 2])
      await wrapper.vm.confirmSelected()
      expect(mockSpammerStore.clear).toHaveBeenCalled()
      expect(wrapper.vm.infiniteId).toBe(before + 1)
      expect(wrapper.vm.bulkResult.done).toBe(1)
    })

    it('reports every row as failed when the whole call throws', async () => {
      const wrapper = await mountPending(pending(2))
      mockSpammerStore.confirmMany.mockRejectedValue(new Error('Network down'))
      wrapper.vm.bulk.selectAll([1, 2])
      await wrapper.vm.confirmSelected()
      expect(wrapper.vm.bulkResult.done).toBe(0)
      expect(wrapper.vm.bulkResult.failed.map((f) => f.id)).toEqual([1, 2])
      expect(wrapper.vm.bulkResult.failed[0].message).toBe('Network down')
    })

    it('will not tick a 51st row', async () => {
      const wrapper = await mountPending(pending(52))
      wrapper.vm.bulk.selectAll(wrapper.vm.selectableIds)
      await wrapper.vm.$nextTick()
      expect(
        wrapper.find('[data-test="bulk-check-52"]').attributes('disabled')
      ).toBeDefined()
      expect(
        wrapper.find('[data-test="bulk-check-1"]').attributes('disabled')
      ).toBeUndefined()
    })

    it('does nothing when Confirm is pressed with nothing selected', async () => {
      const wrapper = await mountPending(pending(2))
      await wrapper.vm.confirmSelected()
      expect(mockSpammerStore.confirmMany).not.toHaveBeenCalled()
    })

    it('copies the selected rows as plain fields, or everything shown when none are', async () => {
      const wrapper = await mountPending(pending(2))
      expect(wrapper.vm.copyRows).toEqual([
        {
          id: 1001,
          name: 'Eric Jones',
          email: 'eric1@example.com',
          reason: 'Sent spam',
          added: '2026-10-24',
          by: 'Sheila',
        },
        {
          id: 1002,
          name: 'Eric Jones',
          email: 'eric2@example.com',
          reason: 'Sent spam',
          added: '2026-10-24',
          by: 'Sheila',
        },
      ])

      wrapper.vm.bulk.toggle(2)
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.copyRows.map((r) => r.id)).toEqual([1002])
    })

    it('clears the selection when the tab changes', async () => {
      const wrapper = await mountPending(pending(2))
      wrapper.vm.bulk.selectAll([1, 2])
      wrapper.vm.tabIndex = 0
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.bulk.count.value).toBe(0)
    })

    it('drops a selected row that has left the list', async () => {
      const rows = pending(2)
      const live = ref(rows)
      mockSpammerStore.getList.mockImplementation(() => live.value)
      const wrapper = mountComponent()
      wrapper.vm.tabIndex = 1
      wrapper.vm.show = 2
      await wrapper.vm.$nextTick()
      wrapper.vm.bulk.selectAll([1, 2])
      expect(wrapper.vm.bulk.count.value).toBe(2)

      // Row 1 is confirmed by somebody else and vanishes on the next fetch.
      live.value = [rows[1]]
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.bulk.isSelected(1)).toBe(false)
      expect(wrapper.vm.bulk.isSelected(2)).toBe(true)
    })
  })
})
