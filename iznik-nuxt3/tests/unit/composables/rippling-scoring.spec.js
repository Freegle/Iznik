import { describe, it, expect } from 'vitest'
import { swingometerDisplay } from '~/modtools/composables/rippling/scoring.js'

// The digest-post helpers this file used to cover (classifyPost, thumbUrlFor,
// formatTimeAgo, escapeHTML, partitionInboxData) went when the explorer stopped
// previewing a digest: the reach map answers "whose posts can I see" with a boundary,
// not a ranked post list.

describe('rippling/scoring', () => {
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
