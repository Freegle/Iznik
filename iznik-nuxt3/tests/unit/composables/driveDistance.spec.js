import { describe, it, expect, vi, beforeEach } from 'vitest'

// Road-distance batching composable: everything registered in one synchronous
// pass must go out as ONE /drivedistance call; results fill the refs; misses
// and failures leave refs null (crow-flies fallback).

const mockDistances = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    driving: { distances: mockDistances },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { APIv2: 'https://api.test' } }),
}))

let mockUser = { id: 1, lat: 51.45, lng: -2.58 }
vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({ user: mockUser }),
}))

async function load() {
  vi.resetModules()
  return await import('~/composables/useDriveDistance.js')
}

const microtasks = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('useDriveDistance', () => {
  beforeEach(() => {
    mockDistances.mockReset()
    mockUser = { id: 1, lat: 51.45, lng: -2.58 }
    // The composable coalesces on a frame boundary in the browser; make the
    // test environment's frame fire on the microtask queue so awaiting
    // microtasks() drains the flush deterministically.
    vi.stubGlobal('requestAnimationFrame', (cb) => queueMicrotask(cb))
  })

  it('batches all synchronous registrations into one call and fills refs', async () => {
    const { roadDistance } = await load()
    mockDistances.mockResolvedValue({
      results: [
        { id: 0, mins: 12.5, miles: 4.2 },
        { id: 1, mins: null, miles: null },
      ],
    })

    const a = roadDistance(51.47, -2.6)
    const b = roadDistance(51.3, -2.3)
    const aDup = roadDistance(51.47, -2.6) // same coords: same cached ref, no extra target

    await microtasks()

    expect(mockDistances).toHaveBeenCalledTimes(1)
    expect(mockDistances.mock.calls[0][0]).toHaveLength(2)
    expect(a.value).toEqual({ mins: 12.5, miles: 4.2 })
    expect(b.value).toBeNull() // unreachable: crow-flies fallback
    expect(aDup).toBe(a)
  })

  it('prewarming a page of posts is one call and cards then hit cache', async () => {
    const { roadDistance, prewarmRoadDistances } = await load()
    mockDistances.mockResolvedValue({
      results: [
        { id: 0, mins: 5, miles: 1.5 },
        { id: 1, mins: 8, miles: 2.5 },
      ],
    })

    // The store receives a page of posts...
    prewarmRoadDistances([
      { lat: 51.47, lng: -2.6 },
      { lat: 51.3, lng: -2.3 },
      { lat: null, lng: null }, // no coords: skipped
    ])
    await microtasks()
    expect(mockDistances).toHaveBeenCalledTimes(1)
    expect(mockDistances.mock.calls[0][0]).toHaveLength(2)

    // ...and the cards rendering later are cache hits: no further calls.
    const a = roadDistance(51.47, -2.6)
    await microtasks()
    expect(mockDistances).toHaveBeenCalledTimes(1)
    expect(a.value).toEqual({ mins: 5, miles: 1.5 })
  })

  it('separate render passes make separate batched calls, cache hits none', async () => {
    const { roadDistance } = await load()
    mockDistances.mockResolvedValue({
      results: [{ id: 0, mins: 3, miles: 1.1 }],
    })

    roadDistance(51.47, -2.6)
    await microtasks()
    roadDistance(51.48, -2.61)
    await microtasks()
    roadDistance(51.47, -2.6) // cached: no new call

    await microtasks()
    expect(mockDistances).toHaveBeenCalledTimes(2)
  })

  it('fails soft on API errors', async () => {
    const { roadDistance } = await load()
    mockDistances.mockRejectedValue(new Error('routing down'))

    const r = roadDistance(51.47, -2.6)
    await microtasks()

    expect(r.value).toBeNull()
  })

  it('does nothing when logged out or without coordinates', async () => {
    mockUser = null
    const { roadDistance } = await load()

    const r = roadDistance(51.47, -2.6)
    await microtasks()

    expect(r.value).toBeNull()
    expect(mockDistances).not.toHaveBeenCalled()
  })

  it('resets the cache when the viewing user changes', async () => {
    const { roadDistance } = await load()
    mockDistances.mockResolvedValue({
      results: [{ id: 0, mins: 5, miles: 2 }],
    })

    roadDistance(51.47, -2.6)
    await microtasks()
    expect(mockDistances).toHaveBeenCalledTimes(1)

    // Same coords, different user: must NOT serve the old user's distance.
    mockUser = { id: 2, lat: 52.0, lng: -1.0 }
    roadDistance(51.47, -2.6)
    await microtasks()
    expect(mockDistances).toHaveBeenCalledTimes(2)
  })

  it('rounds road miles like the crow-flies display', async () => {
    const { roadMilesRounded } = await load()
    expect(roadMilesRounded(4.26)).toBe(4)
    expect(roadMilesRounded(1.26)).toBe(1.3)
    expect(roadMilesRounded(null)).toBeNull()
  })
})
