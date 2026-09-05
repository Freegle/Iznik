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
import { ref } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { useMobileStore } from '@/stores/mobile' // APP
import { bootSession } from '~/composables/useBootSession'
const GoogleOneTap = defineAsyncComponent(
  () => import('~/components/GoogleOneTap')
)
const LoginModal = defineAsyncComponent(() => import('~/components/LoginModal'))

const mobileStore = useMobileStore()

let ready = false
const oneTap = ref(false)
const authStore = useAuthStore()
const miscStore = useMiscStore()

if (import.meta.client) {
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

// For this layout we don't need to be logged in.  So can just continue.  But we want to know first whether or
// not we are logged in.  bootSession() resolves that exactly once per cold
// start (and doesn't repeat the fetch when another layout just did it).
const user = await bootSession()

if (user) {
  ready = true
}

if (!ready && !mobileStore.isApp) {
  // APP
  // We don't have a valid JWT.  See if OneTap can sign us in.
  oneTap.value = true
}

function googleLoggedIn() {
  // OneTap has logged us in.  Re-render the page as logged in.
  bump.value++
}
</script>
