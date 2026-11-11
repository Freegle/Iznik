// Which failed API calls become a Sentry issue.
//
// An API call fails for a reason we can act on, or because that is the answer. A 404 for a group
// that no longer exists, a 401 for a signed-out member, a 429 from the load balancer and a 403 for
// something a moderator may not see are all answers, so none of them is a Sentry issue. A 400 or a
// 500 is the client or the server being wrong, and is. A call that got no reply, or only a gateway
// error, is one thing seen from many places (the API is unreachable), so it is one issue with the
// endpoint as a tag, and the number of events in it tells us how bad an outage is.
//
// Everything that is not sent to Sentry is still written to the client log (Loki) with its status,
// method and normalised path, so it stays visible as a rate.

import { warn } from '~/composables/useClientLog'

// Statuses that are an answer, not a fault.
const EXPECTED_STATUSES = new Set([401, 403, 404, 409, 410, 429])

// Gateway errors mean the API did not answer, the same as no reply at all.
const UNREACHABLE_STATUSES = new Set([502, 503, 504])

// The most events one fingerprint may send in a session. A device stuck in a loop would otherwise
// send thousands of identical events.
export const MAX_EVENTS_PER_FINGERPRINT = 10

export const UNREACHABLE_FINGERPRINT = ['api-unreachable']

const sentThisSession = new Map()

/**
 * The path without its query string, and with every numeric segment (or comma list of them) as
 * :id, so one endpoint is one issue however many ids it is called with.
 */
export function normaliseApiPath(path) {
  if (!path || typeof path !== 'string') {
    return ''
  }

  return path
    .split('?')[0]
    .split('/')
    .map((segment) => (/^\d+(,\d+)*$/.test(segment) ? ':id' : segment))
    .join('/')
}

/**
 * What to do with a failed call: 'drop' (not a Sentry issue), 'unreachable' (the API did not
 * answer) or 'error' (our bug).
 */
export function apiFailureDisposition(status) {
  if (status === null || status === undefined) {
    return 'unreachable'
  }

  if (UNREACHABLE_STATUSES.has(status)) {
    return 'unreachable'
  }

  if (EXPECTED_STATUSES.has(status)) {
    return 'drop'
  }

  if (status >= 400) {
    return 'error'
  }

  return 'drop'
}

export function apiFailureFingerprint(disposition, method, path, status) {
  if (disposition === 'unreachable') {
    return UNREACHABLE_FINGERPRINT
  }

  return ['api-error', method, normaliseApiPath(path), String(status)]
}

/**
 * True while this fingerprint is under its limit for the session, and counts the event.
 */
export function underSessionCap(fingerprint) {
  const key = fingerprint.join('|')
  const sent = sentThisSession.get(key) || 0

  if (sent >= MAX_EVENTS_PER_FINGERPRINT) {
    return false
  }

  sentThisSession.set(key, sent + 1)
  return true
}

// For tests.
export function resetSessionCap() {
  sentThisSession.clear()
}

/**
 * Write a failure that is not going to Sentry to the client log.
 */
export function logDroppedApiFailure({ status, method, path, reason }) {
  try {
    warn('API failure not sent to Sentry', {
      event_type: 'api_failure_dropped',
      api_status: status ?? null,
      api_method: method || null,
      api_path: normaliseApiPath(path),
      reason,
    })
  } catch (e) {
    // Logging must never make a failure worse.
  }
}

/**
 * Pull the call out of an APIError, or null if this is not one.
 */
function failedCall(err) {
  const request = err?.request
  const response = err?.response

  if (!request && !response) {
    return null
  }

  return {
    status: response?.status ?? null,
    method: request?.method || '',
    path: request?.path || '',
  }
}

/**
 * Used by beforeSend, so that an API error captured anywhere (the Vue error handler, an unhandled
 * rejection, or a component calling captureException itself) follows the same rules. Returns false
 * when the event should be dropped. For an event it keeps it sets the fingerprint.
 */
export function applyApiFailurePolicy(event, hint) {
  const err = hint?.originalException
  const call = failedCall(err)

  if (!call) {
    return true
  }

  // BaseAPI has already sent this failure as its own event.
  if (err.sentryReported) {
    return false
  }

  const disposition = apiFailureDisposition(call.status)

  if (disposition === 'drop') {
    logDroppedApiFailure({ ...call, reason: 'expected_status' })
    return false
  }

  const fingerprint = apiFailureFingerprint(
    disposition,
    call.method,
    call.path,
    call.status
  )

  if (!underSessionCap(fingerprint)) {
    return false
  }

  event.fingerprint = fingerprint

  if (disposition === 'unreachable') {
    event.level = 'warning'
    event.tags = {
      ...event.tags,
      api_path: normaliseApiPath(call.path),
      api_method: call.method,
    }
  }

  return true
}

/**
 * Describe something that was thrown or rejected that is not an Error, for the client log. These
 * carry no stack and no message, so Sentry cannot group or explain them. The description (what it
 * is, what it was built from, which fields it has) is what tells us where it came from.
 */
export function describeNonError(reason) {
  const type = typeof reason
  const description = { reason_type: type }

  if (reason === null || reason === undefined) {
    return description
  }

  if (type === 'string') {
    description.reason_text = reason.slice(0, 200)
    return description
  }

  if (type === 'object') {
    description.reason_constructor = reason.constructor?.name || null

    try {
      description.reason_keys = Object.keys(reason).slice(0, 20)
    } catch (e) {
      description.reason_keys = []
    }

    if (typeof reason.type === 'string') {
      description.reason_event_type = reason.type
    }
  }

  return description
}
