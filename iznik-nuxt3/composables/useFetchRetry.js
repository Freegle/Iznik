// Originally based on https://github.com/jonbern/fetch-retry, but reworked for our purposes.
import { useMiscStore } from '~/stores/misc'

class FetchError extends Error {
  constructor(message, response) {
    super(message)
    this.response = response
  }
}

export function fetchRetry(fetch) {
  const retryDelay = function (attempt, error, response) {
    if (response?.status === 504) {
      // A gateway timeout means the server was still working on an expensive request when the
      // gateway gave up, so back off much harder before adding more load.
      return 10000
    }

    if (response?.status === 429) {
      // Rate limited.  The load balancer's rate window is short (per-second), so a couple of
      // seconds is normally enough for a burst to clear.  Honour Retry-After if the server
      // sent one.
      const retryAfter = parseInt(response.headers?.get('Retry-After'))
      if (!isNaN(retryAfter) && retryAfter > 0) {
        return Math.min(retryAfter * 1000, 30000)
      }

      return 2000 * (attempt + 1)
    }

    // Slowly back off for longer each time.
    return attempt * 1000
  }

  const retryOn = async function (attempt, error, response) {
    let miscStore = null

    if (import.meta.client) {
      // Client-only: this callback runs on a later tick, where server-side
      // (especially Netlify prerender) there is no active pinia instance any
      // more, so useMiscStore() itself throws — the wrapper promise then
      // never settles and the throw surfaces as an unhandledRejection
      // instead of a fetch error. "Online" is also meaningless on the
      // server.
      miscStore = useMiscStore()
    }

    // There is no point making another attempt until we believe we are back
    // online - but that is the only thing the connection check is for.  It
    // used to run here, as the very first act of every retry decision, before
    // we had even looked at what came back.  So a member whose connection
    // dropped in the moment between sending a request and its reply arriving
    // had a perfectly good response thrown away: the online check did not
    // resolve, the success branch below was never reached, and the caller was
    // left awaiting a promise that could never settle.
    //
    // That is what stranded a give-flow photo for user 3512849 on 2026-08-10:
    // POST /apiv2/image returned 200 with image id 45508630, the app flipped
    // offline, and PhotoUploader's `await imageStore.post()` never returned.
    // The photo stayed uploading:true at 100%, compose persisted it, and
    // because the flow gates Next on anyUploading she could not post at all.
    //
    // So wait only on the paths that are about to try again, never before
    // reading a reply we already hold.
    const waitThenRetry = async function (why) {
      console.log(why)
      if (miscStore) {
        await miscStore.waitForOnline()
      }
      return [true, false]
    }

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

    // Some browsers don't return much info from fetch(), deliberately, and just say "Load failed".  So retry those.
    // When fetch() throws (e.g. network failure), the message is in error.message, not response.statusText.
    // https://stackoverflow.com/questions/71280168/javascript-typeerror-load-failed-error-when-calling-fetch-on-ios
    const blandErrors = ['load failed', 'failed to fetch']
    if (
      blandErrors.includes(response?.statusText?.toLowerCase()) ||
      blandErrors.includes(error?.message?.toLowerCase())
    ) {
      return await waitThenRetry('Load failed - retry')
    }

    // A 504 means the gateway gave up on a request the server was probably still executing -
    // typically an expensive endpoint that is slow for everyone, not a transient blip. Each
    // retry stacks another full copy of that work on the server (seen live on the sysadmin
    // rippling analytics tab, where the retry loop kept ~10 heavyweight query sets running),
    // so allow only a single retry rather than the usual ten.
    if (response?.status === 504 && attempt >= 1) {
      console.log('Gateway timeout persisted after retry - give up')
      return [
        false,
        false,
        null,
        new FetchError('Request failed with ' + response.status, response),
      ]
    }

    // Retry rate limiting (429) with backoff (see retryDelay).  Bursts trip the load
    // balancer's per-second limits - e.g. ModTools firing parallel work checks at boot,
    // before HAProxy has re-learned that the user is a privileged mod - and clear within
    // seconds.  Until the LB error responses carried CORS headers these appeared to the
    // browser as opaque network errors and were retried by the branch below; keep that
    // resilience, but as deliberate policy.  Persistent 429s (real abuse) still fail via
    // the overall attempt cap.
    if (response?.status === 429) {
      return await waitThenRetry('Rate limited - retry with backoff')
    }

    // Retry on network errors or server errors (5xx).  Don't retry client errors (4xx) - these are legitimate
    // API responses (e.g. 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found, 409 Conflict).
    if (error !== null || response?.status >= 500) {
      return await waitThenRetry(
        `Error - retry ${error?.message ?? ''} ${response?.status ?? ''}`
      )
    }

    if (response?.status >= 200 && response?.status < 300) {
      try {
        const data = await response.json()

        if (!data) {
          // We've seen 200 responses with no data, which is never valid for us, so retry.
          return await waitThenRetry('Success but no data - retry')
        } else {
          return [false, true, data]
        }
      } catch (e) {
        if (response?.status === 204) {
          // 204 No Content - no body expected, that's fine.
          return [false, true, null]
        }

        // JSON parse failed on a response that should have had a body.  Retry.
        return await waitThenRetry('Bad JSON body - retry')
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
    return new Promise(function (resolve, reject) {
      const wrappedFetch = async function (attempt) {
        let response = null
        let error = null
        let doRetry
        let success
        let data

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
          response
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
