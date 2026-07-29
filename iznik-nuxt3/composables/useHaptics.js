// Lightweight haptic-feedback wrapper for Vue components.
//
// No-ops on the web (and silently when the native engine is unavailable) and
// lazily imports @capacitor/haptics only inside the app, so the web bundle
// never pulls in the native plugin. Every method is fire-and-forget — haptics
// are a nicety and must never break or block a user flow.
//
// Usage:
//   const haptics = useHaptics()
//   haptics.success()   // e.g. after a post/message succeeds
//   haptics.light()     // e.g. on a small confirm / selection
import { useMobileStore } from '~/stores/mobile'

let _mod = null
async function load() {
  if (_mod) return _mod
  _mod = await import('@capacitor/haptics')
  return _mod
}

export function useHaptics() {
  const mobileStore = useMobileStore()

  const fire = async (fn) => {
    if (!mobileStore.isApp) return
    try {
      await fn(await load())
    } catch {
      // best-effort: a missing/unsupported haptic engine must not surface.
    }
  }

  return {
    light: () =>
      fire(({ Haptics, ImpactStyle }) =>
        Haptics.impact({ style: ImpactStyle.Light })
      ),
    medium: () =>
      fire(({ Haptics, ImpactStyle }) =>
        Haptics.impact({ style: ImpactStyle.Medium })
      ),
    heavy: () =>
      fire(({ Haptics, ImpactStyle }) =>
        Haptics.impact({ style: ImpactStyle.Heavy })
      ),
    success: () =>
      fire(({ Haptics, NotificationType }) =>
        Haptics.notification({ type: NotificationType.Success })
      ),
    warning: () =>
      fire(({ Haptics, NotificationType }) =>
        Haptics.notification({ type: NotificationType.Warning })
      ),
    error: () =>
      fire(({ Haptics, NotificationType }) =>
        Haptics.notification({ type: NotificationType.Error })
      ),
  }
}
