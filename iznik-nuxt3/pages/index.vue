<template>
  <NuxtLayout v-if="isChat" name="chat">
    <FreegleChat :samples="samples" />
  </NuxtLayout>
  <NuxtLayout v-else name="default">
    <ClassicLanding />
  </NuxtLayout>
</template>
<script setup>
// Landing. Chat or classic, by the member's choice (settings, then a cookie) or the
// default. The classic page is untouched; the chat is the Freegle conversation. The
// title and description stay the same for both, so search engines see one page.
import { computed, useHead, useRoute, useRuntimeConfig } from '#imports'
import FreegleChat from '~/components/chatshell/FreegleChat.vue'
import ClassicLanding from '~/components/ClassicLanding.vue'
import { buildHead } from '~/composables/useBuildHead'
import { useUiMode } from '~/composables/useUiMode'
import { useAuthStore } from '~/stores/auth'
import { useMessageStore } from '~/stores/message'

definePageMeta({ layout: false })

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const authStore = useAuthStore()
const messageStore = useMessageStore()
const { isChat } = useUiMode()
const me = computed(() => authStore.user)

useHead(
  buildHead(
    route,
    runtimeConfig,
    "Don't throw it away, give it away!",
    "Freegle - like online dating for stuff. Got stuff you don't need? Looking for something? We'll match you with someone local. All completely free.",
    null,
    { class: 'landing' }
  )
)

// A few real recent offers under the opening line, the way the classic landing shows
// a sample grid, so the page has real listings for people and search engines.
const samples = ref([])
if (isChat.value && !me.value) {
  try {
    const list = await messageStore.fetchInBounds(49.45, -9, 61, 2, null, 12, true)
    const offers = (list || []).filter((m) => m.type === 'Offer').slice(0, 4)
    await Promise.all(offers.map((o) => messageStore.fetch(o.id)))
    samples.value = offers.map((o) => o.id)
  } catch (e) {
    samples.value = []
  }
}

// A signed-in member on chat lands on their chat list, as every WhatsApp session
// starts on the list. Visitors and first-timers land in the Freegle chat.
if (import.meta.client && isChat.value && me.value && route.path === '/' && !route.query.chat) {
  navigateTo('/chats', { replace: true })
}
</script>
