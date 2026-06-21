import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import QrPage from '~/pages/qr.vue'
import { createFreegleQr } from '~/composables/useFreegleQr'

// useMe drives the access gate: `mod` is true for Moderator/Support/Admin.
const mockMe = ref(null)
const mockMod = ref(false)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe, mod: mockMod }),
}))

// The QR generator touches the DOM; stub it out. The factory must be
// self-contained (vi.mock is hoisted above any top-level consts).
vi.mock('~/composables/useFreegleQr', () => ({
  createFreegleQr: vi.fn(() =>
    Promise.resolve({ append: vi.fn(), update: vi.fn(), download: vi.fn() })
  ),
}))

// ~/stores/auth is pre-aliased to tests/unit/mocks/auth-store.js; override via
// globalThis.__mockAuthStore.

function mountPage() {
  return mount(QrPage, {
    global: {
      stubs: {
        'b-container': { template: '<div><slot /></div>' },
        'b-form-group': {
          template: '<div><slot /></div>',
          props: ['label', 'labelFor'],
        },
        'b-form-input': {
          template:
            '<input :id="id" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['id', 'modelValue', 'type', 'placeholder', 'autocomplete'],
        },
        'b-button': {
          template:
            '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
          props: ['variant', 'size', 'disabled'],
        },
        'b-alert': {
          template: '<div class="alert"><slot /></div>',
          props: ['modelValue', 'variant'],
        },
        'v-icon': { template: '<i />', props: ['icon'] },
      },
    },
  })
}

describe('qr page access gate', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMe.value = null
    mockMod.value = false
    globalThis.__mockAuthStore = { forceLogin: false }
  })

  it('shows the generator to a mod (or above)', async () => {
    mockMe.value = { id: 1, systemrole: 'Moderator' }
    mockMod.value = true

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('#qr-url').exists()).toBe(true)
    expect(wrapper.text()).toContain('Download PNG')
    expect(wrapper.text()).not.toContain('Log in to continue')
  })

  it('blocks a logged-in non-mod with a volunteers-only message', () => {
    mockMe.value = { id: 2, systemrole: 'User' }
    mockMod.value = false

    const wrapper = mountPage()

    expect(wrapper.find('#qr-url').exists()).toBe(false)
    expect(wrapper.text()).toContain('only available to Freegle volunteers')
    expect(wrapper.text()).not.toContain('Download PNG')
  })

  it('prompts a logged-out visitor to log in, and triggers forceLogin', async () => {
    mockMe.value = null
    mockMod.value = false

    const wrapper = mountPage()

    expect(wrapper.find('#qr-url').exists()).toBe(false)
    expect(wrapper.text()).toContain('Log in to continue')

    await wrapper.find('button').trigger('click')
    expect(globalThis.__mockAuthStore.forceLogin).toBe(true)
  })

  it('does not build a QR code for a non-mod', () => {
    mockMe.value = { id: 3, systemrole: 'User' }
    mockMod.value = false

    mountPage()

    // createFreegleQr is the (mocked) generator; it must not run for non-mods.
    expect(createFreegleQr).not.toHaveBeenCalled()
  })
})
