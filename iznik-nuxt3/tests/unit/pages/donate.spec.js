import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// useHead is auto-imported by Nuxt; expose it globally so donate.vue can call it
globalThis.useHead = vi.fn()

// Mock mobile store (auth is already aliased via vitest.config.mts)
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false, isMobile: false }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

// Stub heavy child components to avoid pulling in Stripe/PayPal SDKs
vi.mock('~/components/DonationButton', () => ({
  default: {
    name: 'DonationButton',
    template: '<div class="donation-button" data-value="value" />',
    props: {
      value: { type: String, default: null },
      text: { type: String, default: null },
      directDonation: { type: Boolean, default: false },
    },
  },
}))

vi.mock('~/components/StripeDonate', () => ({
  default: {
    name: 'StripeDonate',
    template: '<div class="stripe-donate" />',
    props: ['price', 'monthly'],
    emits: ['success', 'error', 'noPaymentMethods'],
  },
}))

vi.mock('~/components/DonationThank', () => ({
  default: { template: '<div class="donation-thank" />' },
}))

vi.mock('~/components/ExternalLink', () => ({
  default: {
    name: 'ExternalLink',
    template: '<a :href="href"><slot /></a>',
    props: ['href'],
  },
}))

import DonatePage from '~/pages/donate.vue'

function createWrapper() {
  return mount(DonatePage, {
    global: {
      stubs: {
        // Render ClientOnly slot content in JSDOM
        ClientOnly: { template: '<div><slot /></div>' },
        // Bootstrap-vue components not covered by global setup
        'b-input': {
          template:
            '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'type', 'min', 'step'],
          emits: ['update:modelValue'],
        },
        'b-input-group': {
          template: '<div class="input-group"><slot /></div>',
          props: ['prepend'],
        },
        'b-img': { template: '<img />', props: ['src', 'fluid', 'alt'] },
        'nuxt-link': {
          template: '<a :href="to"><slot /></a>',
          props: ['to', 'noPrefetch'],
        },
      },
    },
  })
}

describe('donate page', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // Step 3b: the page must NOT render the discontinued PayPal Giving Fund URL.
  // PayPal shut down the Giving Fund in 2024; the URL
  // https://www.paypal.com/fundraiser/charity/55681 now leads to a broken page.
  // This test FAILS on the unfixed code (the link is present) and PASSES after fix.
  it('does not render the discontinued PayPal Giving Fund link', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.html()).not.toContain('paypal.com/fundraiser/charity/55681')
  })

  // The PayPal fallback DonationButton must receive a STRING value so the
  // buttonId switch (===) selects the right hosted-button ID.
  // donate.vue passes :value="amount" where amount starts as ref(3) — a Number —
  // causing the switch to fall through to the default ID for every amount.
  // This test FAILS on unfixed code (Vue prop warning + wrong type) and PASSES after fix.
  it('passes a string value to DonationButton in the PayPal fallback path', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    const stripeDonate = wrapper.findComponent({ name: 'StripeDonate' })
    expect(stripeDonate.exists()).toBe(true)
    await stripeDonate.vm.$emit('noPaymentMethods')
    await flushPromises()

    const donationButton = wrapper.findComponent({ name: 'DonationButton' })
    expect(donationButton.exists()).toBe(true)
    expect(typeof donationButton.props('value')).toBe('string')
  })
})
