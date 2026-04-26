import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fetchRetry } from '~/composables/useFetchRetry'
import { useMiscStore } from '~/stores/misc'

vi.mock('~/stores/misc')

describe('useFetchRetry', () => {
  let mockFetch
  let miscStore

  beforeEach(() => {
    vi.clearAllMocks()

    miscStore = {
      waitForOnline: vi.fn().mockResolvedValue(undefined),
      unloading: false,
    }
    useMiscStore.mockReturnValue(miscStore)

    mockFetch = vi.fn()
  })

  afterEach(() => {
    vi.clearAllTimers()
  })

  describe('successful responses', () => {
    it('should resolve with status and data on 200 response', async () => {
      const responseData = { success: true }
      mockFetch.mockResolvedValueOnce({
        status: 200,
        json: vi.fn().mockResolvedValueOnce(responseData),
      })

      const retryFetch = fetchRetry(mockFetch)
      const result = await retryFetch('http://test.com')

      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('should handle 204 No Content without parsing JSON', async () => {
      mockFetch.mockResolvedValueOnce({
        status: 204,
        json: vi.fn().mockRejectedValueOnce(new Error('No content')),
      })

      const retryFetch = fetchRetry(mockFetch)
      const result = await retryFetch('http://test.com')

      expect(result).toEqual([204, null])
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('should handle various 2xx status codes', async () => {
      const responseData = { id: 123 }
      mockFetch.mockResolvedValueOnce({
        status: 201,
        json: vi.fn().mockResolvedValueOnce(responseData),
      })

      const retryFetch = fetchRetry(mockFetch)
      const result = await retryFetch('http://test.com')

      expect(result).toEqual([201, responseData])
    })
  })

  describe('error handling', () => {
    it('should reject on AbortError without retry', async () => {
      const abortError = new Error('Aborted')
      abortError.name = 'AbortError'
      mockFetch.mockRejectedValueOnce(abortError)

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await expect(promise).rejects.toEqual(abortError)
      expect(mockFetch).toHaveBeenCalledTimes(1)
      vi.useRealTimers()
    })

    it('should reject on 404 Not Found without retry', async () => {
      mockFetch.mockResolvedValueOnce({
        status: 404,
        statusText: 'Not Found',
        json: vi.fn().mockResolvedValueOnce({ error: 'Not found' }),
      })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await expect(promise).rejects.toMatchObject({
        message: 'Request failed with 404',
      })
      expect(mockFetch).toHaveBeenCalledTimes(1)
      vi.useRealTimers()
    })

    it('should include response data in FetchError for 4xx errors', async () => {
      const errorData = { message: 'Bad request', code: 'INVALID_INPUT' }
      mockFetch.mockResolvedValueOnce({
        status: 400,
        statusText: 'Bad Request',
        json: vi.fn().mockResolvedValueOnce(errorData),
      })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      const error = await promise.catch((e) => e)
      expect(error.data).toEqual(errorData)
      vi.useRealTimers()
    })
  })

  describe('retry logic', () => {
    it('should retry on network error and eventually succeed', async () => {
      const responseData = { success: true }

      // First two calls fail with network error, third succeeds
      mockFetch
        .mockRejectedValueOnce(new Error('Network error'))
        .mockRejectedValueOnce(new Error('Network error'))
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      // Advance through retry delays (1000ms for attempt 1, 2000ms for attempt 2)
      await vi.advanceTimersByTimeAsync(3000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(3)
      vi.useRealTimers()
    })

    it('should retry on 500 Internal Server Error', async () => {
      const responseData = { success: true }

      mockFetch
        .mockResolvedValueOnce({
          status: 500,
          statusText: 'Internal Server Error',
        })
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000) // First retry delay

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('should retry on "load failed" error message', async () => {
      const responseData = { success: true }

      mockFetch
        .mockResolvedValueOnce({
          status: 0,
          statusText: 'Load Failed',
        })
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('should retry on "failed to fetch" error message', async () => {
      const responseData = { success: true }
      const failedToFetchError = new Error('Failed to fetch')

      mockFetch
        .mockRejectedValueOnce(failedToFetchError)
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('should retry on 200 response with no data', async () => {
      const responseData = { success: true }

      mockFetch
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(null),
        })
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('should retry on JSON parse error with 2xx status', async () => {
      const responseData = { success: true }

      mockFetch
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockRejectedValueOnce(new Error('Invalid JSON')),
        })
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('should call waitForOnline before retrying', async () => {
      const responseData = { success: true }

      mockFetch
        .mockRejectedValueOnce(new Error('Network error'))
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      await vi.advanceTimersByTimeAsync(1000)
      await promise

      expect(miscStore.waitForOnline).toHaveBeenCalled()
      vi.useRealTimers()
    })
  })

  describe('max retries', () => {
    it('should reject after 10 retries', async () => {
      mockFetch.mockRejectedValue(new Error('Network error'))

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      // Advance through all 10 retry delays (1000+2000+...+10000 = 55000ms)
      await vi.advanceTimersByTimeAsync(55000)

      const error = await promise.catch((e) => e)
      expect(error.message).toBe('Too many retries, give up')
      expect(mockFetch).toHaveBeenCalledTimes(11) // Initial + 10 retries
      vi.useRealTimers()
    })
  })

  describe('unloading state', () => {
    it('should not retry when unloading', async () => {
      mockFetch.mockRejectedValueOnce(new Error('Network error'))
      miscStore.unloading = true

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      vi.advanceTimersByTime(1000)

      const error = await promise.catch((e) => e)
      expect(error.message).toBe('Unloading, no retry')
      expect(mockFetch).toHaveBeenCalledTimes(1)
      vi.useRealTimers()
    })
  })

  describe('retry delay calculation', () => {
    it('should use exponential delay: attempt * 1000', async () => {
      const responseData = { success: true }
      const delayCalls = []

      mockFetch
        .mockRejectedValueOnce(new Error('Network error'))
        .mockRejectedValueOnce(new Error('Network error'))
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com')

      // First retry at 1000ms
      await vi.advanceTimersByTimeAsync(1000)
      // Second retry at 2000ms
      await vi.advanceTimersByTimeAsync(2000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(3)
      vi.useRealTimers()
    })
  })

  describe('request parameters', () => {
    it('should pass input and init to underlying fetch', async () => {
      mockFetch.mockResolvedValueOnce({
        status: 200,
        json: vi.fn().mockResolvedValueOnce({ data: 'test' }),
      })

      const retryFetch = fetchRetry(mockFetch)
      const init = { method: 'POST', headers: { 'Content-Type': 'application/json' } }
      await retryFetch('http://test.com/api', init)

      expect(mockFetch).toHaveBeenCalledWith('http://test.com/api', init)
    })
  })
})
