import { describe, it, expect } from 'vitest'
import {
  SITEMAP_CHUNK_SIZE,
  chunk,
  groupLinks,
  messageLinks,
  renderSitemapIndex,
  renderUrlset,
  staticLinks,
} from '~/server/utils/sitemap'

const SITE = 'https://www.ilovefreegle.org'

describe('sitemap', () => {
  describe('staticLinks', () => {
    it('includes the landing pages', () => {
      const urls = staticLinks().map((l) => l.url)
      expect(urls).toContain('/')
      expect(urls).toContain('/give')
      expect(urls).toContain('/find')
      expect(urls).toContain('/explore')
    })

    it('includes a page per comparison competitor', () => {
      const urls = staticLinks().map((l) => l.url)
      const comparisons = urls.filter((u) => u.startsWith('/compare/'))
      expect(comparisons.length).toBeGreaterThan(0)
    })

    it('gives every link a priority and a changefreq', () => {
      for (const link of staticLinks()) {
        expect(link.priority).toBeDefined()
        expect(link.changefreq).toBeDefined()
      }
    })
  })

  describe('groupLinks', () => {
    it('maps groups to explore URLs', () => {
      const links = groupLinks([
        { nameshort: 'Northampton-Freegle' },
        { nameshort: 'EdinburghFreegle' },
      ])
      expect(links.map((l) => l.url)).toEqual([
        '/explore/Northampton-Freegle',
        '/explore/EdinburghFreegle',
      ])
    })

    it('skips groups with no short name rather than emitting /explore/undefined', () => {
      const links = groupLinks([{ nameshort: 'Good' }, { id: 1 }, null])
      expect(links).toHaveLength(1)
      expect(links[0].url).toBe('/explore/Good')
    })

    it('copes with no groups at all', () => {
      expect(groupLinks(null)).toEqual([])
      expect(groupLinks([])).toEqual([])
    })
  })

  describe('messageLinks', () => {
    it('maps posts to message URLs', () => {
      const links = messageLinks([{ id: 121002880 }, { id: 121054975 }])
      expect(links.map((l) => l.url)).toEqual([
        '/message/121002880',
        '/message/121054975',
      ])
    })

    it('carries lastmod through as an ISO timestamp', () => {
      const links = messageLinks([{ id: 1, lastmod: '2026-07-17T17:15:33Z' }])
      expect(links[0].lastmod).toBe('2026-07-17T17:15:33.000Z')
    })

    it('omits lastmod when the API did not give us one', () => {
      const links = messageLinks([{ id: 1 }])
      expect(links[0].lastmod).toBeUndefined()
    })

    it('skips entries with no id', () => {
      const links = messageLinks([{ id: 1 }, {}, null])
      expect(links).toHaveLength(1)
    })

    it('copes with no posts at all', () => {
      expect(messageLinks(null)).toEqual([])
    })
  })

  describe('chunk', () => {
    it('splits a list into files below the 50,000 URL sitemap limit', () => {
      expect(SITEMAP_CHUNK_SIZE).toBeLessThanOrEqual(50000)
    })

    it('returns one chunk when everything fits', () => {
      expect(chunk([1, 2, 3], 10)).toEqual([[1, 2, 3]])
    })

    it('splits evenly divisible lists without a trailing empty chunk', () => {
      expect(chunk([1, 2, 3, 4], 2)).toEqual([
        [1, 2],
        [3, 4],
      ])
    })

    it('puts the remainder in a final short chunk', () => {
      expect(chunk([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]])
    })

    it('returns no chunks for an empty list, so we declare no post sitemaps', () => {
      expect(chunk([], 10)).toEqual([])
    })
  })

  describe('renderUrlset', () => {
    it('produces a valid urlset with absolute locations', () => {
      const xml = renderUrlset([{ url: '/message/1' }], SITE)
      expect(xml).toContain('<?xml version="1.0" encoding="UTF-8"?>')
      expect(xml).toContain(
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
      )
      expect(xml).toContain('<loc>https://www.ilovefreegle.org/message/1</loc>')
      expect(xml).toContain('</urlset>')
    })

    it('emits lastmod, changefreq and priority when given', () => {
      const xml = renderUrlset(
        [
          {
            url: '/message/1',
            lastmod: '2026-07-17T17:15:33.000Z',
            changefreq: 'hourly',
            priority: 0.7,
          },
        ],
        SITE
      )
      expect(xml).toContain('<lastmod>2026-07-17T17:15:33.000Z</lastmod>')
      expect(xml).toContain('<changefreq>hourly</changefreq>')
      expect(xml).toContain('<priority>0.7</priority>')
    })

    it('leaves out optional elements rather than emitting empty ones', () => {
      const xml = renderUrlset([{ url: '/give' }], SITE)
      expect(xml).not.toContain('<lastmod>')
      expect(xml).not.toContain('<changefreq>')
      expect(xml).not.toContain('<priority>')
    })

    it('escapes ampersands so the XML stays well formed', () => {
      const xml = renderUrlset([{ url: '/explore/Bath&NE' }], SITE)
      expect(xml).toContain('Bath&amp;NE')
      expect(xml).not.toMatch(/Bath&NE/)
    })
  })

  describe('renderSitemapIndex', () => {
    it('produces a sitemapindex listing each child', () => {
      const xml = renderSitemapIndex([
        SITE + '/sitemap-pages.xml',
        SITE + '/sitemap-posts/0',
      ])
      expect(xml).toContain(
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
      )
      expect(xml).toContain(
        '<loc>https://www.ilovefreegle.org/sitemap-pages.xml</loc>'
      )
      expect(xml).toContain(
        '<loc>https://www.ilovefreegle.org/sitemap-posts/0</loc>'
      )
    })

    it('stamps lastmod on each child when given one', () => {
      const xml = renderSitemapIndex(
        [SITE + '/sitemap-pages.xml'],
        '2026-07-27T00:00:00.000Z'
      )
      expect(xml).toContain('<lastmod>2026-07-27T00:00:00.000Z</lastmod>')
    })
  })

  describe('end to end shape', () => {
    it('a full post sitemap round-trips ids into locations', () => {
      const posts = Array.from({ length: 5 }, (_, i) => ({
        id: 100 + i,
        lastmod: '2026-07-17T17:15:33Z',
      }))
      const xml = renderUrlset(messageLinks(posts), SITE)
      const locs = [...xml.matchAll(/<loc>([^<]*)<\/loc>/g)].map((m) => m[1])
      expect(locs).toEqual([
        SITE + '/message/100',
        SITE + '/message/101',
        SITE + '/message/102',
        SITE + '/message/103',
        SITE + '/message/104',
      ])
    })
  })
})
