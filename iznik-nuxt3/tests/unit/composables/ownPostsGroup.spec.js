import { describe, it, expect } from 'vitest'
import {
  partitionOwnPosts,
  ownPostsLabel,
} from '~/composables/useOwnPostsGroup'

// The viewer's own posts were pinned to the top of every browse sort (Discourse 9933, so
// members could find them) but shown in full, so on a feed where you have several live posts
// you scrolled past your own before reaching anything new. They are now collapsed behind a
// single line, expandable on click.
//
// Pure functions, extracted for the same reason sortBrowseMessages was: MessageList is heavy
// with ScrollGrid, async cards and observers, and the interesting logic deserves a test that
// does not have to mount any of it.

const mine = (id, extra = {}) => ({ id, mine: true, ...extra })
const theirs = (id, extra = {}) => ({ id, mine: false, ...extra })

describe('partitionOwnPosts', () => {
  it('splits the viewer’s posts out from the rest, keeping each order', () => {
    const { own, others } = partitionOwnPosts([
      mine(1),
      theirs(2),
      mine(3),
      theirs(4),
    ])

    expect(own.map((m) => m.id)).toEqual([1, 3])
    expect(others.map((m) => m.id)).toEqual([2, 4])
  })

  it('preserves the incoming order within each side', () => {
    // sortBrowseMessages has already ordered own posts newest-first and the rest by the
    // chosen sort. Partitioning must not disturb either.
    const { own, others } = partitionOwnPosts([
      mine(9),
      mine(7),
      theirs(5),
      theirs(3),
    ])

    expect(own.map((m) => m.id)).toEqual([9, 7])
    expect(others.map((m) => m.id)).toEqual([5, 3])
  })

  it('treats a missing mine flag as not the viewer’s', () => {
    // Older cached feeds, and any path that did not populate the flag.
    const { own, others } = partitionOwnPosts([{ id: 1 }, mine(2)])

    expect(own.map((m) => m.id)).toEqual([2])
    expect(others.map((m) => m.id)).toEqual([1])
  })

  it('copes with an empty or absent list', () => {
    expect(partitionOwnPosts([])).toEqual({ own: [], others: [] })
    expect(partitionOwnPosts(null)).toEqual({ own: [], others: [] })
    expect(partitionOwnPosts(undefined)).toEqual({ own: [], others: [] })
  })

  it('returns new arrays rather than mutating the input', () => {
    const input = [mine(1), theirs(2)]
    const { own, others } = partitionOwnPosts(input)

    expect(input.map((m) => m.id)).toEqual([1, 2])
    expect(own).not.toBe(input)
    expect(others).not.toBe(input)
  })
})

describe('ownPostsLabel', () => {
  it('reads as the collapsed invitation when closed', () => {
    expect(ownPostsLabel(3, false)).toBe('3 posts by you - click to show')
  })

  it('offers to put them away again when open', () => {
    expect(ownPostsLabel(3, true)).toBe('3 posts by you - click to hide')
  })

  it('says post, not posts, for one', () => {
    expect(ownPostsLabel(1, false)).toBe('1 post by you - click to show')
    expect(ownPostsLabel(1, true)).toBe('1 post by you - click to hide')
  })

  it('says nothing when there are none, so the row can be left out entirely', () => {
    expect(ownPostsLabel(0, false)).toBeNull()
  })
})
