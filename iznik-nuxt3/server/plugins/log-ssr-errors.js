// Nitro error hook. Two jobs:
//
//   1. Write a full stack to stderr so Netlify Function Logs capture it.
//      Without this, caught errors during SSR disappear entirely from the
//      Netlify log output.
//
//   2. Attach the stack to error.data.stack so error.vue on the client can
//      relay it to /clientlog → Loki alongside client-side errors. Nuxt
//      strips error.stack in production but preserves error.data in the
//      serialized payload sent to the browser.
//
// Defensive throughout: every step is wrapped so a failure inside this
// hook never compounds the original request error. If this plugin's
// registration or handler throws, we swallow it and let Nitro's default
// error handling proceed unchanged.

export default defineNitroPlugin((nitroApp) => {
  try {
    nitroApp.hooks.hook('error', (error, ctx) => {
      try {
        let url
        let method
        try {
          url = ctx?.event?.node?.req?.url
          method = ctx?.event?.node?.req?.method
        } catch (_) {
          // ignore — we'll emit with placeholders below
        }

        let stack
        try {
          stack =
            (error && (error.stack || error.message)) || String(error)
        } catch (_) {
          stack = 'Error stack unavailable'
        }

        try {
          console.error(
            `SSR error on ${method || '?'} ${url || '?'}:`,
            stack
          )
        } catch (_) {
          // logging must never throw
        }

        try {
          if (error && typeof error === 'object') {
            if (!error.data || typeof error.data !== 'object') {
              error.data = {}
            }
            if (!error.data.stack) {
              error.data.stack = stack
            }
          }
        } catch (_) {
          // best effort only
        }
      } catch (_) {
        // outer guard — never throw from an error hook
      }
    })
  } catch (_) {
    // registering the hook shouldn't throw, but belt-and-braces
  }
})
