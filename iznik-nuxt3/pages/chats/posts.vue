<template>
  <NuxtLayout name="chat">
    <client-only>
      <YourPosts v-if="me" />
      <div v-else class="signin-body">
        <ShellHeader title="Your posts" subtitle="Freegle" avatar="/icon.png" back="/" />
        <p class="p-3 text-center">Sign in to see your posts.</p>
      </div>
    </client-only>
  </NuxtLayout>
</template>
<script setup>
// Your posts as one chat, inside the shell.
import { computed, useRoute, useHead } from '#imports'
import YourPosts from '~/components/chatshell/YourPosts.vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import { useAuthStore } from '~/stores/auth'
import { buildHead } from '~/composables/useBuildHead'

definePageMeta({ layout: false })

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const authStore = useAuthStore()
const me = computed(() => authStore.user)

useHead(buildHead(route, runtimeConfig, 'Your posts', 'Replies to your posts, and who gets what.'))
</script>
