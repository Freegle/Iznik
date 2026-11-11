import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AppInterstitial from '~/components/AppInterstitial.vue'
import {
  appInterstitialOpen,
  appInterstitialPlatform,
  resetAppInterstitial,
} from '~/composables/useAppInterstitial'
import { APP_STORE_URL_ANDROID, APP_STORE_URL_IOS } from '~/constants'

const mockAction = vi.fn()
const mockRoute = { path: '/browse', query: {} }
const mockMisc = { modtools: false }
const mockMobile = { isApp: false }
const experiment = vi.hoisted(() => ({ arm: 'b' }))

vi.mock('~/composables/useClientLog', () => ({
  action: (...args) => mockAction(...args),
}))
vi.mock('~/composables/useExperiment', () => ({
  useExperiment: () => experiment.arm,
  experimentVariant: () => 'mobile-web-app-interstitial:b',
}))
vi.mock('~/stores/misc', () => ({ useMiscStore: () => mockMisc }))
vi.mock('~/stores/mobile', () => ({ useMobileStore: () => mockMobile }))
vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return { ...actual, useRoute: () => mockRoute }
})

const ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'
const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'

function setBrowser(ua, width = 390) {
  Object.defineProperty(window.navigator, 'userAgent', {
    value: ua,
    configurable: true,
  })
  Object.defineProperty(window, 'innerWidth', {
    value: width,
    configurable: true,
  })
}

async function open(ua = ANDROID) {
  setBrowser(ua)
  const wrapper = mount(AppInterstitial, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('AppInterstitial', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    resetAppInterstitial()
    experiment.arm = 'b'
    mockAction.mockClear()
    mockRoute.path = '/browse'
    mockRoute.query = {}
    mockMisc.modtools = false
    mockMobile.isApp = false
    document.body.innerHTML = ''
    document.body.style.overflow = ''
  })

  it('shows the benefits and the Google Play button on an Android phone', async () => {
    const wrapper = await open(ANDROID)

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-labelledby')).toBe(
      wrapper.find('h1').attributes('id')
    )
    expect(wrapper.findAll('.benefit')).toHaveLength(4)
    const store = wrapper.find('[data-testid="app-interstitial-store"]')
    expect(store.attributes('href')).toBe(APP_STORE_URL_ANDROID)
    expect(store.find('img').attributes('alt')).toBe('Get it on Google Play')
    expect(
      wrapper.find('[data-testid="app-interstitial-continue"]').text()
    ).toBe('Continue to the website')
  })

  it('shows the App Store button on an iPhone', async () => {
    const wrapper = await open(IPHONE)

    const store = wrapper.find('[data-testid="app-interstitial-store"]')
    expect(store.attributes('href')).toBe(APP_STORE_URL_IOS)
    expect(store.find('img').attributes('alt')).toBe(
      'Download on the App Store'
    )
  })

  it('records that it was shown, with the platform and the arms', async () => {
    await open(ANDROID)

    expect(mockAction).toHaveBeenCalledWith('app_interstitial_shown', {
      platform: 'android',
      variant: 'mobile-web-app-interstitial:b',
    })
  })

  it('shows nothing to control', async () => {
    experiment.arm = 'control'
    const wrapper = await open(ANDROID)

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(mockAction).not.toHaveBeenCalled()
  })

  it('shows nothing inside the app', async () => {
    mockMobile.isApp = true
    const wrapper = await open(ANDROID)

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('shows nothing on ModTools', async () => {
    mockMisc.modtools = true
    const wrapper = await open(ANDROID)

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('shows nothing on an unsubscribe page', async () => {
    mockRoute.path = '/unsubscribe/4'
    const wrapper = await open(ANDROID)

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('counts a store click with the platform and leaves it open', async () => {
    const wrapper = await open(IPHONE)

    await wrapper
      .find('[data-testid="app-interstitial-store"]')
      .trigger('click')

    expect(mockAction).toHaveBeenCalledWith('app_store_click', {
      platform: 'ios',
      variant: 'mobile-web-app-interstitial:b',
    })
  })

  it('closes on Continue and says how', async () => {
    const wrapper = await open()

    await wrapper
      .find('[data-testid="app-interstitial-continue"]')
      .trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(appInterstitialOpen.value).toBe(false)
    expect(mockAction).toHaveBeenCalledWith('app_interstitial_dismissed', {
      platform: 'android',
      how: 'continue',
      variant: 'mobile-web-app-interstitial:b',
    })
  })

  it('closes on Escape', async () => {
    const wrapper = await open()

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(mockAction).toHaveBeenCalledWith(
      'app_interstitial_dismissed',
      expect.objectContaining({ how: 'escape' })
    )
  })

  it('keeps Tab inside the page', async () => {
    const wrapper = await open()
    const store = wrapper.find('[data-testid="app-interstitial-store"]')
    const cont = wrapper.find('[data-testid="app-interstitial-continue"]')

    cont.element.focus()
    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(store.element)

    store.element.focus()
    await wrapper
      .find('[role="dialog"]')
      .trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(cont.element)
  })

  it('stops the page behind it scrolling, and lets it go again', async () => {
    const wrapper = await open()
    expect(document.body.style.overflow).toBe('hidden')

    await wrapper
      .find('[data-testid="app-interstitial-continue"]')
      .trigger('click')
    expect(document.body.style.overflow).toBe('')
  })

  it('reflects the platform chosen', () => {
    expect(appInterstitialPlatform.value).toBe(null)
  })
})
