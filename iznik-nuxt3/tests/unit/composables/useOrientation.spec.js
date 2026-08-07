import { describe, it, expect, vi } from 'vitest'

import { useOrientation } from '~/composables/useOrientation'

let mockIsLandscape

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get isLandscape() {
      return mockIsLandscape
    },
  }),
}))

describe('useOrientation', () => {
  it.each([
    [true, true, false],
    [false, false, true],
  ])(
    'when miscStore.isLandscape is %s, isLandscape=%s and isPortrait=%s',
    (storeValue, expectedLandscape, expectedPortrait) => {
      mockIsLandscape = storeValue
      const { isLandscape, isPortrait } = useOrientation()
      expect(isLandscape.value).toBe(expectedLandscape)
      expect(isPortrait.value).toBe(expectedPortrait)
    }
  )
})
