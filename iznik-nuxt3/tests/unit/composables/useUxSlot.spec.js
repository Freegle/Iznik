import { describe, it, expect, vi } from 'vitest'
import {
  idleSlot,
  loadInto,
  settled,
  NOT_SWITCHED_ON,
} from '~/modtools/composables/useUxSlot'

// A promise the test settles by hand, so it decides which request finishes first.
function later() {
  let resolve
  let reject
  const promise = new Promise((a, b) => {
    resolve = a
    reject = b
  })
  return { promise, resolve, reject }
}

const failure = (status, message = 'boom') =>
  Object.assign(new Error(message), { response: { status } })

describe('useUxSlot', () => {
  it('an idle slot holds nothing, is not loading and is not missing', () => {
    expect(idleSlot()).toEqual({
      data: null,
      loading: false,
      error: null,
      missing: false,
    })
  })

  it('each call gives a slot of its own', () => {
    expect(idleSlot()).not.toBe(idleSlot())
  })

  it('stores the answer and stops loading', async () => {
    const slot = idleSlot()
    const p = loadInto(slot, async () => ({ flows: [1] }))
    expect(slot.loading).toBe(true)
    await p
    expect(slot).toEqual({
      data: { flows: [1] },
      loading: false,
      error: null,
      missing: false,
    })
  })

  it('a slower earlier answer does not replace a later one', async () => {
    const slot = idleSlot()
    const slow = later()
    const fast = later()
    const first = loadInto(slot, () => slow.promise)
    const second = loadInto(slot, () => fast.promise)

    fast.resolve('new')
    await second
    expect(slot.data).toBe('new')

    slow.resolve('old')
    await first
    expect(slot.data).toBe('new')
  })

  it('an old failure does not clear new data or set an error', async () => {
    const slot = idleSlot()
    const slow = later()
    const first = loadInto(slot, () => slow.promise)
    await loadInto(slot, async () => 'new')

    slow.reject(failure(500, 'late'))
    await first
    expect(slot.data).toBe('new')
    expect(slot.error).toBeNull()
    expect(slot.missing).toBe(false)
  })

  it('stays loading until the newest request ends', async () => {
    const slot = idleSlot()
    const a = later()
    const b = later()
    const first = loadInto(slot, () => a.promise)
    const second = loadInto(slot, () => b.promise)

    a.resolve('a')
    await first
    expect(slot.loading).toBe(true)

    b.resolve('b')
    await second
    expect(slot.loading).toBe(false)
    expect(slot.data).toBe('b')
  })

  describe('telling the caller its answer was stored', () => {
    it('calls back at the moment the answer is in the slot', async () => {
      const slot = idleSlot()
      const seen = []
      await loadInto(
        slot,
        async () => 'a',
        () => seen.push(slot.data)
      )
      expect(seen).toEqual(['a'])
    })

    it('does not call back for an answer that was not the newest', async () => {
      const slot = idleSlot()
      const slow = later()
      const landed = vi.fn()
      const first = loadInto(slot, () => slow.promise, landed)
      await loadInto(slot, async () => 'new')

      slow.resolve('old')
      await first
      expect(landed).not.toHaveBeenCalled()
      expect(slot.data).toBe('new')
    })

    it('does not call back for a failure', async () => {
      const slot = idleSlot()
      const landed = vi.fn()
      await loadInto(
        slot,
        async () => {
          throw failure(500)
        },
        landed
      )
      expect(landed).not.toHaveBeenCalled()
    })

    it('is optional', async () => {
      const slot = idleSlot()
      await loadInto(slot, async () => 'a')
      expect(slot.data).toBe('a')
    })
  })

  it.each([404, 501])(
    'HTTP %i means the part is not switched on, which is not an error',
    async (status) => {
      const slot = idleSlot()
      slot.data = 'earlier'
      await loadInto(slot, async () => {
        throw failure(status)
      })
      expect(slot.missing).toBe(true)
      expect(slot.error).toBeNull()
      expect(slot.data).toBeNull()
      expect(slot.loading).toBe(false)
    }
  )

  it('any other status is an error with its message, and clears the data', async () => {
    const slot = idleSlot()
    slot.data = 'earlier'
    await loadInto(slot, async () => {
      throw failure(500, 'server fell over')
    })
    expect(slot.error).toBe('server fell over')
    expect(slot.missing).toBe(false)
    expect(slot.data).toBeNull()
  })

  it('falls back to a generic message when the error has none', async () => {
    const slot = idleSlot()
    await loadInto(slot, async () => {
      throw {}
    })
    expect(slot.error).toBe('Unknown error')
  })

  it('a new request clears an earlier error and missing', async () => {
    const slot = idleSlot()
    await loadInto(slot, async () => {
      throw failure(404)
    })
    expect(slot.missing).toBe(true)
    const p = loadInto(slot, async () => 'ok')
    expect(slot.missing).toBe(false)
    expect(slot.error).toBeNull()
    await p
    expect(slot.data).toBe('ok')
  })

  describe('settled', () => {
    it('is false for a slot nothing has asked yet, and while loading', () => {
      expect(settled(idleSlot())).toBe(false)
      expect(settled({ ...idleSlot(), loading: true })).toBe(false)
    })

    it('is true with data, with an error and when the part is not switched on', () => {
      expect(settled({ ...idleSlot(), data: {} })).toBe(true)
      expect(settled({ ...idleSlot(), error: 'x' })).toBe(true)
      expect(settled({ ...idleSlot(), missing: true })).toBe(true)
    })

    it('is false while a reload is under way, even with old data', () => {
      expect(settled({ ...idleSlot(), data: {}, loading: true })).toBe(false)
    })
  })

  it('says what a missing part reads in plain words', () => {
    expect(NOT_SWITCHED_ON).toBe('This part is not switched on yet.')
  })
})
