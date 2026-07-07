import { describe, it, expect } from 'vitest'
import {
  linkifyText,
  linkifyAndHighlightEmails,
  useLinkify,
} from '~/composables/useLinkify'

const EMAIL_REGEX = /[\w.+-]+@[\w-]+\.[\w.-]+/gi

describe('useLinkify', () => {
  describe('linkifyText', () => {
    it('returns empty string for falsy input', () => {
      expect(linkifyText('')).toBe('')
      expect(linkifyText(null)).toBe('')
      expect(linkifyText(undefined)).toBe('')
    })

    it('escapes HTML special characters', () => {
      const out = linkifyText('<script>alert(1)</script>')
      expect(out).not.toMatch(/<script>/)
      expect(out).toMatch(/&lt;script&gt;/)
    })

    it('wraps http:// URLs in an anchor tag', () => {
      const out = linkifyText('See http://example.com for details')
      expect(out).toMatch(
        /<a href="http:\/\/example\.com"[^>]*class="chat-link"[^>]*>http:\/\/example\.com<\/a>/
      )
    })

    it('wraps https:// URLs in an anchor tag', () => {
      const out = linkifyText('Visit https://freegle.in now')
      expect(out).toMatch(/href="https:\/\/freegle\.in"/)
      expect(out).toMatch(/rel="noopener noreferrer"/)
      expect(out).toMatch(/target="_blank"/)
    })

    it('prepends https:// to bare www. URLs', () => {
      const out = linkifyText('Check www.freegle.in please')
      expect(out).toMatch(/href="https:\/\/www\.freegle\.in"/)
      expect(out).toMatch(/>www\.freegle\.in</)
    })

    it('returns plain escaped text when no URLs present', () => {
      expect(linkifyText('just text & stuff')).toBe('just text &amp; stuff')
    })

    it('linkifies multiple URLs in one string', () => {
      const out = linkifyText('a http://a.com and b https://b.com')
      const matches = out.match(/<a /g)
      expect(matches).toHaveLength(2)
    })

    it('does not include trailing punctuation in the link text', () => {
      const out = linkifyText('See http://example.com.')
      expect(out).toMatch(/>http:\/\/example\.com</)
      expect(out).not.toMatch(/>http:\/\/example\.com\.</)
    })
  })

  describe('linkifyAndHighlightEmails', () => {
    it('returns empty string for falsy input', () => {
      expect(linkifyAndHighlightEmails('', EMAIL_REGEX)).toBe('')
      expect(linkifyAndHighlightEmails(null, EMAIL_REGEX)).toBe('')
      expect(linkifyAndHighlightEmails(undefined, EMAIL_REGEX)).toBe('')
    })

    it('linkifies URLs like linkifyText does', () => {
      const out = linkifyAndHighlightEmails(
        'visit http://example.com',
        EMAIL_REGEX
      )
      expect(out).toMatch(/<a href="http:\/\/example\.com"[^>]*>/)
    })

    it('wraps emails in a highlight span when regex provided', () => {
      const out = linkifyAndHighlightEmails(
        'email me at foo@bar.com please',
        EMAIL_REGEX
      )
      expect(out).toMatch(/<span class="highlight">foo@bar\.com<\/span>/)
    })

    it('handles both URL and email in one string', () => {
      const out = linkifyAndHighlightEmails(
        'foo@bar.com or http://x.y',
        EMAIL_REGEX
      )
      expect(out).toMatch(/<span class="highlight">foo@bar\.com<\/span>/)
      expect(out).toMatch(/<a href="http:\/\/x\.y"/)
    })

    it('does not apply email highlight when regex is omitted/null', () => {
      const out = linkifyAndHighlightEmails('foo@bar.com is my email', null)
      expect(out).not.toMatch(/<span class="highlight">/)
      expect(out).toMatch(/foo@bar\.com/)
    })

    it('escapes HTML before linkifying and highlighting', () => {
      const out = linkifyAndHighlightEmails(
        '<b>foo@bar.com</b> http://x.y',
        EMAIL_REGEX
      )
      expect(out).toMatch(/&lt;b&gt;/)
      expect(out).not.toMatch(/^<b>/)
    })
  })

  describe('useLinkify composable', () => {
    it('returns an object exposing both helpers', () => {
      const api = useLinkify()
      expect(typeof api.linkifyText).toBe('function')
      expect(typeof api.linkifyAndHighlightEmails).toBe('function')
    })

    it('helpers from composable produce same output as direct import', () => {
      const api = useLinkify()
      const input = 'visit http://example.com'
      expect(api.linkifyText(input)).toBe(linkifyText(input))
      expect(api.linkifyAndHighlightEmails(input, EMAIL_REGEX)).toBe(
        linkifyAndHighlightEmails(input, EMAIL_REGEX)
      )
    })
  })
})
