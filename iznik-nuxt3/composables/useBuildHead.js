import { useMiscStore } from '~/stores/misc'

/* The tags our WYSIWYG group descriptions actually contain. We strip only these,
rather than anything shaped like <word>, so that item descriptions mentioning e.g.
"fits <angle> brackets" survive intact. */
const HTML_TAGS =
  'p|br|strong|b|em|i|u|s|div|span|a|ul|ol|li|dl|dt|dd|h[1-6]|blockquote|img|hr|pre|code|table|thead|tbody|tfoot|tr|td|th|font|small|sub|sup'

const TAG_RE = new RegExp(`</?(?:${HTML_TAGS})(?:\\s[^>]*)?/?>`, 'gi')

const ENTITIES = {
  '&nbsp;': ' ',
  '&amp;': '&',
  '&lt;': '<',
  '&gt;': '>',
  '&quot;': '"',
  '&#39;': "'",
  '&apos;': "'",
  '&hellip;': '...',
  '&ndash;': '-',
  '&mdash;': '-',
  '&pound;': '£',
}

/**
 * Turn arbitrary text (a group's HTML description, a post's plain-text body) into
 * something fit for a meta description: no markup, no runaway whitespace, and short
 * enough that Google shows it rather than truncating it mid-word.
 */
export function seoDescription(text, maxLength = 160) {
  if (!text) {
    return ''
  }

  let ret = String(text).replace(TAG_RE, ' ')

  for (const [entity, char] of Object.entries(ENTITIES)) {
    ret = ret.split(entity).join(char)
  }

  ret = ret.replace(/\s+/g, ' ').trim()

  if (ret.length <= maxLength) {
    return ret
  }

  /* Cut at a word boundary so we don't end mid-word. */
  const cut = ret.slice(0, maxLength)
  const lastSpace = cut.lastIndexOf(' ')

  return (lastSpace > 0 ? cut.slice(0, lastSpace) : cut).trimEnd() + '...'
}

/**
 * The URL we want search engines to treat as the one true address for this page:
 * no query string (tracking params like ?src= must not fragment our signals) and
 * no hash.
 */
export function canonicalUrl(route, runtimeConfig, override = null) {
  const site = runtimeConfig.public.USER_SITE

  if (override) {
    return override.startsWith('http') ? override : site + override
  }

  if (!route) {
    return site
  }

  const path = route.path || (route.fullPath || '').split(/[?#]/)[0]

  return site + (path || '')
}

export function buildHead(
  route,
  runtimeConfig,
  title,
  description,
  image = null,
  bodyAttrs = {},
  options = {}
) {
  /* Descriptions reach us from all sorts of places - group descriptions are WYSIWYG
  HTML, post bodies are free text - and they all end up in a meta tag, so clean them
  here rather than at every call site. */
  const cleanDescription = seoDescription(description)

  // Pain to have to pass in runtimeConfig but you can't use that in a composable.
  const meta = [
    {
      key: 'description',
      name: 'description',
      content: cleanDescription,
    },
    { key: 'og:title', property: 'og:title', content: title },
    {
      key: 'og:description',
      property: 'og:description',
      content: cleanDescription,
    },

    {
      key: 'twitter:title',
      name: 'twitter:title',
      content: title,
    },
    {
      key: 'twitter:description',
      name: 'twitter:description',
      content: cleanDescription,
    },
  ]

  let retImage = image || runtimeConfig.public.USER_SITE + '/icon.png'

  /* Attachment paths from the API come through as both `<delivery>?url=...` and
  `<delivery>/?url=...`, so accept either. The original condition here was
  `retImage?.includes(DELIVERY) + '/?url='`, which is a string concatenation and so
  always truthy - meaning any image URL containing an `=` got mangled. */
  const delivery = runtimeConfig.public.IMAGE_DELIVERY

  if (
    typeof retImage === 'string' &&
    delivery &&
    (retImage.includes(delivery + '?url=') ||
      retImage.includes(delivery + '/?url='))
  ) {
    // We've seen problems with Facebook preview failing to fetch images from weserv, so strip this back to the
    // original image URL.
    const p = retImage.indexOf('=')
    retImage = retImage.slice(p + 1)

    // Need to remove URL parameters as those are for the transforms.  This might lead to preview being
    // rotated incorrectly, but there we go.
    let q = retImage.indexOf('?')
    if (q > -1) {
      retImage = retImage.slice(0, q)
    }

    q = retImage.indexOf('&')
    if (q > -1) {
      retImage = retImage.slice(0, q)
    }

    retImage = decodeURIComponent(retImage)
  }

  /* The uploads host is published with an internal :8080 on it. Crawlers and social
  preview fetchers are wary of images on non-standard ports, and the same file serves
  fine on 443, so drop it. */
  if (typeof retImage === 'string') {
    retImage = retImage.replace(
      /^(https:\/\/[^/:]+):(?:8080|8192)(?=\/|$)/,
      '$1'
    )
  }

  meta.push({
    key: 'og:image',
    property: 'og:image',
    content: retImage,
  })

  /* og:url and rel=canonical should agree, and both should be the clean address -
  not whatever tracking params (?src=...) the visitor happened to arrive with. */
  const canonical = canonicalUrl(route, runtimeConfig, options.canonical)

  meta.push({
    key: 'og:url',
    property: 'og:url',
    content: canonical,
  })

  if (options.ogType) {
    meta.push({
      key: 'og:type',
      property: 'og:type',
      content: options.ogType,
    })
  }

  if (options.noindex) {
    meta.push({
      key: 'robots',
      name: 'robots',
      content: 'noindex, follow',
    })
  }

  meta.push({
    key: 'twitter:image',
    property: 'twitter:image',
    content: retImage,
  })

  meta.push({ name: 'msapplication-TileColor', content: '#ffffff' })
  meta.push({
    name: 'msapplication-TileImage',
    content: '/icons/mstile-144x144.png',
  })
  meta.push({
    name: 'msapplication-config',
    content: '/icons/browserconfig.xml',
  })
  meta.push({ name: 'theme-color', content: '#ffffff' })

  // Store the page title in the store so that we can access it later if we need to.
  useMiscStore().pageTitle = title

  return {
    title,
    meta,
    link: [
      { rel: 'canonical', href: canonical },
      {
        rel: 'apple-touch-icon',
        sizes: '180x180',
        href: '/icons/apple-touch-icon.png',
      },
      {
        rel: 'icon',
        type: 'image/png',
        sizes: '32x32',
        href: '/icons/favicon-32x32.png',
      },
      {
        rel: 'icon',
        type: 'image/png',
        sizes: '16x16',
        href: '/icons/favicon-16x16.png',
      },
      { href: '/icons/safari-pinned-tab.svg', color: '#5bbad5' },
      { rel: 'shortcut icon', href: '/icons/favicon.ico' },
    ],
    bodyAttrs,
  }
}
