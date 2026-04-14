<template>
  <client-only>
    <div class="chat-reply-page">
      <ChatReplyPane v-if="replyToMessageId" :message-id="replyToMessageId" />
      <div v-else class="empty-state">
        <p>No message to reply to.</p>
        <nuxt-link to="/browse">Browse messages</nuxt-link>
      </div>
    </div>
  </client-only>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from '#imports'
import { buildHead } from '~/composables/useBuildHead'
import ChatReplyPane from '~/components/ChatReplyPane.vue'

definePageMeta({
  layout: 'login',
})

const route = useRoute()
const runtimeConfig = useRuntimeConfig()

const replyToMessageId = computed(() => {
  const id = parseInt(route.query.replyto)
  return id > 0 ? id : null
})

useHead(buildHead(route, runtimeConfig, 'Reply', 'Reply to a freegler'))
</script>

<style scoped lang="scss">
.chat-reply-page {
  height: calc(100vh - 68px); // Account for navbar
  display: flex;
  flex-direction: column;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: 12px;
  color: $color-gray--dark;
}
</style>
