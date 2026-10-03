import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import NewUserInfo from '~/components/NewUserInfo.vue'

const { mockMe } = vi.hoisted(() => {
  const { computed } = require('vue')
  return {
    mockMe: computed(() => ({
      id: 1,
      displayname: 'Test User',
    })),
  }
})

const mockAuthStore = {
  saveAndGet: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: mockMe,
  }),
}))

describe('NewUserInfo', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(NewUserInfo, {
      props: {
        password: 'abc123',
        ...props,
      },
      global: {
        stubs: {
          'v-icon': {
            template: '<span class="v-icon" :data-icon="icon" />',
            props: ['icon'],
          },
          'b-input-group': {
            template:
              '<div class="b-input-group"><slot /><slot name="append" /></div>',
          },
          'b-form-input': {
            template:
              '<input class="b-form-input" :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
            props: ['modelValue', 'type', 'placeholder'],
            emits: ['update:modelValue'],
          },
          SpinButton: {
            template:
              '<button class="spin-button" @click="handleClick"><slot />{{ label }}</button>',
            props: ['variant', 'iconName', 'label'],
            emits: ['handle'],
            methods: {
              handleClick() {
                this.$emit('handle', () => {})
              },
            },
          },
          SettingsGroup: {
            template: '<div class="settings-group" />',
            props: ['emailfrequency', 'eventshide', 'volunteerhide', 'label'],
            emits: ['update:emailfrequency'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders new user info container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.new-user-info').exists()).toBe(true)
    })

    it('shows welcome header', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Welcome to Freegle!')
    })

    it('shows welcome subtitle', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('first time')
    })

    it('shows hand-sparkles icon', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('[data-icon="hand-sparkles"]').exists()).toBe(true)
    })
  })

  describe('password section', () => {
    it('shows password card header', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Your Account Password')
    })

    it('shows key icon', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('[data-icon="key"]').exists()).toBe(true)
    })

    it('displays the provided password', () => {
      const wrapper = createWrapper({ password: 'secret123' })
      expect(wrapper.text()).toContain('secret123')
    })

    it('shows password intro text', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('emailed a password')
    })

    it('shows set your own option', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('set your own')
    })

    it('renders password input', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.b-form-input[type="password"]').exists()).toBe(true)
    })

    it('renders save button', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.spin-button').exists()).toBe(true)
    })
  })

  describe('password setting', () => {
    it('calls saveAndGet when setting password', async () => {
      const wrapper = createWrapper()
      const input = wrapper.find('.b-form-input[type="password"]')
      await input.setValue('newpassword')

      const saveBtn = wrapper.find('.spin-button')
      await saveBtn.trigger('click')
      await flushPromises()

      expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({
        password: 'newpassword',
      })
    })

    it('does not call saveAndGet when password is empty', async () => {
      const wrapper = createWrapper()
      const saveBtn = wrapper.find('.spin-button')
      await saveBtn.trigger('click')
      await flushPromises()

      expect(mockAuthStore.saveAndGet).not.toHaveBeenCalled()
    })
  })

  describe('email preferences section', () => {
    it('shows email preferences card when me exists', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Email Preferences')
    })

    it('shows envelope icon', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('[data-icon="envelope"]').exists()).toBe(true)
    })

    it('shows email intro text', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('OFFERs and WANTEDs')
    })

    it('renders SettingsGroup component', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.settings-group').exists()).toBe(true)
    })
  })

  describe('email frequency updates', () => {
    it('calls saveAndGet with the national emailfrequency when it changes', async () => {
      const wrapper = createWrapper()
      const settingsGroup = wrapper.findComponent('.settings-group')

      await settingsGroup.vm.$emit('update:emailfrequency', 24)
      await flushPromises()

      expect(mockAuthStore.saveAndGet).toHaveBeenCalledWith({
        emailfrequency: 24,
      })
    })
  })

  describe('styling classes', () => {
    it('has welcome-header class', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.welcome-header').exists()).toBe(true)
    })

    it('has info-card class', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.info-card').exists()).toBe(true)
    })

    it('has card-header class', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.card-header').exists()).toBe(true)
    })

    it('has password-display class', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.password-display').exists()).toBe(true)
    })
  })
})
