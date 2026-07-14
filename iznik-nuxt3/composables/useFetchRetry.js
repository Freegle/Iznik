// Originally based on https://github.com/jonbern/fetch-retry, but reworked for our purposes.
import { useMiscStore } from '~/stores/misc'

class FetchError extends Error {
  constructor(message, response) {
    super(message)
    this.response = response
  }
}

// Only these methods have no side effects, so only they are safe to retry blindly on
// an ambiguous outcome (network error, 5xx, or a 2xx we can't parse). A mutating
// request (POST/PUT/PATCH/DELETE - e.g. sending a chat message) may already have been
// applied server-side even though the client sees one of those outcomes - retrying it
// anyway resends the same create/update and can duplicate it (Discourse #9913: a chat
// message send retried after a dropped response created a second, real, chat_messages
// row, both shown to the sender until a batch job later deduped them). So a mutating
// request fails fast instead, and the caller is responsible for surfacing that failure
// (see stores/chat.js + ChatFooter.vue for the chat-send case) - or for retrying with
// the same client-side idempotency key if it decides to retry itself.
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']

export function fetchRetry(fetch) {
  const retryDelay = function (attempt, error, response) {
    // Slowly back off for longer each time.
    return attempt * 1000
  }

  const retryOn = async function (attempt, error, response, isSafeMethod) {
    if (isSafeMethod) {
      // No point retrying until we know we are back online. Only awaited when we
      // might actually retry - a mutating request must fail fast instead (see below),
      // not hang until reconnect and only then reject.
      const miscStore = useMiscStore()
      await miscStore.waitForOnline()

      if (attempt >= 10) {
        return [false, false, null, new Error('Too many retries, give up')]
      }

      // Don't retry aborted requests — these are intentional cancellations
      // (e.g. all pending requests are aborted during logout to prevent stale
      // responses from re-establishing session cookies).
      if (error?.name === 'AbortError') {
        return [false, false, null, error]
      }

      if (miscStore?.unloading) {
        // Don't retry if we're unloading.
        console.log("Unloading - don't retry")
        return [false, false, null, new Error('Unloading, no retry')]
      }
    } else if (error?.name === 'AbortError') {
      // Still don't misreport an intentional abort as a generic failure.
      return [false, false, null, error]
    }

    // Some browsers don't return much info from fetch(), deliberately, and just say "Load failed".  So retry those.
    // When fetch() throws (e.g. network failure), the message is in error.message, not response.statusText.
    // https://stackoverflow.com/questions/71280168/javascript-typeerror-load-failed-error-when-calling-fetch-on-ios
    const blandErrors = ['load failed', 'failed to fetch']
    if (
      blandErrors.includes(response?.statusText?.toLowerCase()) ||
      blandErrors.includes(error?.message?.toLowerCase())
    ) {
      if (isSafeMethod) {
        console.log('Load failed - retry')
        return [true, false]
      }
      return [
        false,
        false,
        null,
        error || new FetchError('Load failed', response),
      ]
    }

    // Retry on network errors or server errors (5xx).  Don't retry client errors (4xx) - these are legitimate
    // API responses (e.g. 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found, 409 Conflict).
    if (error !== null || response?.status >= 500) {
      if (isSafeMethod) {
        console.log('Error - retry', error, response?.status)
        return [true, false]
      }
      return [
        false,
        false,
        null,
        error ||
          new FetchError('Request failed with ' + response?.status, response),
      ]
    }

    if (response?.status >= 200 && response?.status < 300) {
      try {
        const data = await response.json()

        if (!data) {
          // We've seen 200 responses with no data, which is never valid for us, so retry.
          if (isSafeMethod) {
            console.log('Success but no data - retry')
            return [true, false]
          }
          return [
            false,
            false,
            null,
            new FetchError('Success but no data', response),
          ]
        } else {
          return [false, true, data]
        }
      } catch (e) {
        if (response?.status === 204) {
          // 204 No Content - no body expected, that's fine.
          return [false, true, null]
        }

        // JSON parse failed on a response that should have had a body.  Retry.
        if (isSafeMethod) {
          return [true, false]
        }
        return [false, false, null, e]
      }
    } else {
      // Client error (4xx) that we aren't supposed to retry.  Parse the response body if available
      // so callers can access error details.
      let responseData = null

      try {
        responseData = await response.json()
      } catch (e) {
        // No JSON body - that's OK for error responses.
      }

      if (!error) {
        const fetchError = new FetchError(
          'Request failed with ' + response?.status,
          response
        )
        fetchError.data = responseData
        error = fetchError
      }

      return [false, false, null, error]
    }
  }

  return function (input, init) {
    // The method can come from `init` (the common case) or, when the caller passes a
    // Request object as `input` with no `init` (or an init that doesn't set method),
    // from `input` itself - fetch() falls back to the Request's own method in that
    // case, and defaults to GET when neither specifies one.
    const method = String(init?.method ?? input?.method ?? 'GET').toUpperCase()
    const isSafeMethod = SAFE_METHODS.includes(method)

    return new Promise(function (resolve, reject) {
      const wrappedFetch = async function (attempt) {
        let response = null
        let error = null
        let doRetry = false
        let success = false
        let data = null

        try {
          response = await fetch(input, init)
        } catch (e) {
          console.log('Error in attempt', e)
          error = e
        }

        // We might retry in a variety of circumstances, including apparent success.
        ;[doRetry, success, data, error] = await retryOn(
          attempt,
          error,
          response,
          isSafeMethod
        )

        if (success) {
          // We have to pass the data because .json() can only be called once.
          resolve([response.status, data])
        } else if (doRetry) {
          console.log('Retry')
          retry(attempt, null, response)
        } else {
          reject(error)
        }
      }

      function retry(attempt, error, response) {
        const delay = retryDelay(attempt, error, response)

        setTimeout(function () {
          wrappedFetch(++attempt)
        }, delay)
      }

      wrappedFetch(0)
    })
  }
}
