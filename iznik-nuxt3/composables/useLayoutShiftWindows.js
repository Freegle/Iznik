// Cumulative Layout Shift the way web-vitals defines it: shifts are grouped into session
// windows, and the page's score is the largest window. A window ends when the gap since the
// previous shift is 1s or more, or when it has been open for 5s. Summing every shift over the
// life of a single-page app instead makes any long session cross a threshold eventually.

export const WINDOW_GAP_MS = 1000
export const WINDOW_MAX_MS = 5000

export function createLayoutShiftWindows() {
  let total = 0
  let count = 0
  let current = null
  let largest = null
  const shifts = []

  // value: the shift's score. startTime: ms since navigation start. detail: anything the
  // caller wants kept with the shift (selectors, rects).
  function add(value, startTime, detail = null) {
    total += value
    count++
    shifts.push({ value, startTime, detail })

    if (
      !current ||
      startTime - current.lastTime >= WINDOW_GAP_MS ||
      startTime - current.startTime >= WINDOW_MAX_MS
    ) {
      current = { startTime, lastTime: startTime, value: 0, count: 0 }
    }

    current.value += value
    current.count++
    current.lastTime = startTime

    if (!largest || current.value > largest.value) {
      largest = current
    }
  }

  function result() {
    return {
      // The web-vitals score: the worst single window.
      cls: largest ? largest.value : 0,
      windowStart: largest ? largest.startTime : null,
      windowShifts: largest ? largest.count : 0,
      // Every shift added together, kept to compare with the old measure.
      cumulative: total,
      shiftCount: count,
    }
  }

  // The largest individual shifts, biggest first.
  function topShifts(n = 5) {
    return [...shifts].sort((a, b) => b.value - a.value).slice(0, n)
  }

  return { add, result, topShifts }
}
