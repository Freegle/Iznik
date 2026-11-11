import { ref } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { action } from '~/composables/useClientLog'

// Experiment join-name-optional: a member who joined in arm 'b' gave no name, so the server invents one
// from their email. Givers choose real names more often when several people reply, so we ask for one the
// next time the member posts or replies, after it has gone, so the ask never stops either from finishing.
// The flag lives in the member's settings so it follows them to other devices, and is cleared once they
// give a name. It is set only at an arm 'b' signup, so nobody else is asked.

const ASK_FLAG = 'asknamelater'

const askNameOpen = ref(false)

export function useAskName() {
  const authStore = useAuthStore()

  async function saveSettings(change) {
    const settings = { ...(authStore.user?.settings || {}), ...change }
    await authStore.saveAndGet({ settings })
  }

  // Called once an arm 'b' signup has logged the member in.
  async function markAskNameLater() {
    await saveSettings({ [ASK_FLAG]: true })
  }

  // Called after a reply has been sent or a post submitted.
  function maybeAskName(after) {
    if (askNameOpen.value || !authStore.user?.settings?.[ASK_FLAG]) return
    askNameOpen.value = true
    action('ask_name_shown', { after })
  }

  async function saveName(name) {
    await authStore.saveAndGet({ displayname: name })
    await saveSettings({ [ASK_FLAG]: false })
    action('ask_name_saved')
    askNameOpen.value = false
  }

  // Not now: keep the flag, so the next post or reply asks again.
  function skipName() {
    action('ask_name_skipped')
    askNameOpen.value = false
  }

  return { askNameOpen, markAskNameLater, maybeAskName, saveName, skipName }
}
