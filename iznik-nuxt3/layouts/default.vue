<template>
  <div>
    <LayoutCommon :key="'nuxt-' + bump">
      <slot />
    </LayoutCommon>
    <client-only>
      <GoogleOneTap v-if="oneTap" @loggedin="googleLoggedIn" />
      <LoginModal v-if="!loggedIn" ref="loginModal" />
    </client-only>
  </div>
</template>
<script setup>
import { useMiscStore } from '~/stores/misc'
import LayoutCommon from '~/components/LayoutCommon'
import { ref, useRuntimeConfig } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMobileStore } from '@/stores/mobile' // APP
import { bootSession, bootSessionDeferred } from '~/composables/useBootSession'
const GoogleOneTap = defineAsyncComponent(() =>
  import('~/components/GoogleOneTap')
)
const LoginModal = defineAsyncComponent(() => import('~/components/LoginModal'))

const mobileStore = useMobileStore()

const oneTap = ref(false)
const authStore = useAuthStore()
const miscStore = useMiscStore()

if (process.client) {
  // Ensure we don't wrongly think we have some outstanding requests if the server happened to start some.
  miscStore.apiCount = 0
}

useHead({
  bodyAttrs: {
    style: 'background-color: var(--color-gray-50)',
  },
})

const bump = ref(0)
const loginStateKnown = computed(() => authStore.loginStateKnown)
const loggedIn = computed(() => authStore.user !== null)

watch(
  loginStateKnown,
  (newVal) => {
    if (newVal && loggedIn.value) {
      // We now know that we have logged in.  We rendered the page originally
      // as logged out.  So re-render the page to make it reflect that.
      bump.value++
    }
  },
  {
    immediate: true,
  }
)

// For this layout we don't need to be logged in.  So can just continue.
// bootSession() resolves the login state exactly once per cold start (and
// doesn't repeat the fetch when another layout just did it).
//
// In the APP build (ssr:false) we deliberately do NOT await: first paint used
// to be blocked on this network round trip under the root Suspense
// (multi-second white screen; stuck forever if the API hung).  The shell
// renders immediately and the existing loginStateKnown/bump watcher above
// re-renders when the session resolves - the same reveal mechanism this
// layout has always used for the logged-out → logged-in flip.
// bootSessionDeferred adds a safety timeout so a hung API proceeds as logged
// out.
//
// On the WEB we keep the await, on the server (no stored credentials, so no
// network call) and during client hydration alike: the server-rendered HTML
// is already painted while hydration runs, so blocking here costs no white
// screen - and an interactive page whose session is still unresolved opens
// real races (login modal intercepting clicks, session-gated buttons
// disabled), which Playwright caught when this was tried web-wide.
const runtimeConfig = useRuntimeConfig()

if (import.meta.server || !runtimeConfig.public.ISAPP) {
  await bootSession()
  if (!authStore.user && !mobileStore.isApp) {
    // We don't have a valid JWT.  See if OneTap can sign us in.
    oneTap.value = true
  }
} else {
  bootSessionDeferred().then(({ user }) => {
    if (!user && !mobileStore.isApp) {
      // APP build without a valid JWT - OneTap isn't used in the app, but
      // keep the same logic shape for layer consumers.
      oneTap.value = true
    }
  })
}

function googleLoggedIn() {
  // OneTap has logged us in.  Re-render the page as logged in.
  bump.value++
}
</script>
