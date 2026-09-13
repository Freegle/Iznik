import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed, reactive } from 'vue'
import PendingPage from '~/modtools/pages/messages/pending/[[id]]/[[term]].vue'

// Mock refs that will be shared between tests
const mockBusy = ref(false)
const mockContext = ref(null)
const mockGroup = ref(null)
const mockGroupid = ref(0)
const mockLimit = ref(10)
const mockWorkType = ref(null)
const mockShow = ref(0)
const mockCollection = ref(null)
const mockMessageTerm = ref(null)
const mockMemberTerm = ref(null)
const mockDistance = ref(10)
const mockSummarykey = ref(false)
const mockSummary = computed(() => false)
const mockMessages = ref([])
const mockVisibleMessages = ref([])
const mockWork = ref(0)
const mockNextAfterRemoved = ref(null)
const mockGetMessages = vi.fn()
const mockListingIds = ref(new Set())

vi.mock('~/composables/useModMessages', () => ({
  setupModMessages: () => ({
    busy: mockBusy,
    context: mockContext,
    group: mockGroup,
    groupid: mockGroupid,
    limit: mockLimit,
    workType: mockWorkType,
    show: mockShow,
    collection: mockCollection,
    messageTerm: mockMessageTerm,
    memberTerm: mockMemberTerm,
    distance: mockDistance,
    summarykey: mockSummarykey,
    summary: mockSummary,
    messages: mockMessages,
    visibleMessages: mockVisibleMessages,
    work: mockWork,
    nextAfterRemoved: mockNextAfterRemoved,
    listingIds: mockListingIds,
    getMessages: mockGetMessages,
  }),
}))

// Mock stores
// Reactive so the page's `outstanding` computed (which reads authStore.work,
// the same counts the menu badge uses) and the watcher on it actually fire.
const mockAuthStore = reactive({
  user: {
    settings: {
      lastaimsshow: null,
    },
  },
  work: {},
  saveAndGet: vi.fn(),
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

const mockMessageStore = {
  list: {},
  context: null,
  fetchMessagesMT: vi.fn(),
  clearContext: vi.fn(),
  clear: vi.fn(),
}

vi.mock('@/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const mockMiscStore = {
  get: vi.fn(),
  set: vi.fn(),
}

vi.mock('@/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

const mockModGroupStore = {
  list: {},
  received: true,
  fetchIfNeedBeMT: vi.fn(),
  get: vi.fn(),
}

vi.mock('@/stores/modgroup', () => ({
  useModGroupStore: () => mockModGroupStore,
}))

// Mock useMe composable
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 1, displayname: 'Test User' }),
    myGroups: ref([{ id: 1, name: 'TestGroup', role: 'Owner' }]),
  }),
}))

// Mock useRoute
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
      path: '/',
      name: 'modtools-messages-pending',
      fullPath: '/',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
  }
})

globalThis.__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/',
  name: 'modtools-messages-pending',
  fullPath: '/',
  matched: [],
  redirectedFrom: undefined,
  meta: {},
})

