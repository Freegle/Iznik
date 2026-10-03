import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'
import DonationAskModal from '~/components/DonationAskModal.vue'

const { mockVariant, mockShow, mockRaised, mockTarget } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockVariant: ref('default'),
    mockShow: vi.fn(),
    mockRaised: ref(500),
    mockTarget: ref(1000),
  }
})

const mockDonationStore = {
  raised: mockRaised,
  target: mockTarget,
}

const mockAuthStore = {
  user: { donated: null },
}

const mockApi = {
  bandit: {
    chosen: vi.fn(),
  },
}

vi.mock('~/stores/donations', () => ({
  useDonationStore: () => mockDonationStore,
}))

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('pinia', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    storeToRefs: () => ({
      raised: mockRaised,
      target: mockTarget,
    }),
  }
})

const { mockModal } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockModal: ref(null),
  }
})

vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    hide: vi.fn(),
  }),
}))

vi.mock('~/composables/useDonationAskModal', () => ({
  useDonationAskModal: () =>
    Promise.resolve({
      variant: mockVariant,
      show: mockShow,
    }),
}))

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

describe('DonationAskModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockVariant.value = 'default'
    mockRaised.value = 500
    mockTarget.value = 1000
    mockAuthStore.user = { donated: null, engagementlevel: null }
  })

  async function createWrapper() {
    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(Suspense, null, {
            default: () => h(DonationAskModal),
            fallback: () => h('div', 'Loading...'),
          })
      },
    })

    const wrapper = mount(TestWrapper, {
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="b-modal" @show="$emit(\'show\')"><slot name="default" /></div>',
            props: ['title', 'hideHeader', 'hideFooter', 'size', 'noStacking'],
            emits: ['show'],
          },
          DonationAskStripe: {
            template: '<div class="donation-ask-stripe" />',
            props: [
              'target',
              'raised',
              'targetMet',
              'donated',
              'amounts',
              'default',
            ],
            emits: ['score', 'success', 'cancel'],
          },
          DonationThank: {
            template: '<div class="donation-thank" />',
          },
          RateAppAsk: {
            template: '<div class="rate-app-ask" />',
            emits: ['hide'],
          },
        },
      },
    })

    await flushPromises()
    return wrapper
  }

  describe('rendering', () => {
    it('renders modal', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.b-modal').exists()).toBe(true)
    })

    it('calls show on mount', async () => {
      await createWrapper()
      expect(mockShow).toHaveBeenCalled()
    })
  })

  describe('donation stripe variant', () => {
    it('renders DonationAskStripe when variant is default', async () => {
      mockVariant.value = 'default'
      const wrapper = await createWrapper()
      expect(wrapper.find('.donation-ask-stripe').exists()).toBe(true)
    })

    it('does not show thank you message initially', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.find('.donation-thank').exists()).toBe(false)
    })
  })

  describe('rate app variant', () => {
    it('renders RateAppAsk when variant is rateapp', async () => {
      mockVariant.value = 'rateapp'
      const wrapper = await createWrapper()
      expect(wrapper.find('.rate-app-ask').exists()).toBe(true)
    })

    it('does not render DonationAskStripe when variant is rateapp', async () => {
      mockVariant.value = 'rateapp'
      const wrapper = await createWrapper()
      expect(wrapper.find('.donation-ask-stripe').exists()).toBe(false)
    })
  })

  describe('computed properties', () => {
    it('computes targetMet correctly when raised > target', async () => {
      mockRaised.value = 1500
      mockTarget.value = 1000
      const wrapper = await createWrapper()
      const component = wrapper.findComponent(DonationAskModal)
      expect(component.vm.targetMet).toBe(true)
    })

    it('computes targetMet as false when raised < target', async () => {
      mockRaised.value = 500
      mockTarget.value = 1000
      const wrapper = await createWrapper()
      const component = wrapper.findComponent(DonationAskModal)
      expect(component.vm.targetMet).toBe(false)
    })
  })

  describe('donated display', () => {
    it('shows donated message when user has donated', async () => {
      mockAuthStore.user = { donated: '2024-01-15' }
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain("You've already donated")
    })

    it('does not show donated message when user has not donated', async () => {
      mockAuthStore.user = { donated: null }
      const wrapper = await createWrapper()
      expect(wrapper.text()).not.toContain("You've already donated")
    })
  })

  describe('engagement-based suggested donation amount', () => {
    it('defaults to 2 when engagementlevel is null', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: null }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(2)
    })

    it('defaults to 2 for New users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'New' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(2)
    })

    it('defaults to 2 for Inactive users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'Inactive' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(2)
    })

    it('defaults to 2 for Dormant users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'Dormant' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(2)
    })

    it('defaults to 3 for Occasional users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'Occasional' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(3)
    })

    it('defaults to 3 for Frequent users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'Frequent' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(3)
    })

    it('defaults to 5 for Obsessed users', async () => {
      mockAuthStore.user = { donated: null, engagementlevel: 'Obsessed' }
      const wrapper = await createWrapper()
      expect(
        wrapper.findComponent(DonationAskModal).vm.suggestedDonationDefault
      ).toBe(5)
    })
  })

  describe('score method', () => {
    it('calls bandit API with correct params', async () => {
      mockVariant.value = 'test-variant'
      const wrapper = await createWrapper()
      const component = wrapper.findComponent(DonationAskModal)

      component.vm.score(5)

      expect(mockApi.bandit.chosen).toHaveBeenCalledWith({
        uid: 'donation',
        variant: 'test-variant',
        score: 5,
      })
    })
  })

  describe('funnel instrumentation', () => {
    it('logs donation_modal_open when modal show event fires', async () => {
      mockVariant.value = 'minimal-friction-5'
      const wrapper = await createWrapper()

      const modal = wrapper.find('.b-modal')
      await modal.trigger('show')

      expect(mockAction).toHaveBeenCalledWith('donation_modal_open', {
        variant: 'minimal-friction-5',
      })
    })

    it('logs donation_modal_engaged after 2 seconds', async () => {
      vi.useFakeTimers()
      mockVariant.value = 'stripe'
      const wrapper = await createWrapper()

      const modal = wrapper.find('.b-modal')
      await modal.trigger('show')

      expect(mockAction).not.toHaveBeenCalledWith(
        'donation_modal_engaged',
        expect.anything()
      )

      vi.advanceTimersByTime(2000)

      expect(mockAction).toHaveBeenCalledWith('donation_modal_engaged', {
        variant: 'stripe',
        elapsed_ms: expect.any(Number),
      })

      vi.useRealTimers()
    })

    it('logs donation_modal_dismissed on hide with timing', async () => {
      vi.useFakeTimers()
      mockVariant.value = 'minimal-friction-5'
      const wrapper = await createWrapper()
      const component = wrapper.findComponent(DonationAskModal)

      const modal = wrapper.find('.b-modal')
      await modal.trigger('show')

      vi.advanceTimersByTime(1500)

      component.vm.hide()

      expect(mockAction).toHaveBeenCalledWith('donation_modal_dismissed', {
        variant: 'minimal-friction-5',
        time_open_ms: expect.any(Number),
        engaged: false,
      })

      vi.useRealTimers()
    })

    it('does not log dismissed when thankyou is showing', async () => {
      const wrapper = await createWrapper()
      const component = wrapper.findComponent(DonationAskModal)

      const modal = wrapper.find('.b-modal')
      await modal.trigger('show')

      // Simulate successful donation
      component.vm.thankyou = true

      mockAction.mockClear()
      component.vm.hide()

      expect(mockAction).not.toHaveBeenCalledWith(
        'donation_modal_dismissed',
        expect.anything()
      )
    })
  })
})
