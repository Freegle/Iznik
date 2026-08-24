import { createGtm } from '@gtm-support/vue-gtm'
import { defineNuxtPlugin, useRuntimeConfig } from '#app'
import { useRouter } from '#imports'

// Loads Google Tag Manager when GTM_ID is configured (runtimeConfig.public.gtm
// is only populated in that case — see nuxt.config.ts). This replaces the
// @zadigetvoltaire/nuxt-gtm module, whose hardcoded compatibility gate
// (nuxt ^3.0.0) made @nuxt/kit silently skip it under Nuxt 4; the module's
// runtime plugin did exactly this and nothing more. createGtm() also makes
// useGtm() (used by useTrackConversion) return a real singleton.
export default defineNuxtPlugin((nuxtApp) => {
  const options = useRuntimeConfig().public.gtm

  if (!options?.enabled) {
    return
  }

  nuxtApp.vueApp.use(
    createGtm({
      ...options,
      vueRouter: options.enableRouterSync ? useRouter() : undefined,
    })
  )
})
