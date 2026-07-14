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
      // Attach catch before advancing to prevent unhandled rejection during timer advance
      const errorPromise = promise.catch((e) => e)

      // Advance through all 10 retry delays (0+1000+2000+...+9000 = 45000ms; 55000ms is safe)
      await vi.advanceTimersByTimeAsync(55000)

      const error = await errorPromise
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
      const init = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      }
      await retryFetch('http://test.com/api', init)

      expect(mockFetch).toHaveBeenCalledWith('http://test.com/api', init)
    })
  })

  describe('mutating requests are not retried on an ambiguous outcome (#9913)', () => {
    // A POST (e.g. sending a chat message) is not idempotent. If the request actually
    // reached the server and created the row, but the client sees a network error, a
    // 5xx, or a 200 it can't parse, we can't tell whether the write already happened.
    // Retrying anyway resends the same create and can produce a second, real,
    // duplicate row. GET/HEAD/OPTIONS have no such side effect and keep retrying.

    it('does not retry a POST on a network error - rejects with the underlying error after one attempt', async () => {
      const networkError = new Error('Network error')
      mockFetch.mockRejectedValueOnce(networkError)

      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1/message', {
        method: 'POST',
      })

      await expect(promise).rejects.toThrow('Network error')
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('does not retry a POST that returns a 200 it cannot parse as JSON - the write may already have happened', async () => {
      mockFetch.mockResolvedValueOnce({
        status: 200,
        json: vi.fn().mockRejectedValueOnce(new Error('Invalid JSON')),
      })

      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1/message', {
        method: 'POST',
      })

      await expect(promise).rejects.toThrow('Invalid JSON')
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('does not retry a POST on a 500', async () => {
      mockFetch.mockResolvedValueOnce({
        status: 500,
        statusText: 'Internal Server Error',
      })

      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1/message', {
        method: 'POST',
      })

      await expect(promise).rejects.toThrow('Request failed with 500')
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('still retries a GET on a network error, unaffected by the mutating-request restriction', async () => {
      const responseData = { success: true }

      mockFetch
        .mockRejectedValueOnce(new Error('Network error'))
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1', { method: 'GET' })

      await vi.advanceTimersByTimeAsync(1000)

      const result = await promise
      expect(result).toEqual([200, responseData])
      expect(mockFetch).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it.each(['HEAD', 'OPTIONS'])(
      'treats %s as a safe method too - it retries like GET',
      async (method) => {
        const responseData = { success: true }

        mockFetch
          .mockRejectedValueOnce(new Error('Network error'))
          .mockResolvedValueOnce({
            status: 200,
            json: vi.fn().mockResolvedValueOnce(responseData),
          })

        vi.useFakeTimers()
        const retryFetch = fetchRetry(mockFetch)
        const promise = retryFetch('http://test.com/thing/1', { method })

        await vi.advanceTimersByTimeAsync(1000)

        const result = await promise
        expect(result).toEqual([200, responseData])
        expect(mockFetch).toHaveBeenCalledTimes(2)
        vi.useRealTimers()
      }
    )

    it.each(['PUT', 'PATCH', 'DELETE'])(
      'does not retry a %s on a network error',
      async (method) => {
        mockFetch.mockRejectedValueOnce(new Error('Network error'))

        const retryFetch = fetchRetry(mockFetch)
        const promise = retryFetch('http://test.com/thing/1', { method })

        await expect(promise).rejects.toThrow('Network error')
        expect(mockFetch).toHaveBeenCalledTimes(1)
      }
    )

    it('treats a lower-case method string as mutating too - does not retry "post"', async () => {
      mockFetch.mockRejectedValueOnce(new Error('Network error'))

      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1/message', {
        method: 'post',
      })

      await expect(promise).rejects.toThrow('Network error')
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('derives the method from a Request-like `input` when `init` has none - a POST Request is not retried', async () => {
      mockFetch.mockRejectedValueOnce(new Error('Network error'))

      const retryFetch = fetchRetry(mockFetch)
      // No `init` at all, and `input` is not a string URL but a Request-shaped
      // object - fetch() would take the method from `input.method` in this case.
      const promise = retryFetch({
        url: 'http://test.com/chat/1/message',
        method: 'POST',
      })

      await expect(promise).rejects.toThrow('Network error')
      expect(mockFetch).toHaveBeenCalledTimes(1)
    })

    it('does not block on connectivity before rejecting a mutating request - fails fast instead of hanging until reconnect', async () => {
      miscStore.waitForOnline.mockReturnValue(new Promise(() => {})) // never resolves
      mockFetch.mockRejectedValueOnce(new Error('Network error'))

      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1/message', {
        method: 'POST',
      })

      await expect(promise).rejects.toThrow('Network error')
      expect(miscStore.waitForOnline).not.toHaveBeenCalled()
    })

    it('GET still awaits waitForOnline before retrying, unaffected by the mutating-request restriction', async () => {
      const responseData = { success: true }

      mockFetch
        .mockRejectedValueOnce(new Error('Network error'))
        .mockResolvedValueOnce({
          status: 200,
          json: vi.fn().mockResolvedValueOnce(responseData),
        })

      vi.useFakeTimers()
      const retryFetch = fetchRetry(mockFetch)
      const promise = retryFetch('http://test.com/chat/1', { method: 'GET' })

      await vi.advanceTimersByTimeAsync(1000)
      await promise

      expect(miscStore.waitForOnline).toHaveBeenCalled()
      vi.useRealTimers()
    })
  })
})
