import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import CheckPage from '~/modtools/pages/messages/check/[[id]].vue'

const mockBusy = ref(false)
const mockContext = ref(null)
const mockGroup = ref(null)
const mockGroupid = ref(0)
const mockShow = ref(0)
const mockCollection = ref(null)
const mockDistance = ref(10)
const mockSummarykey = ref(false)
const mockMessages = ref([])
const mockListingIds = ref(new Set())

vi.mock('~/composables/useModMessages', () => ({
  setupModMessages: () => ({
    busy: mockBusy,
    context: mockContext,
    group: mockGroup,
    groupid: mockGroupid,
    show: mockShow,
    collection: mockCollection,
    distance: mockDistance,
    summarykey: mockSummarykey,
    messages: mockMessages,
    listingIds: mockListingIds,
  }),
}))

const mockMessageStore = {
  list: {},
  context: null,
  fetchMessagesMT: vi.fn().mockResolvedValue([]),
  markChecked: vi.fn().mockResolvedValue(2),
  clear: vi.fn(),
}
vi.mock('@/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const mockCheckWork = vi.fn().mockResolvedValue(undefined)
vi.mock('~/composables/useModMe', () => ({
  useModMe: () => ({ checkWork: mockCheckWork }),
}))

// What the menu badge counts, and the communities of mine that have a Check queue.
const mockAuthStore = { work: { checked: 0 } }
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

const mockTrialGroupIds = ref([3, 7])
vi.mock('~/modtools/composables/useAutoapproveTrial', () => ({
  useAutoapproveTrial: () => ({ trialGroupIds: mockTrialGroupIds }),
}))

const mockModsHeld = ref(false)
vi.mock('~/modtools/composables/useLockdown', () => ({
  useLockdown: () => ({ modsHeld: mockModsHeld }),
}))

const mockMiscStore = { get: vi.fn(), set: vi.fn() }
vi.mock('@/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

const mockModGroupStore = {
  received: true,
  get: vi.fn(),
  fetchIfNeedBeMT: vi.fn().mockResolvedValue(undefined),
}
vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: ref({ id: 1, displayname: 'Test User' }) }),
}))

const mockRouteParams = ref({ id: undefined, term: undefined })
const mockRouterPush = vi.fn()

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({ params: mockRouteParams.value, query: {} }),
    useRouter: () => ({ push: mockRouterPush }),
  }
})

globalThis.__testUseRoute = () => ({ params: mockRouteParams.value, query: {} })
globalThis.__testUseRouter = () => ({
  push: mockRouterPush,
  replace: mockRouterPush,
  currentRoute: { value: { path: '/' } },
})

