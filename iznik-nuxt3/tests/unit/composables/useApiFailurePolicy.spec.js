import { describe, it, expect, vi, beforeEach } from 'vitest'
import { APIError } from '~/api/APIErrors'

const mockWarn = vi.fn()
vi.mock('~/composables/useClientLog', () => ({
  warn: (...args) => mockWarn(...args),
}))

const {
  normaliseApiPath,
  apiFailureDisposition,
  apiFailureFingerprint,
  underSessionCap,
  resetSessionCap,
  applyApiFailurePolicy,
  describeNonError,
  MAX_EVENTS_PER_FINGERPRINT,
} = await import('~/composables/useApiFailurePolicy')

function apiError(status, path = '/x', method = 'GET') {
  return new APIError({ request: { method, path }, response: { status } }, 'e')
}

describe('useApiFailurePolicy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetSessionCap()
  })

  describe('normaliseApiPath', () => {
    it.each([
      ['/user/45209734/applied?loggedInAs=1&requestid=2', '/user/:id/applied'],
      ['/message/1,2,3', '/message/:id'],
      ['/group/work', '/group/work'],
      ['/config/ux_experiments', '/config/ux_experiments'],
      ['/chat/123/message/456', '/chat/:id/message/:id'],
      ['', ''],
      [undefined, ''],
    ])('%s becomes %s', (path, expected) => {
      expect(normaliseApiPath(path)).toBe(expected)
    })
  })

  describe('apiFailureDisposition', () => {
    it.each([
      [401, 'drop'],
      [403, 'drop'],
      [404, 'drop'],
      [409, 'drop'],
      [410, 'drop'],
      [429, 'drop'],
      [400, 'error'],
      [422, 'error'],
      [500, 'error'],
      [501, 'error'],
      [502, 'unreachable'],
      [503, 'unreachable'],
      [504, 'unreachable'],
      [null, 'unreachable'],
      [undefined, 'unreachable'],
    ])('%s is %s', (status, expected) => {
      expect(apiFailureDisposition(status)).toBe(expected)
    })
  })

  describe('apiFailureFingerprint', () => {
    it('gives every unreachable call one fingerprint', () => {
      expect(apiFailureFingerprint('unreachable', 'GET', '/a/1', null)).toEqual(
        apiFailureFingerprint('unreachable', 'POST', '/b', 504)
      )
    })

    it('gives an error one fingerprint per method, normalised path and status', () => {
      expect(apiFailureFingerprint('error', 'GET', '/user/5?x=1', 500)).toEqual(
        ['api-error', 'GET', '/user/:id', '500']
      )
    })
  })

  describe('underSessionCap', () => {
    it('allows the limit and then refuses, per fingerprint', () => {
      const a = ['api-error', 'GET', '/a', '500']
      const b = ['api-error', 'GET', '/b', '500']

      for (let i = 0; i < MAX_EVENTS_PER_FINGERPRINT; i++) {
        expect(underSessionCap(a)).toBe(true)
      }

      expect(underSessionCap(a)).toBe(false)
      expect(underSessionCap(b)).toBe(true)
    })
  })

  describe('applyApiFailurePolicy', () => {
    it('leaves an event that is not an API error alone', () => {
      const event = {}
      expect(
        applyApiFailurePolicy(event, { originalException: new Error() })
      ).toBe(true)
      expect(applyApiFailurePolicy(event, undefined)).toBe(true)
      expect(event.fingerprint).toBeUndefined()
    })

    it('drops a 401 captured by a component, and logs it', () => {
      expect(
        applyApiFailurePolicy(
          {},
          {
            originalException: apiError(401, '/stripecreateintent?x=1', 'POST'),
          }
        )
      ).toBe(false)
      expect(mockWarn).toHaveBeenCalledWith(
        'API failure not sent to Sentry',
        expect.objectContaining({
          api_status: 401,
          api_method: 'POST',
          api_path: '/stripecreateintent',
        })
      )
    })

    it('keeps a 500 and fingerprints it', () => {
      const event = {}
      expect(
        applyApiFailurePolicy(event, {
          originalException: apiError(500, '/m/9'),
        })
      ).toBe(true)
      expect(event.fingerprint).toEqual(['api-error', 'GET', '/m/:id', '500'])
    })

    it('turns a call with no reply into the api-unreachable warning with the endpoint as a tag', () => {
      const event = { tags: { a: 'b' } }
      expect(
        applyApiFailurePolicy(event, {
          originalException: apiError(null, '/chat?x=1', 'GET'),
        })
      ).toBe(true)
      expect(event.fingerprint).toEqual(['api-unreachable'])
      expect(event.level).toBe('warning')
      expect(event.tags).toEqual({
        a: 'b',
        api_path: '/chat',
        api_method: 'GET',
      })
    })

    it('drops an error BaseAPI already sent', () => {
      const err = apiError(500)
      err.sentryReported = true
      expect(applyApiFailurePolicy({}, { originalException: err })).toBe(false)
    })

    it('drops events past the session cap', () => {
      const results = []
      for (let i = 0; i < MAX_EVENTS_PER_FINGERPRINT + 2; i++) {
        results.push(
          applyApiFailurePolicy({}, { originalException: apiError(500) })
        )
      }

      expect(results.filter(Boolean)).toHaveLength(MAX_EVENTS_PER_FINGERPRINT)
    })
  })

  describe('describeNonError', () => {
    it('describes an empty object', () => {
      expect(describeNonError({})).toEqual({
        reason_type: 'object',
        reason_constructor: 'Object',
        reason_keys: [],
      })
    })

    it('describes an Event by its type and constructor', () => {
      expect(describeNonError(new Event('error'))).toMatchObject({
        reason_constructor: 'Event',
        reason_event_type: 'error',
      })
    })

    it('keeps the text of a string, shortened', () => {
      expect(describeNonError('x'.repeat(500)).reason_text).toHaveLength(200)
    })

    it('copes with null', () => {
      expect(describeNonError(null)).toEqual({ reason_type: 'object' })
    })
  })
})
