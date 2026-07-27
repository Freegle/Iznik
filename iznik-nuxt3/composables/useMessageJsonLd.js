import { seoDescription } from '~/composables/useBuildHead'

/* Subjects are stored with the type on the front, e.g. "OFFER: Dining chairs
(Moulton NN3)". Google wants the product name, not our posting convention. */
const TYPE_PREFIX = /^\s*(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i

export function productName(subject) {
  if (!subject) {
    return ''
  }

  return String(subject).replace(TYPE_PREFIX, '').trim()
}

/* The uploads host is published with an internal port on it, which crawlers dislike
in image URLs and which isn't needed - the same file serves on 443. */
function tidyImageUrl(url) {
  if (!url) {
    return null
  }

  return String(url).replace(/^(https:\/\/[^/:]+):(?:8080|8192)(?=\/|$)/, '$1')
}

/**
 * Structured data for a post, as JSON-LD.
 *
 * This replaces microdata which never worked: it declared schema.org/Product but
 * carried only price/currency/availability, with no name, image or description, so
 * Google discarded the lot. It also sat inside a `d-none` element, which their
 * guidelines don't allow for microdata.
 *
 * Returns null where structured data would be wrong or misleading:
 *  - WANTED posts, which are requests rather than something available;
 *  - posts that are finished, which we serve as 410 and noindex anyway.
 */
export function messageJsonLd(message, siteUrl, options = {}) {
  if (!message || options.gone) {
    return null
  }

  if (message.type !== 'Offer') {
    return null
  }

  const name = productName(message.subject)

  if (!name) {
    return null
  }

  const images = (message.attachments || [])
    .map((a) => tidyImageUrl(a.path))
    .filter(Boolean)

  const ld = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name,
    url: siteUrl + '/message/' + message.id,
    offers: {
      '@type': 'Offer',
      price: 0,
      priceCurrency: 'GBP',
      availability: 'https://schema.org/InStock',
      itemCondition: 'https://schema.org/UsedCondition',
      url: siteUrl + '/message/' + message.id,
      seller: {
        '@type': 'Organization',
        name: 'Freegle',
        url: siteUrl,
      },
    },
  }

  const description = seoDescription(message.textbody, 5000)

  if (description) {
    ld.description = description
  }

  if (images.length) {
    ld.image = images
  }

  return ld
}
