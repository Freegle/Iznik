/**
 * Tests for composables/useClientLog.js
 *
 * The module uses module-level state (logQueue, pageLoadPhase, etc.).
 * vi.resetModules() in beforeEach ensures each test gets a fresh module
 * instance, the same pattern used in useTrace.spec.js.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

describe('useClientLog', () => {
  let mod

  beforeEach(async () => {
    vi.useFakeTimers()
    vi.resetModules()
    // Intercept fetch so sendLogs doesn't make real network calls.
    global.fetch = vi.fn().mockResolvedValue({ ok: true })
    mod = await import('~/composables/useClientLog')
    // Initialize runtimeConfig — without this, sendLogs returns early because
    // runtimeConfig?.public?.APIv2 is undefined (module-level var starts null).
    mod.useClientLog()
  })

  afterEach(() => {
    vi.runAllTimers()
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  // ------------------------------------------------------------------ //
  // LogLevel enum
  // ------------------------------------------------------------------ //

  describe('LogLevel', () => {
    it('exports expected log level constants', () => {
      expect(mod.LogLevel.DEBUG).toBe('debug')
      expect(mod.LogLevel.INFO).toBe('info')
      expect(mod.LogLevel.WARN).toBe('warn')
      expect(mod.LogLevel.ERROR).toBe('error')
    })
  })

  // ------------------------------------------------------------------ //
  // Page load phase tracking
  // ------------------------------------------------------------------ //

  describe('page load phase tracking', () => {
    it('starts in idle phase', () => {
      expect(mod.getPageLoadPhase()).toBe('idle')
    })

    it.each([
      ['loading'],
      ['interactive'],
      ['idle'],
      ['user_action'],
    ])('setPageLoadPhase("%s") updates getPageLoadPhase', (phase) => {
      mod.setPageLoadPhase(phase)
      expect(mod.getPageLoadPhase()).toBe(phase)
    })

    it('setPageLoadPhase("loading") records start time (no throw)', () => {
      expect(() => mod.setPageLoadPhase('loading')).not.toThrow()
    })

    it('calling setPageLoadPhase("loading") twice does not throw', () => {
      mod.setPageLoadPhase('loading')
      expect(() => mod.setPageLoadPhase('loading')).not.toThrow()
    })
  })

  // ------------------------------------------------------------------ //
  // markPageInteractive
  // ------------------------------------------------------------------ //

  describe('markPageInteractive', () => {
    it('transitions from loading to interactive', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('interactive')
    })

    it('does not change phase if already interactive', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('interactive')
    })

    it('does not change phase if idle (no-op)', () => {
      // starts as idle
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('idle')
    })

    it('does not change phase if user_action', () => {
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('user_action')
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('user_action')
    })

    it('transitions from interactive to idle after 2 s (resetIdleTimeout)', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('interactive')
      vi.advanceTimersByTime(2001)
      expect(mod.getPageLoadPhase()).toBe('idle')
    })
  })

  // ------------------------------------------------------------------ //
  // markUserAction
  // ------------------------------------------------------------------ //

  describe('markUserAction', () => {
    it('transitions from idle to user_action', () => {
      expect(mod.getPageLoadPhase()).toBe('idle')
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('user_action')
    })

    it('does not change phase from loading', () => {
      mod.setPageLoadPhase('loading')
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('loading')
    })

    it('does not change phase from interactive', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('interactive')
    })

    it('resets back to idle after 500 ms', () => {
      mod.markUserAction()
      expect(mod.getPageLoadPhase()).toBe('user_action')
      vi.advanceTimersByTime(501)
      expect(mod.getPageLoadPhase()).toBe('idle')
    })

    it('successive calls do not accumulate – still resets to idle after 500 ms', () => {
      mod.markUserAction()
      vi.advanceTimersByTime(300)
      mod.markUserAction() // This is a no-op because phase is user_action not idle
      vi.advanceTimersByTime(250)
      // The second call was a no-op (phase was already user_action), so the
      // first timer still fired after 300+250=550 ms from the start.
      expect(mod.getPageLoadPhase()).toBe('idle')
    })
  })

  // ------------------------------------------------------------------ //
  // setAuthStore / isAuthStoreReady / getCurrentUserId
  // ------------------------------------------------------------------ //

  describe('setAuthStore', () => {
    it('accepts null without throwing', () => {
      expect(() => mod.setAuthStore(null)).not.toThrow()
    })

    it('accepts an incomplete store object without throwing', () => {
      expect(() => mod.setAuthStore({})).not.toThrow()
    })

    it('accepts a ready store object without throwing', () => {
      expect(() =>
        mod.setAuthStore({ user: { id: 42 }, loginStateKnown: true })
      ).not.toThrow()
    })
  })

  // ------------------------------------------------------------------ //
  // Log functions — queuing + flush
  // ------------------------------------------------------------------ //

  describe('individual log functions', () => {
    it.each([
      ['debug', 'a debug message'],
      ['info', 'an info message'],
      ['warn', 'a warning'],
      ['error', 'an error'],
    ])('%s() queues without throwing', (fn, msg) => {
      expect(() => mod[fn](msg)).not.toThrow()
    })

    it('pageView() queues without throwing', () => {
      expect(() => mod.pageView('home', { extra: 1 })).not.toThrow()
    })

    it('action() queues without throwing', () => {
      expect(() => mod.action('button_clicked', { target: 'submit' })).not.toThrow()
    })

    it('sessionStart() queues without throwing (no appDeviceInfo)', () => {
      expect(() => mod.sessionStart()).not.toThrow()
    })

    it('sessionStart() queues without throwing (with appDeviceInfo)', () => {
      const appDeviceInfo = {
        manufacturer: 'Apple',
        model: 'iPhone 14',
        platform: 'ios',
        osVersion: '16.0',
        webViewVersion: '16.0',
        isVirtual: false,
      }
      expect(() => mod.sessionStart({}, appDeviceInfo)).not.toThrow()
    })

    it('sentryError() queues without throwing', () => {
      expect(() =>
        mod.sentryError('Something broke', 'sentry-event-id-123', { extra: true })
      ).not.toThrow()
    })
  })

  // ------------------------------------------------------------------ //
  // apiRequest — log level selection
  // ------------------------------------------------------------------ //

  describe('apiRequest', () => {
    it.each([
      ['GET', '/api/resource', 150, 200],
      ['POST', '/api/resource', 80, 201],
      ['GET', '/api/resource', 50, 304],
    ])('%s %s (status %i < 400) queues without throwing', (method, path, dur, status) => {
      expect(() => mod.apiRequest(method, path, dur, status)).not.toThrow()
    })

    it.each([
      ['GET', '/api/missing', 30, 404],
      ['POST', '/api/fail', 200, 500],
      ['GET', '/api/auth', 20, 401],
      ['GET', '/api/forbidden', 20, 403],
    ])('%s %s (status %i >= 400) queues without throwing', (method, path, dur, status) => {
      expect(() => mod.apiRequest(method, path, dur, status)).not.toThrow()
    })

    it('apiRequest triggers resetIdleTimeout when in interactive phase', () => {
      mod.setPageLoadPhase('loading')
      mod.markPageInteractive()
      expect(mod.getPageLoadPhase()).toBe('interactive')
      // Make an API call to reset the idle clock
      mod.apiRequest('GET', '/api/test', 10, 200)
      // With idle timeout reset, advancing < 2000 ms should keep phase
      vi.advanceTimersByTime(1500)
      expect(mod.getPageLoadPhase()).toBe('interactive')
      // After 2000 ms from last API call, it becomes idle
      vi.advanceTimersByTime(600)
      expect(mod.getPageLoadPhase()).toBe('idle')
    })
  })

  // ------------------------------------------------------------------ //
  // flush — auth store ready vs not ready
  // ------------------------------------------------------------------ //

  describe('flush', () => {
    it('flush() with empty queue does not call fetch', () => {
      mod.flush()
      vi.runAllTimers()
      expect(global.fetch).not.toHaveBeenCalled()
    })

    it('flush() with auth-ready store sends logs via fetch', () => {
      mod.setAuthStore({ user: { id: 1 }, loginStateKnown: true })
      mod.info('hello')
      mod.flush()
      vi.runAllTimers()
      expect(global.fetch).toHaveBeenCalled()
    })

    it('flush() attaches user_id from auth store to logs', () => {
      mod.setAuthStore({ user: { id: 99 }, loginStateKnown: true })
      mod.info('tagged log')
      mod.flush()
      vi.runAllTimers()

      const [, callOptions] = global.fetch.mock.calls[0]
      const body = JSON.parse(callOptions.body)
      expect(body.logs.some((l) => l.user_id === 99)).toBe(true)
    })

    it('flush() without auth store ready retries after 500 ms', () => {
      const store = { loginStateKnown: false }
      mod.setAuthStore(store)
      mod.info('queued log')
      mod.flush()

      // Auth not ready yet — fetch should not have been called
      expect(global.fetch).not.toHaveBeenCalled()

      // Make auth ready then advance past the retry interval
      store.loginStateKnown = true
      vi.advanceTimersByTime(600)
      expect(global.fetch).toHaveBeenCalled()
    })

    it('flush() eventually fires even if auth store never becomes ready (10 s timeout)', () => {
      const store = { loginStateKnown: false }
      mod.setAuthStore(store)
      mod.info('timed-out log')
      mod.flush()

      // Before 10 s: retrying, fetch not called
      vi.advanceTimersByTime(9000)
      expect(global.fetch).not.toHaveBeenCalled()

      // Past 10 s: gives up waiting and sends anyway
      vi.advanceTimersByTime(2000)
      expect(global.fetch).toHaveBeenCalled()
    })
  })

  // ------------------------------------------------------------------ //
  // Auto-flush at MAX_BATCH_SIZE (10 entries)
  // ------------------------------------------------------------------ //

  describe('auto-flush at batch size', () => {
    it('auto-flushes immediately when 10 log entries are queued', () => {
      mod.setAuthStore({ user: { id: 1 }, loginStateKnown: true })
      // Queue exactly MAX_BATCH_SIZE (10) entries
      for (let i = 0; i < 10; i++) {
        mod.info(`log entry ${i}`)
      }
      // Auto-flush is synchronous (no timer needed)
      vi.runAllTimers()
      expect(global.fetch).toHaveBeenCalled()
    })

    it('does not auto-flush before MAX_BATCH_SIZE is reached', () => {
      mod.setAuthStore({ user: { id: 1 }, loginStateKnown: true })
      for (let i = 0; i < 9; i++) {
        mod.info(`log entry ${i}`)
      }
      // No immediate flush — only a scheduled one
      expect(global.fetch).not.toHaveBeenCalled()
    })
  })

  // ------------------------------------------------------------------ //
  // useClientLog composable shape
  // ------------------------------------------------------------------ //

  describe('useClientLog composable', () => {
    it('returns all expected functions and constants', () => {
      const api = mod.useClientLog()
      const expectedFunctions = [
        'debug',
        'info',
        'warn',
        'error',
        'pageView',
        'action',
        'apiRequest',
        'sessionStart',
        'sentryError',
        'flush',
        'setAuthStore',
        'setPageLoadPhase',
        'getPageLoadPhase',
        'markPageInteractive',
        'markUserAction',
      ]
      for (const fn of expectedFunctions) {
        expect(typeof api[fn], `${fn} should be a function`).toBe('function')
      }
      expect(api.LogLevel).toBeDefined()
      expect(api.LogLevel).toEqual(mod.LogLevel)
    })

    it('composable functions are the same references as the direct exports', () => {
      const api = mod.useClientLog()
      expect(api.debug).toBe(mod.debug)
      expect(api.info).toBe(mod.info)
      expect(api.warn).toBe(mod.warn)
      expect(api.error).toBe(mod.error)
      expect(api.pageView).toBe(mod.pageView)
      expect(api.action).toBe(mod.action)
      expect(api.apiRequest).toBe(mod.apiRequest)
      expect(api.sessionStart).toBe(mod.sessionStart)
      expect(api.sentryError).toBe(mod.sentryError)
      expect(api.flush).toBe(mod.flush)
      expect(api.setAuthStore).toBe(mod.setAuthStore)
      expect(api.setPageLoadPhase).toBe(mod.setPageLoadPhase)
      expect(api.getPageLoadPhase).toBe(mod.getPageLoadPhase)
      expect(api.markPageInteractive).toBe(mod.markPageInteractive)
      expect(api.markUserAction).toBe(mod.markUserAction)
    })
  })

  // ------------------------------------------------------------------ //
  // Individual log function context and event_type
  // ------------------------------------------------------------------ //

  describe('log entry structure', () => {
    beforeEach(() => {
      mod.setAuthStore({ user: { id: 7 }, loginStateKnown: true })
    })

    it('pageView() log has event_type "page_view" and page_name', () => {
      mod.pageView('about')
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs.find((l) => l.event_type === 'page_view')
      expect(entry).toBeDefined()
      expect(entry.page_name).toBe('about')
    })

    it('action() log has event_type "action" and action_name', () => {
      mod.action('reply_sent', { message_id: 123 })
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs.find((l) => l.event_type === 'action')
      expect(entry).toBeDefined()
      expect(entry.action_name).toBe('reply_sent')
      expect(entry.message_id).toBe(123)
    })

    it('sentryError() log has event_type "sentry_error" and sentry_event_id', () => {
      mod.sentryError('Crash occurred', 'evt-abc')
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs.find((l) => l.event_type === 'sentry_error')
      expect(entry).toBeDefined()
      expect(entry.sentry_event_id).toBe('evt-abc')
      expect(entry.error_message).toBe('Crash occurred')
    })

    it('sessionStart() log has event_type "session_start"', () => {
      mod.sessionStart({ test_context: true })
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs.find((l) => l.event_type === 'session_start')
      expect(entry).toBeDefined()
      expect(entry.test_context).toBe(true)
    })

    it('apiRequest() log has event_type "api_request"', () => {
      mod.apiRequest('DELETE', '/api/item/1', 45, 204)
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs.find((l) => l.event_type === 'api_request')
      expect(entry).toBeDefined()
      expect(entry.method).toBe('DELETE')
      expect(entry.path).toBe('/api/item/1')
      expect(entry.duration_ms).toBe(45)
      expect(entry.status).toBe(204)
    })

    it('log entries include timestamp, level, trace_id, and session_id', () => {
      mod.info('structured log')
      mod.flush()
      vi.runAllTimers()

      const body = JSON.parse(global.fetch.mock.calls[0][1].body)
      const entry = body.logs[0]
      expect(entry.timestamp).toBeDefined()
      expect(new Date(entry.timestamp).toISOString()).toBe(entry.timestamp)
      expect(entry.level).toBe('info')
      expect(entry.trace_id).toBeDefined()
      expect(entry.session_id).toBeDefined()
    })
  })
})
