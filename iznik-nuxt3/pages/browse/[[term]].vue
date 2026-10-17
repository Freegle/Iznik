<template>
  <NuxtLayout v-if="isChat" name="chat">
    <FreegleChat :nearby="true" :nearby-term="term" />
  </NuxtLayout>
  <NuxtLayout v-else name="login">
    <ClassicBrowse />
  </NuxtLayout>
</template>
<script setup>
// Browse. In chat mode this is the chat with the Nearby sheet up: search, a filter and
// a list that scrolls inside the sheet, so the page never grows. The classic page is
// untouched.
import { computed, useRoute } from '#imports'
import FreegleChat from '~/components/chatshell/FreegleChat.vue'
import ClassicBrowse from '~/components/ClassicBrowse.vue'
import { useUiMode } from '~/composables/useUiMode'

definePageMeta({
  layout: false,
  chatShell: true,
  alias: ['/communities'],
})

const route = useRoute()
const { isChat } = useUiMode()
const term = computed(() => String(route.params.term || ''))
</script>
