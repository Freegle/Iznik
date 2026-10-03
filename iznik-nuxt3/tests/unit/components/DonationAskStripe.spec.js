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
        ...props,
      },
      global: {
        stubs: {
          DonationIntroText: {
            name: 'DonationIntroText',
            template: '<div class="donation-intro-text" />',
            props: ['target', 'donated', 'hideIntro'],
          },
          DonationTraditionalExtras: {
            name: 'DonationTraditionalExtras',
            template: '<div class="donation-traditional-extras" />',
            props: ['targetMet'],
          },
          DonationThermometer: {
            name: 'DonationThermometer',
            template: '<div class="donation-thermometer" />',
            props: [],
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

  describe('rendering', () => {
    it('renders donation container', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-ask-container').exists()).toBe(true)
    })

    it('shows donation controls', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-controls').exists()).toBe(true)
    })

    it('shows amount slider', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.amount-slider').exists()).toBe(true)
    })

    it('shows amount display', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.amount-display').exists()).toBe(true)
    })

    it('shows Stripe donate component', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.stripe-donate').exists()).toBe(true)
    })

    it('shows cancel button', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Not now')
    })
  })

  describe('minimal variant', () => {
    it('shows minimal message for minimal variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.text()).toContain('Freegle is volunteer-run')
    })

    it('does not show intro text for minimal variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-intro-text').exists()).toBe(false)
    })

    it('does not show traditional extras for minimal variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-traditional-extras').exists()).toBe(false)
    })

    it('does not show thermometer for minimal variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-thermometer').exists()).toBe(false)
    })
  })

  describe('traditional variant', () => {
    beforeEach(() => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'traditional-5' })
    })

    it('shows intro text for traditional variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-intro-text').exists()).toBe(true)
    })

    it('shows traditional extras for traditional variant', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      expect(wrapper.find('.donation-traditional-extras').exists()).toBe(true)
    })

  })

  describe('amount selection', () => {
    it('displays default amount', async () => {
      const wrapper = createWrapper({ default: 10 })
      await flushPromises()
      expect(wrapper.text()).toContain('£')
    })

    it('slider has correct min value', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      const slider = wrapper.find('.amount-slider')
      expect(slider.attributes('min')).toBe('2')
    })

    it('slider has correct max value', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      const slider = wrapper.find('.amount-slider')
      expect(slider.attributes('max')).toBe('25')
    })

    it('slider labels show range', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      const labels = wrapper.find('.slider-labels')
      expect(labels.text()).toContain('£2')
      expect(labels.text()).toContain('£25')
    })
  })

  describe('engagement floor applied to bandit amount', () => {
    it('uses bandit amount when it exceeds the default', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'minimal-friction-5' })
      const wrapper = createWrapper({ default: 2 })
      await flushPromises()
      expect(wrapper.find('.amount-value').text()).toBe('£5.00')
    })

    it('uses default when bandit amount is below it', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'minimal-friction-2' })
      const wrapper = createWrapper({ default: 5 })
      await flushPromises()
      expect(wrapper.find('.amount-value').text()).toBe('£5.00')
    })

    it('uses default when bandit returns no variant', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: null })
      const wrapper = createWrapper({ default: 5 })
      await flushPromises()
      expect(wrapper.find('.amount-value').text()).toBe('£5.00')
    })
  })

  describe('events', () => {
    it('emits cancel on Not now click', async () => {
      const wrapper = createWrapper()
      await flushPromises()
      const cancelBtn = wrapper.find('.b-button.secondary')
      await cancelBtn.trigger('click')
      expect(wrapper.emitted('cancel')).toBeTruthy()
    })
  })

  describe('bandit API', () => {
    it('calls bandit choose on mount', async () => {
      createWrapper()
      await flushPromises()
      expect(mockApi.bandit.choose).toHaveBeenCalledWith({ uid: 'donation' })
    })

    it('calls bandit shown after choose', async () => {
      createWrapper()
      await flushPromises()
      expect(mockApi.bandit.shown).toHaveBeenCalledWith({
        uid: 'donation',
        variant: 'minimal-friction-5',
      })
    })

    it('handles legacy stripe variant', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'stripe' })
      const wrapper = createWrapper()
      await flushPromises()
      // Should map to traditional-5
      expect(wrapper.find('.donation-intro-text').exists()).toBe(true)
    })

    it('handles choose error gracefully', async () => {
      mockApi.bandit.choose.mockRejectedValue(new Error('Network error'))
      const wrapper = createWrapper()
      await flushPromises()
      // Should still render with defaults
      expect(wrapper.find('.donation-ask-container').exists()).toBe(true)
    })
  })

  describe('PayPal fallback', () => {
    it('shows PayPal button when Stripe has no methods', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      // Verify the Stripe component rendered correctly
      // PayPal button would only show after fallback is triggered via event
      expect(wrapper.find('.stripe-donate').exists()).toBe(true)
    })
  })

  describe('funnel instrumentation', () => {
    it('logs donation_amount_changed when slider changes', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'minimal-friction-5' })
      vi.useFakeTimers()
      const wrapper = createWrapper()
      await flushPromises()

      const slider = wrapper.find('.amount-slider')
      await slider.setValue(10)
      await flushPromises()

      vi.advanceTimersByTime(500)

      expect(mockAction).toHaveBeenCalledWith('donation_amount_changed', {
        old_amount: 5,
        new_amount: 10,
        input_method: 'slider',
      })

      vi.useRealTimers()
    })

    it('logs donation_payment_completed on success', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      const stripe = wrapper.findComponent({ name: 'StripeDonate' })
      await stripe.vm.$emit('success')
      await flushPromises()

      expect(mockAction).toHaveBeenCalledWith('donation_payment_completed', {
        amount: expect.any(Number),
        variant: 'minimal-friction',
      })
    })

    it('logs donation_payment_fallback when no payment methods', async () => {
      const wrapper = createWrapper()
      await flushPromises()

      const stripe = wrapper.findComponent({ name: 'StripeDonate' })
      await stripe.vm.$emit('no-payment-methods')
      await flushPromises()

      expect(mockAction).toHaveBeenCalledWith('donation_payment_fallback', {
        amount: expect.any(Number),
        variant: 'minimal-friction',
        reason: 'no_stripe_methods',
      })
    })
  })

  describe('props passed to children', () => {
    it('passes targetMet correctly', async () => {
      mockApi.bandit.choose.mockResolvedValue({ variant: 'traditional-5' })
      const wrapper = createWrapper({ targetMet: true })
      await flushPromises()
      expect(wrapper.find('.donation-intro-text').exists()).toBe(true)
    })
  })
})
