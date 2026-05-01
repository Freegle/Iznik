import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { createRetryCoalescer } from '~/composables/useUppyRetryCoalesce'

// createRetryCoalescer uses queueMicrotask to batch bursts of Uppy error
// events. We stub queueMicrotask so tests can drive the microtask queue
// deterministically rather than relying on async timing.
describe('createRetryCoalescer', () => {
  let microtaskQueue

  beforeEach(() => {
    microtaskQueue = []
    vi.stubGlobal('queueMicrotask', (fn) => microtaskQueue.push(fn))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  function flush() {
    const queue = [...microtaskQueue]
    microtaskQueue = []
    queue.forEach((fn) => fn())
  }

  describe('coalescing behaviour', () => {
    it('does not call retryAll() until the microtask fires', () => {
      const retryAll = vi.fn()
      const coalescer = createRetryCoalescer(() => ({ retryAll }))

      coalescer()
      coalescer()
      coalescer()

      expect(retryAll).not.toHaveBeenCalled()
      flush()
      expect(retryAll).toHaveBeenCalledTimes(1)
    })

    it('queues only one microtask regardless of call count within a burst', () => {
      const coalescer = createRetryCoalescer(() => ({ retryAll: vi.fn() }))

      coalescer()
      coalescer()
      coalescer()

      expect(microtaskQueue).toHaveLength(1)
    })

    it('resets after flushing so a second burst schedules a new retry', () => {
      const retryAll = vi.fn()
      const coalescer = createRetryCoalescer(() => ({ retryAll }))

      // First burst
      coalescer()
      coalescer()
      flush()

      // Second burst — must work without leftover scheduled flag
      coalescer()
      flush()

      expect(retryAll).toHaveBeenCalledTimes(2)
    })

    it('a second call after flushing queues a fresh microtask', () => {
      const coalescer = createRetryCoalescer(() => ({ retryAll: vi.fn() }))

      coalescer()
      flush()

      expect(microtaskQueue).toHaveLength(0)

      coalescer()
      expect(microtaskQueue).toHaveLength(1)
    })
  })

  describe('error handling', () => {
    it('swallows errors thrown by retryAll() and does not propagate them', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      const coalescer = createRetryCoalescer(() => ({
        retryAll: () => {
          throw new Error('call is locked')
        },
      }))

      coalescer()
      expect(() => flush()).not.toThrow()
      expect(consoleSpy).toHaveBeenCalledWith(
        'retryAll() failed (Uppy state corruption)',
        expect.any(Error)
      )
    })

    it('logs the original error object, not just a message string', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
      const thrownError = new Error('state corruption')
      const coalescer = createRetryCoalescer(() => ({
        retryAll: () => {
          throw thrownError
        },
      }))

      coalescer()
      flush()

      const [, loggedError] = consoleSpy.mock.calls[0]
      expect(loggedError).toBe(thrownError)
    })
  })

  describe('null / missing uppy instance', () => {
    it('handles null uppy without throwing', () => {
      const coalescer = createRetryCoalescer(() => null)
      coalescer()
      expect(() => flush()).not.toThrow()
    })

    it('handles undefined uppy without throwing', () => {
      const coalescer = createRetryCoalescer(() => undefined)
      coalescer()
      expect(() => flush()).not.toThrow()
    })
  })

  describe('multiple independent instances', () => {
    it('each instance maintains its own scheduling state', () => {
      const retryAll1 = vi.fn()
      const retryAll2 = vi.fn()
      const coalescer1 = createRetryCoalescer(() => ({ retryAll: retryAll1 }))
      const coalescer2 = createRetryCoalescer(() => ({ retryAll: retryAll2 }))

      coalescer1()
      coalescer1()
      coalescer2()

      // Each coalescer schedules its own microtask
      expect(microtaskQueue).toHaveLength(2)
      flush()

      expect(retryAll1).toHaveBeenCalledTimes(1)
      expect(retryAll2).toHaveBeenCalledTimes(1)
    })

    it('instance A flushing does not affect instance B scheduling state', () => {
      const retryAll1 = vi.fn()
      const retryAll2 = vi.fn()
      const coalescer1 = createRetryCoalescer(() => ({ retryAll: retryAll1 }))
      const coalescer2 = createRetryCoalescer(() => ({ retryAll: retryAll2 }))

      coalescer1()
      flush()

      // coalescer2 has never fired — calling it now should schedule a microtask
      coalescer2()
      expect(microtaskQueue).toHaveLength(1)
      flush()
      expect(retryAll2).toHaveBeenCalledTimes(1)
    })
  })
})
