import { describe, it, expect, vi, beforeEach } from 'vitest'

// Capture what the store receives so tests can assert on it.
const mockMiscStore = { pageTitle: '' }
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

const MOCK_USER_SITE = 'https://www.ilovefreegle.org'
const MOCK_IMAGE_DELIVERY = 'wsrv.nl'

const mockRuntimeConfig = {
  public: {
    USER_SITE: MOCK_USER_SITE,
    IMAGE_DELIVERY: MOCK_IMAGE_DELIVERY,
  },
}

const mockRoute = { fullPath: '/mypage', path: '/mypage' }

function getMeta(result, hid) {
  return result.meta.find((m) => m.hid === hid)
}

function getCanonical(result) {
  return result.link.find((l) => l.rel === 'canonical')
}

describe('buildHead', () => {
  let buildHead

  beforeEach(async () => {
    vi.clearAllMocks()
    mockMiscStore.pageTitle = ''
    vi.resetModules()
    const mod = await import('~/composables/useBuildHead')
    buildHead = mod.buildHead
  })

  describe('title', () => {
    it('sets title field in returned object', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'My Title',
        'My description'
      )
      expect(result.title).toBe('My Title')
    })

    it('stores title in miscStore.pageTitle', () => {
      buildHead(mockRoute, mockRuntimeConfig, 'Stored Title', 'desc')
      expect(mockMiscStore.pageTitle).toBe('Stored Title')
    })
  })

  describe('meta tags', () => {
    it('includes description meta tag', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'Title',
        'My description'
      )
      expect(getMeta(result, 'description').content).toBe('My description')
    })

    it('includes og:title and og:description', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'OG Title',
        'OG desc'
      )
      expect(getMeta(result, 'og:title').content).toBe('OG Title')
      expect(getMeta(result, 'og:description').content).toBe('OG desc')
    })

    it('includes twitter:title and twitter:description', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'TW Title',
        'TW desc'
      )
      expect(getMeta(result, 'twitter:title').content).toBe('TW Title')
      expect(getMeta(result, 'twitter:description').content).toBe('TW desc')
    })

    it('sets og:url to USER_SITE + route path', () => {
      const result = buildHead(
        { fullPath: '/groups/testgroup', path: '/groups/testgroup' },
        mockRuntimeConfig,
        'T',
        'D'
      )
      expect(getMeta(result, 'og:url').content).toBe(
        `${MOCK_USER_SITE}/groups/testgroup`
      )
    })

    it('sets og:url to just USER_SITE when route is null', () => {
      const result = buildHead(null, mockRuntimeConfig, 'T', 'D')
      expect(getMeta(result, 'og:url').content).toBe(MOCK_USER_SITE)
    })

    it('drops tracking params from og:url so arrivals do not fragment the signal', () => {
      const result = buildHead(
        { fullPath: '/message/1?src=digest', path: '/message/1' },
        mockRuntimeConfig,
        'T',
        'D'
      )
      expect(getMeta(result, 'og:url').content).toBe(
        `${MOCK_USER_SITE}/message/1`
      )
    })

    it('does not emit og:type unless asked, so the global default stands', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      expect(getMeta(result, 'og:type')).toBeUndefined()
    })

    it('emits og:type when given one', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'D',
        null,
        {},
        { ogType: 'product' }
      )
      expect(getMeta(result, 'og:type').content).toBe('product')
    })

    it('does not emit a robots tag unless asked', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      expect(getMeta(result, 'robots')).toBeUndefined()
    })

    it('emits noindex when asked, keeping the other meta tags intact', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'My description',
        null,
        {},
        { noindex: true }
      )
      expect(getMeta(result, 'robots').content).toBe('noindex, follow')
      /* Regression: setting robots used to mean replacing head.meta wholesale at the
      call site, which threw away the description and every og:/twitter: tag. */
      expect(getMeta(result, 'description').content).toBe('My description')
      expect(getMeta(result, 'og:title').content).toBe('T')
    })
  })

  describe('canonical', () => {
    it('emits a canonical link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      expect(getCanonical(result).href).toBe(`${MOCK_USER_SITE}/mypage`)
    })

    it('strips query strings from the canonical', () => {
      const result = buildHead(
        { fullPath: '/message/1?src=email&foo=bar', path: '/message/1' },
        mockRuntimeConfig,
        'T',
        'D'
      )
      expect(getCanonical(result).href).toBe(`${MOCK_USER_SITE}/message/1`)
    })

    it('falls back to splitting fullPath when the route has no path', () => {
      const result = buildHead(
        { fullPath: '/message/1?src=email' },
        mockRuntimeConfig,
        'T',
        'D'
      )
      expect(getCanonical(result).href).toBe(`${MOCK_USER_SITE}/message/1`)
    })

    it('honours an explicit canonical path', () => {
      const result = buildHead(
        { fullPath: '/explore/Group/12345', path: '/explore/Group/12345' },
        mockRuntimeConfig,
        'T',
        'D',
        null,
        {},
        { canonical: '/explore/Group' }
      )
      expect(getCanonical(result).href).toBe(`${MOCK_USER_SITE}/explore/Group`)
    })

    it('honours an explicit absolute canonical', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'D',
        null,
        {},
        { canonical: 'https://elsewhere.example/page' }
      )
      expect(getCanonical(result).href).toBe('https://elsewhere.example/page')
    })

    it('canonical and og:url always agree', () => {
      const result = buildHead(
        { fullPath: '/message/1?src=x', path: '/message/1' },
        mockRuntimeConfig,
        'T',
        'D',
        null,
        {},
        { canonical: '/message/1' }
      )
      expect(getCanonical(result).href).toBe(getMeta(result, 'og:url').content)
    })
  })

  describe('description cleaning', () => {
    it('strips the HTML that group descriptions arrive wrapped in', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        '<p><strong>Welcome to Northampton Freegle</strong>.</p><p>Please join.</p>'
      )
      expect(getMeta(result, 'description').content).toBe(
        'Welcome to Northampton Freegle . Please join.'
      )
    })

    it('decodes the entities the WYSIWYG leaves behind', () => {
      const result = buildHead(
        mockRuntimeConfig && mockRoute,
        mockRuntimeConfig,
        'T',
        'your&nbsp;old stuff&nbsp;useful &amp; free'
      )
      expect(getMeta(result, 'description').content).toBe(
        'your old stuff useful & free'
      )
    })

    it('leaves non-HTML angle brackets alone', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'Description with <angle> brackets'
      )
      expect(getMeta(result, 'description').content).toBe(
        'Description with <angle> brackets'
      )
    })

    it('truncates long descriptions at a word boundary', () => {
      const long = 'word '.repeat(100).trim()
      const content = getMeta(
        buildHead(mockRoute, mockRuntimeConfig, 'T', long),
        'description'
      ).content
      expect(content.length).toBeLessThanOrEqual(163)
      expect(content.endsWith('...')).toBe(true)
      expect(content).not.toMatch(/wo\.\.\.$/)
    })

    it('applies the same cleaning to og: and twitter: descriptions', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        '<p>Hello</p>'
      )
      expect(getMeta(result, 'og:description').content).toBe('Hello')
      expect(getMeta(result, 'twitter:description').content).toBe('Hello')
    })

    it('includes msapplication and theme-color meta tags', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const tileColor = result.meta.find(
        (m) => m.name === 'msapplication-TileColor'
      )
      const themeColor = result.meta.find((m) => m.name === 'theme-color')
      expect(tileColor?.content).toBe('#ffffff')
      expect(themeColor?.content).toBe('#ffffff')
    })
  })

  describe('link tags', () => {
    it('includes apple-touch-icon link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const appleIcon = result.link.find((l) => l.rel === 'apple-touch-icon')
      expect(appleIcon).toBeDefined()
      expect(appleIcon.href).toContain('apple-touch-icon')
    })

    it('includes 32x32 favicon link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const fav = result.link.find((l) => l.sizes === '32x32')
      expect(fav).toBeDefined()
      expect(fav.href).toContain('favicon-32x32')
    })

    it('includes 16x16 favicon link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const fav = result.link.find((l) => l.sizes === '16x16')
      expect(fav).toBeDefined()
      expect(fav.href).toContain('favicon-16x16')
    })

    it('includes shortcut icon link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const sc = result.link.find((l) => l.rel === 'shortcut icon')
      expect(sc).toBeDefined()
      expect(sc.href).toContain('favicon.ico')
    })

    it('includes safari-pinned-tab link', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      const safari = result.link.find((l) =>
        l.href?.includes('safari-pinned-tab')
      )
      expect(safari).toBeDefined()
    })
  })

  describe('bodyAttrs', () => {
    it('defaults bodyAttrs to empty object', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      expect(result.bodyAttrs).toEqual({})
    })

    it('passes through custom bodyAttrs', () => {
      const customAttrs = { class: 'no-scroll', 'data-theme': 'dark' }
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'D',
        null,
        customAttrs
      )
      expect(result.bodyAttrs).toEqual(customAttrs)
    })
  })

  describe('image handling', () => {
    it('uses USER_SITE icon.png when image is null', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', null)
      expect(getMeta(result, 'og:image').content).toBe(
        `${MOCK_USER_SITE}/icon.png`
      )
      expect(getMeta(result, 'twitter:image').content).toBe(
        `${MOCK_USER_SITE}/icon.png`
      )
    })

    it('uses USER_SITE icon.png when image is not provided', () => {
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D')
      expect(getMeta(result, 'og:image').content).toBe(
        `${MOCK_USER_SITE}/icon.png`
      )
    })

    it('passes plain image URL through unchanged when no = sign', () => {
      const imageUrl = 'https://example.com/photo.jpg'
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', imageUrl)
      expect(getMeta(result, 'og:image').content).toBe(imageUrl)
    })

    it('extracts and decodes the original URL from an IMAGE_DELIVERY proxy URL', () => {
      // The proxy wraps original URLs as: wsrv.nl/?url=<encoded-original>
      const originalUrl = 'https://original.example.com/photo.jpg'
      const proxyUrl = `https://${MOCK_IMAGE_DELIVERY}/?url=${encodeURIComponent(
        originalUrl
      )}`
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', proxyUrl)
      expect(getMeta(result, 'og:image').content).toBe(originalUrl)
    })

    it('strips literal ? query params from the extracted URL', () => {
      // Original URL has transform params: photo.jpg?w=800&h=600
      const originalPath = 'https://original.example.com/photo.jpg'
      const proxyUrl = `https://${MOCK_IMAGE_DELIVERY}/?url=${originalPath}?w=800&h=600`
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', proxyUrl)
      // After the first = strip we get: `${originalPath}?w=800&h=600`
      // Then ? strip gives us: `${originalPath}`
      expect(getMeta(result, 'og:image').content).toBe(originalPath)
    })

    it('strips literal & params from the extracted URL when no ? is present', () => {
      const originalPath = 'https://original.example.com/photo.jpg'
      const proxyUrl = `https://${MOCK_IMAGE_DELIVERY}/?url=${originalPath}&w=800`
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', proxyUrl)
      // After = strip: `${originalPath}&w=800`, then & strip: `${originalPath}`
      expect(getMeta(result, 'og:image').content).toBe(originalPath)
    })

    // Was a latent bug: the condition evaluated `includes(DELIVERY) + '/?url='`,
    // a string concatenation and so always truthy, meaning any image URL with an
    // '=' in it got sliced at the first '=' and corrupted.
    it('leaves non-IMAGE_DELIVERY URLs with = signs intact', () => {
      const imageUrl = 'https://otherprovider.com/photos?format=jpg'
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', imageUrl)
      expect(getMeta(result, 'og:image').content).toBe(imageUrl)
    })

    it('unwraps proxy URLs written without a slash before the query', () => {
      // This is the form the API actually returns for attachments.
      const proxyUrl = `https://${MOCK_IMAGE_DELIVERY}?url=https://uploads.ilovefreegle.org/abc&ro=0`
      const result = buildHead(mockRoute, mockRuntimeConfig, 'T', 'D', proxyUrl)
      expect(getMeta(result, 'og:image').content).toBe(
        'https://uploads.ilovefreegle.org/abc'
      )
    })

    it('drops the internal :8080 port, which crawlers are wary of', () => {
      const result = buildHead(
        mockRoute,
        mockRuntimeConfig,
        'T',
        'D',
        'https://uploads.ilovefreegle.org:8080/abc'
      )
      expect(getMeta(result, 'og:image').content).toBe(
        'https://uploads.ilovefreegle.org/abc'
      )
    })
  })

  describe('seoDescription', () => {
    let seoDescription

    beforeEach(async () => {
      const mod = await import('~/composables/useBuildHead')
      seoDescription = mod.seoDescription
    })

    it('returns empty for nothing', () => {
      expect(seoDescription(null)).toBe('')
      expect(seoDescription('')).toBe('')
      expect(seoDescription(undefined)).toBe('')
    })

    it('collapses runaway whitespace and newlines', () => {
      expect(seoDescription('one\n\n  two\t three')).toBe('one two three')
    })

    it('strips block and inline HTML', () => {
      expect(
        seoDescription('<div><p>Hello <strong>there</strong></p></div>')
      ).toBe('Hello there')
    })

    it('strips self-closing and attributed tags', () => {
      expect(seoDescription('a<br/>b<a href="x" rel="y">c</a>')).toBe('a b c')
    })

    it('respects a custom max length', () => {
      expect(seoDescription('one two three four five', 12)).toBe('one two...')
    })

    it('leaves text at exactly the limit untouched', () => {
      const exact = 'a'.repeat(160)
      expect(seoDescription(exact)).toBe(exact)
    })
  })

  describe('table-driven: title/description permutations', () => {
    const cases = [
      { title: 'Short', description: 'Brief' },
      {
        title: 'A very long page title that goes on and on',
        description:
          'Detailed description for SEO purposes with lots of content',
      },
      { title: '', description: '' },
      {
        title: 'Title with "quotes"',
        description: 'Description with <angle> brackets',
      },
    ]

    for (const { title, description } of cases) {
      it(`mirrors title="${title.slice(
        0,
        20
      )}" into all title meta tags`, () => {
        const result = buildHead(
          mockRoute,
          mockRuntimeConfig,
          title,
          description
        )
        expect(result.title).toBe(title)
        expect(getMeta(result, 'og:title').content).toBe(title)
        expect(getMeta(result, 'twitter:title').content).toBe(title)
        expect(getMeta(result, 'description').content).toBe(description)
        expect(getMeta(result, 'og:description').content).toBe(description)
        expect(getMeta(result, 'twitter:description').content).toBe(description)
      })
    }
  })
})
