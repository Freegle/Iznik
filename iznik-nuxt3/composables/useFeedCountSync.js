import { watch } from 'vue'

// Keep the browse feed in step with the badge count.
//
// The count is polled every 60 seconds (useNavbar's getCounts -> messageStore.fetchCount).
// The FEED is not on that timer - it is fetched on navigation, on a filter change, and after
// "Mark seen". So a post arriving after the page loaded raises the count while the loaded list
// still predates it: the page says "1 new post" and has nothing unseen to show for it, sitting
// directly above "YOU'RE UP TO DATE". A count is a promise that there is something to see, and
// the page could not keep it.
//
// So when the count RISES, pull the feed. Only on a rise:
//   - unchanged is the common case (the poll fires every minute regardless), and refetching
//     then would put every browsing member's reach query back on the server every minute;
//   - falling is what "Mark seen" does, and that path refreshes the feed itself.
//
// `count` is a ref/computed of the server's unseen count; `refresh` reloads the feed for the
// current browse view. Returns the watch handle so a caller can stop it.
export function useFeedCountSync(count, refresh) {
  // The value present at setup came back alongside the feed we already have, so the posts
  // behind it are loaded. Only what happens AFTER that is news.
  let previous = numeric(count.value)
  let fetching = false

  return watch(count, (raw) => {
    const now = numeric(raw)
    const rose = now > previous
    previous = now

    // A poll can fire again while a slow reach query is still running. Fetching the same feed
    // twice concurrently is wasted work on a query the server is already busy with, and the
    // second answer would only overwrite the first.
    if (!rose || fetching) {
      return
    }

    fetching = true
    Promise.resolve()
      .then(refresh)
      .catch(() => {
        // Best-effort: a failed refresh leaves the count where it is, and the next rise (or
        // the member's own navigation) tries again. Swallowing it here keeps a network blip
        // from wedging the sync permanently.
      })
      .finally(() => {
        fetching = false
      })
  })
}

// fetchCount can resolve to null/undefined before the first real answer arrives, and a
// non-numeric value must never read as a rise.
function numeric(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : 0
}
