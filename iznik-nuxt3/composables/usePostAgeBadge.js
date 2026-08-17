// The age shown on a feed card, and the reason it might not match when the post was written.
//
// The feed used to run two clocks: "Newest posted" sorted on `posted` (the original
// messages.arrival, which never moves) while the card's badge read a group arrival - bumped
// when a post ripples into a new group, and bumped again by a repost. So a feed set to
// "Newest posted" could show 5 days, 4 days, 5 days, 2 hours and be, by its own sort key,
// perfectly ordered.
//
// One clock now: `visibleSince`, the earliest the viewer could have seen it (the oldest
// arrival among the groups where the post is live AND visible to them). The badge shows it and
// the sort uses it, so the order can never contradict the numbers on screen.
//
// A repost bumps visibleSince, which lifts the post back up the feed. That is deliberate: a
// repost is the giver re-offering the item, so it really is new again - unlike a reach bump,
// where nothing changed for the member (Discourse 9844).
//
// When visibleSince and posted differ by more than a day the badge adds "first posted N days",
// so a card reading "4 days" next to a month-old post does not look wrong. One wording covers
// both causes: a member wants to know how long it has been available to them and that it
// started earlier, not whether the mechanism was a ripple or a repost.

const HOUR = 3600 * 1000
const DAY = 24 * HOUR

const MONTH = 30.44 * DAY
const YEAR = 365 * DAY

/**
 * "2 hours", "1 day", "18 days", "3 months", "1 year" - the units a feed card has room for.
 *
 * Days run to about two months, which covers where the feed actually lives. Past that they
 * stop being readable: a long-dormant post re-offered was rendering "first posted 519 days",
 * a number nobody converts in their head.
 */
function span(ms) {
  if (ms < DAY) {
    const hours = Math.max(1, Math.round(ms / HOUR))
    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`
  }

  if (ms < 2 * MONTH) {
    const days = Math.round(ms / DAY)
    return `${days} ${days === 1 ? 'day' : 'days'}`
  }

  if (ms < YEAR) {
    const months = Math.round(ms / MONTH)
    return `${months} ${months === 1 ? 'month' : 'months'}`
  }

  const years = Math.max(1, Math.round(ms / YEAR))
  return `${years} ${years === 1 ? 'year' : 'years'}`
}

function ms(value, now) {
  if (!value) return null
  const t = new Date(value).getTime()
  if (!Number.isFinite(t) || t <= 0) return null

  return Math.max(0, now.getTime() - t)
}

/**
 * @param {object} message feed summary: { visibleSince, posted } - the two fields the browse
 *   feed actually sends. Nothing else is needed: the wording is the same whether the post
 *   rippled to the viewer or was re-offered, so there is no cause to distinguish.
 * @param {object} opts
 *   wide  - true from the lg breakpoint up, matching MessageSummary's existing
 *           `isLgPlus ? timeAgoExpanded : timeAgo`. Phones get the short form.
 *   now   - injectable for tests.
 * @returns {string} '' when there is no usable time
 */
export function postAgeBadge(message, { wide = false, now = new Date() } = {}) {
  const m = message || {}

  // visibleSince is the number the sort uses. Fall back for older cached feeds and any path the
  // server field has not reached yet - better a slightly wrong age than none.
  const age = ms(m.visibleSince, now) ?? ms(m.posted, now) ?? ms(m.arrival, now)
  if (age === null) return ''

  const here = span(age)

  // When the post was WRITTEN. The feed summary calls that `posted`; the full message
  // (/api/message/<ids>) has no such field because its `arrival` already is messages.arrival.
  // Prefer posted: on the feed, `arrival` is the reach-bumped spatial arrival instead, so
  // taking it there would date the post to when it last rippled.
  const postedAge = ms(m.posted, now) ?? ms(m.arrival, now)
  const gap = postedAge === null ? 0 : postedAge - age

  // The gap alone decides. An earlier version also required a server-sent `divergence` field
  // saying WHY the two differed - and no endpoint ever sent one, so this returned early every
  // time and the second clause never appeared on the live site while the tests, which supplied
  // the field, all passed.
  //
  // Under a day is not worth a second clause on every card. A negative gap means the post
  // claims to predate its own arrival - clock skew or a backfilled row - so say nothing rather
  // than render a nonsense second clause.
  if (gap < DAY) return here

  const there = span(postedAge)

  // One wording for both causes. A member does not need to know whether the post travelled to
  // them or was re-offered - only how long it has been available to them, and that it started
  // earlier. "reposted" and "elsewhere" both said more than that and read as jargon.
  return wide
    ? `${here} · first posted ${there} ago`
    : `${here} · first posted ${there}`
}
