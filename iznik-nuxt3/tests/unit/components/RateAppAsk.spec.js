import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RateAppAsk from '~/components/RateAppAsk.vue'

const { mockMobileStore, mockOpenUrl } = vi.hoisted(() => ({
  mockMobileStore: { isiOS: false },
  mockOpenUrl: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => mockMobileStore,
}))

vi.mock('@capacitor/app-launcher', () => ({
  AppLauncher: { openUrl: mockOpenUrl },
}))

describe('RateAppAsk', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMobileStore.isiOS = false
    window.localStorage.clear()
  })

  function createWrapper() {
    return mount(RateAppAsk, {
      global: {
        stubs: {
          'b-button': {
            template:
              '<button class="b-button" :class="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
            emits: ['click'],
          },
        },
      },
    })
  }

  function findButton(wrapper, text) {
    return wrapper.findAll('.b-button').find((b) => b.text().includes(text))
  }

  describe('rendering', () => {
    it('renders the prompt and both buttons', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Enjoying Freegle?')
      expect(findButton(wrapper, 'Rate now')).toBeDefined()
      expect(findButton(wrapper, "Don't ask again")).toBeDefined()
    })

    it('names the Play Store on Android and the App Store on iOS', () => {
      expect(createWrapper().text()).toContain('Play Store')
      mockMobileStore.isiOS = true
      expect(createWrapper().text()).toContain('App Store')
    })
  })

  describe('rate now', () => {
    it('opens the Android review link and hides', async () => {
      const wrapper = createWrapper()
      await findButton(wrapper, 'Rate now').trigger('click')
      await flushPromises()
      expect(mockOpenUrl).toHaveBeenCalledWith({
        url: 'market://details?id=org.ilovefreegle.direct',
      })
      expect(wrapper.emitted('hide')).toBeTruthy()
      expect(window.localStorage.getItem('rateappnotagain')).toBe('true')
    })

    it('opens the App Store write-review link on iOS', async () => {
      mockMobileStore.isiOS = true
      const wrapper = createWrapper()
      await findButton(wrapper, 'Rate now').trigger('click')
      await flushPromises()
      expect(mockOpenUrl).toHaveBeenCalledWith({
        url: 'https://apps.apple.com/gb/app/freegle/id970045029?action=write-review',
      })
    })

    it('still hides if the launcher throws', async () => {
      mockOpenUrl.mockRejectedValueOnce(new Error('no launcher'))
      const openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
      const wrapper = createWrapper()
      await findButton(wrapper, 'Rate now').trigger('click')
      await flushPromises()
      expect(openSpy).toHaveBeenCalled()
      expect(wrapper.emitted('hide')).toBeTruthy()
    })
  })

  describe("don't ask again", () => {
    it('records the preference and hides without opening the store', async () => {
      const wrapper = createWrapper()
      await findButton(wrapper, "Don't ask again").trigger('click')
      expect(window.localStorage.getItem('rateappnotagain')).toBe('true')
      expect(mockOpenUrl).not.toHaveBeenCalled()
      expect(wrapper.emitted('hide')).toBeTruthy()
    })
  })
})
