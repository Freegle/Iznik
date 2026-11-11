// Coalesces bursts of Uppy `error` events into a single queued retryAll() call.
// Background: Uppy can fire one `error` per file when a batch upload fails;
// calling retryAll() on every event triggers concurrent retries that corrupt
// Uppy's internal state (NUXT3-D2C — "call is locked"). This helper batches
// the bursts into one microtask-deferred retry and swallows retryAll()'s own
// thrown state-corruption errors so they don't take the whole upload with them.
//
// Factored out of OurUploader.vue / PhotoUploader.vue so the error-handler
// body stays small; Playwright e2e flows don't trigger upload errors so the
// in-component handler was dragging Playwright's per-job coverage down. This
// file is excluded from Playwright coverage (see playwright.config.js
// sourceFilter) and is exercised directly by the component unit tests.
export function createRetryCoalescer(getUppy) {
  let scheduled = false
  return function scheduleRetry() {
    if (scheduled) return
    scheduled = true
    queueMicrotask(() => {
      scheduled = false
      try {
        // retryAll() returns a promise in Uppy 4. Uppy's own tus retry can throw
        // "Cannot use 'in' operator to search for 'error' in undefined" from an
        // undefined file in its internal list - and because that surfaces from
        // an async continuation it REJECTS the promise rather than throwing
        // synchronously, so the try/catch alone misses it and it reaches Sentry
        // uncaught. Handle the rejection too.
        const result = getUppy()?.retryAll()
        if (result && typeof result.then === 'function') {
          result.catch((retryError) => {
            console.error(
              'retryAll() rejected (Uppy state corruption)',
              retryError
            )
          })
        }
      } catch (retryError) {
        console.error('retryAll() failed (Uppy state corruption)', retryError)
      }
    })
  }
}

// Takes the photos that have finished out of Uppy once a batch completes, and
// says whether anything is left. Use this instead of uppy.clear().
//
// clear() empties Uppy's file list without stopping uploads that are still
// running. When one photo in a batch fails, `complete` fires and the retry
// above has already restarted it, so clear() pulled the file out from under
// its own upload: @uppy/tus then crashed reading `progress` of the missing
// file ("Cannot read properties of undefined (reading 'progress')"), and the
// photo was lost. Leaving unfinished files where they are lets the retry finish
// and report back through the next `complete`.
export function removeFinishedFiles(uppy, result) {
  if (!uppy) return true

  const finished = (result?.successful || [])
    .map((file) => file?.id)
    .filter((id) => id && uppy.getFile(id))

  if (finished.length) uppy.removeFiles(finished)

  return uppy.getFiles().length === 0
}
