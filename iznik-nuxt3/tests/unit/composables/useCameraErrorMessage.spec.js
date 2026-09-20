import { describe, it, expect, vi, beforeEach } from 'vitest'
import * as Sentry from '@sentry/browser'
import {
  cameraErrorMessage,
  reportCameraError,
} from '~/composables/useCameraErrorMessage'

// Sentry is the measurable signal for declined permissions; mock it so we can
// assert exactly when reportCameraError() fires it.
vi.mock('@sentry/browser', () => ({
  captureMessage: vi.fn(),
}))

describe('useCameraErrorMessage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // "User denied access to camera/photos" is what the Capacitor Camera plugin
  // rejects with when the OS permission prompt is declined; "User cancelled
  // photos app" is a plain back-out that must stay silent.
  describe('cameraErrorMessage (pure mapping)', () => {
    it('stays silent on a plain cancellation', () => {
      expect(
        cameraErrorMessage(new Error('User cancelled photos app'))
      ).toBeNull()
    })

    it('explains a declined permission', () => {
      expect(
        cameraErrorMessage(new Error('User denied access to camera'))
      ).toMatch(/permission was declined/i)
    })

    it('falls back to a generic message for an unknown failure', () => {
      expect(cameraErrorMessage(new Error('boom'))).toMatch(/try again/i)
    })

    it('tolerates a missing/empty error', () => {
      expect(cameraErrorMessage(undefined)).toMatch(/try again/i)
    })

    it('has no side effect on Sentry', () => {
      cameraErrorMessage(new Error('User denied access to photos'))
      expect(Sentry.captureMessage).not.toHaveBeenCalled()
    })
  })

  describe('reportCameraError', () => {
    it('records a Sentry warning when the permission was declined', () => {
      const msg = reportCameraError(new Error('User denied access to camera'))
      expect(Sentry.captureMessage).toHaveBeenCalledTimes(1)
      expect(Sentry.captureMessage).toHaveBeenCalledWith(
        'app camera/photos permission denied',
        'warning'
      )
      // still returns the user-facing message
      expect(msg).toMatch(/permission was declined/i)
    })

    it('stays silent (no Sentry, null message) on a plain cancellation', () => {
      const msg = reportCameraError(new Error('User cancelled photos app'))
      expect(Sentry.captureMessage).not.toHaveBeenCalled()
      expect(msg).toBeNull()
    })

    it('does not record Sentry for a generic failure but still returns a message', () => {
      const msg = reportCameraError(new Error('boom'))
      expect(Sentry.captureMessage).not.toHaveBeenCalled()
      expect(msg).toMatch(/try again/i)
    })
  })
})
