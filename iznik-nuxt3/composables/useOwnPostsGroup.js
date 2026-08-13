// The viewer's own posts, collapsed out of the browse feed.
//
// They are pinned to the top of every sort (see sortBrowseMessages - Discourse 9933, so
// members can find their own posts rather than losing them in the reach order), but shown in
// full that meant anyone with a few live posts scrolled past their own before reaching
// anything new. Same information, one line instead of several cards, expandable on click.
//
// Pure, and separate from MessageList for the same reason sortBrowseMessages is: that
// component carries ScrollGrid, async cards and visibility observers, none of which this
// logic needs in order to be tested.

/**
 * Split a feed into the viewer's own posts and everyone else's.
 *
 * Order is preserved on both sides: sortBrowseMessages has already put own posts
 * newest-first and the rest in the chosen sort, and neither should be disturbed here.
 *
 * A missing `mine` flag counts as not the viewer's - older cached feeds, and any path that
 * did not populate it, must not have their posts hidden behind the collapsed row.
 *
 * @param {Array} messages feed summaries
 * @returns {{own: Array, others: Array}} new arrays; the input is not mutated
 */
export function partitionOwnPosts(messages) {
  const own = []
  const others = []

  for (const m of messages || []) {
    if (m?.mine) {
      own.push(m)
    } else {
      others.push(m)
    }
  }

  return { own, others }
}

/**
 * The text of the collapsed row.
 *
 * Says what it does when clicked, because the row is the only affordance - there is no
 * separate chevron to read as "expandable".
 *
 * @param {number} count how many of the viewer's posts are in the feed
 * @param {boolean} expanded whether they are currently shown
 * @returns {string|null} null when there are none, so the caller can omit the row
 */
export function ownPostsLabel(count, expanded) {
  if (!count) return null

  const noun = count === 1 ? 'post' : 'posts'
  const action = expanded ? 'hide' : 'show'

  return `${count} ${noun} by you - click to ${action}`
}
