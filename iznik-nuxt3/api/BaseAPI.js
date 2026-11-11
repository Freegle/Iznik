import * as Sentry from '@sentry/vue'
import {
  APIError,
  MaintenanceError,
  LoginError,
  SignUpError,
} from './APIErrors'
import { fetchRetry } from '~/composables/useFetchRetry'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '~/stores/misc'
import { useLoggingContextStore } from '~/stores/loggingContext'
import { getTraceHeaders } from '~/composables/useTrace'
import { warn, noteApiRequestStarted } from '~/composables/useClientLog'
import { trackApiCall } from '~/composables/useApiCallsInFlight'
import { getLiveFlowInstanceId } from '~/composables/useFlowTelemetry'
import {
  apiFailureDisposition,
  apiFailureFingerprint,
  logDroppedApiFailure,
  normaliseApiPath,
  underSessionCap,
} from '~/composables/useApiFailurePolicy'

export { APIError, MaintenanceError, LoginError, SignUpError }

let requestId = 0

// Global AbortController for all API requests. Aborting this cancels every
// in-flight request, preventing stale responses (and their Set-Cookie headers)
// from arriving after an intentional logout.
let globalAbortController = new AbortController()

// When true, the global controller stays aborted so that any new requests
// triggered by Vue re-rendering during logout are also immediately aborted.
// This closes the race window between abortAllPendingRequests() and the
// DELETE /api/session completing.
let logoutInProgress = false

export function abortAllPendingRequests() {
  globalAbortController.abort()

  if (!logoutInProgress) {
    globalAbortController = new AbortController()
  }
}

// Enter logout mode: abort all requests and keep the controller aborted
// so new requests from Vue re-rendering are also killed.
export function enterLogoutMode() {
  logoutInProgress = true
  globalAbortController.abort()
}

// Exit logout mode and restore a fresh controller for normal operation.
export function exitLogoutMode() {
  logoutInProgress = false
  globalAbortController = new AbortController()
}

// let timer = 0

// We add fetch retrying.
//
// Note that $fetch and useFetch cause problems on Node v18, so we don't use them.
const ourFetch = fetchRetry(fetch)

// Send one failed call to Sentry under the rules in useApiFailurePolicy: all the calls the API did
// not answer are one issue (api-unreachable), and every other failure is one issue per method,
// normalised path and status. Returns whether an event was sent.
function reportApiFailure(disposition, { status, method, path, detail }) {
  // Sentry is only initialized on the client, so check before calling, and before counting: a
  // server render that cannot send must not use up the session's allowance.
  if (typeof Sentry?.captureMessage !== 'function') {
    return false
  }

  const fingerprint = apiFailureFingerprint(disposition, method, path, status)

  if (!underSessionCap(fingerprint, method + ' ' + normaliseApiPath(path))) {
    logDroppedApiFailure({ status, method, path, reason: 'session_cap' })
    return false
  }

  const unreachable = disposition === 'unreachable'

  Sentry.captureMessage(
    unreachable
      ? 'API unreachable'
      : 'API2 request failed ' +
          normaliseApiPath(path) +
          ' returned HTTP ' +
          status,
    {
      level: unreachable ? 'warning' : 'error',
      fingerprint,
      tags: {
        api_path: normaliseApiPath(path),
        api_method: method,
        http_status: String(status ?? 'none'),
        ...(status === null ? { error_type: 'network' } : {}),
      },
      extra: { detail },
    }
  )

  return true
}

export default class BaseAPI {
  constructor(config) {
    this.config = config
  }

