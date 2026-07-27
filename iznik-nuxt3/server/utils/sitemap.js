import { comparisonList } from '../../constants/comparisons'

/* One sitemap file may hold at most 50,000 URLs. We chunk well below that so a
surge in posts can't tip us over, and so each file stays quick to generate. */
export const SITEMAP_CHUNK_SIZE = 20000

/**
 * The pages that exist regardless of what's been posted: landing pages, policy
 * pages, and the "Freegle vs ..." comparisons.
 */
export function staticLinks() {
  const links = [
    { url: '/', changefreq: 'daily', priority: 1.0 },
    { url: '/give', changefreq: 'monthly', priority: 1.0 },
    { url: '/find', changefreq: 'monthly', priority: 1.0 },
    { url: '/explore', changefreq: 'daily', priority: 0.8 },
    { url: '/unsubscribe', changefreq: 'monthly', priority: 0.7 },
    { url: '/donate', changefreq: 'monthly', priority: 0.7 },
    { url: '/help', changefreq: 'monthly', priority: 0.5 },
    { url: '/stories', changefreq: 'weekly', priority: 0.5 },
    { url: '/communityevents', changefreq: 'monthly', priority: 0.1 },
    { url: '/volunteerings', changefreq: 'monthly', priority: 0.1 },
    { url: '/mobile', changefreq: 'monthly', priority: 0.3 },
    { url: '/about', changefreq: 'monthly', priority: 0.3 },
    { url: '/compare', changefreq: 'monthly', priority: 0.5 },
    { url: '/disclaimer', changefreq: 'monthly', priority: 0.1 },
    { url: '/terms', changefreq: 'monthly', priority: 0.1 },
    { url: '/privacy', changefreq: 'monthly', priority: 0.1 },
    { url: '/forgot', changefreq: 'monthly', priority: 0.1 },
    { url: '/giftaid', changefreq: 'monthly', priority: 0.1 },
    { url: '/stories/summary', changefreq: 'weekly', priority: 0.1 },
  ]

  // The "Freegle vs ..." comparison pages, driven off the same data as the pages
  // themselves so the two can't drift apart.
  comparisonList.forEach((comparison) => {
    links.push({
      url: '/compare/' + comparison.slug,
      changefreq: 'monthly',
      priority: 0.5,
    })
  })

  return links
}

/**
 * One entry per community. These change every time someone posts, hence daily.
 */
export function groupLinks(groups) {
  return (groups || [])
    .filter((group) => group?.nameshort)
    .map((group) => ({
      url: '/explore/' + group.nameshort,
      changefreq: 'daily',
      priority: 0.8,
    }))
}

/**
 * One entry per live post.
 *
 * This is the change that matters most: until now the sitemap listed only the 496
 * community pages and 26 static pages, so nothing ever told Google that an individual
 * post existed. Trash Nothing publish every post, which is why their copy of a
 * Freegle post outranks ours.
 *
 * `changefreq: hourly` is honest here - a post can be taken at any moment, at which
 * point the URL starts answering 410 and we want it re-crawled promptly.
 */
export function messageLinks(messages) {
  return (messages || [])
    .filter((m) => m?.id)
    .map((m) => ({
      url: '/message/' + m.id,
      changefreq: 'hourly',
      priority: 0.7,
      lastmod: m.lastmod ? new Date(m.lastmod).toISOString() : undefined,
    }))
}

export function chunk(items, size = SITEMAP_CHUNK_SIZE) {
  if (!items.length) {
    return []
  }

  const ret = []

  for (let i = 0; i < items.length; i += size) {
    ret.push(items.slice(i, i + size))
  }

  return ret
}

function escapeXml(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

export function renderUrlset(links, hostname) {
  const body = links
    .map((l) => {
      const parts = ['    <loc>' + escapeXml(hostname + l.url) + '</loc>']

      if (l.lastmod) {
        parts.push('    <lastmod>' + l.lastmod + '</lastmod>')
      }

      if (l.changefreq) {
        parts.push('    <changefreq>' + l.changefreq + '</changefreq>')
      }

      if (l.priority !== undefined) {
        parts.push('    <priority>' + l.priority.toFixed(1) + '</priority>')
      }

      return '  <url>\n' + parts.join('\n') + '\n  </url>'
    })
    .join('\n')

  return (
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    body +
    '\n</urlset>\n'
  )
}

export function renderSitemapIndex(urls, lastmod) {
  const body = urls
    .map((u) => {
      const parts = ['    <loc>' + escapeXml(u) + '</loc>']

      if (lastmod) {
        parts.push('    <lastmod>' + lastmod + '</lastmod>')
      }

      return '  <sitemap>\n' + parts.join('\n') + '\n  </sitemap>'
    })
    .join('\n')

  return (
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    body +
    '\n</sitemapindex>\n'
  )
}