describe('messages/check/[[id]].vue page', () => {
  function mountComponent() {
    return mount(CheckPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ModHelpCheck: { template: '<div class="mod-help-check" />' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModGroupSelect: {
            template: '<div class="mod-group-select" />',
            props: [
              'modelValue',
              'all',
              'modonly',
              'remember',
              'urlOverride',
              'onlyGroupids',
            ],
          },
          'b-button': {
            template:
              '<button class="b-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size', 'disabled'],
            emits: ['click'],
          },
          // Confirms on its own button, as the real one does on its footer button.
          ConfirmModal: {
            template:
              '<div class="confirm-modal"><span class="confirm-title">{{ title }}</span><slot /><button class="confirm-yes" @click="$emit(\'confirm\')">{{ confirmLabel }}</button></div>',
            props: ['title', 'confirmLabel'],
            emits: ['confirm', 'hidden'],
          },
          ModtoolsViewControl: {
            template: '<div class="modtools-view-control" />',
            props: ['misckey'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
          },
          ModMessages: { template: '<div class="mod-messages" />' },
          Spinner: { template: '<div class="spinner" />', props: ['size'] },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /></div>',
            props: ['direction', 'distance', 'identifier'],
            emits: ['infinite'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockBusy.value = false
    mockContext.value = null
    mockGroupid.value = 0
    mockShow.value = 0
    mockCollection.value = null
    mockMessages.value = []
    mockListingIds.value = new Set()
    mockMessageStore.list = {}
    mockMessageStore.context = null
    mockMiscStore.get.mockReturnValue(undefined)
    mockRouteParams.value = { id: undefined, term: undefined }
    mockAuthStore.work = { checked: 0 }
    mockTrialGroupIds.value = [3, 7]
    mockModsHeld.value = false
    mockGroup.value = null
  })

  it('mounts and targets the Approved collection', () => {
    const wrapper = mountComponent()
    expect(wrapper.exists()).toBe(true)
    expect(mockCollection.value).toBe('Approved')
  })

  it('defaults the oversight list to summary view', () => {
    mountComponent()
    expect(mockMiscStore.set).toHaveBeenCalledWith({
      key: 'modtoolsMessagesCheckSummary',
      value: true,
    })
  })

  it('loadMore fetches with the checked filter on the Approved collection', async () => {
    const wrapper = mountComponent()
    const $state = { loaded: vi.fn(), complete: vi.fn(), error: vi.fn() }
    await wrapper.vm.loadMore($state)
    expect(mockMessageStore.fetchMessagesMT).toHaveBeenCalled()
    const params = mockMessageStore.fetchMessagesMT.mock.calls[0][0]
    expect(params.filter).toBe('checked')
    expect(params.collection).toBe('Approved')
  })

  it('reads the group id from the route param', () => {
    mockRouteParams.value = { id: '42', term: undefined }
    const wrapper = mountComponent()
    expect(wrapper.vm.id).toBe(42)
    expect(mockGroupid.value).toBe(42)
  })

  // The list is paged, and a stamp counts as a moderator's look, so only the posts on
  // screen are marked: never the whole bucket.
  it('markAllChecked marks the loaded posts only and refreshes work counts', async () => {
    mockGroupid.value = 7
    mockMessages.value = [{ id: 101 }, { id: 102 }]
    const wrapper = mountComponent()
    await wrapper.vm.markAllChecked()
    expect(mockMessageStore.markChecked).toHaveBeenCalledWith({
      groupid: 7,
      ids: [101, 102],
    })
    expect(mockMessageStore.clear).toHaveBeenCalled()
    expect(mockCheckWork).toHaveBeenCalled()
  })

  // Only a community in the trial has a Check queue, so only those are on offer.
  it('offers only the communities in the trial', () => {
    const wrapper = mountComponent()
    expect(
      wrapper.findComponent('.mod-group-select').props('onlyGroupids')
    ).toEqual([3, 7])
  })

  // One click that says "I have looked at all of these" gets a question first, with the count.
  describe('Mark these as checked', () => {
    const bulk = (wrapper) => wrapper.find('[data-testid="mark-these-checked"]')

    it('asks first, naming how many, and marks nothing yet', async () => {
      mockMessages.value = [{ id: 101 }, { id: 102 }, { id: 103 }]
      const wrapper = mountComponent()
      await bulk(wrapper).trigger('click')
      expect(mockMessageStore.markChecked).not.toHaveBeenCalled()
      const modal = wrapper.find('.confirm-modal')
      expect(modal.exists()).toBe(true)
      expect(modal.find('.confirm-title').text()).toBe(
        'Mark 3 posts as checked?'
      )
      expect(modal.text()).toContain('looked at every post listed here')
      expect(modal.text()).toContain('spread')
    })

    it('says one post, not one posts', async () => {
      mockMessages.value = [{ id: 101 }]
      const wrapper = mountComponent()
      await bulk(wrapper).trigger('click')
      expect(wrapper.find('.confirm-title').text()).toBe(
        'Mark 1 post as checked?'
      )
    })

    it('marks the posts once the question is answered yes', async () => {
      mockGroupid.value = 7
      mockMessages.value = [{ id: 101 }, { id: 102 }]
      const wrapper = mountComponent()
      await bulk(wrapper).trigger('click')
      await wrapper.find('.confirm-yes').trigger('click')
      await flushPromises()
      expect(mockMessageStore.markChecked).toHaveBeenCalledWith({
        groupid: 7,
        ids: [101, 102],
      })
      expect(mockCheckWork).toHaveBeenCalledWith(true)
    })

    it('has nothing to mark when nothing is loaded, or while the moderators surface is held', async () => {
      const empty = mountComponent()
      expect(bulk(empty).attributes('disabled')).toBeDefined()

      mockMessages.value = [{ id: 101 }]
      mockModsHeld.value = true
      const held = mountComponent()
      expect(bulk(held).attributes('disabled')).toBeDefined()
    })
  })

  // The menu badge counts every community of mine, and the choice of community is
  // remembered. A page that says "nothing" while the badge says there is work, with no
  // reason given, is how a moderator concludes ModTools is broken.
  describe('an empty list', () => {
    const notice = (wrapper) => wrapper.find('.notice-message')

    it('says there is nothing to check when there is none anywhere', () => {
      const wrapper = mountComponent()
      expect(notice(wrapper).text()).toContain('Nothing to check.')
      expect(
        wrapper.find('[data-testid="show-all-communities"]').exists()
      ).toBe(false)
    })

    it('says what the filter is hiding, when a community is chosen and there is work in others', () => {
      mockGroupid.value = 3
      mockGroup.value = { id: 3, namedisplay: 'Little Hampton' }
      mockAuthStore.work = { checked: 4 }
      const wrapper = mountComponent()
      const text = notice(wrapper).text()
      expect(text).toContain('Nothing to check in Little Hampton')
      expect(text).toContain('there are 4 waiting across your communities')
      expect(text).toContain('filtering this page to one community')
    })

    it('says "there is 1 waiting" for one', () => {
      mockGroupid.value = 3
      mockAuthStore.work = { checked: 1 }
      const wrapper = mountComponent()
      expect(notice(wrapper).text()).toContain('there is 1 waiting')
    })

    it('does not blame the filter when all communities are shown', () => {
      mockGroupid.value = 0
      mockAuthStore.work = { checked: 4 }
      const wrapper = mountComponent()
      expect(notice(wrapper).text()).toContain('Nothing to check.')
    })

    it('does not blame the filter when there is no work anywhere', () => {
      mockGroupid.value = 3
      mockAuthStore.work = { checked: 0 }
      const wrapper = mountComponent()
      expect(notice(wrapper).text()).toContain('Nothing to check.')
      expect(notice(wrapper).text()).not.toContain('filtering')
    })

    it('shows all my communities again, and forgets the remembered choice', async () => {
      mockGroupid.value = 3
      mockAuthStore.work = { checked: 2 }
      const wrapper = mountComponent()
      await wrapper
        .find('[data-testid="show-all-communities"]')
        .trigger('click')
      // Only a choice made through the dropdown goes through its setter, so the remembered
      // value has to be cleared as well or the filter comes straight back next visit.
      expect(mockMiscStore.set).toHaveBeenCalledWith({
        key: 'groupselect-check',
        value: 0,
      })
      expect(mockGroupid.value).toBe(0)
    })
  })
})
