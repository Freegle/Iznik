/**
 * Build the browser-tab title with an unread-count badge prefix, e.g.
 * "(3) Freegle - ...". Mirrors what ModTools (and apps like Messenger) do so a
 * member sees they have new messages without opening the tab.
 *
 * @param {string|null|undefined} titleChunk The page's own title.
 * @param {number} totalCount Combined unread count (chats + notifications).
 * @returns {string|null} The title to display, count-prefixed when > 0.
 */
export function badgeTitle(titleChunk, totalCount) {
  if (!titleChunk) {
    return null
  }

  // Don't double-prefix if a count is already there, and only add when > 0.
  if (titleChunk.charAt(0) !== '(' && totalCount > 0) {
    return '(' + totalCount + ') ' + titleChunk
  }

  return titleChunk
}
