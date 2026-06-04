import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed } from 'vue'
import CheckedPage from '~/modtools/pages/messages/checked/[[id]]/[[term]].vue'

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

describe('messages/checked/[[id]]/[[term]].vue page', () => {
  function mountComponent() {
    return mount(CheckedPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': { template: '<div><slot /></div>' },
          ScrollToTop: { template: '<div class="scroll-to-top" />' },
          ModGroupSelect: {
            template: '<div class="mod-group-select" />',
            props: ['modelValue', 'all', 'modonly', 'remember', 'urlOverride'],
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
  })

  it('mounts and targets the Approved collection', () => {
    const wrapper = mountComponent()
    expect(wrapper.exists()).toBe(true)
    expect(mockCollection.value).toBe('Approved')
  })

  it('defaults the oversight list to summary view', () => {
    mountComponent()
    expect(mockMiscStore.set).toHaveBeenCalledWith({
      key: 'modtoolsMessagesCheckedSummary',
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

  it('markAllChecked clears the checked bucket and refreshes work counts', async () => {
    mockGroupid.value = 7
    const wrapper = mountComponent()
    await wrapper.vm.markAllChecked()
    expect(mockMessageStore.markChecked).toHaveBeenCalledWith({
      groupid: 7,
      filter: 'checked',
    })
    expect(mockMessageStore.clear).toHaveBeenCalled()
    expect(mockCheckWork).toHaveBeenCalled()
  })
})
