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

let mockUser = { lat: 51.45, lng: -2.58 }
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
    mockUser = { lat: 51.45, lng: -2.58 }
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

  it('rounds road miles like the crow-flies display', async () => {
    const { roadMilesRounded } = await load()
    expect(roadMilesRounded(4.26)).toBe(4)
    expect(roadMilesRounded(1.26)).toBe(1.3)
    expect(roadMilesRounded(null)).toBeNull()
  })
})
