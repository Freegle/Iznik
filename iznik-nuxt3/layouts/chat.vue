<template>
  <div class="chat-layout">
    <ShellFrame>
      <slot />
    </ShellFrame>
    <client-only>
      <LoginModal v-if="!loggedIn" ref="loginModal" />
    </client-only>
  </div>
</template>
<script setup>
// The chat shell: on a phone the page is the chat; on a desktop it is a phone-sized
// column in the middle of the page. Nothing from the classic layout (navbar, ads,
// sidebars) is here. The login modal is kept so "Sign in" works as it does everywhere.
import { computed, ref, onMounted } from '#imports'
import ShellFrame from '~/components/chatshell/ShellFrame.vue'
import { useAuthStore } from '~/stores/auth'
import { useMiscStore } from '~/stores/misc'
import { bootSession } from '~/composables/useBootSession'
const LoginModal = defineAsyncComponent(() => import('~/components/LoginModal'))

const authStore = useAuthStore()
const miscStore = useMiscStore()
const loggedIn = computed(() => authStore.user !== null)
const loginModal = ref(null)

if (import.meta.client) {
  miscStore.apiCount = 0
}

useHead({
  bodyAttrs: {
    class: 'chat-shell-body',
  },
})

await bootSession()

onMounted(() => {
  // Components that size themselves by breakpoint should behave as on a phone
  // inside the column. The classic layout's fettler resets this when it mounts.
  miscStore.breakpoint = 'xs'
})
</script>
<style lang="scss">
body.chat-shell-body {
  background: #e9edf0;
  margin: 0;
}
</style>
