// iOS detection for keyboard-behaviour workarounds, covering both the
// Capacitor app and Safari on iPhone/iPad. iPadOS masquerades as MacIntel,
// so a Mac platform with real touch points is treated as iPad.
export function isIOS() {
  if (!import.meta.client) {
    return false
  }

  return (
    /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  )
}
