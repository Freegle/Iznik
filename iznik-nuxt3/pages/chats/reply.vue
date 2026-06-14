<template>
  <client-only>
    <ChatReplyPane
      v-if="replyToMessageId"
      :message-id="replyToMessageId"
      @close="onClose"
      @sent="onSent"
    />
    <div v-else class="empty-state">
      <p>No message to reply to.</p>
      <nuxt-link to="/browse">Browse messages</nuxt-link>
    </div>
  </client-only>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from '#imports'
import { buildHead } from '~/composables/useBuildHead'
import ChatReplyPane from '~/components/ChatReplyPane.vue'

// Uses the default layout — logged-out users can supply their email in the
// ChatReplyPane, matching the inline reply flow. A forced login modal would
// break the email-first reply flow.

const route = useRoute()
const router = useRouter()
const runtimeConfig = useRuntimeConfig()

const replyToMessageId = computed(() => {
  const id = parseInt(route.query.replyto)
  return id > 0 ? id : null
})

// When opened directly (deep link), closing returns to the post being replied
// to. The in-app Reply button opens the pane as an overlay instead, so closing
// there simply returns you to exactly where you were.
function onClose() {
  if (replyToMessageId.value) {
    router.push(`/message/${replyToMessageId.value}`)
  } else {
    router.back()
  }
}

// After a successful send the state machine has already navigated to the real
// chat, so there's nothing more to do here.
function onSent() {}

useHead(buildHead(route, runtimeConfig, 'Reply', 'Reply to a freegler'))
</script>

<style scoped lang="scss">
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: calc(100dvh - 68px);
  gap: 12px;
  color: $color-gray--dark;
}
</style>
