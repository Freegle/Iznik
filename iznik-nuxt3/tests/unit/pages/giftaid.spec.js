import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive, ref } from 'vue'

import GiftAidPage from '~/pages/giftaid.vue'

// A saved address can fill in the home address with one tap. The saved addresses
// come back from the API as PAF fields, so the page builds the text itself.

vi.mock('#imports', async () => {
  const actual = await vi.importActual('vue')
  return {
    ...actual,
    definePageMeta: () => {},
    useHead: () => {},
    useRuntimeConfig: () => ({ public: {} }),
    useRoute: () => ({ query: {}, path: '/giftaid', fullPath: '/giftaid' }),
  }
})

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: ref({ id: 1, marketingconsent: false }) }),
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => reactive({ auth: { jwt: 'x', persistent: null } }),
}))

const mockGiftAidStore = reactive({ giftaid: {}, fetch: vi.fn() })
vi.mock('~/stores/giftaid', () => ({
  useGiftAidStore: () => mockGiftAidStore,
}))

const mockAddressStore = reactive({ list: [], fetch: vi.fn() })
vi.mock('~/stores/address', () => ({
  useAddressStore: () => mockAddressStore,
}))

const stub = { template: '<div><slot /></div>' }

describe('gift aid page', () => {
  it('offers a saved address, house number included, and fills it in', async () => {
    mockAddressStore.list = [
      {
        id: 7,
        postcode: 'OX3 7AA',
        posttown: 'OXFORD',
        buildingnumber: '66',
        thoroughfaredescriptor: 'Old Road',
      },
    ]

    const wrapper = mount(GiftAidPage, {
      global: {
        stubs: {
          'client-only': stub,
          'b-button': {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
          'b-form-textarea': stub,
          'b-form-input': stub,
          'b-form-radio-group': stub,
          'b-form-radio': stub,
          'b-form-checkbox': stub,
          'b-form-group': stub,
          OurToggle: stub,
          SpinButton: stub,
          NoticeMessage: stub,
          'v-icon': stub,
          NuxtLink: stub,
        },
      },
    })
    await flushPromises()

    const button = wrapper
      .findAll('button')
      .find((b) => b.text().startsWith('Use'))
    expect(button).toBeTruthy()
    expect(button.text()).toContain('66 Old Road')

    await button.trigger('click')
    expect(mockGiftAidStore.giftaid.homeaddress).toContain('66 Old Road')
    expect(mockGiftAidStore.giftaid.homeaddress).toContain('OX3 7AA')
  })
})
