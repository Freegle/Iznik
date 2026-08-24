import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ExternalDa from '~/components/ExternalDa.vue'

// Full decision matrix for the sticky ad slot: supporter vs not, job ads vs other ads,
// and every fallback. What `rendered` reports here decides whether LayoutCommon reserves
// the band or collapses it (.adNotShown, padding-bottom 0, only on stickyAdRendered === 0),
// so each row is really "does the band take space".
//
// The bug this pins: 8b8b18176 (2025-12-04, "Show fallback donation ad when ads disabled or
// ad fails to load") replaced every `emit('rendered', false)` with "set fallbackAdVisible,
// claim rendered:true", on the failed-render, prebid-blocked, TC-data-blocked and
// ads-disabled-in-config paths. Nothing could collapse the band after that. Worse, the
// fallback it switched to is the JOBS slot (jobs defaults true), so when the jobs list was
// also empty the band was reserved and drew nothing - 123px of $gray-200 on dev-live with
// .aboveSticky padded 125px.
//
// HARNESS NOTE, learned the hard way: the component calls `useRuntimeConfig` as a Nuxt
// auto-import, which under vitest resolves to the GLOBAL installed by tests/unit/setup.ts
// (line ~117) - not to '#app' or '#imports'. Mocking those modules does nothing here, which
// is why the sibling spec's app-mode test had to be written as
// `fallbackAdVisible || find('.pointer').exists()` (always true) and never exercised the
// branch. Override globalThis.useRuntimeConfig instead. USER_SITE must be non-empty too:
// the system-account check is `myEmail.includes(userSite)`, and '' matches every address.

const { mockMe, mockRecentDonor } = vi.hoisted(() => {
  const { ref } = require('vue')
  return {
    mockMe: ref({ id: 1, email: 'member@example.com' }),
    mockRecentDonor: ref(false),
  }
})

const mockConfigStore = { fetch: vi.fn().mockResolvedValue([{ value: '1' }]) }
const mockMiscStore = { boredWithJobs: false, adsDisabled: false }

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe, recentDonor: mockRecentDonor }),
}))
vi.mock('~/stores/config', () => ({ useConfigStore: () => mockConfigStore }))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMiscStore }))

const DONATE_BANNER = 'img[src*="SupportFreegle"]'
const JOBS = '.jobs-da-slot'
const PLAYWIRE = '.playwire-da'
const GOOGLE = '.google-da'
const PREBID = '.prebid-da'

let priorRuntimeConfig

/** Point the component's auto-imported useRuntimeConfig at this public config. */
function setRuntimeConfig(publicConfig) {
  globalThis.useRuntimeConfig = () => ({
    public: {
      ISAPP: false,
      USE_COOKIES: true,
      COOKIEYES: false,
      USER_SITE: 'https://www.ilovefreegle.org',
      ...publicConfig,
    },
  })
}

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
        OurPlaywireDa: {
          template: '<div class="playwire-da" />',
          emits: ['rendered'],
        },
        OurGoogleDa: {
          template: '<div class="google-da" />',
          emits: ['rendered'],
        },
        OurPrebidDa: {
          template: '<div class="prebid-da" />',
          emits: ['rendered'],
        },
        'nuxt-link': {
          template: '<a class="nuxt-link" :href="to"><slot /></a>',
          props: ['to'],
        },
      },
      // The real directive is what drives visibilityChanged, so fire it on mount rather
      // than having each test poke the method.
      directives: {
        'observe-visibility': { mounted: (el, binding) => binding.value(true) },
      },
    },
  })
}

/** Last `rendered` payload, or undefined if the component never reported. */
function lastRendered(wrapper) {
  const emitted = wrapper.emitted('rendered') ?? []
  return emitted.length ? emitted[emitted.length - 1][0] : undefined
}

/**
 * No ad is being asked for, and no fallback is on screen.
 *
 * Deliberately NOT "the network component is absent": OurPlaywireDa/OurGoogleDa/OurPrebidDa
 * are mounted whenever the slot is visible, and it is renderAd=false that stops them
 * requesting anything. Asserting on their absence fails against correct behaviour.
 */
