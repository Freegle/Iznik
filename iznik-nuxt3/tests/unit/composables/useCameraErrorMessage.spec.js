import { describe, it, expect, vi, beforeEach } from 'vitest'
import {
  cameraErrorMessage,
  reportCameraError,
} from '~/composables/useCameraErrorMessage'

// A declined permission is counted as a Loki client action, never a Sentry event.
const { mockAction } = vi.hoisted(() => ({ mockAction: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({ action: mockAction }))

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

    it('has no side effect on the client log', () => {
      cameraErrorMessage(new Error('User denied access to photos'))
      expect(mockAction).not.toHaveBeenCalled()
    })
  })

  describe('reportCameraError', () => {
    it('logs a client action when the permission was declined', () => {
      const msg = reportCameraError(new Error('User denied access to camera'))
      expect(mockAction).toHaveBeenCalledTimes(1)
      expect(mockAction).toHaveBeenCalledWith('camera_permission_denied', {
        error_message: 'User denied access to camera',
      })
      // still returns the user-facing message
      expect(msg).toMatch(/permission was declined/i)
    })

    it('stays silent (no log, null message) on a plain cancellation', () => {
      const msg = reportCameraError(new Error('User cancelled photos app'))
      expect(mockAction).not.toHaveBeenCalled()
      expect(msg).toBeNull()
    })

    it('does not log a generic failure but still returns a message', () => {
      const msg = reportCameraError(new Error('boom'))
      expect(mockAction).not.toHaveBeenCalled()
      expect(msg).toMatch(/try again/i)
    })
  })
})
