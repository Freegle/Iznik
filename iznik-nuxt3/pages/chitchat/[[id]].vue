<template>
  <NuxtLayout v-if="isChat" name="chat">
    <client-only>
      <ChitChatGroup :focus="id" />
    </client-only>
  </NuxtLayout>
  <NuxtLayout v-else name="login">
    <ClassicChitChat />
  </NuxtLayout>
</template>
<script setup>
// ChitChat. In chat mode: your local chit-chat as a group chat inside the shell. In
// classic mode: the existing page, unchanged.
import { computed, useRoute, useHead } from '#imports'
import ChitChatGroup from '~/components/chatshell/ChitChatGroup.vue'
import ClassicChitChat from '~/components/ClassicChitChat.vue'
import { useUiMode } from '~/composables/useUiMode'
import { buildHead } from '~/composables/useBuildHead'

definePageMeta({ layout: false, chatShell: true })

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const { isChat } = useUiMode()
const id = computed(() => (route.params.id ? parseInt(route.params.id) : 0))

useHead(
  buildHead(route, runtimeConfig, 'ChitChat', 'Chat with people near you.')
)
</script>
