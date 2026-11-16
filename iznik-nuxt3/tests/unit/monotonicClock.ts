// A clock that never goes backwards, built on one that might.
//
// Vue skips a DOM event handler when the event is stamped earlier than the
// moment the handler was attached, and Vue Test Utils stamps every event it
// triggers with Date.now() + 1. Both read the wall clock. If the machine's
// clock is stepped back between a mount and a click (time sync does this), the
// click is dropped without an error and the test fails on whatever the click
// should have caused. Holding the last reading across a backward step keeps
// the two stamps in order.
export function monotonic(now: () => number): () => number {
  let last = 0
  return () => {
    const t = now()
    if (t > last) last = t
    return last
  }
}
