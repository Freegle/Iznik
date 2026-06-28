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

/**
 * Re-apply (or strip) the "(N) " unread badge prefix on an existing tab title,
 * preserving the page title. Pure + testable.
 *
 * @param {string|null|undefined} currentTitle The current document.title.
 * @param {number} totalCount Combined unread count.
 * @returns {string} The title with the badge prefix reconciled.
 */
export function applyBadgeToTitle(currentTitle, totalCount) {
  const base = (currentTitle || '').replace(/^\(\d+\)\s*/, '')
  return totalCount > 0 ? `(${totalCount}) ${base}` : base
}

/**
 * Keep the browser-tab badge live. useHead's titleTemplate is a plain function,
 * so Nuxt does NOT re-run it when the count refs read inside it change — the badge
 * only refreshed on navigation, and ModTools only refreshed on its 30s work poll
 * (Discourse 9806/9: the count appeared only when the user clicked a chat). Watch
 * the count and re-apply the prefix to document.title directly so it updates the
 * moment a chat/notification arrives. Call inside a component setup (FD + MT).
 *
 * @param {() => number} totalCountGetter Reactive getter for the combined count.
 */
export function useReactiveTabBadge(totalCountGetter) {
  if (typeof document === 'undefined') return
  watch(
    totalCountGetter,
    (total) => {
      document.title = applyBadgeToTitle(document.title, total)
    },
    { immediate: true, flush: 'post' }
  )
}