  // options: extra fetch settings for this one request, such as keepalive for a request that
  // has to outlive the page (see JobAPI.log).
  $postv2(path, data, logError = true, options = {}) {
    const authStore = useAuthStore()

    return this.$requestv2(
      'POST',
      path,
      {
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'Iznik ' + JSON.stringify(authStore.auth?.persistent),
        },
        data,
        ...options,
      },
      logError
    )
  }

  async $requestv2(method, path, config, logError = true, body = null) {
    // The query string below gains loggedInAs and a request counter, so failures are reported by
    // the path as it came in (see reportApiFailure), which names the endpoint and not this request.

    // timer++
    // const timerLabel = path + ' api-' + timer

    // console.log('Start ', timerLabel)
    // console.time(timerLabel)

    let status = null
    let data = null
    let sentryReported = false
    const headers = config.headers ? config.headers : {}

    // Add trace headers for distributed tracing.
    const traceHeaders = getTraceHeaders()
    Object.assign(headers, traceHeaders)

    // Add logging context headers.
    try {
      const loggingCtx = useLoggingContextStore()
      const loggingHeaders = loggingCtx.getHeaders()
      Object.assign(headers, loggingHeaders)
    } catch (e) {
      // Store may not be initialized on server-side.
    }

    // Count requests started (the interaction plugin uses it to spot clicks that did nothing) and
    // tie requests made during a flow to its instance.
    noteApiRequestStarted()
    const apiCall = trackApiCall(method, path)
    const flowInstance = getLiveFlowInstanceId()
    if (flowInstance) {
      headers['X-Freegle-Flow-Instance'] = flowInstance
    }

    // Capture store references once, synchronously, while the caller's pinia
    // context is still active. The catch/finally blocks below run after
    // awaits, where server-side (especially prerender) there may be no active
    // pinia any more — a fresh useMiscStore() there throws, surfacing as an
    // unhandledRejection or, from finally, failing an otherwise-successful
    // request (seen as prerendered pages 500ing in Netlify builds).
    let authStore
    let miscStore = null

    try {
      authStore = useAuthStore()
      miscStore = useMiscStore()

      if (authStore?.auth?.jwt) {
        // Use the JWT to authenticate the request if possible.
        headers.Authorization = JSON.stringify(authStore.auth.jwt)
      }

      if (authStore?.auth?.persistent) {
        // The JWT is quick but short-lived; use the persistent token as a fallback.
        headers.Authorization2 = JSON.stringify(authStore.auth.persistent)
      }

      const loggedInAs = authStore.user?.id

      if (loggedInAs) {
        // Add the user ID as a query parameter to the path, checking whether there already are any
        // query parameters.
        path += (path.includes('?') ? '&' : '?') + 'loggedInAs=' + loggedInAs
      }

      // Add requestId to the path, checking whether there already are any query parameters.
      path += (path.includes('?') ? '&' : '?') + 'requestid=' + requestId++

      headers['Cache-Control'] =
        'max-age=0, must-revalidate, no-cache, no-store, private'

      if (method === 'GET' && config?.params) {
        // Remove falsey values from the params, unless dontzapfalsey is set.
        // This is needed when we want to pass 0 as a value (e.g., reviewed: 0).
        const keepFalsey = config.params.dontzapfalsey
        delete config.params.dontzapfalsey

        if (!keepFalsey) {
          config.params = Object.fromEntries(
            Object.entries(config.params).filter(([_, v]) => v)
          )
        }

        // URL encode the parameters, joining arrays as comma-separated for Go API
        const urlParams = new URLSearchParams()
        for (const [key, value] of Object.entries(config.params)) {
          if (Array.isArray(value)) {
            urlParams.append(key, value.join(','))
          } else {
            urlParams.append(key, value)
          }
        }
        const urlParamsStr = urlParams.toString()

        if (urlParamsStr.length) {
          path += '&' + urlParamsStr
        }
      } else if (method !== 'POST') {
        // Any parameters are passed in config.params.
        if (!config?.params) {
          config.params = {}
        }

        config.params.modtools = miscStore.modtools

        // JSON-encode these for to pass.
        body = JSON.stringify(config.params)
      } else if (!config?.formPost) {
        // Parameters will be passed in config.data.
        if (!config.data) {
          config.data = {}
        }

        config.data.modtools = miscStore.modtools
        body = JSON.stringify(config.data)
      }

      await miscStore.waitForOnline()
      apiCall.fetching()
      miscStore.api(1)
      ;[status, data] = await ourFetch(this.config.public.APIv2 + path, {
        ...config,
        body,
        method,
        headers,
        signal: config.ownAbortController
          ? config.ownAbortController.signal
          : globalAbortController.signal,
      })

      if (data && data.jwt && data.persistent) {
        // Server returned new auth tokens.  We only update if we already have auth - this prevents stale
        // in-flight API responses from restoring auth after an intentional logout.
        if (authStore.auth.jwt && data.jwt !== authStore.auth.jwt) {
          console.log('JWT renewal: updating auth tokens from API response')
          authStore.setAuth(data.jwt, data.persistent)
        } else if (!authStore.auth.jwt) {
          console.log(
            'JWT renewal blocked: no existing auth (likely logged out), ignoring stale API response'
          )
        }
      }
    } catch (e) {
      console.log('Fetch error', path, e?.message)
      if (e?.response?.status) {
        status = e.response.status
      }

      if (e?.data) {
        data = e.data
      }

      if (e.message.match(/.*aborted.*/i)) {
        // We've seen requests get aborted immediately after beforeunload().  Makes sense to abort the requests
        // when you're leaving a page.  No point in rippling those errors up to result in Sentry errors.
        // Swallow these by returning a problem that never resolves.  Possible memory leak but it's a rare case.
        console.log('Aborted - ignore')
        // An abort during logout or unload is expected and the page is going
        // away, so nothing is left waiting.  An abort at any other time leaves
        // the caller's await hanging for the life of the page with no error to
        // catch, which is how a give-flow photo got stranded at uploading:true.
        // We cannot tell the two apart here, so record it: one of these in Loki
        // beside a stuck UI is the answer straight away, rather than a day of
        // working backwards from what is missing.
        warn('API request abandoned without settling', {
          event_type: 'api_abandoned',
          reason: 'aborted',
          api_path: path,
          api_method: method,
          detail: e?.message,
        })
        return new Promise(function (resolve) {})
      }

      if (status === null) {
        // No HTTP response (e.g. retries exhausted).  Report it only while the online check believes
        // we have connectivity, which suggests a server-side or routing problem rather than the
        // user's network being down, and not when unloading, which is expected.
        if (!e.message.match(/.*unload.*/i) && miscStore?.online) {
          sentryReported = reportApiFailure('unreachable', {
            status,
            method,
            path,
            detail: e.message,
          })
        } else {
          logDroppedApiFailure({
            status,
            method,
            path,
            reason: miscStore?.online ? 'unloading' : 'offline',
          })
        }
      }
    } finally {
      apiCall.done()
      miscStore?.api(-1)
    }

    // Accept all 2xx status codes as successful (200, 201, 204, etc.)
    if (status < 200 || status >= 300) {
      const statusstr = status?.toString()

      // Whether a failure is worth a Sentry issue depends on its status (see useApiFailurePolicy), and
      // on the caller.  Some API calls are expected to fail, and throw an exception which is then
      // handled in the code, so the logError parameter (a boolean or a function for more complex
      // decisions) lets a caller say it is dealing with the failure itself.
      if (status !== null) {
        const disposition = apiFailureDisposition(status, data)
        const log = typeof logError === 'function' ? logError(data) : logError

        if (disposition === 'drop' || !log) {
          logDroppedApiFailure({
            status,
            method,
            path,
            reason:
              disposition === 'drop' ? 'expected_status' : 'caller_handles',
          })
        } else {
          sentryReported = reportApiFailure(disposition, {
            status,
            method,
            path,
            detail: 'data length ' + (data ? data.length : 0),
          })
        }
      }

      const message = [
        'API Error',
        method,
        path,
        '->',
        `status: ${statusstr}`,
      ].join(' ')

      const apiError = new APIError(
        {
          request: {
            path,
            method,
            headers: config.headers,
            params: config.params,
            data: config.data,
          },
          response: {
            status,
            data,
          },
        },
        message
      )

      // Tell whoever catches this that the failure has been dealt with here, so it is not sent to
      // Sentry or written to the log a second time as it propagates.
      apiError.sentryReported = sentryReported
      apiError.failureHandled = true

      throw apiError
    }

    // console.timeEnd(timerLabel)

    return data
  }

  $getv2(path, params = {}, logError = true) {
    return this.$requestv2('GET', path, { params }, logError)
  }

  $patchv2(path, data, logError = true) {
    return this.$requestv2(
      'PATCH',
      path,
      {
        headers: { 'Content-Type': 'application/json' },
        params: data,
      },
      logError
    )
  }

  $putv2(path, data, logError = true) {
    return this.$requestv2(
      'PUT',
      path,
      {
        headers: { 'Content-Type': 'application/json' },
        params: data,
      },
      logError
    )
  }

  $postFormv2(path, data, logError = true) {
    return this.$requestv2(
      'POST',
      path,
      {
        formPost: true,
      },
      logError,
      data
    )
  }

  $delv2(path, data, logError = true, options = {}) {
    return this.$requestv2(
      'DELETE',
      path,
      {
        headers: { 'Content-Type': 'application/json' },
        params: data,
        ...options,
      },
      logError
    )
  }
}
