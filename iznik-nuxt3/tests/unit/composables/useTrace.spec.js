import { describe, it, expect, vi, beforeEach } from 'vitest'

// UUID v4 format (permissive — matches both crypto.randomUUID and the Math.random fallback)
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

describe('useTrace', () => {
  let getSessionId, getTraceId, newTraceId, getTraceHeaders, onTraceChange, useTrace

  beforeEach(async () => {
    // Clear browser session state before each test
    sessionStorage.clear()
    // Reset module registry so each test gets fresh module-level state
    vi.resetModules()
    const mod = await import('~/composables/useTrace')
    getSessionId = mod.getSessionId
    getTraceId = mod.getTraceId
    newTraceId = mod.newTraceId
    getTraceHeaders = mod.getTraceHeaders
    onTraceChange = mod.onTraceChange
    useTrace = mod.useTrace
  })

  describe('getSessionId', () => {
    it('returns a UUID-shaped string', () => {
      expect(UUID_RE.test(getSessionId())).toBe(true)
    })

    it('stores the generated ID in sessionStorage', () => {
      const id = getSessionId()
      expect(sessionStorage.getItem('freegle_session_id')).toBe(id)
    })

    it('returns the same value on repeated calls', () => {
      const first = getSessionId()
      const second = getSessionId()
      expect(first).toBe(second)
    })

    it('returns an existing sessionStorage value instead of generating a new one', () => {
      const preset = 'aaaabbbb-cccc-4ddd-8eee-ffffffffffff'
      sessionStorage.setItem('freegle_session_id', preset)
      // Module var is null after resetModules so getSessionId reads from sessionStorage
      expect(getSessionId()).toBe(preset)
    })
  })

  describe('newTraceId', () => {
    it('returns a UUID-shaped string', () => {
      expect(UUID_RE.test(newTraceId())).toBe(true)
    })

    it('returns a different ID on each call', () => {
      const a = newTraceId()
      const b = newTraceId()
      expect(a).not.toBe(b)
    })
  })

  describe('getTraceId', () => {
    it('returns a UUID-shaped string', () => {
      expect(UUID_RE.test(getTraceId())).toBe(true)
    })

    it('returns the same ID on repeated calls without newTraceId', () => {
      const id = getTraceId()
      expect(getTraceId()).toBe(id)
    })

    it('reflects the most recently generated trace ID after newTraceId', () => {
      const fresh = newTraceId()
      expect(getTraceId()).toBe(fresh)
    })
  })

  describe('onTraceChange', () => {
    it('invokes the registered callback when newTraceId is called', () => {
      const cb = vi.fn()
      onTraceChange(cb)
      newTraceId()
      expect(cb).toHaveBeenCalledOnce()
    })

    it('passes the new trace ID as the first argument', () => {
      const cb = vi.fn()
      onTraceChange(cb)
      const id = newTraceId()
      expect(cb).toHaveBeenCalledWith(id, expect.any(String))
    })

    it('passes the session ID as the second argument', () => {
      const sessionId = getSessionId()
      const cb = vi.fn()
      onTraceChange(cb)
      newTraceId()
      expect(cb).toHaveBeenCalledWith(expect.any(String), sessionId)
    })

    it('does not invoke the callback if newTraceId has not been called', () => {
      const cb = vi.fn()
      onTraceChange(cb)
      expect(cb).not.toHaveBeenCalled()
    })
  })

  describe('getTraceHeaders', () => {
    it('returns an object with X-Trace-ID, X-Session-ID, and X-Client-Timestamp', () => {
      const headers = getTraceHeaders()
      expect(headers).toHaveProperty('X-Trace-ID')
      expect(headers).toHaveProperty('X-Session-ID')
      expect(headers).toHaveProperty('X-Client-Timestamp')
    })

    it('X-Trace-ID is a UUID-shaped string', () => {
      expect(UUID_RE.test(getTraceHeaders()['X-Trace-ID'])).toBe(true)
    })

    it('X-Session-ID is a UUID-shaped string', () => {
      expect(UUID_RE.test(getTraceHeaders()['X-Session-ID'])).toBe(true)
    })

    it('X-Client-Timestamp is a parseable ISO 8601 date string', () => {
      const ts = getTraceHeaders()['X-Client-Timestamp']
      const parsed = new Date(ts)
      expect(parsed.toISOString()).toBe(ts)
    })

    it('X-Session-ID matches getSessionId()', () => {
      const sessionId = getSessionId()
      expect(getTraceHeaders()['X-Session-ID']).toBe(sessionId)
    })

    it('X-Trace-ID matches getTraceId()', () => {
      const traceId = getTraceId()
      expect(getTraceHeaders()['X-Trace-ID']).toBe(traceId)
    })
  })

  describe('useTrace composable', () => {
    it('exposes all expected functions', () => {
      const api = useTrace()
      expect(typeof api.getSessionId).toBe('function')
      expect(typeof api.getTraceId).toBe('function')
      expect(typeof api.newTraceId).toBe('function')
      expect(typeof api.getTraceHeaders).toBe('function')
      expect(typeof api.onTraceChange).toBe('function')
    })

    it('composable functions are the same references as the direct exports', () => {
      const api = useTrace()
      expect(api.getSessionId).toBe(getSessionId)
      expect(api.getTraceId).toBe(getTraceId)
      expect(api.newTraceId).toBe(newTraceId)
      expect(api.getTraceHeaders).toBe(getTraceHeaders)
      expect(api.onTraceChange).toBe(onTraceChange)
    })
  })
})
