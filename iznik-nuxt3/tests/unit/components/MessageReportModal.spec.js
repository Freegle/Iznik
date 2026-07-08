import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import MessageReportModal from '~/components/MessageReportModal.vue'

const { mockMessage } = vi.hoisted(() => {
  return {
    mockMessage: {
      id: 1,
      subject: 'OFFER: Test Item',
      type: 'Offer',
      groups: [{ groupid: 100 }],
    },
  }
})

const mockModal = ref(null)

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
}

const mockChatStore = {
  openChatToMods: vi.fn().mockResolvedValue(123),
  send: vi.fn().mockResolvedValue({}),
}

const mockAuthStore = {
  groups: [{ groupid: 100 }, { groupid: 200 }],
}

const mockMe = ref({ systemrole: 'User' })

const mockGroupStore = {
  get: vi.fn((id) => ({ namedisplay: 'Community ' + id })),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/chat', () => ({
  useChatStore: () => mockChatStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    hide: vi.fn(),
  }),
}))

vi.mock('nuxt/app', () => ({
  useRuntimeConfig: () => ({
    public: {
      USER_SITE: 'https://freegle.org',
    },
  }),
}))

describe('MessageReportModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue(mockMessage)
    mockMe.value = { systemrole: 'User' }
    mockGroupStore.get.mockImplementation((id) => ({
      namedisplay: 'Community ' + id,
    }))
    mockChatStore.openChatToMods.mockResolvedValue(123)
    mockChatStore.send.mockResolvedValue({})
  })

  function createWrapper(props = {}) {
    return mount(MessageReportModal, {
      props: {
        id: 1,
        ...props,
      },
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal"><slot /><slot name="footer" /></div>',
            props: ['id', 'scrollable', 'title', 'size'],
            emits: ['hidden'],
            methods: {
              show() {},
              hide() {},
            },
          },
          'b-button': {
            template:
              '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'disabled'],
            emits: ['click'],
          },
          'b-form-radio': {
            template:
              '<label class="b-form-radio"><input type="radio" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)" /><slot /></label>',
            props: ['modelValue', 'name', 'value'],
            emits: ['update:modelValue'],
          },
          'b-form-textarea': {
            template:
              '<textarea class="b-form-textarea" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'rows', 'placeholder'],
            emits: ['update:modelValue'],
          },
          'b-spinner': {
            template: '<span class="b-spinner" />',
            props: ['small'],
          },
          'b-form-checkbox': {
            template:
              '<label class="b-form-checkbox"><input type="checkbox" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
            props: ['modelValue', 'value', 'indeterminate'],
            emits: ['update:modelValue'],
          },
          'b-form-checkbox-group': {
            template: '<div class="b-form-checkbox-group"><slot /></div>',
            props: ['modelValue', 'stacked'],
            emits: ['update:modelValue'],
          },
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders modal', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-modal').exists()).toBe(true)
    })

    it('shows report content initially', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.report-content').exists()).toBe(true)
    })

    it('shows message preview', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.report-item-preview').exists()).toBe(true)
    })

    it('shows message type', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('OFFER')
    })

    it('shows message subject', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Test Item')
    })
  })

  describe('explanation', () => {
    it('shows explanation text', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('If something')
      expect(wrapper.text()).toContain('wrong with this post')
    })

    it('mentions local volunteers', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('local volunteers')
    })
  })

  describe('report reasons', () => {
    it('shows reason label', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('wrong with this post')
    })

    it('shows inappropriate content option', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Inappropriate content')
    })

    it('shows spam option', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Spam or advertising')
    })

    it('shows scam option', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Possible scam')
    })

    it('shows other option', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Other issue')
    })

    it('has four radio buttons', () => {
      const wrapper = createWrapper()
      const radios = wrapper.findAll('.b-form-radio')
      expect(radios.length).toBe(4)
    })
  })

  describe('additional details', () => {
    it('shows additional details label', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Additional details')
    })

    it('shows textarea for details', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-textarea').exists()).toBe(true)
    })

    it('shows placeholder text', () => {
      const wrapper = createWrapper()
      const textarea = wrapper.find('.b-form-textarea')
      expect(textarea.attributes('placeholder')).toContain(
        'additional information'
      )
    })
  })

  describe('footer buttons', () => {
    it('shows cancel button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Cancel')
    })

    it('shows submit button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Submit Report')
    })

    it('submit button is disabled when no reason selected', () => {
      const wrapper = createWrapper()
      const submitBtn = wrapper
        .findAll('.b-button')
        .find((b) => b.text().includes('Submit'))
      expect(submitBtn.attributes('disabled')).toBeDefined()
    })
  })

  describe('wanted type', () => {
    it('shows WANTED for wanted type messages', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Wanted',
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('WANTED')
    })
  })

  describe('multi-group report targeting', () => {
    it('reports to a shared group when message is on multiple groups', () => {
      // Message on groups 100 and 300. User is on groups 100 and 200.
      // Should target group 100 (the shared one).
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [
          { groupid: 300, arrival: '2024-01-01T00:00:00Z' },
          { groupid: 100, arrival: '2024-01-02T00:00:00Z' },
        ],
      })
      const wrapper = createWrapper()
      expect(wrapper.vm.reportGroupId).toBe(100)
    })

    it('falls back to first group when user shares no groups', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [
          { groupid: 500, arrival: '2024-01-01T00:00:00Z' },
          { groupid: 600, arrival: '2024-01-02T00:00:00Z' },
        ],
      })
      const wrapper = createWrapper()
      expect(wrapper.vm.reportGroupId).toBe(500)
    })

    it('prefers the most recently arrived shared group', () => {
      // Message on groups 100 and 200. User is on both. 200 has later arrival.
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [
          { groupid: 100, arrival: '2024-01-01T00:00:00Z' },
          { groupid: 200, arrival: '2024-01-02T00:00:00Z' },
        ],
      })
      const wrapper = createWrapper()
      expect(wrapper.vm.reportGroupId).toBe(200)
    })
  })

  describe('success state', () => {
    it('does not show success state initially', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.report-success').exists()).toBe(false)
    })
  })

  describe('mod multi-community reporting', () => {
    function modWrapperOnGroups(groups, role = 'Moderator') {
      mockMe.value = { systemrole: role }
      mockMessageStore.byId.mockReturnValue({ ...mockMessage, groups })
      return createWrapper()
    }

    it('hides the community selector for non-mods', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [{ groupid: 100 }, { groupid: 300 }],
      })
      const wrapper = createWrapper()
      expect(wrapper.vm.showGroupSelector).toBe(false)
      expect(wrapper.find('.report-groups').exists()).toBe(false)
    })

    it('hides the community selector for a mod on a single-group post', () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }])
      expect(wrapper.vm.showGroupSelector).toBe(false)
      expect(wrapper.find('.report-groups').exists()).toBe(false)
    })

    it('shows the community selector for a mod on a multi-group post', () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      expect(wrapper.vm.showGroupSelector).toBe(true)
      expect(wrapper.find('.report-groups').exists()).toBe(true)
      expect(wrapper.text()).toContain('Community 100')
      expect(wrapper.text()).toContain('Community 300')
    })

    it('shows the selector for Support and Admin too', () => {
      expect(
        modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }], 'Support').vm
          .showGroupSelector
      ).toBe(true)
      expect(
        modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }], 'Admin').vm
          .showGroupSelector
      ).toBe(true)
    })

    it('lists the default group first', () => {
      // User shares group 300; it should be the default and sort first.
      mockMe.value = { systemrole: 'Moderator' }
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [
          { groupid: 100, arrival: '2024-01-01T00:00:00Z' },
          { groupid: 300, arrival: '2024-01-02T00:00:00Z' },
        ],
      })
      mockAuthStore.groups = [{ groupid: 300 }]
      const wrapper = createWrapper()
      expect(wrapper.vm.reportableGroups[0].groupid).toBe(300)
      mockAuthStore.groups = [{ groupid: 100 }, { groupid: 200 }]
    })

    it('de-duplicates repeated group ids', () => {
      const wrapper = modWrapperOnGroups([
        { groupid: 100 },
        { groupid: 100 },
        { groupid: 300 },
      ])
      expect(wrapper.vm.reportableGroups.length).toBe(2)
    })

    it('seeds the selection with the default single group', () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      expect(wrapper.vm.selectedGroupIds).toEqual([100])
    })

    it('all-communities toggle selects and clears every group', () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      wrapper.vm.allSelected = true
      expect(wrapper.vm.selectedGroupIds).toEqual([100, 300])
      wrapper.vm.allSelected = false
      expect(wrapper.vm.selectedGroupIds).toEqual([])
    })

    it('reports to every selected community', async () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      wrapper.vm.selectedReason = 'spam'
      wrapper.vm.selectedGroupIds = [100, 300]
      await wrapper.vm.report()
      expect(mockChatStore.openChatToMods).toHaveBeenCalledTimes(2)
      expect(mockChatStore.openChatToMods).toHaveBeenCalledWith(100)
      expect(mockChatStore.openChatToMods).toHaveBeenCalledWith(300)
      expect(mockChatStore.send).toHaveBeenCalledTimes(2)
      expect(wrapper.vm.submitted).toBe(true)
      expect(wrapper.vm.failedGroups).toEqual([])
    })

    it('reports to a single group for a non-mod', async () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        groups: [{ groupid: 100 }, { groupid: 300 }],
      })
      const wrapper = createWrapper()
      wrapper.vm.selectedReason = 'spam'
      await wrapper.vm.report()
      expect(mockChatStore.openChatToMods).toHaveBeenCalledTimes(1)
      expect(mockChatStore.openChatToMods).toHaveBeenCalledWith(100)
      expect(wrapper.vm.submitted).toBe(true)
    })

    it('continues after one group fails and reports the failure', async () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      mockChatStore.openChatToMods.mockImplementation((groupid) => {
        if (groupid === 300) return Promise.reject(new Error('boom'))
        return Promise.resolve(123)
      })
      wrapper.vm.selectedReason = 'spam'
      wrapper.vm.selectedGroupIds = [100, 300]
      await wrapper.vm.report()
      expect(mockChatStore.openChatToMods).toHaveBeenCalledTimes(2)
      expect(wrapper.vm.submitted).toBe(true)
      expect(wrapper.vm.failedGroups).toEqual(['Community 300'])
      expect(wrapper.vm.submitError).toBe(false)
    })

    it('shows an error and stays on the form when all groups fail', async () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      mockChatStore.openChatToMods.mockRejectedValue(new Error('boom'))
      wrapper.vm.selectedReason = 'spam'
      wrapper.vm.selectedGroupIds = [100, 300]
      await wrapper.vm.report()
      expect(wrapper.vm.submitted).toBe(false)
      expect(wrapper.vm.submitError).toBe(true)
      expect(wrapper.vm.failedGroups).toEqual([
        'Community 100',
        'Community 300',
      ])
    })

    it('blocks submit when a mod deselects every community', () => {
      const wrapper = modWrapperOnGroups([{ groupid: 100 }, { groupid: 300 }])
      wrapper.vm.selectedReason = 'spam'
      wrapper.vm.selectedGroupIds = []
      expect(wrapper.vm.canSubmit).toBe(false)
    })
  })
})
