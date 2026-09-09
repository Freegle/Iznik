<template>
  <NuxtLayout v-if="isChat" name="chat">
    <client-only>
      <YourPosts v-if="me" />
      <div v-else class="signin-body">
        <ShellHeader
          title="Your posts"
          subtitle="Freegle"
          avatar="/icon.png"
          back="/"
        />
        <p class="p-3 text-center">Sign in to see your posts.</p>
      </div>
    </client-only>
  </NuxtLayout>
</template>
<script setup>
// Your posts as one chat, inside the shell.
import { computed, onMounted, useRoute, useRouter, useHead } from '#imports'
import { useUiMode } from '~/composables/useUiMode'
import YourPosts from '~/components/chatshell/YourPosts.vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import { useAuthStore } from '~/stores/auth'
import { buildHead } from '~/composables/useBuildHead'

definePageMeta({ layout: false, chatShell: true })

const route = useRoute()
const router = useRouter()
const runtimeConfig = useRuntimeConfig()
const { isChat } = useUiMode()

// On the website, your posts is My Posts; a bookmark or back button lands there.
onMounted(() => {
  if (!isChat.value) router.replace('/myposts')
})
const authStore = useAuthStore()
const me = computed(() => authStore.user)

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Your posts',
    'Replies to your posts, and who gets what.'
  )
)
</script>