function noAdRequested(wrapper) {
  return (
    wrapper.vm.renderAd === false &&
    !wrapper.find(JOBS).exists() &&
    !wrapper.find(DONATE_BANNER).exists()
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  priorRuntimeConfig = globalThis.useRuntimeConfig
  setRuntimeConfig({})
  mockMe.value = { id: 1, email: 'member@example.com' }
  mockRecentDonor.value = false
  mockMiscStore.boredWithJobs = false
  mockMiscStore.adsDisabled = false
  mockConfigStore.fetch.mockResolvedValue([{ value: '1' }])
})

afterEach(() => {
  vi.useRealTimers()
  globalThis.useRuntimeConfig = priorRuntimeConfig
})

describe('supporter (recent donor) — no ads, and no space held for them', () => {
  it('web: reports no ad and draws nothing', async () => {
    mockRecentDonor.value = true
    const wrapper = createWrapper()
    // checkStillVisible is what consults donor status; it runs behind a 100ms timer.
    await wrapper.vm.checkStillVisible()
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
    expect(noAdRequested(wrapper)).toBe(true)
  })

  it('app without cookies: reports no ad and shows no donate banner either', async () => {
    // The app cannot run a real ad, so it normally falls back to the banner. A supporter
    // should not even get that - they have already paid for the ads to go away.
    setRuntimeConfig({ ISAPP: true, USE_COOKIES: false })
    mockRecentDonor.value = true

    const wrapper = createWrapper()
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
    expect(wrapper.find(DONATE_BANNER).exists()).toBe(false)
    expect(noAdRequested(wrapper)).toBe(true)
  })

  it('a system account too, with the deployed URL-shaped USER_SITE', async () => {
    // The deployed value is a URL, and the old check compared the address against it
    // verbatim, so this suppression never fired outside tests. A bare-domain fixture
    // (as the sibling spec uses) hides that.
    setRuntimeConfig({ USER_SITE: 'https://www.ilovefreegle.org' })
    mockMe.value = { id: 1, email: 'geeks@ilovefreegle.org' }
    const wrapper = createWrapper()
    await wrapper.vm.checkStillVisible()
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
    expect(noAdRequested(wrapper)).toBe(true)
  })

  it('counts a subdomain address as ours too', async () => {
    setRuntimeConfig({ USER_SITE: 'https://www.ilovefreegle.org' })
    mockMe.value = { id: 1, email: 'bot@mail.ilovefreegle.org' }
    const wrapper = createWrapper()
    await wrapper.vm.checkStillVisible()
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
  })

  it('leaves an ordinary member alone, even one whose domain merely ends similarly', async () => {
    setRuntimeConfig({ USER_SITE: 'https://www.ilovefreegle.org' })
    mockMe.value = { id: 1, email: 'someone@notilovefreegle.org' }
    const wrapper = createWrapper()
    await wrapper.vm.checkStillVisible()
    await flushPromises()

    // Ads are wanted here: no suppression, so the slot goes on to request one.
    expect(wrapper.vm.renderAd).toBe(true)
  })
})

