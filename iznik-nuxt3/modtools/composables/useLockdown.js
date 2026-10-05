import { computed } from 'vue'
import { useLockdownStore } from '~/stores/lockdown'
import { useMe } from '~/composables/useMe'

// The one place that decides "is this held for ME, right now" -
// plans/active/2026-09-27-lockdown-switch.md section 11.3: "Support/Admin
// callers are exempt from every refusal." The Go API lets them act even
// while a surface is held, so the client must not hide their buttons either
// - otherwise a Support mod trying to clear a genuine backlog during an
// incident would find their own tools missing. Every button-hiding component
// should read modsHeld/held() from here rather than the lockdown store
// directly, so the exemption only has to be right in one place.
export function useLockdown() {
  const lockdownStore = useLockdownStore()
  const { supportOrAdmin } = useMe()

  const modsHeld = computed(
    () => lockdownStore.modsHeld && !supportOrAdmin.value
  )

  function held(surface) {
    return lockdownStore.held(surface) && !supportOrAdmin.value
  }

  return { modsHeld, held }
}
