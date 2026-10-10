import { describe, it, expect, vi } from 'vitest'

const mockConfig = { public: {} }
vi.mock('#imports', () => ({
  useRuntimeConfig: () => mockConfig,
}))

describe('useGroupless', () => {
  it('is off unless switched on', async () => {
    const { useGroupless } = await import('~/composables/useGroupless')
    mockConfig.public = {}
    expect(useGroupless()).toBe(false)
    mockConfig.public = { GROUPLESS: '0' }
    expect(useGroupless()).toBe(false)
  })

  it('accepts the usual truthy spellings', async () => {
    const { useGroupless } = await import('~/composables/useGroupless')
    for (const v of [true, 1, '1', 'true']) {
      mockConfig.public = { GROUPLESS: v }
      expect(useGroupless(), String(v)).toBe(true)
    }
  })
})
