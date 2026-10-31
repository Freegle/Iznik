import { describe, it, expect } from 'vitest'
import { sortBrowseMessages } from '~/composables/useMessageSort.js'

const ids = (list, sort, rm) => sortBrowseMessages(list, sort, rm).map((m) => m.id)
const iso = (s) => `2026-01-0${s}T00:00:00Z`

// Table-driven cases: each row is [name, messages, sort, roadMiles, expected ids].
// Every row is also run with the input reversed where `both` is set, so the comparator is
// exercised with a and b in either position.
const roadByLookup = (table) => (m) => (m.id in table ? table[m.id] : null)

const CASES = [
  // Empty / missing input.
  ['undefined list', undefined, 'Newest', null, []],
  ['null list', null, 'Unseen', null, []],
  ['empty list', [], 'Nearby', null, []],

  // Plain recency.
  [
    'Newest: newest first',
    [{ id: 1, posted: iso(1) }, { id: 2, posted: iso(3) }, { id: 3, posted: iso(2) }],
    'Newest',
    null,
    [2, 3, 1],
  ],
  [
    'unknown sort name behaves as Newest',
    [{ id: 1, posted: iso(1) }, { id: 2, posted: iso(3) }],
    'Whatever',
    null,
    [2, 1],
  ],

  // recencyTs fallback chain.
  [
    'visibleSince beats posted',
    [
      { id: 1, visibleSince: iso(1), posted: iso(9) },
      { id: 2, visibleSince: iso(2), posted: iso(1) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'posted beats arrival when no visibleSince',
    [
      { id: 1, posted: iso(1), arrival: iso(9) },
      { id: 2, posted: iso(2), arrival: iso(1) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'arrival used when visibleSince and posted absent',
    [{ id: 1, arrival: iso(1) }, { id: 2, arrival: iso(2) }],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'Go zero-time visibleSince falls through to posted',
    [
      { id: 1, visibleSince: '0001-01-01T00:00:00Z', posted: iso(5) },
      { id: 2, visibleSince: '0001-01-01T00:00:00Z', posted: iso(2) },
    ],
    'Newest',
    null,
    [1, 2],
  ],
  [
    'Go zero-time posted falls through to arrival',
    [
      { id: 1, posted: '0001-01-01T00:00:00Z', arrival: iso(1) },
      { id: 2, posted: '0001-01-01T00:00:00Z', arrival: iso(2) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'unparseable visibleSince falls through to posted',
    [
      { id: 1, visibleSince: 'garbage', posted: iso(1) },
      { id: 2, visibleSince: 'garbage', posted: iso(2) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'unparseable posted falls through to arrival',
    [
      { id: 1, posted: 'garbage', arrival: iso(1) },
      { id: 2, posted: 'garbage', arrival: iso(2) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'no usable date scores 0 so a dated post leads',
    [{ id: 1, arrival: 'nope' }, { id: 2, posted: iso(1) }, { id: 3 }],
    'Newest',
    null,
    [2, 1, 3],
  ],
  [
    'all undated messages tie and keep input order',
    [{ id: 1 }, { id: 2 }, { id: 3 }],
    'Newest',
    null,
    [1, 2, 3],
  ],

  // Pinned / mine / successful precedence.
  [
    'pinned leads Newest',
    [{ id: 1, posted: iso(9) }, { id: 2, posted: iso(1), pinned: true }],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'pinned leads Nearby despite a nearer post',
    [{ id: 1, distance: 1 }, { id: 2, distance: 50, pinned: true }],
    'Nearby',
    null,
    [2, 1],
  ],
  [
    'pinned leads Unseen despite higher score',
    [{ id: 1, unseen: true, score: 99 }, { id: 2, unseen: false, pinned: true }],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'pinned beats mine',
    [
      { id: 1, mine: true, posted: iso(9) },
      { id: 2, pinned: true, posted: iso(1) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'pinned and mine: pinned posts keep the normal order among themselves',
    [
      { id: 1, pinned: true, posted: iso(1) },
      { id: 2, pinned: true, posted: iso(2) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'own open post pins in Nearby, newest-first among own posts',
    [
      { id: 1, distance: 1 },
      { id: 2, mine: true, distance: 90, posted: iso(1) },
      { id: 3, mine: true, distance: 80, posted: iso(2) },
    ],
    'Nearby',
    null,
    [3, 2, 1],
  ],
  [
    'own open post pins in Unseen ahead of unseen high score',
    [
      { id: 1, unseen: true, score: 50 },
      { id: 2, mine: true, unseen: false, posted: iso(1) },
    ],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'own successful post does not pin (Newest)',
    [
      { id: 1, mine: true, successful: true, posted: iso(1) },
      { id: 2, posted: iso(2) },
    ],
    'Newest',
    null,
    [2, 1],
  ],
  [
    'own successful post does not pin, but own open post does',
    [
      { id: 1, mine: true, successful: true, posted: iso(5) },
      { id: 2, posted: iso(4) },
      { id: 3, mine: true, posted: iso(1) },
    ],
    'Newest',
    null,
    [3, 1, 2],
  ],
  [
    'successful post that is not mine is not pinned either',
    [{ id: 1, successful: true, posted: iso(1) }, { id: 2, posted: iso(2) }],
    'Newest',
    null,
    [2, 1],
  ],

  // Unseen sort.
  [
    'Unseen: unseen before seen, seen listed first in input',
    [
      { id: 1, unseen: false, posted: iso(9) },
      { id: 2, unseen: true, score: 1 },
    ],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'Unseen: unseen before seen, unseen listed first in input',
    [
      { id: 1, unseen: true, score: 1 },
      { id: 2, unseen: false, posted: iso(9) },
    ],
    'Unseen',
    null,
    [1, 2],
  ],
  [
    'Unseen: unseen ordered by score descending',
    [
      { id: 1, unseen: true, score: 1 },
      { id: 2, unseen: true, score: 5 },
      { id: 3, unseen: true, score: 3 },
    ],
    'Unseen',
    null,
    [2, 3, 1],
  ],
  [
    'Unseen: missing score counts as 0 (below a positive score)',
    [
      { id: 1, unseen: true },
      { id: 2, unseen: true, score: 2 },
    ],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'Unseen: missing score counts as 0 (above a negative score)',
    [
      { id: 1, unseen: true, score: -2 },
      { id: 2, unseen: true },
    ],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'Unseen: truthy non-boolean unseen is coerced',
    [
      { id: 1, unseen: 0, posted: iso(9) },
      { id: 2, unseen: 1, score: 1 },
    ],
    'Unseen',
    null,
    [2, 1],
  ],
  [
    'Unseen: seen block newest-first, ignoring score',
    [
      { id: 1, posted: iso(1), score: 100 },
      { id: 2, posted: iso(3), score: 0 },
      { id: 3, posted: iso(2), score: 50 },
    ],
    'Unseen',
    null,
    [2, 3, 1],
  ],
  [
    'Unseen: successful unseen post stays in the unseen block',
    [
      { id: 1, unseen: false, posted: iso(9) },
      { id: 2, unseen: true, successful: true, score: 1 },
    ],
    'Unseen',
    null,
    [2, 1],
  ],

  // Nearby sort.
  [
    'Nearby: nearest first by m.distance',
    [{ id: 1, distance: 5 }, { id: 2, distance: 1 }, { id: 3, distance: 3 }],
    'Nearby',
    null,
    [2, 3, 1],
  ],
  [
    'Nearby: missing distance sorts last',
    [{ id: 1 }, { id: 2, distance: 9 }, { id: 3, distance: 1 }],
    'Nearby',
    null,
    [3, 2, 1],
  ],
  [
    'Nearby: non-finite distance (NaN) sorts last',
    [{ id: 1, distance: NaN }, { id: 2, distance: 4 }],
    'Nearby',
    null,
    [2, 1],
  ],
  [
    'Nearby: zero distance is real and sorts first',
    [{ id: 1, distance: 3 }, { id: 2, distance: 0 }],
    'Nearby',
    null,
    [2, 1],
  ],
  [
    'Nearby: equal distance falls back to recency',
    [
      { id: 1, distance: 2, posted: iso(1) },
      { id: 2, distance: 2, posted: iso(3) },
      { id: 3, distance: 2, posted: iso(2) },
    ],
    'Nearby',
    null,
    [2, 3, 1],
  ],
  [
    'Nearby: both missing distance falls back to recency',
    [{ id: 1, posted: iso(1) }, { id: 2, posted: iso(2) }],
    'Nearby',
    null,
    [2, 1],
  ],
  [
    'Nearby: roadMiles overrides distance when finite',
    [{ id: 1, distance: 1 }, { id: 2, distance: 2 }],
    'Nearby',
    roadByLookup({ 1: 10, 2: 3 }),
    [2, 1],
  ],
  [
    'Nearby: roadMiles of 0 is finite and wins',
    [{ id: 1, distance: 1 }, { id: 2, distance: 2 }],
    'Nearby',
    roadByLookup({ 1: 5, 2: 0 }),
    [2, 1],
  ],
  [
    'Nearby: roadMiles null falls back to distance',
    [{ id: 1, distance: 9 }, { id: 2, distance: 2 }],
    'Nearby',
    () => null,
    [2, 1],
  ],
  [
    'Nearby: roadMiles NaN falls back to distance',
    [{ id: 1, distance: 9 }, { id: 2, distance: 2 }],
    'Nearby',
    () => NaN,
    [2, 1],
  ],
  [
    'Nearby: roadMiles answering for only some posts mixes road and crow',
    [{ id: 1, distance: 1 }, { id: 2, distance: 8 }, { id: 3, distance: 9 }],
    'Nearby',
    roadByLookup({ 3: 2 }),
    [1, 3, 2],
  ],
  [
    'Nearby: no road answer and no distance sorts last',
    [{ id: 1 }, { id: 2, distance: 50 }],
    'Nearby',
    () => null,
    [2, 1],
  ],
  [
    'roadMiles is ignored outside Nearby',
    [{ id: 1, posted: iso(1), distance: 1 }, { id: 2, posted: iso(2), distance: 9 }],
    'Newest',
    roadByLookup({ 1: 0, 2: 100 }),
    [2, 1],
  ],
]

describe('sortBrowseMessages', () => {
  it.each(CASES)('%s', (_name, messages, sort, roadMiles, expected) => {
    expect(ids(messages, sort, roadMiles)).toEqual(expected)
  })

  // Re-run every non-tie case with the input reversed: the result must not depend on the
  // starting position (ties are excluded because they keep input order by design).
  const TIES = new Set([
    'all undated messages tie and keep input order',
    'no usable date scores 0 so a dated post leads',
    'pinned and mine: pinned posts keep the normal order among themselves',
  ])
  it.each(CASES.filter((c) => c[1] && c[1].length > 1 && !TIES.has(c[0])))(
    '%s (input reversed)',
    (_name, messages, sort, roadMiles, expected) => {
      expect(ids([...messages].reverse(), sort, roadMiles)).toEqual(expected)
    }
  )

  describe('purity', () => {
    it.each(['Unseen', 'Nearby', 'Newest'])(
      'returns a new array and does not mutate the input (%s)',
      (sort) => {
        const input = [
          { id: 1, posted: iso(1), distance: 5, unseen: false },
          { id: 2, posted: iso(3), distance: 1, unseen: true, score: 2 },
          { id: 3, posted: iso(2), distance: 3, mine: true },
          { id: 4, posted: iso(4), pinned: true },
        ]
        const snapshot = input.map((m) => m.id)
        const copies = input.map((m) => ({ ...m }))
        const out = sortBrowseMessages(input, sort)
        expect(out).not.toBe(input)
        expect(input.map((m) => m.id)).toEqual(snapshot)
        expect(input).toEqual(copies)
        expect(out).toHaveLength(input.length)
        // Same message objects, not clones.
        out.forEach((m) => expect(input).toContain(m))
      }
    )

    it('returns a new empty array for an empty input', () => {
      const input = []
      const out = sortBrowseMessages(input, 'Newest')
      expect(out).toEqual([])
      expect(out).not.toBe(input)
    })

    it('does not call roadMiles when the sort is not Nearby', () => {
      let calls = 0
      sortBrowseMessages([{ id: 1 }, { id: 2 }], 'Newest', () => {
        calls++
        return 1
      })
      expect(calls).toBe(0)
    })

    it('calls roadMiles once per message (Schwartzian: key computed once)', () => {
      let calls = 0
      const list = Array.from({ length: 20 }, (_, i) => ({ id: i, distance: 20 - i }))
      sortBrowseMessages(list, 'Nearby', () => {
        calls++
        return null
      })
      expect(calls).toBe(list.length)
    })
  })

  it('is stable for equal keys across a larger list', () => {
    const list = Array.from({ length: 30 }, (_, i) => ({ id: i, posted: iso(1) }))
    expect(ids(list, 'Newest')).toEqual(list.map((m) => m.id))
  })
})
