import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { parseRetryAfter, postDiscourseReply } from '../actions/index'

/**
 * Discourse rate-limit (429) handling.
 *
 * Discourse rate-limits writes aggressively, and the FSM posts a burst of
 * deployed-reply drafts in one iteration. Without backoff, postDiscourseReply
 * dropped replies on the first 429 (retried only on the next iteration) and the
 * sibling discover_active_topics GET — running moments later in the same step —
 * silently 429'd too, returning zero topics and missing active bug reports.
 *
 * These tests pin the JS-side retry behaviour. (discover_active_topics shells
 * out to a Python helper that mirrors the same retry-with-backoff loop; that one
 * is covered by the FSM integration run.)
 *
 * postDiscourseReply reads the real /home/edward/profile.json for the API key,
 * but every network call is mocked, so no request leaves the machine.
 */

type FakeResponse = {
  status: number
  text: () => Promise<string>
  headers: { get: (k: string) => string | null }
}

function res(status: number, body = '', retryAfter?: string): FakeResponse {
  return {
    status,
    text: async () => body,
    headers: {
      get: (k: string) => (k.toLowerCase() === 'retry-after' ? retryAfter ?? null : null),
    },
  }
}

const RATE_LIMIT_BODY = JSON.stringify({
  errors: ['You’ve performed this action too many times.'],
  error_type: 'rate_limit',
  extras: { wait_seconds: 44, time_left: '44 seconds' },
})

describe('parseRetryAfter', () => {
  it('prefers the Retry-After header', () => {
    expect(parseRetryAfter('30', RATE_LIMIT_BODY)).toBe(30)
  })

  it('falls back to extras.wait_seconds when no header', () => {
    expect(parseRetryAfter(null, RATE_LIMIT_BODY)).toBe(44)
  })

  it('falls back to a default when neither is present', () => {
    expect(parseRetryAfter(null, 'not json')).toBe(5)
    expect(parseRetryAfter(null, '{}')).toBe(5)
  })

  it('ignores a non-numeric / non-positive header', () => {
    expect(parseRetryAfter('soon', RATE_LIMIT_BODY)).toBe(44)
    expect(parseRetryAfter('0', RATE_LIMIT_BODY)).toBe(44)
  })
})

describe('postDiscourseReply 429 retry', () => {
  let fetchMock: ReturnType<typeof vi.fn>
  const sleeps: number[] = []
  const sleepFn = async (ms: number) => { sleeps.push(ms) }

  beforeEach(() => {
    sleeps.length = 0
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('retries after a 429 and succeeds on the next attempt', async () => {
    fetchMock
      .mockResolvedValueOnce(res(429, RATE_LIMIT_BODY, '2'))
      .mockResolvedValueOnce(res(200))

    const result = await postDiscourseReply(9692, 'a deployed-reply', 8, { sleepFn })

    expect(result.ok).toBe(true)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    // Honoured the Retry-After header (2s), not the body's wait_seconds.
    expect(sleeps).toEqual([2000])
  })

  it('backs off using extras.wait_seconds when no Retry-After header, capped at 60s', async () => {
    fetchMock
      .mockResolvedValueOnce(res(429, RATE_LIMIT_BODY)) // wait_seconds: 44
      .mockResolvedValueOnce(res(201))

    const result = await postDiscourseReply(9692, 'body', undefined, { sleepFn })

    expect(result.ok).toBe(true)
    expect(sleeps).toEqual([44000])
  })

  it('caps a pathological Retry-After at 60s', async () => {
    fetchMock
      .mockResolvedValueOnce(res(429, RATE_LIMIT_BODY, '9999'))
      .mockResolvedValueOnce(res(200))

    await postDiscourseReply(9692, 'body', undefined, { sleepFn })
    expect(sleeps).toEqual([60000])
  })

  it('gives up after maxRetries consecutive 429s and reports the error', async () => {
    fetchMock.mockResolvedValue(res(429, RATE_LIMIT_BODY, '1'))

    const result = await postDiscourseReply(9692, 'body', undefined, { maxRetries: 3, sleepFn })

    expect(result.ok).toBe(false)
    expect(result.error).toContain('HTTP 429')
    // 3 attempts total, 2 backoffs between them (no sleep after the final failure).
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(sleeps).toEqual([1000, 1000])
  })

  it('does not retry a non-429 error', async () => {
    fetchMock.mockResolvedValueOnce(res(422, 'unprocessable'))

    const result = await postDiscourseReply(9692, 'body', undefined, { sleepFn })

    expect(result.ok).toBe(false)
    expect(result.error).toContain('HTTP 422')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(sleeps).toEqual([])
  })
})
