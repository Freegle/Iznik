import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModCommunityEvent from '~/modtools/components/ModCommunityEvent.vue'

const defaultEvent = {
  id: 123,
  pending: true,
  userid: 789,
}

const mockCommunityEventStore = {
  byId: vi.fn((id) => (id === 123 ? defaultEvent : null)),
  delete: vi.fn(),
  save: vi.fn(),
}

// Mock the stores
vi.mock('~/stores/communityevent', () => ({
  useCommunityEventStore: () => mockCommunityEventStore,
}))

const mockUserStore = {
  fetch: vi.fn(),
  byId: vi.fn(),
}

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

describe('ModCommunityEvent', () => {
  const mockUser = {
    id: 789,
    displayname: 'Test User',
    postingstatus: 'DEFAULT',
  }

  function mountComponent(props = {}, eventOverrides = {}) {
    const event = { ...defaultEvent, ...eventOverrides }
    const eventId = props.eventid || event.id
    mockCommunityEventStore.byId.mockReturnValue(
      Object.keys(eventOverrides).length > 0 ? event : defaultEvent
    )

    return mount(ModCommunityEvent, {
      props: { eventid: eventId, ...props },
      global: {
        plugins: [createPinia()],
        stubs: {
          'b-card': {
            template: '<div class="card"><slot /></div>',
          },
          'b-card-header': {
            template: '<div class="card-header"><slot /></div>',
          },
          'b-card-body': {
            template: '<div class="card-body"><slot /></div>',
          },
          'b-card-footer': {
            template: '<div class="card-footer"><slot /></div>',
          },
          'b-row': {
            template: '<div class="row"><slot /></div>',
          },
          'b-col': {
            template: '<div class="col"><slot /></div>',
            props: ['cols', 'md'],
          },
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          'v-icon': {
            template: '<span class="icon" />',
            props: ['icon', 'scale'],
          },
          NoticeMessage: {
            template: '<div class="notice"><slot /></div>',
            props: ['variant'],
          },
          CommunityEvent: {
            template: '<div class="community-event" />',
            props: ['id', 'summary'],
          },
          ChatButton: {
            template: '<button class="chat-button" />',
            props: ['userid', 'title', 'variant'],
          },
          CommunityEventModal: {
            template: '<div class="event-modal" />',
            props: ['id', 'startEdit', 'ismod'],
            methods: {
              show: vi.fn(),
            },
          },
          ConfirmModal: {
            template: '<div class="confirm-modal" />',
          },
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockUserStore.byId.mockReturnValue(mockUser)
    mockUserStore.fetch.mockResolvedValue(mockUser)
    mockCommunityEventStore.byId.mockReturnValue(defaultEvent)
  })

  describe('rendering', () => {
    it('renders when event is pending', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.card').exists()).toBe(true)
    })

    it('does not render when event is not pending', () => {
      const wrapper = mountComponent({}, { pending: false })
      expect(wrapper.find('.card').exists()).toBe(false)
    })

    it('renders event id', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('123')
    })

    it('renders user displayname from user store', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Test User')
    })

    it('fetches user on mount', () => {
      mountComponent()
      expect(mockUserStore.fetch).toHaveBeenCalledWith(789)
    })

    it('renders "Added by the system" when no userid', () => {
      const wrapper = mountComponent({}, { userid: 0 })
      expect(wrapper.text()).toContain('Added by the system')
    })

    it('renders Approve button', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Approve')
    })

    it('renders Edit button', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Edit')
    })

    it('renders Delete button', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Delete')
    })

    it('renders ChatButton when userid exists', () => {
      const wrapper = mountComponent()
      expect(wrapper.find('.chat-button').exists()).toBe(true)
    })

    it('does not render ChatButton when no userid', () => {
      const wrapper = mountComponent({}, { userid: 0 })
      expect(wrapper.find('.chat-button').exists()).toBe(false)
    })

    it('renders prohibited posting notice when appropriate', () => {
      mockUserStore.byId.mockReturnValue({
        ...mockUser,
        postingstatus: 'PROHIBITED',
      })
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('not to be able to post')
    })
  })

  describe('methods', () => {
    it('edit sets showModal to true', () => {
      const wrapper = mountComponent()
      expect(wrapper.vm.showModal).toBe(false)
      wrapper.vm.edit()
      expect(wrapper.vm.showModal).toBe(true)
    })
  })
})
