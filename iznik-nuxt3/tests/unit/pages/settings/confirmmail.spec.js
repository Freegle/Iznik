import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

import ConfirmMailPage from '~/pages/settings/confirmmail/[key].vue'

// The link in the verification email lands here. The page has to show the outcome
// (Verification Succeeded or Verification Failed) to the logged-in member, and the resend
// box in the failure modal starts with their current address.

const mockPush = vi.fn()
globalThis.__testUseRouter = () => ({ push: mockPush })
globalThis.__testUseRoute = () => ({
  params: { key: 'made-up-key' },
  query: {},
  path: '/settings/confirmmail/made-up-key',
  fullPath: '/settings/confirmmail/made-up-key',
})
globalThis.useHead = () => {}

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: ref({ id: 1, email: 'freegler@example.com' }),
  }),
}))

vi.mock('~/components/ExternalLink', () => ({
  default: { template: '<a><slot /></a>', props: ['href'] },
}))

function mountPage() {
  return mount(ConfirmMailPage, {
    global: {
      stubs: {
        'client-only': { template: '<div><slot /></div>' },
        'b-row': { template: '<div><slot /></div>' },
        'b-col': { template: '<div><slot /></div>' },
        'b-modal': {
          template:
            '<div v-if="modelValue" class="modal"><h5 class="modal-title">{{ title }}</h5><slot /><slot name="footer" /></div>',
          props: ['modelValue', 'title'],
        },
        'b-form-input': {
          template:
            '<input class="resend-email" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'placeholder'],
        },
      },
    },
  })
}

describe('pages/settings/confirmmail/[key].vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    globalThis.__mockAuthStore = {
      user: { id: 1, email: 'freegler@example.com' },
      saveAndGet: vi.fn().mockResolvedValue({}),
      saveEmail: vi.fn().mockResolvedValue({ ret: 0 }),
    }
  })

  it('confirms the key and shows the Verification Succeeded modal', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(globalThis.__mockAuthStore.saveAndGet).toHaveBeenCalledWith({
      key: 'made-up-key',
    })
    expect(wrapper.text()).toContain('Verification Succeeded')
    expect(wrapper.text()).not.toContain('Verification Failed')
  })

  it('shows the Verification Failed modal with the resend box prefilled when the key is refused', async () => {
    globalThis.__mockAuthStore.saveAndGet = vi
      .fn()
      .mockRejectedValue(new Error('bad key'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Verification Failed')
    expect(wrapper.text()).not.toContain('Verification Succeeded')
    expect(wrapper.find('.resend-email').element.value).toBe(
      'freegler@example.com'
    )
  })
})
