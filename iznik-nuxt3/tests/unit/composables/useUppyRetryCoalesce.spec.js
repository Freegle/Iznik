import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import Uppy from '@uppy/core'
import {
  createRetryCoalescer,
  removeFinishedFiles,
} from '~/composables/useUppyRetryCoalesce'

describe('createRetryCoalescer', () => {
  let microtasks

  beforeEach(() => {
    microtasks = []
    vi.stubGlobal('queueMicrotask', (fn) => microtasks.push(fn))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    microtasks = []
  })

  function flushMicrotasks() {
    const pending = microtasks.splice(0)
    pending.forEach((fn) => fn())
  }

  describe('basic scheduling', () => {
    it('calls retryAll once after the first scheduleRetry call', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))

      scheduleRetry()
      expect(retryAll).not.toHaveBeenCalled()

      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(1)
    })

    it('queues exactly one microtask per burst', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))

      scheduleRetry()
      scheduleRetry()
      scheduleRetry()

      expect(microtasks).toHaveLength(1)

      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(1)
    })

    it('ignores subsequent calls within the same burst', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))

      scheduleRetry()
      scheduleRetry()
      scheduleRetry()
      scheduleRetry()
      scheduleRetry()

      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(1)
    })
  })

  describe('reset behaviour', () => {
    it('resets the scheduled flag after the microtask runs', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))

      // First burst
      scheduleRetry()
      scheduleRetry()
      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(1)

      // Second burst after reset
      scheduleRetry()
      scheduleRetry()
      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(2)
    })

    it('allows independent bursts separated by microtask flushes', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))

      scheduleRetry()
      flushMicrotasks()

      scheduleRetry()
      flushMicrotasks()

      scheduleRetry()
      flushMicrotasks()

      expect(retryAll).toHaveBeenCalledTimes(3)
    })
  })

  describe('error handling', () => {
    it('swallows errors thrown by retryAll', () => {
      const retryAll = vi.fn().mockImplementation(() => {
        throw new Error('call is locked')
      })
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))
      vi.spyOn(console, 'error').mockImplementation(() => {})

      scheduleRetry()
      expect(() => flushMicrotasks()).not.toThrow()
    })

    it('logs retryAll errors to console.error', () => {
      const error = new Error('Uppy state corruption')
      const retryAll = vi.fn().mockImplementation(() => {
        throw error
      })
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))
      const consoleError = vi
        .spyOn(console, 'error')
        .mockImplementation(() => {})

      scheduleRetry()
      flushMicrotasks()

      expect(consoleError).toHaveBeenCalledTimes(1)
      expect(consoleError).toHaveBeenCalledWith(
        'retryAll() failed (Uppy state corruption)',
        error
      )
    })

    it('handles a rejected promise returned by retryAll (async Uppy corruption)', async () => {
      const rejection = new TypeError(
        "Cannot use 'in' operator to search for 'error' in undefined"
      )
      const retryAll = vi.fn().mockRejectedValue(rejection)
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))
      const consoleError = vi
        .spyOn(console, 'error')
        .mockImplementation(() => {})

      scheduleRetry()
      // The synchronous flush kicks off retryAll(); its rejection settles on a
      // later microtask, so let the event loop drain before asserting.
      flushMicrotasks()
      await Promise.resolve()
      await Promise.resolve()

      expect(consoleError).toHaveBeenCalledWith(
        'retryAll() rejected (Uppy state corruption)',
        rejection
      )
    })

    it('continues to accept new calls after a swallowed error', () => {
      let callCount = 0
      const retryAll = vi.fn().mockImplementation(() => {
        callCount++
        if (callCount === 1) throw new Error('first call fails')
      })
      const scheduleRetry = createRetryCoalescer(() => ({ retryAll }))
      vi.spyOn(console, 'error').mockImplementation(() => {})

      // First burst — retryAll throws
      scheduleRetry()
      expect(() => flushMicrotasks()).not.toThrow()
      expect(retryAll).toHaveBeenCalledTimes(1)

      // Second burst — should work normally
      scheduleRetry()
      flushMicrotasks()
      expect(retryAll).toHaveBeenCalledTimes(2)
    })
  })

  describe('null/undefined uppy guard', () => {
    it('does not throw when getUppy returns null', () => {
      const scheduleRetry = createRetryCoalescer(() => null)

      scheduleRetry()
      expect(() => flushMicrotasks()).not.toThrow()
    })

    it('does not throw when getUppy returns undefined', () => {
      const scheduleRetry = createRetryCoalescer(() => undefined)

      scheduleRetry()
      expect(() => flushMicrotasks()).not.toThrow()
    })

    it('does not call retryAll when uppy is null', () => {
      const retryAll = vi.fn()
      const scheduleRetry = createRetryCoalescer(() => null)

      scheduleRetry()
      flushMicrotasks()

      expect(retryAll).not.toHaveBeenCalled()
    })
  })

  describe('coalescer isolation', () => {
    it('two separate coalescer instances do not interfere', () => {
      const retryAll1 = vi.fn()
      const retryAll2 = vi.fn()
      const scheduleRetry1 = createRetryCoalescer(() => ({
        retryAll: retryAll1,
      }))
      const scheduleRetry2 = createRetryCoalescer(() => ({
        retryAll: retryAll2,
      }))

      scheduleRetry1()
      scheduleRetry1()
      scheduleRetry2()

      expect(microtasks).toHaveLength(2)

      flushMicrotasks()
      expect(retryAll1).toHaveBeenCalledTimes(1)
      expect(retryAll2).toHaveBeenCalledTimes(1)
    })
  })
})

describe('removeFinishedFiles', () => {
  function uppyWith(names) {
    const uppy = new Uppy()
    const ids = names.map((name) =>
      uppy.addFile({ name, type: 'image/jpeg', data: new Blob([name]) })
    )
    return { uppy, ids }
  }

  it('keeps a photo whose retry is still running when its batch completes', () => {
    const { uppy, ids } = uppyWith(['done.jpg', 'retrying.jpg'])

    const empty = removeFinishedFiles(uppy, {
      successful: [uppy.getFile(ids[0])],
      failed: [uppy.getFile(ids[1])],
    })

    expect(empty).toBe(false)
    expect(uppy.getFile(ids[0])).toBeUndefined()
    // @uppy/tus reads this file's progress on every chunk of the retry.
    expect(uppy.getFile(ids[1]).progress).toBeDefined()
  })

  it('reports empty once every photo has finished', () => {
    const { uppy, ids } = uppyWith(['a.jpg', 'b.jpg'])

    expect(
      removeFinishedFiles(uppy, {
        successful: ids.map((id) => uppy.getFile(id)),
        failed: [],
      })
    ).toBe(true)
    expect(uppy.getFiles()).toHaveLength(0)
  })

  it('ignores photos already gone and a result with nothing in it', () => {
    const { uppy, ids } = uppyWith(['a.jpg'])

    expect(
      removeFinishedFiles(uppy, { successful: [{ id: 'gone' }, null] })
    ).toBe(false)
    expect(removeFinishedFiles(uppy, {})).toBe(false)
    expect(uppy.getFile(ids[0])).toBeDefined()
    expect(removeFinishedFiles(null, {})).toBe(true)
  })
})
