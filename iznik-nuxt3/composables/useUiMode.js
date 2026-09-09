// Chat or classic. A member's choice is remembered in their settings; a visitor's in the
// browser. Anyone who has not chosen gets the runtime default, which can be a fixed mode
// or a percentage of members by user id, and which is also the kill switch.
import { useAuthStore } from '~/stores/auth'

export const UI_MODE_KEY = 'freegle-ui-mode'

// Pure: work out the mode from what we know. Exported for tests.
export function resolveUiMode({
  settingsMode,
  storedMode,
  defaultMode,
  userId,
}) {
  if (settingsMode === 'chat' || settingsMode === 'classic') return settingsMode
  if (storedMode === 'chat' || storedMode === 'classic') return storedMode
  const d = String(defaultMode ?? 'classic')
    .trim()
    .toLowerCase()
  if (d === 'chat' || d === 'classic') return d
  const pct = parseInt(d, 10)
  if (Number.isFinite(pct) && pct > 0) {
    if (!userId) return 'classic'
    return userId % 100 < pct ? 'chat' : 'classic'
  }
  return 'classic'
}

export function readStoredMode() {
  try {
    if (typeof localStorage === 'undefined') return null
    return localStorage.getItem(UI_MODE_KEY)
  } catch (e) {
    return null
  }
}

export function writeStoredMode(mode) {
  try {
    if (typeof localStorage === 'undefined') return
    if (mode) localStorage.setItem(UI_MODE_KEY, mode)
    else localStorage.removeItem(UI_MODE_KEY)
  } catch (e) {
    // Storage may be unavailable; the settings copy still works for members.
  }
}

export function useUiMode() {
  const authStore = useAuthStore()
  const runtimeConfig = useRuntimeConfig()
  const me = computed(() => authStore.user)
  // A cookie rather than localStorage so the server renders the same choice as the
  // browser and nobody sees the other version flash first.
  const cookie = useCookie(UI_MODE_KEY, {
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
  })
  const stored = computed(() => cookie.value || readStoredMode())

  const mode = computed(() =>
    resolveUiMode({
      settingsMode: me.value?.settings?.uiMode,
      storedMode: stored.value,
      defaultMode: runtimeConfig.public.CHAT_FIRST_DEFAULT,
      userId: me.value?.id,
    })
  )
  const isChat = computed(() => mode.value === 'chat')

  async function setMode(next) {
    cookie.value = next
    writeStoredMode(next)
    if (me.value) {
      const settings = { ...(me.value.settings || {}), uiMode: next }
      await authStore.saveAndGet({ settings })
    }
  }

  return { mode, isChat, setMode }
}
