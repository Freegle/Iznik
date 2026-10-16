// What the Experiments section does when someone presses Start, Stop now or Mark as cleaned up, and
// how it finds out what happened. The batch applies a request within a few minutes, and it may refuse
// it, so the section keeps a note of each request until the experiment changes or a refusal arrives.
//
// The store is passed in, so this can be tested alone. `getList` gives the experiments the section
// holds, which is how it knows whether any request is still waiting.
import { computed, getCurrentScope, onScopeDispose, reactive, ref } from 'vue'

// The batch applies a request within seconds, but the tab only knows when it asks again. Ask a few
// times, further apart each time, for as long as some request is waiting.
export const ASK_AFTER_MS = [5000, 10000, 20000, 40000, 80000, 160000]
// A request that nothing has answered after this long probably has no batch to answer it.
export const TOO_LONG_MS = 10 * 60 * 1000
// How often the clock behind "too long" moves on while something waits.
const TICK_MS = 30000

export const REQUESTED_LABEL = {
  start: 'Start requested',
  stop: 'Stop requested',
  close: 'Cleanup noted',
}

const FALLBACK = 'That did not work.'

/** The message the server sent with a refusal, else a plain fallback. Never the technical text. */
export function serverMessage(err, fallback = FALLBACK) {
  const message = err?.response?.data?.message
  return typeof message === 'string' && message.trim()
    ? message.trim()
    : fallback
}

// The batch's last "no" for an experiment (the cap, a second change on the funnel, a change too small
// to read in a day). It is kept on the experiment so it can be shown here.
const lastRefusal = (e) => e?.result?.last_refusal ?? null

// A request belongs to one run of one experiment, in the status it was asked in: the id, the status
// and the time the run started. Starting an experiment stamps a new start time, and stopping or
// deciding it keeps that time. So an experiment that comes back to a status it had before (stopped,
// started again, stopped again) has a different key, and finds none of the requests made in its
// earlier run. A draft has no start time yet, and its key ends in a colon.
const keyOf = (e) => `${e.id}:${e.status}:${e.started_at ?? ''}`

export function useUxExperimentActions(store, getList = () => []) {
  // Per experiment, so one click does not freeze every card, and so the answer appears on the card
  // that was clicked.
  const busy = reactive({})
  const errors = reactive({})
  // keyOf -> the action asked for. It holds while the experiment is still in that status and run, so
  // a second click cannot queue a duplicate, and lets go by itself once the batch has changed it.
  const requested = reactive({})
  // keyOf -> the time of the refusal already on the experiment when the click was made (or null), so a
  // refusal the batch writes afterwards can be told from an old one without comparing clocks.
  const refusalWhenAsked = reactive({})
  // keyOf -> when the click was made, in milliseconds.
  const askedAt = reactive({})
  // keyOf -> true once the list has been asked for again after the request turned ten minutes old.
  const lookedWhenOld = reactive({})
  // "stop:<id>" while the person is being asked whether to stop that experiment, else null.
  const confirming = ref(null)
  // Moves on every 30 seconds while a request waits, so "too long" shows without a reload.
  const now = ref(Date.now())

  const pending = (e) => {
    const key = keyOf(e)
    const asked = requested[key]
    if (!asked) return null
    const refusal = lastRefusal(e)
    if (refusal && refusal.at !== refusalWhenAsked[key]) return null
    return asked
  }

  const problem = (e) => {
    if (errors[e.id]) return errors[e.id]
    const refusal = pending(e) ? null : lastRefusal(e)
    return refusal ? `That was not done: ${refusal.message}` : ''
  }

  // Said only after the list has been asked for again at ten minutes and still has the request waiting:
  // the first looks end about five minutes in, so the clock alone is not evidence that nothing was
  // applied.
  const pendingTooLong = (e) => {
    if (!pending(e)) return false
    const key = keyOf(e)
    const at = askedAt[key]
    return (
      at !== undefined &&
      lookedWhenOld[key] === true &&
      now.value - at >= TOO_LONG_MS
    )
  }

  // Stopping takes everyone back to the old version, so it asks first.
  const askToStop = (e) => {
    confirming.value = `stop:${e.id}`
  }
  const cancelStop = () => {
    confirming.value = null
  }
  const isConfirmingStop = (e) => confirming.value === `stop:${e.id}`

  const anyPending = computed(() => getList().some((e) => pending(e)))

  let asked = 0
  let timer = null
  let ticker = null

  function askAgainSoon() {
    if (timer || asked >= ASK_AFTER_MS.length) return
    timer = setTimeout(async () => {
      timer = null
      asked++
      try {
        await store.load()
      } catch {
        // The next look will try again.
      }
      if (anyPending.value) askAgainSoon()
      else asked = 0
    }, ASK_AFTER_MS[asked])
  }

  let looking = false

  // The looks above end about five minutes after the click, but a request is called too old at ten.
  // When one turns ten minutes old the list is asked for once more, so "not applied" is only said of
  // a request that this look still finds waiting. A look that fails is tried again at the next tick.
  async function lookWhenTooOld() {
    if (looking) return
    const due = getList().filter((e) => {
      const key = keyOf(e)
      return (
        pending(e) &&
        askedAt[key] !== undefined &&
        !lookedWhenOld[key] &&
        now.value - askedAt[key] >= TOO_LONG_MS
      )
    })
    if (!due.length) return
    // The click each look is for, so a new click made while it is on its way is not counted as looked at.
    const clicks = due.map((e) => [keyOf(e), askedAt[keyOf(e)]])
    looking = true
    try {
      await store.load()
      for (const [key, at] of clicks) {
        if (askedAt[key] === at) lookedWhenOld[key] = true
      }
    } catch {
      // The next tick will try again.
    } finally {
      looking = false
    }
  }

  function startClock() {
    if (ticker) return
    ticker = setInterval(() => {
      now.value = Date.now()
      lookWhenTooOld()
      if (!anyPending.value) stopClock()
    }, TICK_MS)
  }

  function stopClock() {
    if (ticker) clearInterval(ticker)
    ticker = null
  }

  async function act(e, action) {
    busy[e.id] = true
    errors[e.id] = null
    try {
      await store.experimentAction(e.id, { action })
      const key = keyOf(e)
      refusalWhenAsked[key] = lastRefusal(e)?.at ?? null
      requested[key] = action
      now.value = Date.now()
      askedAt[key] = now.value
      // A new click is looked at again at ten minutes, whatever came of an earlier one.
      delete lookedWhenOld[key]
      confirming.value = null
      // Each request gets its own run of looks.
      asked = 0
      askAgainSoon()
      startClock()
    } catch (err) {
      errors[e.id] = serverMessage(err)
    } finally {
      busy[e.id] = false
    }
  }

  // Let go of the timers. Done by itself when the section is left.
  function stop() {
    if (timer) clearTimeout(timer)
    timer = null
    stopClock()
  }
  if (getCurrentScope()) onScopeDispose(stop)

  return {
    busy,
    errors,
    requested,
    refusalWhenAsked,
    confirming,
    askToStop,
    cancelStop,
    isConfirmingStop,
    now,
    pending,
    problem,
    pendingTooLong,
    anyPending,
    act,
    stop,
  }
}
