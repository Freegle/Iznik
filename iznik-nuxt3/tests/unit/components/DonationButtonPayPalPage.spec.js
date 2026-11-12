import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, Suspense } from 'vue'
import DonationButton from '~/components/DonationButton.vue'

// The real component, not a copy: PayPal's pop-up cannot open in the app's
// WebView or in an in-app browser, so there the button must open PayPal's
// donate page instead of PayPal's script.

const { mockLoadScript, mockMobileStore } = vi.hoisted(() => ({
  mockLoadScript: vi.fn().mockResolvedValue(undefined),
  mockMobileStore: { isApp: false },
}))

vi.mock('vue-plugin-load-script', () => ({ loadScript: mockLoadScript }))
vi.mock('~/composables/useId', () => ({ uid: (prefix) => `${prefix}test` }))
vi.mock('#app', () => ({
  useNuxtApp: () => ({ $gtm: { enabled: () => false, trackEvent: vi.fn() } }),
}))
vi.mock('~/stores/mobile', () => ({ useMobileStore: () => mockMobileStore }))
vi.mock('~/components/PayPalGivingFundModal', () => ({
  default: { name: 'PayPalGivingFundModal', template: '<div />' },
}))

const desktopChrome =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36'
const googleAppOnIPad =
  'Mozilla/5.0 (iPad; CPU OS 26_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/444.2.1001595231 Mobile/15E148 Safari/604.1'

function setUserAgent(userAgent) {
  Object.defineProperty(window.navigator, 'userAgent', {
    value: userAgent,
    configurable: true,
  })
}

async function mountButton(props = { value: '2' }) {
  const wrapper = mount(
    defineComponent({
      render: () =>
        h(Suspense, null, { default: () => h(DonationButton, props) }),
    }),
    {
      attachTo: document.body,
      global: {
        stubs: {
          // BButton declares its click event, so the parent's listener is
          // not also attached to the element.
          'b-button': {
            emits: ['click'],
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
        },
      },
    }
  )
  await flushPromises()
  return wrapper
}

describe('DonationButton where PayPal cannot open its pop-up', () => {
  let paypalButton
  let paypalClicked

  beforeEach(() => {
    mockLoadScript.mockClear()
    paypalClicked = vi.fn()
    mockMobileStore.isApp = false
    setUserAgent(desktopChrome)
    // PayPal's script draws its own image into the container.
    paypalButton = vi.fn(() => ({
      render: (selector) => {
        const img = document.createElement('img')
        img.id = 'donate-button'
        img.onclick = paypalClicked
        document.querySelector(selector).appendChild(img)
      },
    }))
    window.PayPal = { Donation: { Button: paypalButton } }
    vi.spyOn(window, 'open').mockImplementation(() => null)
  })

  afterEach(() => {
    delete window.navigator.userAgent
    delete window.PayPal
    vi.restoreAllMocks()
  })

  it('opens PayPal donate page from the app, without PayPal script', async () => {
    mockMobileStore.isApp = true
    const wrapper = await mountButton()

    expect(mockLoadScript).not.toHaveBeenCalled()
    expect(paypalButton).not.toHaveBeenCalled()

    const img = wrapper.find('img')
    expect(img.attributes('alt')).toBe('Donate £2 with PayPal')
    await img.trigger('click')

    expect(window.open).toHaveBeenCalledTimes(1)
    expect(window.open).toHaveBeenCalledWith(
      'https://www.paypal.com/donate/?hosted_button_id=S3RKX5JHQUUL4',
      '_blank'
    )
  })

  it("does the same in the Google app's in-app browser", async () => {
    setUserAgent(googleAppOnIPad)
    const wrapper = await mountButton()

    expect(paypalButton).not.toHaveBeenCalled()
    await wrapper.find('button').trigger('click')

    expect(window.open).toHaveBeenCalledWith(
      'https://www.paypal.com/donate/?hosted_button_id=S3RKX5JHQUUL4',
      '_blank'
    )
  })

  it('uses PayPal script in an ordinary browser', async () => {
    const wrapper = await mountButton()

    expect(mockLoadScript).toHaveBeenCalledWith(
      'https://www.paypalobjects.com/donate/sdk/donate-sdk.js'
    )
    expect(paypalButton).toHaveBeenCalledWith(
      expect.objectContaining({ hosted_button_id: 'S3RKX5JHQUUL4' })
    )
    expect(wrapper.find('img#donate-button').exists()).toBe(true)

    // Our button passes the click to PayPal's image.
    await wrapper.find('button').trigger('click')
    expect(paypalClicked).toHaveBeenCalledTimes(1)

    // A tap on PayPal's own image reaches PayPal once, not again via our button.
    await wrapper.find('img#donate-button').trigger('click')
    expect(paypalClicked).toHaveBeenCalledTimes(2)
    expect(window.open).not.toHaveBeenCalled()
  })
})
