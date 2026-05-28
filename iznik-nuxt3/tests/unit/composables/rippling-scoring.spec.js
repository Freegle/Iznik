import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  classifyPost,
  thumbUrlFor,
  formatTimeAgo,
  escapeHTML,
} from '~/modtools/composables/rippling/scoring.js'

describe('rippling/scoring', () => {
  describe('classifyPost', () => {
    it('marks successful posts as completed (grey)', () => {
      const r = classifyPost({ successful: true, promised: false, home_group: true })
      expect(r.color).toBe('#888')
      expect(r.label).toBe('completed')
      expect(r.section).toBe('completed')
    })

    it('marks promised posts as in-flight (amber)', () => {
      const r = classifyPost({ successful: false, promised: true, home_group: true })
      expect(r.color).toBe('#f39c12')
      expect(r.label).toBe('promised')
      expect(r.section).toBe('promised')
    })

    it('marks active home-group posts green', () => {
      const r = classifyPost({ successful: false, promised: false, home_group: true })
      expect(r.color).toBe('#27ae60')
      expect(r.label).toBe('home group')
      expect(r.section).toBe('active')
    })

    it('marks active cross-group posts blue', () => {
      const r = classifyPost({ successful: false, promised: false, home_group: false })
      expect(r.color).toBe('#1f77b4')
      expect(r.label).toBe('rippled in')
      expect(r.section).toBe('active')
    })

    it('treats successful-and-promised as completed (successful wins)', () => {
      // Belt and braces — if both flags are set the post is gone.
      const r = classifyPost({ successful: true, promised: true, home_group: false })
      expect(r.section).toBe('completed')
    })
  })

  describe('thumbUrlFor', () => {
    it('builds a cropped Uploadcare URL when externaluid is present', () => {
      const url = thumbUrlFor({ thumb_externaluid: 'abc123' })
      expect(url).toContain('https://ucarecdn.com/abc123/')
      expect(url).toContain('scale_crop/120x120')
    })

    it('falls back to the legacy mimg endpoint when only the attachment id is set', () => {
      const url = thumbUrlFor({ thumb_attachment_id: 42 })
      expect(url).toBe('https://images.ilovefreegle.org/tmimg_42.jpg')
    })

    it('returns null when no thumbnail data is present', () => {
      expect(thumbUrlFor({})).toBeNull()
    })

    it('prefers Uploadcare over the legacy attachment when both are present', () => {
      const url = thumbUrlFor({ thumb_externaluid: 'abc', thumb_attachment_id: 42 })
      expect(url).toContain('ucarecdn.com')
      expect(url).not.toContain('tmimg_')
    })
  })

  describe('formatTimeAgo', () => {
    const NOW = new Date('2026-05-28T12:00:00Z').getTime()

    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(NOW)
    })
    afterEach(() => {
      vi.useRealTimers()
    })

    it('shows minutes for posts under an hour old', () => {
      const arrival = new Date(NOW - 17 * 60 * 1000).toISOString()
      expect(formatTimeAgo(arrival)).toBe('17 min ago')
    })

    it('rounds zero-minute-old posts up to 1 min', () => {
      const arrival = new Date(NOW - 10 * 1000).toISOString() // 10 seconds ago
      expect(formatTimeAgo(arrival)).toBe('1 min ago')
    })

    it('shows hours for posts between 1 and 24 hours old', () => {
      const arrival = new Date(NOW - 5 * 3600 * 1000).toISOString()
      expect(formatTimeAgo(arrival)).toBe('5 h ago')
    })

    it('shows an absolute date for posts more than 24 hours old', () => {
      const arrival = new Date(NOW - 48 * 3600 * 1000).toISOString()
      const out = formatTimeAgo(arrival)
      // We don't assert on locale-specific formatting; just that it's
      // no longer the "X h ago" / "X min ago" form.
      expect(out).not.toMatch(/min ago|h ago/)
    })
  })

  describe('escapeHTML', () => {
    it('escapes the three characters that matter inside innerHTML', () => {
      expect(escapeHTML('<img src=x onerror=alert(1)>')).toBe(
        '&lt;img src=x onerror=alert(1)&gt;'
      )
      expect(escapeHTML('Tom & Jerry')).toBe('Tom &amp; Jerry')
    })

    it('returns an empty string for null / undefined', () => {
      expect(escapeHTML(null)).toBe('')
      expect(escapeHTML(undefined)).toBe('')
    })

    it('leaves safe ASCII untouched', () => {
      expect(escapeHTML("plain old text 123")).toBe('plain old text 123')
    })
  })
})
