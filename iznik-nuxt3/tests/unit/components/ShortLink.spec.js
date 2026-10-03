import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ShortLink from '~/components/ShortLink.vue'

// Mock shortlink store
const mockShortlinkStore = {
  byId: vi.fn(),
}

vi.mock('~/stores/shortlinks', () => ({
  useShortlinkStore: () => mockShortlinkStore,
}))

describe('ShortLink', () => {
  const mockShortlink = {
    id: 1,
    name: 'test-link',
    url: 'https://example.com/destination',
  }

  beforeEach(() => {
    vi.clearAllMocks()
    mockShortlinkStore.byId.mockReturnValue(mockShortlink)
  })

  function createWrapper(props = {}) {
    return mount(ShortLink, {
      props: {
        id: 1,
        ...props,
      },
      global: {
        stubs: {
          'b-row': { template: '<div class="row"><slot /></div>' },
          'b-col': {
            template: '<div class="col"><slot /></div>',
            props: ['cols'],
          },
          'b-button': {
            template: '<button :to="to"><slot /></button>',
            props: ['variant', 'to'],
          },
          ExternalLink: {
            template: '<a :href="href"><slot /></a>',
            props: ['href'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders when a shortlink is found', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.row').exists()).toBe(true)
    })

    it('does not render when no shortlink is found', () => {
      mockShortlinkStore.byId.mockReturnValue(undefined)
      const wrapper = createWrapper()
      expect(wrapper.find('.row').exists()).toBe(false)
    })

    it('displays the destination URL', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('https://example.com/destination')
    })

    it('does not render a destination link when the shortlink has no url', () => {
      mockShortlinkStore.byId.mockReturnValue({
        ...mockShortlink,
        url: null,
      })
      const wrapper = createWrapper()
      const links = wrapper.findAll('a')
      const urlLink = links.find(
        (l) => l.attributes('href') === 'https://example.com/destination'
      )
      expect(urlLink).toBeFalsy()
    })

    it('displays the freegle.in URL', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('https://freegle.in/test-link')
    })
  })

  describe('props', () => {
    it('accepts nostats as true', () => {
      const wrapper = createWrapper({ nostats: true })
      expect(wrapper.props('nostats')).toBe(true)
    })
  })

  describe('store interaction', () => {
    it('calls shortlinkStore.byId with the id', () => {
      createWrapper({ id: 123 })
      expect(mockShortlinkStore.byId).toHaveBeenCalledWith(123)
    })
  })

  describe('View Stats button', () => {
    it('shows View Stats button when nostats is false', () => {
      const wrapper = createWrapper({ nostats: false })
      expect(wrapper.text()).toContain('View Stats')
    })

    it('hides View Stats button when nostats is true', () => {
      const wrapper = createWrapper({ nostats: true })
      expect(wrapper.text()).not.toContain('View Stats')
    })

    it('View Stats button has correct link', () => {
      const wrapper = createWrapper({ nostats: false })
      const button = wrapper.find('button')
      expect(button.attributes('to')).toBe('/shortlinks/1')
    })
  })

  describe('external links', () => {
    it('links to the destination URL', () => {
      const wrapper = createWrapper()
      const links = wrapper.findAll('a')
      const urlLink = links.find(
        (l) => l.attributes('href') === 'https://example.com/destination'
      )
      expect(urlLink).toBeTruthy()
    })

    it('links to freegle.in shortlink', () => {
      const wrapper = createWrapper()
      const links = wrapper.findAll('a')
      const shortLink = links.find(
        (l) => l.attributes('href') === 'https://freegle.in/test-link'
      )
      expect(shortLink).toBeTruthy()
    })
  })

  describe('edge cases', () => {
    it('handles shortlink with special characters in name', () => {
      mockShortlinkStore.byId.mockReturnValue({
        ...mockShortlink,
        name: 'test-special&chars',
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('https://freegle.in/test-special&chars')
    })
  })
})