describe('not a supporter — job ads vs other ads', () => {
  it('shows the jobs slot when job ads are on for this placement', async () => {
    const wrapper = createWrapper({ jobs: true })
    wrapper.vm.isVisible = true
    wrapper.vm.renderAd = true
    await flushPromises()

    expect(wrapper.find(JOBS).exists()).toBe(true)
    expect(wrapper.find(PLAYWIRE).exists()).toBe(false)
  })

  it('shows the ad network instead when job ads are off for this placement', async () => {
    const wrapper = createWrapper({ jobs: false })
    wrapper.vm.isVisible = true
    wrapper.vm.renderAd = true
    await flushPromises()

    expect(wrapper.find(JOBS).exists()).toBe(false)
    expect(wrapper.find(PLAYWIRE).exists()).toBe(true)
  })

  it('switches from jobs to the ad network once the member is bored of jobs', async () => {
    // JobsDaSlot emits borednow after ~31s so the slot earns revenue for the rest of the
    // session instead of showing jobs nobody clicked.
    mockMiscStore.boredWithJobs = true
    const wrapper = createWrapper({ jobs: true })
    wrapper.vm.isVisible = true
    wrapper.vm.renderAd = true
    await flushPromises()

    expect(wrapper.find(JOBS).exists()).toBe(false)
    expect(wrapper.find(PLAYWIRE).exists()).toBe(true)
  })

  it('uses Google when AdSense is the configured network', async () => {
    const wrapper = createWrapper({ jobs: false })
    wrapper.vm.isVisible = true
    wrapper.vm.renderAd = true
    wrapper.vm.playWire = false
    wrapper.vm.adSense = true
    await flushPromises()

    expect(wrapper.find(GOOGLE).exists()).toBe(true)
    expect(wrapper.find(PLAYWIRE).exists()).toBe(false)
  })

  it('falls to Prebid when neither Playwire nor AdSense is configured', async () => {
    const wrapper = createWrapper({ jobs: false })
    wrapper.vm.isVisible = true
    wrapper.vm.renderAd = true
    wrapper.vm.playWire = false
    wrapper.vm.adSense = false
    await flushPromises()

    expect(wrapper.find(PREBID).exists()).toBe(true)
  })
})

describe('an unfilled slot must collapse, not reserve space', () => {
  it('emits rendered=false when the ad fails to render', async () => {
    const wrapper = createWrapper()
    await flushPromises()

    wrapper.vm.rippleRendered(false)
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
  })

  it('shows no fallback of any kind when the ad fails to render', async () => {
    // Asserting only on the banner would not discriminate: the old code turned
    // fallbackAdVisible on and rendered the JOBS slot there, so the banner was absent
    // either way while the band stayed reserved. The flag is the tell.
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

  it('collapses, and says ads are disabled, when the server config switches ads off', async () => {
    mockConfigStore.fetch.mockResolvedValue([{ value: '0' }])
    const wrapper = createWrapper()
    await wrapper.vm.checkStillVisible()
    await flushPromises()

    expect(lastRendered(wrapper)).toBe(false)
    expect(wrapper.emitted('disabled')).toBeTruthy()
    expect(mockMiscStore.adsDisabled).toBe(true)
    expect(noAdRequested(wrapper)).toBe(true)
  })
})

describe('the fallback banner and its one legitimate home', () => {
  it('app without cookies, not a supporter: shows the donate banner', async () => {
    setRuntimeConfig({ ISAPP: true, USE_COOKIES: false })
    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.vm.fallbackAdVisible).toBe(true)
    expect(wrapper.find(DONATE_BANNER).exists()).toBe(true)
    expect(lastRendered(wrapper)).toBe(true)
  })

  it('never puts the jobs slot in that fallback, which is what left the band blank', async () => {
    // Edward's app report: the fallback mounted JobsDaSlot, whose own list was empty, so
    // the band was reserved and showed nothing. jobs defaults true, so this was the
    // default shape rather than an edge case.
    setRuntimeConfig({ ISAPP: true, USE_COOKIES: false })
    const wrapper = createWrapper({ jobs: true })
    await flushPromises()

    expect(wrapper.find(JOBS).exists()).toBe(false)
    expect(wrapper.find(DONATE_BANNER).exists()).toBe(true)
  })

  it('leaves video ads alone in the app, since a banner cannot stand in for one', async () => {
    setRuntimeConfig({ ISAPP: true, USE_COOKIES: false })
    const wrapper = createWrapper({ video: true })
    await flushPromises()

    expect(wrapper.find(DONATE_BANNER).exists()).toBe(false)
  })
})

describe('logged out', () => {
  it('draws nothing when there is no member and the slot is not for logged-out visitors', async () => {
    mockMe.value = null
    const wrapper = createWrapper({ showLoggedOut: false })
    await flushPromises()

    expect(noAdRequested(wrapper)).toBe(true)
  })
})
