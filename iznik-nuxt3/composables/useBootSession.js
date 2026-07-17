import { useAuthStore } from '~/stores/auth'
import { fetchMe } from '~/composables/useMe'

// How recently the session must have been fetched for a layout to trust it
// without firing a background refresh. Layout swaps (e.g. / → /browse on a
// logged-in cold start) happen within a second or two, and the second layout
// must not repeat the GET /session the first one just did.
export const BOOT_SESSION_FRESH_MS = 30_000

// Shared boot gate for layouts/default.vue and layouts/login.vue.
//
// Resolves the login state exactly once per cold start: if we have stored
// credentials but no user yet, it waits for GET /session; if the user is
// already in memory (another layout booted moments ago), it returns
// immediately and refreshes in the background only when stale. Without
// credentials it still resolves loginStateKnown, which fetchUser does without
// a network call.
//
// Boot must never throw - fetch errors are logged and swallowed, and the
// caller gets whatever user state we ended up with.
export async function bootSession() {
  const authStore = useAuthStore()
  const { jwt, persistent } = authStore.auth

  // fetchMe's dedup uses module-scope state, which the server would share
  // across concurrent SSR requests - so only dedup on the client (where it
  // matters: the / → /browse layout swap). On the server, hit the per-request
  // store directly, as the layouts always did.
  const fetchBlocking = import.meta.server
    ? () => authStore.fetchUser()
    : () => fetchMe(true)

  if (jwt || persistent) {
    if (authStore.user) {
      if (Date.now() - (authStore.userFetchedAt || 0) > BOOT_SESSION_FRESH_MS) {
        // Known but stale - refresh without blocking render.
        fetchMe(false)
      }

      return authStore.user
    }

    try {
      await fetchBlocking()
    } catch (e) {
      console.log('Error fetching user', e)
    }
  }

  if (!authStore.loginStateKnown) {
    // No credentials, or the fetch above failed: this marks the login state
    // known (no network call happens when there are no credentials).
    try {
      await fetchBlocking()
    } catch (e) {
      console.log('Error in second fetchUser', e?.message)
    }
  }

  return authStore.user
}
