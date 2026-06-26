import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

const mockUnsubscribe = vi.fn()
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ unsubscribe: mockUnsubscribe }),
}))

import ContactSupportModal from '~/components/ContactSupportModal.vue'

function createWrapper(props = {}) {
  return mount(ContactSupportModal, {
    props,
    global: {
      stubs: {
        'b-modal': {
          template: '<div class="b-modal"><slot /><slot name="footer" /></div>',
        },
        EmailValidator: {
          template:
            '<input class="email-input" :value="email" @input="$emit(\'update:email\', $event.target.value); $emit(\'update:valid\', true)" />',
          props: ['email', 'valid', 'label'],
          emits: ['update:email', 'update:valid'],
        },
        SpinButton: {
          template:
            '<button class="spin-btn" @click="$emit(\'handle\', () => {})">{{ label }}</button>',
          props: ['label', 'variant', 'iconName', 'size'],
          emits: ['handle'],
        },
        NoticeMessage: {
          template: '<div class="notice"><slot /></div>',
          props: ['variant'],
        },
        'v-icon': { template: '<span />', props: ['icon'] },
      },
    },
  })
}

describe('ContactSupportModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('initial state (Step A)', () => {
    it('shows the find-your-account prompt', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain("Let's try to find your account")
      expect(wrapper.text()).toContain('Enter your email')
    })

    it('renders an email input', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.email-input').exists()).toBe(true)
    })

    it('does not show the mailto fallback before submit', () => {
      const wrapper = createWrapper()
      expect(wrapper.html()).not.toContain('mailto:')
    })
  })

  describe('submit with known email', () => {
    it('shows confirmation message when API returns worked:true', async () => {
      mockUnsubscribe.mockResolvedValue({ worked: true, unknown: false })
      const wrapper = createWrapper()
      await wrapper.find('.email-input').setValue('found@example.com')
      await wrapper.find('.spin-btn').trigger('click')
      await flushPromises()

      expect(mockUnsubscribe).toHaveBeenCalledWith('found@example.com')
      expect(wrapper.text()).toMatch(/sent you (an|a confirmation) email/i)
      expect(wrapper.html()).not.toContain('mailto:')
    })
  })

  describe('submit with unknown email (Step B)', () => {
    it('shows mailto fallback when API returns unknown:true', async () => {
      mockUnsubscribe.mockResolvedValue({ worked: false, unknown: true })
      const wrapper = createWrapper()
      await wrapper.find('.email-input').setValue('nobody@example.com')
      await wrapper.find('.spin-btn').trigger('click')
      await flushPromises()

      const html = wrapper.html()
      expect(html).toContain('mailto:support@ilovefreegle.org')
      expect(html).toContain('Contact us to delete your account')
      // No hidden-email talk
      expect(html).not.toMatch(/hide my email|private relay|hidden/i)
    })
  })

  describe('submit with API error', () => {
    it('falls back to mailto when worked:false unknown:false', async () => {
      mockUnsubscribe.mockResolvedValue({ worked: false, unknown: false })
      const wrapper = createWrapper()
      await wrapper.find('.email-input').setValue('something@example.com')
      await wrapper.find('.spin-btn').trigger('click')
      await flushPromises()

      expect(wrapper.html()).toContain('mailto:support@ilovefreegle.org')
    })
  })
})
