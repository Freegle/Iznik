<template>
  <div>
    <LayoutCommon :key="'nuxt-' + bump">
      <slot />
    </LayoutCommon>
    <client-only>
      <GoogleOneTap
        v-if="oneTap"
        @loggedin="googleLoggedIn"
        @complete="googleLoaded"
      />
      <LoginModal
        v-if="!loggedIn"
        ref="loginModal"
        :key="'login-' + bumpLogin"
      />
    </client-only>
  </div>
</template>
<script setup>
import { useAuthStore } from '~/stores/auth'
import LayoutCommon from '~/components/LayoutCommon'
import { useMobileStore } from '@/stores/mobile' // APP
import { useMiscStore } from '~/stores/misc'
import { bootSession, bootSessionDeferred } from '~/composables/useBootSession'
import { ref, computed, watch, useRuntimeConfig } from '#imports'
const GoogleOneTap = defineAsyncComponent(() =>
  import('~/components/GoogleOneTap')
)
const LoginModal = defineAsyncComponent(() => import('~/components/LoginModal'))

const mobileStore = useMobileStore()
const ready = ref(mobileStore.isApp) // APP
const oneTap = ref(false)
const bump = ref(0)
const bumpLogin = ref(0)
const loginModal = ref(null)
const authStore = useAuthStore()
const miscStore = useMiscStore()

const loggedIn = computed(() => authStore.user !== null)
const me = computed(() => authStore.user)
const loginStateKnown = computed(() => authStore.loginStateKnown)

if (process.client) {
  // Ensure we don't wrongly think we have some outstanding requests if the server happened to start some.
  miscStore.apiCount = 0
}

useHead({
  bodyAttrs: {
    style: 'background-color: var(--color-gray-50)',
  },
})

// Force the login modal only once the login state is actually KNOWN. With
// the non-blocking boot below, `me` is always null for an instant on cold
// start - forcing the modal on that would flash it at logged-in users. The
// boot's safety timeout guarantees loginStateKnown eventually flips even if
// the API hangs, so a genuinely logged-out user always gets the modal.
watch(
  [me, loginStateKnown],
  ([newMe, known]) => {
    if (newMe) {
      // We've logged in.
      ready.value = true
    } else if (known) {
      authStore.forceLogin = true
    }
  },
  { immediate: true }
)

function googleLoggedIn() {
  // OneTap has logged us in. Re-render the page as logged in.
  bump.value++
}

function googleLoaded() {
  if (loginModal.value && loginModal.value.showModal) {
    // The login modal is already showing — don't re-render it or we'll
    // destroy any form state the user (or test automation) has entered.
    console.log('Login modal already showing - not re-rendering')
  } else {
    // We need to force the login modal to rerender
    bumpLogin.value++
  }
}

// Resolve the login state exactly once per cold start - if the default layout
// already fetched the session moments ago (/ → /browse swap), this returns
// immediately instead of repeating GET /session.
//
// APP build only: don't await - first paint must not wait on the network (see
// layouts/default.vue for the full rationale and why the web keeps the
// blocking await). The [me, loginStateKnown] watcher above handles the
// reveal either way.
const runtimeConfig = useRuntimeConfig()

if (import.meta.server || !runtimeConfig.public.ISAPP) {
  const user = await bootSession()

  if (user) {
    ready.value = true
  } else if (!mobileStore.isApp) {
    // We don't have a valid JWT. See if OneTap can sign us in.
    oneTap.value = true
  }
} else {
  bootSessionDeferred().then(({ user }) => {
    if (user) {
      ready.value = true
    } else if (!mobileStore.isApp) {
      // We don't have a valid JWT. See if OneTap can sign us in.
      oneTap.value = true
    }
  })
}
</script>
