<template>
  <NuxtLayout v-if="isChat" name="chat">
    <client-only>
      <MemberChat v-if="me && id" :id="id" />
      <ChatList v-else-if="me" />
      <div v-else class="signin-prompt">
        <ShellHeader
          title="Chats"
          subtitle="Freegle"
          avatar="/icon.png"
          back="/"
        />
        <div class="signin-body">
          <p>Sign in to see your chats.</p>
          <b-button
            variant="primary"
            data-testid="signin-button"
            @click="signIn"
            >Sign in</b-button
          >
        </div>
      </div>
    </client-only>
  </NuxtLayout>
  <NuxtLayout v-else name="login">
    <ClassicChats />
  </NuxtLayout>
</template>
<script setup>
// Chats. In chat mode: the list, or one chat, inside the shell. In classic mode: the
// existing page, unchanged. A constant key keeps the page mounted between chats.
import { computed, useRoute, useHead } from '#imports'
import ChatList from '~/components/chatshell/ChatList.vue'
import MemberChat from '~/components/chatshell/MemberChat.vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ClassicChats from '~/components/ClassicChats.vue'
import { useUiMode } from '~/composables/useUiMode'
import { useAuthStore } from '~/stores/auth'
import { buildHead } from '~/composables/useBuildHead'

definePageMeta({ layout: false, key: 'chats', chatShell: true })

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const authStore = useAuthStore()
const { isChat } = useUiMode()
const me = computed(() => authStore.user)
const id = computed(() => (route.params.id ? parseInt(route.params.id) : 0))

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Chats',
    "See the conversations you're having with other freeglers."
  )
)

function signIn() {
  authStore.forceLogin = true
}
</script>
<style scoped lang="scss">
.signin-prompt {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.signin-body {
  padding: 1.5rem 1rem;
  text-align: center;
}
</style>
