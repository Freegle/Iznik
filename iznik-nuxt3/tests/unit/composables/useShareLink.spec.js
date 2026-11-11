import { describe, it, expect } from 'vitest'
import { shareLink } from '~/composables/useShareLink'

const item = {
  url: 'https://www.ilovefreegle.org/message/1',
  title: 'OFFER: Sofa (Town)',
  description: 'A brown sofa & two chairs',
  hashtags: 'freegle,free,reuse',
}

describe('shareLink', () => {
  it('builds a Facebook link with one encoded hashtag', () => {
    expect(shareLink({ network: 'facebook', ...item })).toBe(
      'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fwww.ilovefreegle.org%2Fmessage%2F1&title=OFFER%3A%20Sofa%20(Town)&description=A%20brown%20sofa%20%26%20two%20chairs&hashtag=%23freegle'
    )
  })

  it('builds an X link with the hashtags', () => {
    expect(shareLink({ network: 'twitter', ...item })).toBe(
      'https://twitter.com/intent/tweet?text=OFFER%3A%20Sofa%20(Town)&url=https%3A%2F%2Fwww.ilovefreegle.org%2Fmessage%2F1&hashtags=freegle,free,reuse'
    )
  })

  it('leaves the hashtags parameter off an X link that has none', () => {
    expect(
      shareLink({ network: 'twitter', ...item, hashtags: '' })
    ).not.toContain('hashtags')
  })

  it('builds WhatsApp and email links with the line breaks encoded', () => {
    expect(shareLink({ network: 'whatsapp', ...item })).toBe(
      'https://api.whatsapp.com/send?text=OFFER%3A%20Sofa%20(Town)%0D%0Ahttps%3A%2F%2Fwww.ilovefreegle.org%2Fmessage%2F1%0D%0AA%20brown%20sofa%20%26%20two%20chairs'
    )
    expect(shareLink({ network: 'email', ...item, url: '' })).toBe(
      'mailto:?subject=OFFER%3A%20Sofa%20(Town)&body=%0D%0A%0D%0AA%20brown%20sofa%20%26%20two%20chairs'
    )
  })

  it('refuses a network it does not know', () => {
    expect(() => shareLink({ network: 'myspace', ...item })).toThrow(
      'does not exist'
    )
  })
})
