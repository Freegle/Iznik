// Dwell-gated view recording.
//
// A post should only count as "viewed" when the member actually paused on it,
// not when it merely scrolled past in a list. This wraps a record callback in
// a visibility dwell timer: the view is recorded only after the post has
// stayed visible for `dwellMs`; if it scrolls away sooner the pending view is
// cancelled and never recorded. Genuine opens (expanding/opening a post)
// should call the record function directly and bypass the dwell.
//
// Returns { onVisibilityChange, cancel }. The caller must call cancel() when
// the component unmounts so a queued view can't fire after it's gone.

export const DEFAULT_VIEW_DWELL_MS = 2000

export function useDwellView(recordFn, dwellMs = DEFAULT_VIEW_DWELL_MS) {
  let timer = null

  function cancel() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function onVisibilityChange(isVisible) {
    if (isVisible) {
      // Start a single dwell timer; ignore repeat "visible" callbacks while
      // one is already pending so we don't schedule duplicates.
      if (timer === null) {
        timer = setTimeout(() => {
          timer = null
          recordFn()
        }, dwellMs)
      }
    } else {
      // Left the screen before the dwell elapsed — don't count it.
      cancel()
    }
  }

  return { onVisibilityChange, cancel }
}
