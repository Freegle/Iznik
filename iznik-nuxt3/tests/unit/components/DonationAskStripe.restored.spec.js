import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DonationAskStripe from '~/components/DonationAskStripe.vue'

const mockApi = {
  bandit: {
    choose: vi.fn().mockResolvedValue({ variant: 'minimal-friction-5' }),
    shown: vi.fn().mockResolvedValue(undefined),
    chosen: vi.fn().mockResolvedValue(undefined),
  },
}

vi.mock('~/api', () => ({
  default: () => mockApi,
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: {} }),
}))

const mockAction = vi.fn()
vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({
    isApp: false,
  }),
}))

describe('DonationAskStripe', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockApi.bandit.choose.mockResolvedValue({ variant: 'minimal-friction-5' })
  })

  function createWrapper(props = {}) {
    return mount(DonationAskStripe, {
      props: {
        groupname: 'Test Group',
        ...props,
      },
      global: {
        stubs: {
          DonationIntroText: {
            name: 'DonationIntroText',
            template: '<div class="donation-intro-text" />',
            props: [
              'groupid',
              'groupname',
              'target',
              'targetMet',
              'donated',
              'hideIntro',
            ],
          },
          DonationBirthdayDisplay: {
            name: 'DonationBirthdayDisplay',
            template:
              '<div class="donation-birthday-display"><input class="other-amount" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></div>',
            props: ['modelValue', 'price', 'monthly'],
            emits: ['update:modelValue'],
          },
          DonationTraditionalExtras: {
            name: 'DonationTraditionalExtras',
            template: '<div class="donation-traditional-extras" />',
            props: ['groupid', 'groupname', 'targetMet', 'hideThermometer'],
          },
          DonationThermometer: {
            name: 'DonationThermometer',
            template: '<div class="donation-thermometer" />',
            props: ['groupid'],
          },
          DonationButton: {
            name: 'DonationButton',
            template:
              '<button class="donation-button" :data-value="value">{{ text }}</button>',
            props: ['text', 'value'],
          },
          StripeDonate: {
            name: 'StripeDonate',
            template: '<div class="stripe-donate" :data-price="price" />',
            props: ['price', 'monthly'],
            emits: ['success', 'no-payment-methods', 'error'],
          },
          'b-button': {
            name: 'BButton',
            template:
              '<button class="b-button" :class="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
            emits: ['click'],
          },
        },
      },
    })
  }

  describe('traditional variant', () => {
    beforeEach(() => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'traditional-5' })
    })

    it('shows thermometer when not hidden', async () => {
      const wrapper = createWrapper({ hideThermometer: false })
      await flushPromises()
      expect(wrapper.find('.donation-thermometer').exists()).toBe(true)
    })

  })

})
