import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock the mobile store so we can control isApp
let mockIsApp = false
vi.mock('~/stores/mobile', () => ({
  useMobileStore: () => ({ isApp: mockIsApp }),
}))

// Mock @capacitor/haptics — the composable imports it dynamically
const mockImpact = vi.fn().mockResolvedValue(undefined)
const mockNotification = vi.fn().mockResolvedValue(undefined)
vi.mock('@capacitor/haptics', () => ({
  Haptics: { impact: mockImpact, notification: mockNotification },
  ImpactStyle: { Light: 'LIGHT', Medium: 'MEDIUM', Heavy: 'HEAVY' },
  NotificationType: {
    Success: 'SUCCESS',
    Warning: 'WARNING',
    Error: 'ERROR',
  },
}))

describe('useHaptics', () => {
  beforeEach(() => {
    mockIsApp = false
    mockImpact.mockClear()
    mockNotification.mockClear()
    vi.resetModules()
  })

  describe('on web (isApp = false)', () => {
    it('returns an object with the expected methods', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      const haptics = useHaptics()
      expect(typeof haptics.light).toBe('function')
      expect(typeof haptics.medium).toBe('function')
      expect(typeof haptics.heavy).toBe('function')
      expect(typeof haptics.success).toBe('function')
      expect(typeof haptics.warning).toBe('function')
      expect(typeof haptics.error).toBe('function')
    })

    it('light() is a no-op (does not call Haptics)', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().light()
      expect(mockImpact).not.toHaveBeenCalled()
    })

    it('medium() is a no-op', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().medium()
      expect(mockImpact).not.toHaveBeenCalled()
    })

    it('heavy() is a no-op', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().heavy()
      expect(mockImpact).not.toHaveBeenCalled()
    })

    it('success() is a no-op', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().success()
      expect(mockNotification).not.toHaveBeenCalled()
    })

    it('warning() is a no-op', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().warning()
      expect(mockNotification).not.toHaveBeenCalled()
    })

    it('error() is a no-op', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().error()
      expect(mockNotification).not.toHaveBeenCalled()
    })
  })

  describe('on native app (isApp = true)', () => {
    beforeEach(() => {
      mockIsApp = true
    })

    it('light() calls Haptics.impact with ImpactStyle.Light', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().light()
      expect(mockImpact).toHaveBeenCalledWith({ style: 'LIGHT' })
    })

    it('medium() calls Haptics.impact with ImpactStyle.Medium', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().medium()
      expect(mockImpact).toHaveBeenCalledWith({ style: 'MEDIUM' })
    })

    it('heavy() calls Haptics.impact with ImpactStyle.Heavy', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().heavy()
      expect(mockImpact).toHaveBeenCalledWith({ style: 'HEAVY' })
    })

    it('success() calls Haptics.notification with NotificationType.Success', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().success()
      expect(mockNotification).toHaveBeenCalledWith({ type: 'SUCCESS' })
    })

    it('warning() calls Haptics.notification with NotificationType.Warning', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().warning()
      expect(mockNotification).toHaveBeenCalledWith({ type: 'WARNING' })
    })

    it('error() calls Haptics.notification with NotificationType.Error', async () => {
      const { useHaptics } = await import('~/composables/useHaptics')
      await useHaptics().error()
      expect(mockNotification).toHaveBeenCalledWith({ type: 'ERROR' })
    })

    it('silently swallows errors from the native haptics engine', async () => {
      mockImpact.mockRejectedValueOnce(new Error('haptics engine unavailable'))
      const { useHaptics } = await import('~/composables/useHaptics')
      // Must not throw
      await expect(useHaptics().light()).resolves.toBeUndefined()
    })
  })
})
