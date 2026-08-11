import { ref } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useStoryStore } from '~/stores/stories'

/**
 * Shared "Tell us your story!" button behaviour.
 *
 * Adding a story needs a login, so if they're not logged in when they ask to
 * tell us their story we get that out of the way before showing them the form.
 * Otherwise they type the whole thing out and the submit fails - which is what
 * happened to the freegler who followed the stories notification out of the app
 * into a browser where she wasn't logged in.
 *
 * app.vue keys the whole app on loginCount, so logging in throws away every
 * component and builds it again. That means the fact that they asked for the
 * form has to be remembered in the store, and picked up by the fresh instance
 * as it sets up - watching for the login here would only ever be seen by the
 * instance that's about to be discarded.
 *
 * StoryAddModal also checks at submit time, in case the login lapses while
 * they're typing. It saves the draft and emits login-required; call
 * loginRequired() from that so we reopen the modal (with their story still in
 * it) once they're back in.
 */
export function useStoryAdd() {
  const authStore = useAuthStore()
  const storyStore = useStoryStore()

  const showStoryAddModal = ref(false)

  function showAddModal() {
    if (authStore.user) {
      showStoryAddModal.value = true
    } else {
      loginRequired()
    }
  }

  function loginRequired() {
    storyStore.addWanted = true
    authStore.forceLogin = true
  }

  // Pick up where they left off if we sent them away to log in. Claim the flag
  // so that on a page with several of these (the ChitChat feed has one per
  // story) we only open one form.
  if (authStore.user && storyStore.addWanted) {
    storyStore.addWanted = false
    showStoryAddModal.value = true
  }

  return { showStoryAddModal, showAddModal, loginRequired }
}
