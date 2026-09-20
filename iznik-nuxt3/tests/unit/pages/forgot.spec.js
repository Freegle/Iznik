import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ForgotPage from '~/pages/forgot.vue'

const mockPush = vi.fn()
globalThis.__testUseRouter = () => ({ push: mockPush })
globalThis.useHead = () => {}

const EmailValidatorStub = {
  template: '<input class="email-input" @input="onInput" />',
  props: ['email', 'valid'],
  emits: ['update:email', 'update:valid'],
  methods: {
    onInput() {
      this.$emit('update:email', 'freegler@example.com')
      this.$emit('update:valid', true)
    },
  },
}

const SpinButtonStub = {
  template: '<button class="spin-button" @click="onClick"><slot /></button>',
  props: ['iconName', 'variant', 'size', 'label'],
  emits: ['handle'],
  methods: {
    onClick() {
      this.$emit('handle', () => {})
    },
  },
}

function mountPage() {
  return mount(ForgotPage, {
    global: {
      stubs: {
        EmailValidator: EmailValidatorStub,
        SpinButton: SpinButtonStub,
        ExternalLink: { template: '<a><slot /></a>', props: ['href'] },
        'b-alert': {
          template: '<div v-if="modelValue" class="alert"><slot /></div>',
          props: ['modelValue', 'variant'],
        },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('pages/forgot.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    globalThis.__mockAuthStore = {
      user: null,
      lostPassword: vi.fn().mockResolvedValue({ worked: true, unknown: false }),
    }
  })

  it('redirects home immediately if already logged in', () => {
    globalThis.__mockAuthStore.user = { id: 1 }

    mountPage()

    expect(mockPush).toHaveBeenCalledWith('/')
  })

  it('does not redirect a logged-out visitor', () => {
    mountPage()

    expect(mockPush).not.toHaveBeenCalled()
  })

  it('shows the "worked" alert once the email link is sent', async () => {
    const wrapper = mountPage()

    await wrapper.find('.email-input').trigger('input')
    await wrapper.find('.spin-button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(globalThis.__mockAuthStore.lostPassword).toHaveBeenCalledWith(
      'freegler@example.com'
    )
    expect(wrapper.text()).toContain("We've sent you a link to log in")
  })

  it('shows the "unknown" alert when the address is not recognised', async () => {
    globalThis.__mockAuthStore.lostPassword = vi
      .fn()
      .mockResolvedValue({ worked: false, unknown: true })

    const wrapper = mountPage()

    await wrapper.find('.email-input').trigger('input')
    await wrapper.find('.spin-button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain("We don't know that email address")
  })

  it('shows the generic error alert on any other failure', async () => {
    globalThis.__mockAuthStore.lostPassword = vi
      .fn()
      .mockResolvedValue({ worked: false, unknown: false })

    const wrapper = mountPage()

    await wrapper.find('.email-input').trigger('input')
    await wrapper.find('.spin-button').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Something went wrong')
  })
})
