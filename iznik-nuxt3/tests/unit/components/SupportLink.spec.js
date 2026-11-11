import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import SupportLink from '~/components/SupportLink.vue'

import { useAuthStore } from '~/stores/auth'

// Mock auth store
vi.mock('~/stores/auth', () => ({
  useAuthStore: vi.fn(() => ({
    user: null,
  })),
}))

describe('SupportLink', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(SupportLink, {
      props,
      global: {
        stubs: {
          'client-only': {
            template: '<span><slot /></span>',
          },
          ExternalLink: {
            template: '<a :href="href" class="external-link"><slot /></a>',
            props: ['href'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders ExternalLink', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.external-link').exists()).toBe(true)
    })

    it('displays default text', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('support@ilovefreegle.org')
    })

    it('displays custom text', () => {
      const wrapper = createWrapper({ text: 'Contact Us' })
      expect(wrapper.text()).toContain('Contact Us')
    })
  })

  describe('href when not logged in', () => {
    beforeEach(() => {
      useAuthStore.mockReturnValue({ user: null })
    })

    it('creates mailto link', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.external-link').attributes('href')).toContain(
        'mailto:support@ilovefreegle.org'
      )
    })

    it('mentions not logged in', () => {
      const wrapper = createWrapper()
      const href = wrapper.find('.external-link').attributes('href')
      // Check for "not logged in" with any URL encoding variation
      expect(href.toLowerCase()).toContain('not')
      expect(href.toLowerCase()).toContain('logged')
    })
  })

  describe('href when logged in', () => {
    beforeEach(() => {
      useAuthStore.mockReturnValue({ user: { id: 12345 } })
    })

    it('creates mailto link with user id', () => {
      const wrapper = createWrapper()
      const href = wrapper.find('.external-link').attributes('href')
      expect(href).toContain('mailto:support@ilovefreegle.org')
      expect(href).toContain('12345')
    })
  })

  describe('custom email', () => {
    beforeEach(() => {
      useAuthStore.mockReturnValue({ user: null })
    })

    it('uses custom email in href', () => {
      const wrapper = createWrapper({ email: 'custom@example.com' })
      expect(wrapper.find('.external-link').attributes('href')).toContain(
        'mailto:custom@example.com'
      )
    })
  })

  describe('page the mail was sent from', () => {
    function body(wrapper) {
      const href = wrapper.find('.external-link').attributes('href')
      return decodeURIComponent(href.split('?body=')[1])
    }

    it('names the page in the note when logged in', () => {
      useAuthStore.mockReturnValue({ user: { id: 12345 } })
      const wrapper = createWrapper({ from: 'the National Reuse Day page' })
      expect(body(wrapper)).toContain(
        'Note to Freegle: this was sent from the National Reuse Day page. This freegler was logged in as user id: #12345.'
      )
    })

    it('names the page in the note when not logged in', () => {
      useAuthStore.mockReturnValue({ user: null })
      const wrapper = createWrapper({ from: 'the National Reuse Day page' })
      expect(body(wrapper)).toContain(
        'Note to Freegle: this was sent from the National Reuse Day page. This freegler was not logged in.'
      )
    })

    it('keeps the support note when no page is given', () => {
      useAuthStore.mockReturnValue({ user: { id: 12345 } })
      const wrapper = createWrapper()
      expect(body(wrapper)).toContain(
        'Note to support: this freegler was logged in as user id: #12345.'
      )
      expect(body(wrapper)).not.toContain('sent from')
    })
  })
})
