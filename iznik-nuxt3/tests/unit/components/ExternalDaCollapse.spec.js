import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ExternalDa from '~/components/ExternalDa.vue'

// The contract: when there is no ad to show, SAY SO, so the sticky band collapses.
//
// It used to. 8b8b18176 (2025-12-04, "Show fallback donation ad when ads disabled or ad
// fails to load") replaced every `emit('rendered', false)` with "set fallbackAdVisible,
// claim rendered:true". LayoutCommon reserves space on a true and only collapses on
// stickyAdRendered === 0, so an unfilled slot left a 123px grey band with nothing in it -
// measured on dev-live, with .aboveSticky padded 125px to make room for it.
//
// The donate banner survives in exactly one place: the app without cookies, which cannot
// run a real ad at all, so a banner there is genuine content rather than hole-filling.
// That is also pre-8b8b18176 behaviour, not something invented here.

const { mockMe, mockRecentDonor } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockMe: ref({ id: 1, email: 'test@example.com' }),
    mockRecentDonor: ref(false),
  }
})

const mockConfigStore = { fetch: vi.fn().mockResolvedValue([{ value: '1' }]) }
const mockMiscStore = { boredWithJobs: false, adsDisabled: false }
const mockRuntimeConfig = {
  public: {
    ISAPP: false,
    USE_COOKIES: true,
    COOKIEYES: false,
    USER_SITE: 'ilovefreegle.org',
  },
}

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe, recentDonor: mockRecentDonor }),
}))
vi.mock('~/stores/config', () => ({ useConfigStore: () => mockConfigStore }))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMiscStore }))
vi.mock('#app', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, useRuntimeConfig: () => mockRuntimeConfig }
})

const DONATE_BANNER = 'img[src*="SupportFreegle"]'

function createWrapper(props = {}) {
  return mount(ExternalDa, {
    props: { adUnitPath: '/12345/test-ad', divId: 'test-ad-div', ...props },
    global: {
      stubs: {
        ClientOnly: { template: '<div><slot /></div>' },
        JobsDaSlot: {
          template: '<div class="jobs-da-slot" />',
          emits: ['rendered', 'borednow'],
        },
        OurPlaywireDa: { template: '<div class="playwire-da" />', emits: ['rendered'] },
        OurGoogleDa: { template: '<div class="google-da" />', emits: ['rendered'] },
        OurPrebidDa: { template: '<div class="prebid-da" />', emits: ['rendered'] },
        'nuxt-link': {
          template: '<a class="nuxt-link" :href="to"><slot /></a>',
          props: ['to'],
        },
      },
      // Matches the sibling spec: the real directive is what drives visibilityChanged, so
      // the stub fires it on mount rather than each test poking the method by hand.
      directives: {
        'observe-visibility': {
          mounted: (el, binding) => binding.value(true),
        },
      },
    },
  })
}

/** Last `rendered` payload, or undefined if the component never reported. */
function lastRendered(wrapper) {
  const emitted = wrapper.emitted('rendered') ?? []
  return emitted.length ? emitted[emitted.length - 1][0] : undefined
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  mockMe.value = { id: 1, email: 'test@example.com' }
  mockRecentDonor.value = false
  mockMiscStore.boredWithJobs = false
  mockMiscStore.adsDisabled = false
  mockRuntimeConfig.public.ISAPP = false
  mockRuntimeConfig.public.USE_COOKIES = true
  mockRuntimeConfig.public.COOKIEYES = false
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ExternalDa — an unfilled slot must collapse, not reserve space', () => {
  it('emits rendered=false when the ad fails to render', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.rippleRendered(false)
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
  })

  it('shows no fallback of any kind when the ad fails to render', async () => {
    // Asserting only on the banner would not discriminate: the old code turned
    // fallbackAdVisible on and rendered the JOBS slot there (jobs defaults true), so the
    // banner was absent either way while the band stayed reserved. The flag is the tell.
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.rippleRendered(false)
    await flushPromises()

    expect(wrapper.vm.fallbackAdVisible).toBe(false)
    expect(wrapper.find(DONATE_BANNER).exists()).toBe(false)
  })

  it('does not mark ads disabled globally just because one slot went unfilled', async () => {
    // adsDisabled is read elsewhere as "ads are off"; an unfilled auction is not that.
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.rippleRendered(false)
    await flushPromises()

    expect(mockMiscStore.adsDisabled).toBe(false)
  })

  it('still reports rendered=true when an ad really did render', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.rippleRendered(true)
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(true)
  })
})

// The app-without-cookies path itself is NOT reachable from a unit test: it lives inside
// `if (process.client)`, which vite replaces at build time, so under vitest it compiles to
// `if (false)`. Setting process.client makes no difference - the branch is already gone.
// (The sibling spec's version of this test asserts `fallbackAdVisible || .pointer exists`,
// which is why it looks like it passes.) What IS reachable is the fallback template, so pin
// that; the runtime path is covered by the browser A/B on dev-live recorded in the PR.
describe('ExternalDa — the fallback block holds the banner, never the jobs slot', () => {
  it('renders the donate banner when the fallback is on', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.fallbackAdVisible = true
    await flushPromises()

    expect(wrapper.find(DONATE_BANNER).exists()).toBe(true)
  })

  it('does not render the jobs slot there, which is what left the band blank', async () => {
    // Edward's app case: the fallback mounted JobsDaSlot, whose own list was empty, so the
    // band was reserved and showed nothing at all. jobs defaults true, so this is the
    // default shape, not an edge case.
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.fallbackAdVisible = true
    await flushPromises()

    expect(wrapper.find('.jobs-da-slot').exists()).toBe(false)
  })
})
