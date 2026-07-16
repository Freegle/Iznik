import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import PayPalGivingFundModal from '~/components/PayPalGivingFundModal.vue'

// Mock useOurModal composable
const mockShow = vi.fn()
const mockHide = vi.fn()
const mockModal = ref({ show: mockShow, hide: mockHide })
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({
    modal: mockModal,
    show: mockShow,
    hide: mockHide,
  }),
}))

const FUNDRAISER_URL = 'https://www.paypal.com/fundraiser/charity/55681'

describe('PayPalGivingFundModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(PayPalGivingFundModal, {
      props,
      global: {
        stubs: {
          'b-modal': {
            template:
              '<div class="modal" :title="title"><slot /><slot name="footer" /></div>',
            props: ['title'],
          },
          'b-button': {
            template:
              '<button :class="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
          },
          ExternalLink: {
            template:
              '<a class="external-link" :href="href" @click="$emit(\'click\')"><slot /></a>',
            props: ['href'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('explains the PayPal Giving Fund favourite charity idea', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('PayPal Giving Fund')
      expect(wrapper.text().toLowerCase()).toContain('favourite')
    })

    it('pre-warns the member to click the heart icon to set the favourite', () => {
      const wrapper = createWrapper()
      // Tell them exactly what to do once PayPal opens: click the heart icon.
      const text = wrapper.text().toLowerCase()
      expect(text).toContain('set freegle')
      expect(text).toContain('heart icon')
    })

    it('renders a No thanks button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('No thanks')
    })

    it('renders a Yes button', () => {
      const wrapper = createWrapper()
      expect(wrapper.text().toLowerCase()).toContain('yes')
    })
  })

  describe('yes action', () => {
    it('links the Yes button to the PayPal Giving Fund fundraiser page', () => {
      const wrapper = createWrapper()
      const link = wrapper.find('a.external-link')
      expect(link.exists()).toBe(true)
      expect(link.attributes('href')).toBe(FUNDRAISER_URL)
    })

    it('closes the modal when the member chooses Yes', async () => {
      const wrapper = createWrapper()
      const link = wrapper.find('a.external-link')
      await link.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })

  describe('no action', () => {
    it('closes the modal when the member clicks No thanks', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const noBtn = buttons.find((b) => b.text() === 'No thanks')
      await noBtn.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })
})
