// When a rippling post is due to reach you.
//
// A post that has rippled out but not yet to your area is still repliable: the reply is
// accepted and HELD so people closer to the item get first go. The API tells us when the
// waiting ends, as `reachesyouat`, with `reachesyoufully` saying which of two questions
// it answered (see message.ReachesYouAt in iznik-server-go):
//
//   fully = true   a tick of the post's reach schedule grows far enough to include you,
//                  and this is when that happens.
//   fully = false  no tick ever does. This is instead when the reach stops expanding,
//                  which is the point held replies are passed on regardless.
//
// The second is the common case, and it is an upper bound rather than a prediction: a
// reach also finishes early when the post gathers enough repliers or is taken. So the
// wording is deliberately "by", never "at", and never a clock time.

const MINUTE = 60 * 1000
const HOUR = 60 * MINUTE

/**
 * How far off a moment is, in words, from a fixed "now".
 *
 * Rounded hard on purpose. The underlying timetable has steps at 1, 3, 6, 12, 24, 48,
 * 72, 120 and 168 hours, so anything more precise than this would be inventing accuracy
 * the schedule does not have.
 *
 * @param {string|number|Date} at when the reach arrives (anything Date accepts)
 * @param {Date} [now] the moment to measure from, injectable for tests
 * @returns {string|null} e.g. "within the hour", "tomorrow", "by Friday", or null when
 *   there is nothing sensible to say
 */
export function reachArrivalWording(at, now = new Date()) {
  if (!at) return null

  const when = at instanceof Date ? at : new Date(at)
  if (isNaN(when.getTime())) return null

  const ms = when.getTime() - now.getTime()

  // Already due. The reach engine runs every minute and the feed is cached, so a
  // moment slightly in the past is normal rather than wrong - it just means there is
  // no wait left worth describing.
  if (ms <= 0) return 'any moment now'

  // Beyond the schedule's own ceiling of a week. Either the row is odd or the clock is,
  // and a number we cannot justify is worse than saying nothing.
  if (ms > 14 * 24 * HOUR) return null

  if (ms < HOUR) return 'within the hour'

  if (ms < 12 * HOUR) {
    const hours = Math.max(2, Math.round(ms / HOUR))
    return `in about ${hours} hours`
  }

  const midnightTonight = new Date(now)
  midnightTonight.setHours(24, 0, 0, 0)
  const daysAhead = Math.floor(
    (when.getTime() - midnightTonight.getTime()) / (24 * HOUR)
  )

  if (daysAhead < 0) return 'later today'
  if (daysAhead === 0) return 'tomorrow'

  // Within the week ahead a weekday name is the clearest thing to say. Beyond that it
  // would be ambiguous ("Friday" could be either), so fall back to counting days.
  if (daysAhead < 6) {
    return `by ${when.toLocaleDateString('en-GB', { weekday: 'long' })}`
  }

  const days = Math.round(ms / (24 * HOUR))
  return `within about ${days} days`
}

/**
 * The sentence shown under the Reply button when the post hasn't rippled to you yet.
 *
 * Built here rather than in each template because three components show it and the two
 * cases say different things: one is about the post arriving, the other about the reply
 * being passed on anyway. Returns null when there is no usable estimate, and the
 * components then fall back to their existing open-ended wording.
 *
 * @param {string|number|Date} at reachesyouat from the API
 * @param {boolean} fully reachesyoufully from the API
 * @param {Date} [now] injectable for tests
 * @returns {string|null}
 */
export function reachNoticeSentence(at, fully, now = new Date()) {
  const words = reachArrivalWording(at, now)
  if (!words) return null

  // "at the latest" on the second case is load-bearing, not padding. The reach finishing
  // is the LAST moment the reply goes: the buffer delay (RippleReplyService::releaseDue)
  // releases it sooner, and a reach also ends early when the post gathers enough repliers
  // or is taken. Stating it flatly would understate by days, and would go stale the moment
  // the buffer policy changed.
  return fully
    ? `It's due to reach you ${words}, and we'll pass your reply on then.`
    : `People closer to it get first go, so we'll pass yours on ${words} at the latest.`
}
