import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'

import DonatePage from '~/pages/donate.vue'

// Donation asks in email carry ?amount= (and an auto-login key). These tests
// cover the arrival: the amount the donor tapped must be the amount asked for,
// and the payment buttons must not appear before the auto-login lands, because
// the API refuses to create a PaymentIntent for a logged-out user.

let mockRouteQuery = {}

vi.mock('#imports', async () => {
  const actual = await vi.importActual('vue')
  return {
    ...actual,
    useRoute: () => ({
      query: mockRouteQuery,
      path: '/donate',
      fullPath: '/donate',
    }),
  }
})

// Reactive: the page watches authStore.user to know when the auto-login from
// an email link has landed, so a plain object would never fire the watcher.
const mockAuthStore = reactive({ user: null })
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => mockAuthStore,
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: false }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

vi.mock('~/components/StripeDonate', () => ({
  default: {
    name: 'StripeDonate',
    template: '<div class="stripe-donate-stub" />',
    props: ['price', 'monthly'],
  },
}))
vi.mock('~/components/DonationButton', () => ({
  default: {
    template: '<div class="donation-button-stub" />',
    props: ['text', 'value', 'suggestGivingFund'],
  },
}))
vi.mock('~/components/DonationThank', () => ({
  default: { template: '<div class="donation-thank-stub" />' },
}))
vi.mock('~/components/ExternalLink', () => ({
  default: { template: '<a><slot /></a>', props: ['href'] },
}))

const globalStubs = {
  'client-only': { template: '<div><slot /></div>' },
  'v-icon': { template: '<i />', props: ['icon'] },
  'b-input-group': { template: '<div><slot /></div>', props: ['prepend'] },
  'b-input': {
    template: '<input />',
    props: ['modelValue', 'type', 'min', 'step'],
  },
  'b-img': { template: '<img />', props: ['src', 'alt', 'fluid'] },
  NuxtLink: { template: '<a><slot /></a>', props: ['to'] },
}

async function mountPage(query = {}, search = '') {
  mockRouteQuery = query
  window.__initSearch = search

  const wrapper = mount(DonatePage, {
    global: {
      stubs: globalStubs,
      mocks: { useHead: () => {} },
    },
  })

  await flushPromises()
  return wrapper
}

describe('donate page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers({ shouldAdvanceTime: true })
    mockAuthStore.user = null
    globalThis.useHead = () => {}
    globalThis.useRuntimeConfig = () => ({ public: {} })
  })

  afterEach(() => {
    vi.useRealTimers()
    delete window.__initSearch
  })

  it('asks for the amount from the email link', async () => {
    const wrapper = await mountPage({ amount: '5' })

    expect(wrapper.text()).toContain('Please donate £5')
  })

  it('falls back to the default when no amount is given', async () => {
    const wrapper = await mountPage({})

    expect(wrapper.text()).toContain('Please donate £3')
  })

  it.each([
    ['nonsense', '£3'],
    ['0', '£3'],
    ['-4', '£3'],
    ['', '£3'],
  ])('ignores a junk amount of %s', async (amount, expected) => {
    const wrapper = await mountPage({ amount })

    expect(wrapper.text()).toContain(`Please donate ${expected}`)
  })

  it('clamps an amount above what the API will accept', async () => {
    // The Go API rejects anything over £250, so a hand-edited URL must not
    // produce a payment that 400s at confirm time.
    const wrapper = await mountPage({ amount: '9999' })

    expect(wrapper.text()).toContain('Please donate £250')
  })

  it('honours a monthly link', async () => {
    const wrapper = await mountPage({ amount: '3', monthly: '1' })

    const stripe = wrapper.findComponent({ name: 'StripeDonate' })
    expect(stripe.exists()).toBe(true)
    expect(stripe.props('monthly')).toBe(true)
  })

  it('shows the payment buttons straight away for a normal visit', async () => {
    const wrapper = await mountPage({})

    expect(wrapper.find('.stripe-donate-stub').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Signing you in')
  })

  it('holds the payment buttons back while an email auto-login is in flight', async () => {
    const wrapper = await mountPage(
      { amount: '2' },
      '?u=42&k=deadbeef&amount=2'
    )

    expect(wrapper.text()).toContain('Signing you in')
    expect(wrapper.find('.stripe-donate-stub').exists()).toBe(false)
  })

  it('shows the payment buttons once the auto-login lands', async () => {
    const wrapper = await mountPage(
      { amount: '2' },
      '?u=42&k=deadbeef&amount=2'
    )

    mockAuthStore.user = { id: 42 }
    await flushPromises()

    expect(wrapper.find('.stripe-donate-stub').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Signing you in')
  })

  it('does not strand the donor if the auto-login never lands', async () => {
    // An expired key must not leave someone stuck behind a spinner - let them
    // through and let the payment sheet do what it still can.
    const wrapper = await mountPage({ amount: '2' }, '?u=42&k=expired&amount=2')

    expect(wrapper.text()).toContain('Signing you in')

    vi.advanceTimersByTime(5001)
    await flushPromises()

    expect(wrapper.find('.stripe-donate-stub').exists()).toBe(true)
  })

  it('does not wait when the visitor is already logged in', async () => {
    mockAuthStore.user = { id: 42 }

    const wrapper = await mountPage(
      { amount: '2' },
      '?u=42&k=deadbeef&amount=2'
    )

    expect(wrapper.text()).not.toContain('Signing you in')
    expect(wrapper.find('.stripe-donate-stub').exists()).toBe(true)
  })
})
