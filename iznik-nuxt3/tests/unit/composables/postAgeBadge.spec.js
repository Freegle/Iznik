import { describe, it, expect } from 'vitest'
import { postAgeBadge } from '~/composables/usePostAgeBadge'

// The feed showed 5 days, 4 days, 5 days, 2 hours under "Newest posted" - not descending -
// because the badge and the sort read different clocks. The badge took a group arrival (bumped
// when a post ripples, and by a repost) while the sort took `posted`, the original
// messages.arrival, which never moves.
//
// One clock now: visibleSince, the earliest the viewer could have seen it. When that differs
// materially from when it was first posted, the badge says so, in the same words either way -
// a member wants to know how long it has been available to them and that it started earlier,
// not whether the mechanism was a ripple or a repost.
//
// These cases feed the badge exactly what the server sends - visibleSince and posted, nothing
// else. An earlier version gated the second clause on a `divergence` field that no endpoint
// ever populated, so the clause never rendered in production while every test passed: the
// tests were supplying a field the real payload does not have.
//
// Short on mobile, longer from lg up, mirroring MessageSummary's existing
// `isLgPlus ? timeAgoExpanded : timeAgo`.

const HOUR = 3600 * 1000
const DAY = 24 * HOUR
const NOW = new Date('2026-08-13T12:00:00Z')
const ago = (ms) => new Date(NOW.getTime() - ms).toISOString()

describe('postAgeBadge — one clock, so order and badge agree', () => {
  it('shows just the age when nothing has moved', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(4 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days')
  })

  it('measures the age from visibleSince, not from when it was posted', () => {
    // The whole point: a post that reached you 4 days ago reads as 4 days, even though it was
    // posted 6 days ago somewhere else.
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(6 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b.startsWith('4 days')).toBe(true)
  })
})

describe('postAgeBadge — reached you later than it was posted', () => {
  it('is short on mobile', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(6 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days · first posted 6 days')
  })

  it('spells it out from lg up', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(6 * DAY) },
      { wide: true, now: NOW }
    )

    expect(b).toBe('4 days · first posted 6 days ago')
  })
})

describe('postAgeBadge — reposted (same wording: the member does not need the mechanism)', () => {
  it('is short on mobile', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(18 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days · first posted 18 days')
  })

  it('spells it out from lg up', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(18 * DAY) },
      { wide: true, now: NOW }
    )

    expect(b).toBe('4 days · first posted 18 days ago')
  })
})

describe('postAgeBadge — the two payload shapes it is handed', () => {
  // The feed summary (GET /isochrone/message) and the full message (GET /api/message/<ids>)
  // are different shapes, and a browse card is rendered from BOTH at different moments: the
  // summary while the list is being built, the full message once the store has loaded it.
  //
  // The summary carries `posted`. The full message does not - its `arrival` IS
  // messages.arrival, the original write time, the same number `posted` reports. Reading only
  // `posted` meant the clause vanished the instant the store filled in the real message, which
  // is the state a member actually looks at.
  it('uses posted on a feed summary', () => {
    const b = postAgeBadge(
      {
        visibleSince: ago(4 * DAY),
        posted: ago(18 * DAY),
        arrival: ago(4 * DAY),
      },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days · first posted 18 days')
  })

  it('falls back to arrival on a full message, which has no posted', () => {
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), arrival: ago(18 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days · first posted 18 days')
  })

  it('prefers posted over arrival when both are present', () => {
    // On the feed, `arrival` is the reach-BUMPED spatial arrival, not the write time. If that
    // won, a post would claim to have been "first posted" after it became visible.
    const b = postAgeBadge(
      {
        visibleSince: ago(10 * DAY),
        posted: ago(30 * DAY),
        arrival: ago(2 * DAY),
      },
      { wide: false, now: NOW }
    )

    expect(b).toBe('10 days · first posted 30 days')
  })

  it('dates a full message from arrival when visibleSince has not reached that endpoint', () => {
    const b = postAgeBadge({ arrival: ago(5 * DAY) }, { wide: false, now: NOW })

    expect(b).toBe('5 days')
  })
})

describe('postAgeBadge — when not to explain', () => {
  it('says nothing extra when the gap is under a day', () => {
    // Every card would otherwise grow a second clause for a difference nobody cares about.
    const b = postAgeBadge(
      { visibleSince: ago(2 * DAY), posted: ago(2 * DAY + 6 * HOUR) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('2 days')
  })

  it('says nothing extra when the post is somehow newer than its arrival', () => {
    // Clock skew, or a backfilled row. Never render a negative second clause.
    const b = postAgeBadge(
      { visibleSince: ago(4 * DAY), posted: ago(1 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('4 days')
  })

  it('ignores the zero time Go sends for an unset visibleSince', () => {
    // MessageSummary.VisibleSince is a time.Time, and `omitempty` does not omit a struct, so
    // every endpoint that does not populate it still ships "0001-01-01T00:00:00Z". Treated as
    // present, that would date every post in the map view to the year 1.
    const b = postAgeBadge(
      { visibleSince: '0001-01-01T00:00:00Z', posted: ago(3 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('3 days')
  })

  it('falls back to posted when visibleSince is missing', () => {
    // Older cached feeds, and any path the server field has not reached yet.
    const b = postAgeBadge({ posted: ago(3 * DAY) }, { wide: false, now: NOW })

    expect(b).toBe('3 days')
  })

  it('returns an empty string when there is no usable time at all', () => {
    expect(postAgeBadge({}, { wide: false, now: NOW })).toBe('')
    expect(postAgeBadge(null, { wide: false, now: NOW })).toBe('')
  })
})

describe('postAgeBadge — units', () => {
  it('uses hours for something posted today', () => {
    const b = postAgeBadge(
      { visibleSince: ago(2 * HOUR), posted: ago(2 * HOUR) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('2 hours')
  })

  it('switches to months once days stop being readable', () => {
    // Real feed data: a long-dormant post re-offered reads "first posted 519 days", which is a
    // number nobody converts in their head.
    const b = postAgeBadge(
      { visibleSince: ago(6 * DAY), posted: ago(100 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('6 days · first posted 3 months')
  })

  it('switches to years beyond twelve months', () => {
    const b = postAgeBadge(
      { visibleSince: ago(6 * DAY), posted: ago(519 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('6 days · first posted 1 year')
  })

  it('still counts in days up to a couple of months', () => {
    // "43 days" is fine; the switch must not swallow the range the feed mostly lives in.
    const b = postAgeBadge(
      { visibleSince: ago(6 * DAY), posted: ago(43 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('6 days · first posted 43 days')
  })

  it('says 1 day, not 1 days', () => {
    const b = postAgeBadge(
      { visibleSince: ago(1 * DAY), posted: ago(1 * DAY) },
      { wide: false, now: NOW }
    )

    expect(b).toBe('1 day')
  })
})
