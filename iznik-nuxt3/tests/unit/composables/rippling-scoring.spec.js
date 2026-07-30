import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  classifyPost,
  thumbUrlFor,
  formatTimeAgo,
  escapeHTML,
  partitionInboxData,
  swingometerDisplay,
} from '~/modtools/composables/rippling/scoring.js'

describe('rippling/scoring', () => {
  describe('classifyPost', () => {
    it('marks successful posts as completed (grey)', () => {
      const r = classifyPost({
        successful: true,
        promised: false,
        home_group: true,
      })
      expect(r.color).toBe('#888')
      expect(r.label).toBe('completed')
      expect(r.section).toBe('completed')
    })

    it('marks promised posts as in-flight (amber)', () => {
      const r = classifyPost({
        successful: false,
        promised: true,
        home_group: true,
      })
      expect(r.color).toBe('#f39c12')
      expect(r.label).toBe('promised')
      expect(r.section).toBe('promised')
    })

    it('marks active home-group posts green', () => {
      const r = classifyPost({
        successful: false,
        promised: false,
        home_group: true,
      })
      expect(r.color).toBe('#27ae60')
      expect(r.label).toBe('home group')
      expect(r.section).toBe('active')
    })

    it('marks active cross-group posts blue', () => {
      const r = classifyPost({
        successful: false,
        promised: false,
        home_group: false,
      })
      expect(r.color).toBe('#1f77b4')
      expect(r.label).toBe('rippled in')
      expect(r.section).toBe('active')
    })

    it('treats successful-and-promised as completed (successful wins)', () => {
      // Belt and braces — if both flags are set the post is gone.
      const r = classifyPost({
        successful: true,
        promised: true,
        home_group: false,
      })
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
      const url = thumbUrlFor({
        thumb_externaluid: 'abc',
        thumb_attachment_id: 42,
      })
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

  describe('partitionInboxData', () => {
    const post = (overrides) => ({
      msgid: 1,
      successful: false,
      promised: false,
      home_group: false,
      ...overrides,
    })

    it('returns empty arrays for an empty response', () => {
      const r = partitionInboxData({})
      expect(r.ranked).toEqual([])
      expect(r.active).toEqual([])
      expect(r.activeHome).toEqual([])
      expect(r.activeCross).toEqual([])
      expect(r.promised).toEqual([])
      expect(r.taken).toEqual([])
    })

    it('flattens selected + deferred into one ranked list', () => {
      const data = {
        selected: [post({ msgid: 1 }), post({ msgid: 2 })],
        deferred: [post({ msgid: 3 })],
      }
      const r = partitionInboxData(data)
      expect(r.ranked.map((p) => p.msgid)).toEqual([1, 2, 3])
    })

    it('stamps each ranked post with a 1-based _rank', () => {
      const data = {
        selected: [post({ msgid: 1 }), post({ msgid: 2 })],
        deferred: [post({ msgid: 3 })],
      }
      const r = partitionInboxData(data)
      expect(r.ranked[0]._rank).toBe(1)
      expect(r.ranked[1]._rank).toBe(2)
      expect(r.ranked[2]._rank).toBe(3)
    })

    it('orders available (top picks + deferred) before came-and-went in the ranked array', () => {
      // New simulator contract: available posts (top_picks/selected + deferred)
      // keep their incoming order and come first; came_and_went (taken) follows.
      // There is no active→promised→taken reordering any more.
      const data = {
        top_picks: [
          post({ msgid: 11 }), // active, still available
          post({ msgid: 12, promised: true }), // promised, still available
        ],
        came_and_went: [post({ msgid: 10, successful: true })], // taken
      }
      const r = partitionInboxData(data)
      expect(r.ranked.map((p) => p.msgid)).toEqual([11, 12, 10])
      expect(r.active.map((p) => p.msgid)).toEqual([11])
      expect(r.promised.map((p) => p.msgid)).toEqual([12])
      expect(r.taken.map((p) => p.msgid)).toEqual([10])
    })

    it('splits active posts into home-group and rippled-in buckets', () => {
      const data = {
        selected: [
          post({ msgid: 1, home_group: true }),
          post({ msgid: 2, home_group: false }),
          post({ msgid: 3, home_group: true }),
        ],
      }
      const r = partitionInboxData(data)
      expect(r.activeHome.map((p) => p.msgid)).toEqual([1, 3])
      expect(r.activeCross.map((p) => p.msgid)).toEqual([2])
    })

    it('treats came-and-went posts as taken, not promised, even when flagged promised', () => {
      // came_and_went is the authoritative "taken" signal in the new contract:
      // such a post is taken even if it still carries a promised flag, and it
      // must not leak into the promised slice (which is drawn from available).
      const data = {
        came_and_went: [post({ msgid: 5, successful: true, promised: true })],
      }
      const r = partitionInboxData(data)
      expect(r.taken).toHaveLength(1)
      expect(r.promised).toHaveLength(0)
    })

    it('handles missing selected/deferred fields gracefully', () => {
      expect(() => partitionInboxData({ selected: null })).not.toThrow()
      expect(() => partitionInboxData({ deferred: null })).not.toThrow()
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
      expect(escapeHTML('plain old text 123')).toBe('plain old text 123')
    })
  })

  describe('swingometerDisplay', () => {
    it('reports "unavailable" when the area baseline has not been measured', () => {
      // The bug: on fetch failure the gauge silently used the 60% national
      // fallback as if it were this area's figure. When not ready it must say so.
      const r = swingometerDisplay(25, 60, false)
      expect(r.ready).toBe(false)
      expect(r.label).toBe('Area baseline unavailable')
      // No bias verdict is computed against the fallback baseline.
      expect(r.aboveBaseline).toBeUndefined()
      expect(r.diff).toBeUndefined()
    })

    it('classifies a reading well below the measured baseline as affluent bias', () => {
      const r = swingometerDisplay(25, 50, true)
      expect(r.ready).toBe(true)
      expect(r.label).toBe('Affluent bias')
      expect(r.aboveBaseline).toBe(false)
      expect(r.diff).toBe(25)
    })

    it('classifies a reading well above the baseline as deprived bias', () => {
      const r = swingometerDisplay(70, 50, true)
      expect(r.label).toBe('Deprived bias')
      expect(r.aboveBaseline).toBe(true)
      expect(r.diff).toBe(20)
    })

    it('treats readings within ±8% of the baseline as balanced', () => {
      expect(swingometerDisplay(55, 50, true).label).toBe('Balanced')
      expect(swingometerDisplay(45, 50, true).label).toBe('Balanced')
    })
  })
})
