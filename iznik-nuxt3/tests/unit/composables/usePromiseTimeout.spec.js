import { describe, it, expect, vi, afterEach } from 'vitest'
import { withTimeout } from '~/composables/usePromiseTimeout'

describe('usePromiseTimeout', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('passes through the resolved value when the promise wins', async () => {
    await expect(withTimeout(Promise.resolve('done'), 1000)).resolves.toBe(
      'done'
    )
  })

  it('passes through a rejection when the promise wins', async () => {
    await expect(
      withTimeout(Promise.reject(new Error('nope')), 1000)
    ).rejects.toThrow('nope')
  })

  it('rejects once the deadline passes', async () => {
    vi.useFakeTimers()

    const settled = vi.fn()
    withTimeout(new Promise(() => {}), 5000, 'Took too long').catch(settled)

    await vi.advanceTimersByTimeAsync(4999)
    expect(settled).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)

    expect(settled).toHaveBeenCalledTimes(1)
    expect(settled.mock.calls[0][0].message).toBe('Took too long')
  })

  it('uses a default message when none is given', async () => {
    vi.useFakeTimers()

    const settled = vi.fn()
    withTimeout(new Promise(() => {}), 10).catch(settled)

    await vi.advanceTimersByTimeAsync(20)

    expect(settled.mock.calls[0][0].message).toBe('Timed out')
  })

  // A stray timer would keep the event loop (and in the app, a pending task)
  // alive after the work has already finished.
  it('clears its timer as soon as the promise settles', async () => {
    vi.useFakeTimers()
    const clearSpy = vi.spyOn(globalThis, 'clearTimeout')

    await withTimeout(Promise.resolve('quick'), 60000)

    expect(clearSpy).toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })
})
