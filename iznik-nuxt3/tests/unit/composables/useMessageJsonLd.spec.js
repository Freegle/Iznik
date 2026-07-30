import { describe, it, expect } from 'vitest'
import { messageJsonLd, productName } from '~/composables/useMessageJsonLd'

const SITE = 'https://www.ilovefreegle.org'

function offer(extra = {}) {
  return {
    id: 121054975,
    type: 'Offer',
    subject: 'OFFER: Kitchen cupboard units (Moulton NN3)',
    textbody: 'Solid oak doors. Some glazed as in photo. Good condition.',
    attachments: [
      {
        path: 'https://delivery.ilovefreegle.org/?url=https://uploads.ilovefreegle.org/abc',
      },
    ],
    ...extra,
  }
}

describe('productName', () => {
  it('strips the OFFER: prefix we put on subjects', () => {
    expect(productName('OFFER: Dining chairs (Moulton NN3)')).toBe(
      'Dining chairs (Moulton NN3)'
    )
  })

  it('strips WANTED, TAKEN and RECEIVED prefixes too', () => {
    expect(productName('WANTED: Bike')).toBe('Bike')
    expect(productName('TAKEN: Bike')).toBe('Bike')
    expect(productName('RECEIVED: Bike')).toBe('Bike')
  })

  it('is case insensitive and tolerates odd spacing', () => {
    expect(productName('offer :  Bike')).toBe('Bike')
  })

  it('leaves a subject with no prefix alone', () => {
    expect(productName('Just a bike')).toBe('Just a bike')
  })

  it('copes with nothing', () => {
    expect(productName(null)).toBe('')
    expect(productName('')).toBe('')
  })
})

describe('messageJsonLd', () => {
  it('describes an offer as a schema.org Product', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld['@context']).toBe('https://schema.org')
    expect(ld['@type']).toBe('Product')
  })

  it('names the product without our OFFER: convention', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.name).toBe('Kitchen cupboard units (Moulton NN3)')
  })

  it('includes the description, which the old microdata never did', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.description).toBe(
      'Solid oak doors. Some glazed as in photo. Good condition.'
    )
  })

  it('includes the photos, which the old microdata never did', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.image).toEqual([
      'https://delivery.ilovefreegle.org/?url=https://uploads.ilovefreegle.org/abc',
    ])
  })

  it('uses a valid schema.org availability URL, not the bare "Instock" string', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.offers.availability).toBe('https://schema.org/InStock')
  })

  it('prices the offer at zero pounds', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.offers.price).toBe(0)
    expect(ld.offers.priceCurrency).toBe('GBP')
  })

  it('marks items as used, which they almost always are', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.offers.itemCondition).toBe('https://schema.org/UsedCondition')
  })

  it('points at the canonical post URL', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(ld.url).toBe(SITE + '/message/121054975')
    expect(ld.offers.url).toBe(SITE + '/message/121054975')
  })

  it('strips the internal :8080 port from photo URLs', () => {
    const ld = messageJsonLd(
      offer({
        attachments: [{ path: 'https://uploads.ilovefreegle.org:8080/abc' }],
      }),
      SITE
    )
    expect(ld.image).toEqual(['https://uploads.ilovefreegle.org/abc'])
  })

  it('lists every photo, not just the first', () => {
    const ld = messageJsonLd(
      offer({
        attachments: [
          { path: 'https://uploads.ilovefreegle.org/a' },
          { path: 'https://uploads.ilovefreegle.org/b' },
          { path: 'https://uploads.ilovefreegle.org/c' },
        ],
      }),
      SITE
    )
    expect(ld.image).toHaveLength(3)
  })

  it('omits image entirely when there are no photos, rather than emitting an empty array', () => {
    const ld = messageJsonLd(offer({ attachments: [] }), SITE)
    expect(ld.image).toBeUndefined()
  })

  it('omits description when the post has no body', () => {
    const ld = messageJsonLd(offer({ textbody: '' }), SITE)
    expect(ld.description).toBeUndefined()
  })

  describe('when structured data would be wrong', () => {
    it('emits nothing for a WANTED, which is a request not something available', () => {
      expect(messageJsonLd(offer({ type: 'Wanted' }), SITE)).toBeNull()
    })

    it('emits nothing for a post that has finished', () => {
      expect(messageJsonLd(offer(), SITE, { gone: true })).toBeNull()
    })

    it('emits nothing when there is no message', () => {
      expect(messageJsonLd(null, SITE)).toBeNull()
    })

    it('emits nothing when the subject is only our prefix', () => {
      expect(messageJsonLd(offer({ subject: 'OFFER:' }), SITE)).toBeNull()
    })
  })

  it('serialises to JSON without throwing', () => {
    const ld = messageJsonLd(offer(), SITE)
    expect(() => JSON.stringify(ld)).not.toThrow()
    expect(JSON.parse(JSON.stringify(ld)).name).toBe(
      'Kitchen cupboard units (Moulton NN3)'
    )
  })
})
