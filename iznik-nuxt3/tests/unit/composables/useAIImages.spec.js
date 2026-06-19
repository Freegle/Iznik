import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// useAIImages calls useNuxtApp() as a Nuxt auto-import (no explicit import).
// Wire it globally so the composable finds it in scope at call time.
let mockAiimages

beforeEach(() => {
  mockAiimages = {
    count: vi.fn().mockResolvedValue({ count: 0 }),
    review: vi.fn().mockResolvedValue([]),
    accept: vi.fn().mockResolvedValue({}),
    keep: vi.fn().mockResolvedValue({}),
    suppress: vi.fn().mockResolvedValue({}),
    regenerate: vi.fn().mockResolvedValue({}),
  }
  globalThis.useNuxtApp = () => ({ $api: { aiimages: mockAiimages } })
})

afterEach(() => {
  vi.resetModules()
})

// Fresh import after resetModules so module-level refs start from zero.
async function freshComposable() {
  vi.resetModules()
  const { useAIImages } = await import('~/modtools/composables/useAIImages')
  return useAIImages()
}

describe('useAIImages badge count', () => {
  it('count is set by fetchReview()', async () => {
    mockAiimages.review.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const { count, fetchReview } = await freshComposable()
    await fetchReview()
    expect(count.value).toBe(3)
  })

  it('count decrements after accept()', async () => {
    mockAiimages.review.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    mockAiimages.accept.mockResolvedValue({})
    const { count, fetchReview, accept } = await freshComposable()
    await fetchReview()
    expect(count.value).toBe(3)

    await accept(1, '')
    // Badge must clear as images are processed — not stay stale until navigate-away.
    expect(count.value).toBe(2)
  })

  it('count decrements after keep()', async () => {
    mockAiimages.review.mockResolvedValue([{ id: 1 }, { id: 2 }])
    mockAiimages.keep.mockResolvedValue({})
    const { count, fetchReview, keep } = await freshComposable()
    await fetchReview()
    expect(count.value).toBe(2)

    await keep(1)
    expect(count.value).toBe(1)
  })

  it('count decrements after suppress()', async () => {
    mockAiimages.review.mockResolvedValue([{ id: 1 }])
    mockAiimages.suppress.mockResolvedValue({})
    const { count, fetchReview, suppress } = await freshComposable()
    await fetchReview()
    expect(count.value).toBe(1)

    await suppress(1)
    expect(count.value).toBe(0)
  })

  it('count does not go below zero', async () => {
    mockAiimages.accept.mockResolvedValue({})
    const { count, accept } = await freshComposable()
    // count starts at 0 — a defensive call must not go negative
    await accept(99, '')
    expect(count.value).toBe(0)
  })

  it('count is not decremented when accept() throws', async () => {
    mockAiimages.review.mockResolvedValue([{ id: 1 }, { id: 2 }])
    mockAiimages.accept.mockRejectedValue(new Error('network'))
    const { count, fetchReview, accept } = await freshComposable()
    await fetchReview()
    expect(count.value).toBe(2)

    await expect(accept(1, '')).rejects.toThrow('network')
    // Failed action — count must stay unchanged
    expect(count.value).toBe(2)
  })
})
