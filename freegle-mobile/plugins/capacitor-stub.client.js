// Prevents Capacitor import errors from inherited auth/mobile stores
export default defineNuxtPlugin(() => {
  if (typeof window !== 'undefined' && !window.Capacitor) {
    window.Capacitor = { isNativePlatform: () => false }
  }
})
