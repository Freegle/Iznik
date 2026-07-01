import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

/* ----------------------------------------- component / composable under test */

import { useJobsFollowUpModal } from '~/composables/useJobsFollowUpModal'

/* ------------------------------------------------------------------ mocks */

const mockMiscStore = {
  vals: {},
  get(key) {
    return this.vals[key]
  },
  set({ key, value }) {
    this.vals[key] = value
  },
}

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

/* ------------------------------------------------------------------- tests */

describe('useJobsFollowUpModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMiscStore.vals = {}
    /* Clear the sessionStorage gate between tests. */
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.removeItem('jobs_modal_shown_this_session')
    }
  })

  afterEach(() => {
    vi.restoreAllMocks()
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.removeItem('jobs_modal_shown_this_session')
    }
  })

  describe('shouldShowModal', () => {
    it('returns true when neither cap is set', () => {
      const { shouldShowModal } = useJobsFollowUpModal()
      expect(shouldShowModal()).toBe(true)
    })

    it('returns false when sessionStorage key is set', () => {
      sessionStorage.setItem('jobs_modal_shown_this_session', '1')
      const { shouldShowModal } = useJobsFollowUpModal()
      expect(shouldShowModal()).toBe(false)
    })

    it('returns false when shown within the last 30 minutes', () => {
      const twentyMinsAgo = new Date().getTime() - 20 * 60 * 1000
      mockMiscStore.vals.last_jobs_modal_shown = twentyMinsAgo
      const { shouldShowModal } = useJobsFollowUpModal()
      expect(shouldShowModal()).toBe(false)
    })

    it('returns true when the miscStore timestamp is more than 30 minutes old', () => {
      const fortyMinsAgo = new Date().getTime() - 40 * 60 * 1000
      mockMiscStore.vals.last_jobs_modal_shown = fortyMinsAgo
      const { shouldShowModal } = useJobsFollowUpModal()
      expect(shouldShowModal()).toBe(true)
    })
  })

  describe('recordShown', () => {
    it('sets the sessionStorage key so subsequent calls to shouldShowModal return false', () => {
      const { shouldShowModal, recordShown } = useJobsFollowUpModal()
      expect(shouldShowModal()).toBe(true)
      recordShown()
      expect(shouldShowModal()).toBe(false)
    })

    it('sets the miscStore timestamp', () => {
      const before = new Date().getTime()
      const { recordShown } = useJobsFollowUpModal()
      recordShown()
      const stored = mockMiscStore.vals.last_jobs_modal_shown
      expect(stored).toBeGreaterThanOrEqual(before)
    })
  })
})