describe('PendingPage', () => {
  function mountComponent() {
    return mount(PendingPage, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'client-only': {
            template: '<div><slot /></div>',
          },
          ModHelpPending: {
            template: '<div class="mod-help-pending" />',
          },
          ScrollToTop: {
            template: '<div class="scroll-to-top" />',
          },
          ModAimsModal: {
            template: '<div class="mod-aims-modal" />',
            emits: ['hidden'],
          },
          ModGroupSelect: {
            template: '<div class="mod-group-select" />',
            props: [
              'modelValue',
              'all',
              'modonly',
              'work',
              'remember',
              'urlOverride',
            ],
          },
          ModtoolsViewControl: {
            template: '<div class="modtools-view-control" />',
            props: ['misckey'],
          },
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
          ModMessages: {
            template: '<div class="mod-messages" />',
          },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'b-img': {
            template: '<img />',
            props: ['src', 'alt', 'lazy'],
          },
          'infinite-loading': {
            template:
              '<div class="infinite-loading"><slot name="spinner" /></div>',
            props: ['direction', 'forceUseInfiniteWrapper', 'identifier'],
            emits: ['infinite'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    // Reset mock ref values
    mockBusy.value = false
    mockContext.value = null
    mockGroup.value = null
    mockGroupid.value = 0
    mockShow.value = 0
    mockCollection.value = null
    mockWorkType.value = null
    mockMessages.value = []
    mockVisibleMessages.value = []
    mockWork.value = 0
    mockLimit.value = 10
    mockDistance.value = 10
    mockModGroupStore.received = true
    mockModGroupStore.list = {}
    mockMiscStore.get.mockReturnValue(null)
    mockAuthStore.user = { settings: { lastaimsshow: null } }
    mockAuthStore.work = {}
    mockListingIds.value = new Set()
    mockMessageStore.list = {}
    mockMessageStore.context = null
  })

  describe('rendering', () => {
    it('shows empty message when no messages, not busy, and groups received', async () => {
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('no messages at the moment')
    })

    // Discourse 10037: the group dropdown remembers its selection in
    // localStorage and silently re-applies it on every visit, while the
    // Pending badge in the menu counts work across every community. A
    // moderator whose remembered community happens to have nothing pending
    // sees a bare "no messages" page and a badge insisting there is work.
    // Two moderators reported it as "I can't moderate on desktop".
    it('names the filtered community and the outstanding count when the filter is hiding work', async () => {
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      mockGroupid.value = 522709
      mockGroup.value = { id: 522709, namedisplay: 'Skelmersdale Freegle' }
      // A component left mounted by an earlier test still watches the shared
      // groupid ref and re-derives group from the store, so the store has to
      // agree about which community this is.
      mockModGroupStore.get.mockReturnValue({
        id: 522709,
        namedisplay: 'Skelmersdale Freegle',
      })
      mockAuthStore.work = { pending: 3 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('Skelmersdale Freegle')
      expect(wrapper.text()).toContain('3')
      expect(wrapper.text()).toContain('Show all my communities')
    })

    it('counts spam towards the outstanding total, as the menu badge does', async () => {
      // workType on this page is pending+pendingother, but the listing also
      // includes Spam-collection messages and the badge counts them, so a
      // spam-only backlog on another community must still be explained.
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      mockGroupid.value = 522709
      mockGroup.value = { id: 522709, namedisplay: 'Skelmersdale Freegle' }
      mockModGroupStore.get.mockReturnValue({
        id: 522709,
        namedisplay: 'Skelmersdale Freegle',
      })
      mockAuthStore.work = { pending: 0, spam: 2 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('there are 2 waiting')
      expect(wrapper.text()).toContain('Show all my communities')
    })

    it('ignores backup-community work, which all-communities does not show', async () => {
      // pendingother covers posts on backup communities, and the
      // all-communities listing only fans out over active ones by design
      // (user.GetActiveModGroupIDs). Offering "Show all my communities" for
      // work it will not show would send the moderator nowhere.
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      mockGroupid.value = 522709
      mockGroup.value = { id: 522709, namedisplay: 'Skelmersdale Freegle' }
      mockModGroupStore.get.mockReturnValue({
        id: 522709,
        namedisplay: 'Skelmersdale Freegle',
      })
      mockAuthStore.work = { pending: 0, spam: 0, pendingother: 4 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('no messages at the moment')
      expect(wrapper.text()).not.toContain('Show all my communities')
    })

    it('keeps the plain empty notice when nothing is pending anywhere', async () => {
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      mockGroupid.value = 522709
      mockGroup.value = { id: 522709, namedisplay: 'Skelmersdale Freegle' }
      // A component left mounted by an earlier test still watches the shared
      // groupid ref and re-derives group from the store, so the store has to
      // agree about which community this is.
      mockModGroupStore.get.mockReturnValue({
        id: 522709,
        namedisplay: 'Skelmersdale Freegle',
      })
      mockAuthStore.work = { pending: 0 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).toContain('no messages at the moment')
      expect(wrapper.text()).not.toContain('Show all my communities')
    })

    it('keeps the plain empty notice when already showing all communities', async () => {
      mockMessages.value = []
      mockBusy.value = false
      mockModGroupStore.received = true
      mockGroupid.value = 0
      mockAuthStore.work = { pending: 3 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      expect(wrapper.text()).not.toContain('Show all my communities')
    })

    it('shows loading message when groups not yet received', async () => {
      mockModGroupStore.received = false
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.text()).toContain('Please wait')
    })

    it('renders ModMessages when groups received', async () => {
      mockModGroupStore.received = true
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.find('.mod-messages').exists()).toBe(true)
    })
  })

  describe('setup', () => {
    it('sets summarykey to modtoolsMessagesPendingSummary', () => {
      mountComponent()
      expect(mockSummarykey.value).toBe('modtoolsMessagesPendingSummary')
    })

    it('sets collection to Pending', () => {
      mountComponent()
      expect(mockCollection.value).toBe('Pending')
    })

    it('sets workType to pending and pendingother', () => {
      mountComponent()
      expect(mockWorkType.value).toEqual(['pending', 'pendingother'])
    })
  })

  describe('computed properties', () => {
    it('groups returns array from modGroupStore', async () => {
      mockModGroupStore.list = {
        1: { id: 1, nameshort: 'Group1' },
        2: { id: 2, nameshort: 'Group2' },
      }
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      expect(wrapper.vm.groups).toHaveLength(2)
    })

    it('groupsreceived returns modGroupStore.received', () => {
      mockModGroupStore.received = true
      const wrapper = mountComponent()
      expect(wrapper.vm.groupsreceived).toBe(true)
    })
  })

  describe('methods', () => {
    it('loadAll sets limit to 1000 and fetches messages', async () => {
      const wrapper = mountComponent()
      // Mock the scrollIntoView method on the end ref
      const mockScrollIntoView = vi.fn()
      Object.defineProperty(wrapper.vm.$refs, 'end', {
        value: { scrollIntoView: mockScrollIntoView },
        writable: true,
      })

      await wrapper.vm.loadAll()

      expect(mockLimit.value).toBe(1000)
      expect(mockGetMessages).toHaveBeenCalled()
    })

    it('loadMore increments show when more messages exist', async () => {
      mockMessages.value = [{ id: 1 }, { id: 2 }]
      mockShow.value = 1
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      await wrapper.vm.loadMore(mockState)

      expect(mockShow.value).toBe(2)
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('loadMore calls loaded (not complete) when no user', async () => {
      const wrapper = mountComponent()
      // Access through useMe mock - set me to null
      wrapper.vm.me = null
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      await wrapper.vm.loadMore(mockState)

      expect(mockState.loaded).toHaveBeenCalled()
      expect(mockState.complete).not.toHaveBeenCalled()
    })

    it('loadMore fetches more messages when show equals messages length', async () => {
      mockMessages.value = [{ id: 1 }]
      mockShow.value = 1
      mockMessageStore.list = { 1: { id: 1 } }
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      await wrapper.vm.loadMore(mockState)

      expect(mockMessageStore.fetchMessagesMT).toHaveBeenCalled()
    })

    it('keeps scrolling when a page returns real messages that are already cached from another group (rippled/cross-posted posts)', async () => {
      // Regression (Discourse 9954/5): see the sibling test in
      // approved.spec.js - the same broken completion check (comparing
      // Object.keys(messageStore.list) before/after a fetch) lived here too.
      mockMessageStore.list = { 555: { id: 555 } }
      mockMessages.value = [{ id: 555 }]
      mockShow.value = 1
      mockMessageStore.fetchMessagesMT.mockResolvedValue([555])
      mockMessageStore.context = { date: 123, id: 555 }
      const wrapper = mountComponent()
      const mockState = { loaded: vi.fn(), complete: vi.fn() }

      await wrapper.vm.loadMore(mockState)

      expect(mockState.complete).not.toHaveBeenCalled()
      expect(mockState.loaded).toHaveBeenCalled()
    })

    it('clearing the filter also forgets it, so the next visit is not filtered again (Discourse 10037)', async () => {
      mockMessages.value = []
      mockBusy.value = false
      mockGroupid.value = 522709
      mockGroup.value = { id: 522709, namedisplay: 'Skelmersdale Freegle' }
      // A component left mounted by an earlier test still watches the shared
      // groupid ref and re-derives group from the store, so the store has to
      // agree about which community this is.
      mockModGroupStore.get.mockReturnValue({
        id: 522709,
        namedisplay: 'Skelmersdale Freegle',
      })
      mockAuthStore.work = { pending: 3 }

      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()

      await wrapper.vm.showAllCommunities()

      expect(mockGroupid.value).toBe(0)
      // ModGroupSelect only persists a choice made through the dropdown
      // itself, so clearing the filter from here has to forget the
      // remembered value too - otherwise the next mount restores it.
      expect(mockMiscStore.set).toHaveBeenCalledWith({
        key: 'groupselect-pending',
        value: 0,
      })
    })

    it('does not fire its own fetch on a work count change (Discourse 10037)', async () => {
      // The work-count watcher inside useModMessages already refetches, with a
      // limit covering the whole outstanding total, and reveals everything it
      // gets. A second trigger here would just double every refresh - which is
      // the duplicate-request problem this page is being fixed for.
      mockMessages.value = []
      mockBusy.value = false
      const wrapper = mountComponent()
      await wrapper.vm.$nextTick()
      const before = wrapper.vm.bump

      mockAuthStore.work = { pending: 4 }
      await wrapper.vm.$nextTick()

      expect(wrapper.vm.bump).toBe(before)
      expect(mockMessageStore.fetchMessagesMT).not.toHaveBeenCalled()
    })
  })
})
