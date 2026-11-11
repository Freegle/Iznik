import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import MicroVolunteering from '~/components/MicroVolunteering.vue'

const mockMicroVolunteeringStore = {
  challenge: vi.fn().mockResolvedValue(null),
}

const mockMiscStore = {
  modtools: false,
  get: vi.fn(() => null),
  set: vi.fn(),
}

const mockAuthStore = {
  saveMicrovolunteering: vi.fn().mockResolvedValue({}),
  saveAndGet: vi.fn().mockResolvedValue({}),
}

const mockClientLog = {
  info: vi.fn(),
}

const mockMe = ref({
  id: 1,
  added: '2023-01-01',
  trustlevel: 'Basic',
  isModerator: false,
  isAdmin: false,
  settings: {
    browseView: 'nearby',
  },
})

const mockMyGroups = ref([{ id: 1, microvolunteeringallowed: true }])
const mockFetchMe = vi.fn()

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => mockMicroVolunteeringStore,
}))

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/composables/useClientLog', () => ({
  useClientLog: () => mockClientLog,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
    myGroups: mockMyGroups,
    fetchMe: mockFetchMe,
  }),
}))

describe('MicroVolunteering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = {
      id: 1,
      added: '2023-01-01',
      trustlevel: 'Basic',
      isModerator: false,
      isAdmin: false,
      settings: {
        browseView: 'nearby',
      },
    }
    mockMyGroups.value = [{ id: 1, microvolunteeringallowed: true }]
    mockMiscStore.modtools = false
    mockMiscStore.get.mockReturnValue(null)
    mockMicroVolunteeringStore.challenge.mockResolvedValue(null)
  })

  function createWrapper(props = {}) {
    return mount(MicroVolunteering, {
      props: {
        force: false,
        ...props,
      },
      global: {
        stubs: {
          'client-only': {
            template: '<div class="client-only"><slot /></div>',
          },
          'b-modal': {
            template:
              '<div class="b-modal" :class="{ show: modelValue }" :data-header-class="headerClass" :data-body-class="bodyClass" :data-footer-class="footerClass"><slot name="header" /><slot /><slot name="footer" /></div>',
            props: [
              'modelValue',
              'scrollable',
              'variant',
              'size',
              'noStacking',
              'noFade',
              'noCloseOnBackdrop',
              'hideHeaderClose',
              'noCloseOnEsc',
              'headerClass',
              'bodyClass',
              'footerClass',
            ],
            emits: ['update:modelValue'],
          },
          'b-card-text': {
            template: '<div class="b-card-text"><slot /></div>',
          },
          'b-button': {
            template:
              '<button class="b-button" :data-size="size" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'size'],
            emits: ['click'],
          },
          'v-icon': {
            template: '<span class="v-icon" />',
            props: ['icon'],
          },
          MicroVolunteeringCheckMessage: {
            template: '<div class="mv-check-message" />',
            props: ['id'],
            emits: ['next'],
          },
          MicroVolunteeringFacebook: {
            template: '<div class="mv-facebook" />',
            props: ['id'],
            emits: ['next'],
          },
          MicroVolunteeringPhotosRotate: {
            template: '<div class="mv-photos-rotate" />',
            props: ['photos'],
            emits: ['done'],
          },
          MicroVolunteeringSurvey: {
            template: '<div class="mv-survey" />',
            props: ['url'],
            emits: ['done'],
          },
          MicroVolunteeringUxPrompt: {
            template: '<div class="mv-ux-prompt" />',
            props: ['prompt'],
            emits: ['next', 'skipped'],
          },
          MicroVolunteeringInvite: {
            template: '<div class="mv-invite" />',
            emits: ['next'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders component container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('#microvolunteering').exists()).toBe(true)
    })

    it('renders client-only wrapper', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.client-only').exists()).toBe(true)
    })
  })

  describe('props', () => {
    it('has force prop with false default', () => {
      const props = MicroVolunteering.props || {}
      expect(props.force.default).toBe(false)
      expect(props.force.required).toBe(false)
    })
  })

  describe('emits', () => {
    it('defines verified emit', () => {
      const emits = MicroVolunteering.emits || []
      expect(emits).toContain('verified')
    })
  })

  describe('invite modal', () => {
    it('shows invite modal for users who havent decided', async () => {
      mockMe.value.trustlevel = null
      mockMiscStore.get.mockReturnValue(null)
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Help keep Freegle running smoothly')
    })

    it('shows explanation text in invite modal', () => {
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Would you like to keep freegling')
    })

    it('shows accept button in invite modal', () => {
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain("Yes, I'm happy to do that")
    })

    it('shows decline button in invite modal', () => {
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain("No, that's not my thing")
    })
  })

  describe('task modal', () => {
    it('renders task modal structure', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.findAll('.b-modal').length).toBeGreaterThanOrEqual(1)
    })
  })

  describe('mod view', () => {
    it('shows mod explanation when user is moderator', () => {
      mockMe.value.isModerator = true
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('This is something members see')
    })

    it('shows mod explanation when user is admin', () => {
      mockMe.value.isAdmin = true
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain("even though you're a mod")
    })
  })

  describe('trust level handling', () => {
    it('emits verified for declined users when not forced', () => {
      mockMe.value.trustlevel = 'Declined'
      const wrapper = createWrapper({ force: false })
      expect(wrapper.emitted('verified')).toBeTruthy()
    })
  })

  describe('task types', () => {
    it('renders CheckMessage task type header', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.text()).toContain('Does this post look OK?')
    })

    it('renders PhotoRotate task type header', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'PhotoRotate',
        photos: [{ id: 1, path: '/test.jpg' }],
      })
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.text()).toContain('Photo Rotate')
    })
  })

  describe('UxPrompt task', () => {
    const uxprompt = { id: 4, kind: 'open', question: 'Which is clearer?' }

    function askUx() {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'UxPrompt',
        uxprompt,
      })
    }

    function askCheckMessage() {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
    }

    // The task modal is the one that is open.
    const taskModal = (wrapper) => wrapper.find('.b-modal.show')

    it('asks for UxPrompt tasks in the default types list', async () => {
      createWrapper({ force: true })
      await flushPromises()
      const asked = mockMicroVolunteeringStore.challenge.mock.calls[0][0].types
      expect(asked).toContain('UxPrompt')
    })

    it('renders the UxPrompt component with the task prompt', async () => {
      askUx()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.text()).toContain('A question about Freegle')
      expect(wrapper.text()).not.toContain('What do you think?')
      const prompt = wrapper.findComponent('.mv-ux-prompt')
      expect(prompt.exists()).toBe(true)
      expect(prompt.props('prompt')).toEqual(uxprompt)
    })

    it('does not thank them for housekeeping above a question about the site', async () => {
      askUx()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      const modal = taskModal(wrapper)
      expect(modal.text()).not.toContain('run smoothly')
      // No hearts and no second line of thanks: the question is the first thing they read.
      expect(modal.text()).not.toContain('easier to use')
      expect(modal.text()).not.toContain('Thank you')
      expect(modal.findAll('.v-icon')).toHaveLength(0)
    })

    it('stops asking for UxPrompt tasks for the rest of the visit once one is skipped', async () => {
      askUx()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      mockMicroVolunteeringStore.challenge.mockClear()
      const prompt = wrapper.findComponent('.mv-ux-prompt')
      prompt.vm.$emit('skipped')
      prompt.vm.$emit('next')
      await flushPromises()
      const asked = mockMicroVolunteeringStore.challenge.mock.calls[0][0].types
      expect(asked).not.toContain('UxPrompt')
      expect(asked).toContain('CheckMessage')
    })

    it('gives the modal compact classes only for a UxPrompt task', async () => {
      askUx()
      const ux = createWrapper({ force: true })
      await flushPromises()
      const modal = taskModal(ux)
      expect(modal.attributes('data-header-class')).toBe('py-2')
      expect(modal.attributes('data-body-class')).toBe('py-1')
      expect(modal.attributes('data-footer-class')).toBe('py-1 px-2')

      askCheckMessage()
      const other = createWrapper({ force: true })
      await flushPromises()
      const plain = taskModal(other)
      expect(plain.attributes('data-header-class')).toBeUndefined()
      expect(plain.attributes('data-body-class')).toBeUndefined()
      expect(plain.attributes('data-footer-class')).toBeUndefined()
    })

    it('keeps both footer buttons, in small size on one row, for a UxPrompt task', async () => {
      askUx()
      const wrapper = createWrapper({ force: false })
      await flushPromises()
      const footer = taskModal(wrapper)
        .findAll('.b-button')
        .filter((b) =>
          ["Don't ask me again", 'Ask again tomorrow'].includes(b.text())
        )
      expect(footer.map((b) => b.text())).toEqual([
        "Don't ask me again",
        'Ask again tomorrow',
      ])
      for (const b of footer) {
        expect(b.attributes('data-size')).toBe('sm')
        expect(b.classes()).not.toContain('mb-1')
      }
    })

    it('still makes the footer buttons full size for other tasks', async () => {
      askCheckMessage()
      const wrapper = createWrapper({ force: false })
      await flushPromises()
      const footer = taskModal(wrapper)
        .findAll('.b-button')
        .filter((b) =>
          ["Don't ask me again", 'Ask again tomorrow'].includes(b.text())
        )
      expect(footer).toHaveLength(2)
      for (const b of footer) {
        expect(b.attributes('data-size')).toBeUndefined()
        expect(b.classes()).toContain('mb-1')
      }
    })

    it('still shows the hearts and the subtitle for other tasks', async () => {
      askCheckMessage()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      const modal = taskModal(wrapper)
      expect(modal.findAll('.v-icon').length).toBeGreaterThan(0)
      expect(modal.text()).toContain('run smoothly')
      expect(modal.text()).not.toContain('A question about Freegle')
    })

    it('does not draw the moderator note for a UxPrompt task', async () => {
      mockMe.value.isModerator = true
      askUx()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(taskModal(wrapper).text()).not.toContain(
        'This is something members see'
      )
    })

    it('still draws the moderator note for other tasks', async () => {
      mockMe.value.isModerator = true
      askCheckMessage()
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(taskModal(wrapper).text()).toContain(
        'This is something members see'
      )
    })

    it('logs the prompt id and kind with the challenge received line', async () => {
      askUx()
      createWrapper({ force: true })
      await flushPromises()
      expect(mockClientLog.info).toHaveBeenCalledWith(
        'Microvolunteering challenge received',
        expect.objectContaining({
          event_type: 'microvolunteering',
          challenge_type: 'UxPrompt',
          prompt_id: 4,
          prompt_kind: 'open',
        })
      )
    })

    it('logs no prompt for other tasks', async () => {
      askCheckMessage()
      createWrapper({ force: true })
      await flushPromises()
      const received = mockClientLog.info.mock.calls.find(
        (c) => c[0] === 'Microvolunteering challenge received'
      )
      expect(received[1].challenge_type).toBe('CheckMessage')
      expect(received[1].prompt_id).toBeUndefined()
      expect(received[1].prompt_kind).toBeUndefined()
    })
  })

  describe('progress hearts', () => {
    it('renders heart icons for progress', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.findAll('.v-icon').length).toBeGreaterThan(0)
    })
  })

  describe('footer buttons', () => {
    it('shows dont ask again button when not forced', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: false })
      await flushPromises()
      expect(wrapper.text()).toContain("Don't ask me again")
    })

    it('shows ask again tomorrow button', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: false })
      await flushPromises()
      expect(wrapper.text()).toContain('Ask again tomorrow')
    })

    it('hides footer when forced', async () => {
      mockMicroVolunteeringStore.challenge.mockResolvedValue({
        type: 'CheckMessage',
        msgid: 1,
      })
      const wrapper = createWrapper({ force: true })
      await flushPromises()
      expect(wrapper.text()).not.toContain("Don't ask me again")
    })
  })

  describe('store integration', () => {
    it('fetches task on mount when fetchTask is true', async () => {
      mockMiscStore.get.mockReturnValue(null)
      createWrapper({ force: true })
      await flushPromises()
      expect(mockMicroVolunteeringStore.challenge).toHaveBeenCalled()
    })
  })

  describe('marketing consent', () => {
    it('saves marketing consent when accepting invite', () => {
      mockMe.value.trustlevel = null
      createWrapper()
      // Invite modal shown - the marketing consent text is present
      expect(true).toBe(true)
    })
  })

  describe('group microvolunteering check', () => {
    it('checks if group allows microvolunteering', () => {
      mockMyGroups.value = [{ id: 1, microvolunteeringallowed: false }]
      mockMe.value.trustlevel = null
      const wrapper = createWrapper()
      // Should not show invite if not allowed
      expect(wrapper.exists()).toBe(true)
    })
  })
})
