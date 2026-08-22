import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ProxyImage from '~/components/ProxyImage.vue'

// Mock Sentry
vi.mock('@sentry/browser', () => ({
  captureMessage: vi.fn(),
}))

describe('ProxyImage', () => {
  function createWrapper(props = {}) {
    return mount(ProxyImage, {
      props: {
        src: '/test/image.jpg',
        ...props,
      },
      global: {
        stubs: {
          NuxtPicture: {
            template:
              '<span><img :src="src" :alt="alt" :width="width" :height="height" :loading="loading" :placeholder="placeholder" @error="$emit(\'error\', $event)" /></span>',
            props: [
              'src',
              'alt',
              'format',
              'fit',
              'preload',
              'provider',
              'modifiers',
              'width',
              'height',
              'loading',
              'sizes',
              'placeholder',
            ],
            emits: ['error'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders an img element', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('img').exists()).toBe(true)
    })

    it('sets src attribute', () => {
      const wrapper = createWrapper({ src: '/my/photo.png' })
      expect(wrapper.find('img').attributes('src')).toBe('/my/photo.png')
    })

    it('sets alt attribute', () => {
      const wrapper = createWrapper({ alt: 'Test image' })
      expect(wrapper.find('img').attributes('alt')).toBe('Test image')
    })

    it('sets width attribute', () => {
      const wrapper = createWrapper({ width: 200 })
      expect(wrapper.find('img').attributes('width')).toBe('200')
    })

    it('sets height attribute', () => {
      const wrapper = createWrapper({ height: 150 })
      expect(wrapper.find('img').attributes('height')).toBe('150')
    })

    it('sets placeholder attribute', () => {
      const wrapper = createWrapper({ placeholder: 'blur' })
      expect(wrapper.find('img').attributes('placeholder')).toBe('blur')
    })
  })

  describe('className prop', () => {
    it('applies className to wrapper span', () => {
      const wrapper = createWrapper({ className: 'custom-class' })
      expect(wrapper.find('span').classes()).toContain('custom-class')
    })

    it('handles null className', () => {
      const wrapper = createWrapper({ className: null })
      expect(wrapper.find('span').exists()).toBe(true)
    })
  })

  describe('error handling', () => {
    it('emits error event on broken image', async () => {
      const wrapper = createWrapper()
      await wrapper.find('img').trigger('error')
      expect(wrapper.emitted('error')).toBeTruthy()
    })
  })

  describe('generic broken-image placeholder (gimg_0.jpg)', () => {
    // This branch only runs client-side and only for the specific
    // gimg_0.jpg placeholder src, so nothing exercises it unless a test
    // deliberately sets both up - which meant its Playwright/e2e coverage
    // depended on incidental test data (some rendered image's src happening
    // to contain "gimg_0.jpg") and flipped covered/uncovered run to run,
    // repeatedly tripping Coveralls' "coverage decreased" check on unrelated
    // PRs. Covering it deterministically here (see the matching v8-ignore in
    // ProxyImage.vue) removes that source of jitter.
    const originalClient = import.meta.client

    afterEach(() => {
      import.meta.client = originalClient
    })

    it('reports to Sentry when src is the generic broken-image placeholder', async () => {
      import.meta.client = true
      const Sentry = await import('@sentry/browser')
      Sentry.captureMessage.mockClear()

      createWrapper({ src: '/uploads/gimg_0.jpg' })

      await vi.waitFor(() =>
        expect(Sentry.captureMessage).toHaveBeenCalledWith(
          'Broken image: /uploads/gimg_0.jpg'
        )
      )
    })

    it('does not report to Sentry for a normal image src', async () => {
      import.meta.client = true
      const Sentry = await import('@sentry/browser')
      Sentry.captureMessage.mockClear()

      createWrapper({ src: '/uploads/photo.jpg' })

      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(Sentry.captureMessage).not.toHaveBeenCalled()
    })

    // (The server-side no-report path is compile-time dead here: the unit-test
    // transform substitutes import.meta.client to true, matching the client
    // build, so it cannot be exercised from this suite.)
  })

  describe('props', () => {
    it('requires src prop', () => {
      const wrapper = createWrapper({ src: '/required/image.jpg' })
      expect(wrapper.props('src')).toBe('/required/image.jpg')
    })

    it('defaults modifiers to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('modifiers')).toBe(null)
    })

    it('defaults preload to false', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('preload')).toBe(false)
    })

    it('defaults loading to lazy', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('loading')).toBe('lazy')
    })

    it('defaults className to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('className')).toBe(null)
    })

    it('defaults fluid to false', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('fluid')).toBe(false)
    })

    it('defaults fit to inside', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('fit')).toBe('inside')
    })

    it('defaults format to webp', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('format')).toBe('webp')
    })

    it('defaults alt to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('alt')).toBe(null)
    })

    it('defaults width to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('width')).toBe(null)
    })

    it('defaults height to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('height')).toBe(null)
    })

    it('defaults sizes to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('sizes')).toBe(null)
    })

    it('defaults placeholder to null', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('placeholder')).toBe(null)
    })
  })

  // A member's avatar is a Gravatar URL carrying ?s=200&d=identicon&r=g. Those
  // parameters used to be percent-encoded here before being handed to the
  // provider, and the escaping survived to Gravatar itself, which then saw no
  // d= and served its own logo - a blue disc with a white ring. Every member
  // without a Gravatar account got that same picture in place of their own
  // identicon, and reported it as their avatar having changed.
  describe('source URLs that carry a query string', () => {
    it('hands the query to the provider exactly as given', () => {
      const src =
        'https://www.gravatar.com/avatar/992d65128?s=200&d=identicon&r=g'
      const wrapper = createWrapper({ src })

      expect(wrapper.find('img').attributes('src')).toBe(src)
    })

    // The provider encodes the whole URL once when it builds ?url=, which is
    // what both keeps these separators away from wsrv and delivers them intact
    // to the origin. Escaping them here as well is what corrupted them.
    it('leaves the separators the origin needs unescaped', () => {
      const wrapper = createWrapper({
        src: 'https://example.com/photo.jpg?w=1&h=2',
      })
      const rendered = wrapper.find('img').attributes('src')

      expect(rendered).not.toContain('%3D')
      expect(rendered).not.toContain('%26')
    })
  })
})
