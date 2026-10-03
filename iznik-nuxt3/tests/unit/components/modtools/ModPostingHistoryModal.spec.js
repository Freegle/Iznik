import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModPostingHistoryModal from '~/modtools/components/ModPostingHistoryModal.vue'

// Mock stores
let mockUserData = {}

const mockUserStore = {
  byId: (id) => mockUserData[id] || null,
  fetchMT: vi.fn().mockResolvedValue(null),
  fetch: vi.fn().mockResolvedValue(null),
}

const mockHide = vi.fn()
const mockModalShow = vi.fn()
const mockModalRef = { show: mockModalShow }

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: ref(mockModalRef),
    show: mockModalShow,
    hide: mockHide,
  }),
}))

describe('ModPostingHistoryModal', () => {
  const createUser = (overrides = {}) => ({
    id: 1,
    displayname: 'Test User',
    messagehistory: [
      {
        id: 101,
        subject: 'Offer: Sofa',
        type: 'Offer',
        arrival: '2024-01-15T10:00:00Z',
        outcome: 'Taken',
        repost: false,
        autorepost: false,
        collection: 'Approved',
      },
      {
        id: 102,
        subject: 'Wanted: Table',
        type: 'Wanted',
        arrival: '2024-01-10T10:00:00Z',
        outcome: null,
        repost: true,
        autorepost: true,
        collection: 'Approved',
      },
      {
        id: 103,
        subject: 'Offer: Chair',
        type: 'Offer',
        arrival: '2024-01-20T10:00:00Z',
        outcome: null,
        repost: true,
        autorepost: false,
        collection: 'Pending',
      },
    ],
    ...overrides,
  })

  function populateUserStore(user) {
    mockUserData[user.id] = user
  }

  function mountComponent(props = {}) {
    // If a user object is passed via old-style props, convert to userid and populate store
    const { user: userProp, ...otherProps } = props
    const user = userProp || createUser()
    populateUserStore(user)

    return mount(ModPostingHistoryModal, {
      props: {
        userid: user.id,
        ...otherProps,
      },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal" :title="title"><slot name="default" /><slot name="footer" /></div>',
            props: ['title'],
          },
          'b-row': {
            template: '<div class="row"><slot /></div>',
          },
          'b-col': {
            template: '<div class="col" :class="cols"><slot /></div>',
            props: ['cols', 'sm'],
          },
          'b-button': {
            template:
              '<button :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'v-icon': {
            template: '<i :class="icon" :title="title" />',
            props: ['icon', 'scale', 'title'],
          },
          NoticeMessage: {
            template: '<div class="notice" :class="variant"><slot /></div>',
            props: ['variant'],
          },
        },
        mocks: {
          datetimeshort: (val) => `formatted:${val}`,
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockUserData = {}
  })

  describe('rendering', () => {
    it('shows user displayname in title', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.modal').attributes('title')).toContain('Test User')
    })

    it('shows message rows', () => {
      const wrapper = mountComponent()
      const rows = wrapper.findAll('.row')
      expect(rows.length).toBe(3) // 3 messages
    })
  })

  describe('messages computed', () => {
    it('returns all messages when no type filter', () => {
      const wrapper = mountComponent({ type: null })
      expect(wrapper.vm.messages.length).toBe(3)
    })

    it('filters by type when type prop provided', () => {
      const wrapper = mountComponent({ type: 'Offer' })
      expect(wrapper.vm.messages.length).toBe(2)
      expect(wrapper.vm.messages.every((m) => m.type === 'Offer')).toBe(true)
    })

    it('sorts messages by arrival date descending', () => {
      const wrapper = mountComponent()
      const messages = wrapper.vm.messages
      expect(messages[0].id).toBe(103) // Jan 20
      expect(messages[1].id).toBe(101) // Jan 15
      expect(messages[2].id).toBe(102) // Jan 10
    })

    it('returns empty array when no messagehistory', () => {
      const wrapper = mountComponent({
        user: { id: 1, displayname: 'Test', messagehistory: null },
      })
      expect(wrapper.vm.messages).toEqual([])
    })

    it('returns empty array when user has no messagehistory property', () => {
      const wrapper = mountComponent({
        user: { id: 2, displayname: 'Test' },
      })
      expect(wrapper.vm.messages).toEqual([])
    })
  })

  describe('message display', () => {
    it('shows message id with hashtag', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('101')
      expect(wrapper.text()).toContain('102')
    })

    it('shows message subject', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Offer: Sofa')
      expect(wrapper.text()).toContain('Wanted: Table')
    })

    it('shows outcome when available', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Taken')
    })

    it('shows still open when no outcome', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Still open')
    })

    it('shows Pending status in red', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Pending')
    })

    it('shows auto-repost icon', () => {
      const wrapper = mountComponent()
      // Message 102 has autorepost: true
      const syncIcons = wrapper.findAll('i.sync')
      expect(syncIcons.length).toBeGreaterThan(0)
    })

    it('shows manual repost icon', () => {
      const wrapper = mountComponent()
      // Message 103 has repost: true, autorepost: false
      const handIcons = wrapper.findAll('i.hand-paper')
      expect(handIcons.length).toBeGreaterThan(0)
    })
  })

  describe('no messages state', () => {
    it('shows notice when no posts to show', () => {
      const wrapper = mountComponent({
        user: { id: 3, displayname: 'Test', messagehistory: [] },
      })
      expect(wrapper.text()).toContain('no posts to show')
    })
  })

  describe('show method', () => {
    it('is exposed for external access', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.show).toBeDefined()
      expect(typeof wrapper.vm.show).toBe('function')
    })
  })

  describe('close button', () => {
    it('calls hide on click', async () => {
      const wrapper = mountComponent()
      const closeButton = wrapper.find('button')
      await closeButton.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })
})
