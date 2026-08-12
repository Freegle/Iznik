// Bound an await so it cannot hang for ever.
//
// A promise that never settles is not a slow operation, it is a dead one: the
// awaiting code never runs its success path or its catch, so any state it was
// going to clear stays set and any UI gated on that state stays gated. We have
// shipped that bug more than once - see stores/misc.js waitForOnline() and
// composables/useFetchRetry.js retryOn(), which between them stranded a
// give-flow photo at uploading:true and locked the member out of posting.
//
// Use this wherever a hang would leave the user with no way forward, so the
// worst case becomes an ordinary failure the caller already handles.
export function withTimeout(promise, ms, message = 'Timed out') {
  let timer = null

  const timeout = new Promise((resolve, reject) => {
    timer = setTimeout(() => reject(new Error(message)), ms)
  })

  return Promise.race([promise, timeout]).finally(() => {
    if (timer) {
      clearTimeout(timer)
    }
  })
}
