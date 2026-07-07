import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import BouncingEmail from '~/components/BouncingEmail.vue'

const { mockMe } = vi.hoisted(() => {
  const { ref } = require('vue')
  return { mockMe: ref(null) }
})

vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

describe('BouncingEmail', () => {
  function createWrapper(meValue = null) {
    mockMe.value = meValue
    return mount(BouncingEmail, {
      global: {
        stubs: {
          'b-row': { template: '<div class="row"><slot /></div>' },
          'b-col': {
            template: '<div class="col"><slot /></div>',
            props: ['cols', 'xl', 'offsetXl'],
          },
          NoticeMessage: {
            template:
              '<div class="notice-message" :class="variant"><slot /></div>',
            props: ['variant', 'modelValue'],
          },
          'v-icon': {
            template: '<i :class="icon" />',
            props: ['icon'],
          },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
        },
      },
    })
  }

  beforeEach(() => {
    mockMe.value = null
  })

  describe('rendering', () => {
    it('renders row and column structure', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.row').exists()).toBe(true)
      expect(wrapper.find('.col').exists()).toBe(true)
    })

    it('renders notice message component when bouncing', () => {
      const wrapper = createWrapper({ bouncing: true })
      expect(wrapper.find('.notice-message').exists()).toBe(true)
    })

    it('does not render notice when not bouncing', () => {
      const wrapper = createWrapper({ bouncing: false })
      expect(wrapper.find('.notice-message').exists()).toBe(false)
    })

    it('applies danger variant to notice', () => {
      const wrapper = createWrapper({ bouncing: true })
      const notice = wrapper.find('.notice-message')
      expect(notice.classes()).toContain('danger')
    })

    it('displays warning message text', () => {
      const wrapper = createWrapper({ bouncing: true })
      expect(wrapper.text()).toContain("can't send to your email")
    })

    it('includes link to settings page', () => {
      const wrapper = createWrapper({ bouncing: true })
      const link = wrapper.find('a[href="/settings"]')
      expect(link.exists()).toBe(true)
      expect(link.text()).toBe('Settings')
    })

    it('shows exclamation-triangle icon', () => {
      const wrapper = createWrapper({ bouncing: true })
      expect(wrapper.find('i.exclamation-triangle').exists()).toBe(true)
    })

    it('mentions fixing or retrying', () => {
      const wrapper = createWrapper({ bouncing: true })
      expect(wrapper.text()).toContain('fix or retry')
    })
  })

  describe('names the bouncing address', () => {
    it('shows the specific bouncing email and not the healthy one', () => {
      const wrapper = createWrapper({
        bouncing: true,
        emails: [
          {
            id: 1,
            email: 'good@example.com',
            ourdomain: false,
            bounced: null,
          },
          {
            id: 2,
            email: 'bad@example.com',
            ourdomain: false,
            bounced: '2026-06-25 10:00:00',
          },
        ],
      })
      expect(wrapper.text()).toContain('bad@example.com')
      expect(wrapper.text()).not.toContain('good@example.com')
      // Singular wording for a single bouncing address.
      expect(wrapper.text()).not.toContain('addresses')
    })

    it('lists multiple bouncing addresses with plural wording', () => {
      const wrapper = createWrapper({
        bouncing: true,
        emails: [
          {
            id: 1,
            email: 'one@example.com',
            ourdomain: false,
            bounced: '2026-06-25 10:00:00',
          },
          {
            id: 2,
            email: 'two@example.com',
            ourdomain: false,
            bounced: '2026-06-25 11:00:00',
          },
        ],
      })
      expect(wrapper.text()).toContain('one@example.com')
      expect(wrapper.text()).toContain('two@example.com')
      expect(wrapper.text()).toContain('email addresses')
    })

    it('never shows internal ourdomain addresses', () => {
      const wrapper = createWrapper({
        bouncing: true,
        emails: [
          {
            id: 1,
            email: 'real@example.com',
            ourdomain: false,
            bounced: '2026-06-25 10:00:00',
          },
          {
            id: 2,
            email: 'internal@users.ilovefreegle.org',
            ourdomain: true,
            bounced: '2026-06-25 10:00:00',
          },
        ],
      })
      expect(wrapper.text()).toContain('real@example.com')
      expect(wrapper.text()).not.toContain('users.ilovefreegle.org')
    })

    it('falls back to generic wording when bouncing but no specific address is known', () => {
      const wrapper = createWrapper({ bouncing: true, emails: [] })
      expect(wrapper.find('.notice-message').exists()).toBe(true)
      expect(wrapper.text()).toContain("can't send to your email address")
      expect(wrapper.text()).toContain('fix or retry')
    })
  })

  describe('conditional rendering', () => {
    it('component exists regardless of me value', () => {
      const wrapper = createWrapper(null)
      expect(wrapper.exists()).toBe(true)
    })

    it('renders with me.bouncing false', () => {
      const wrapper = createWrapper({ bouncing: false })
      expect(wrapper.exists()).toBe(true)
    })

    it('renders with me.bouncing true', () => {
      const wrapper = createWrapper({ bouncing: true })
      expect(wrapper.exists()).toBe(true)
    })
  })
})
