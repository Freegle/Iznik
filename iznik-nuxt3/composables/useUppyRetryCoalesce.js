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
        getUppy()?.retryAll()
      } catch (retryError) {
        console.error('retryAll() failed (Uppy state corruption)', retryError)
      }
    })
  }
}
