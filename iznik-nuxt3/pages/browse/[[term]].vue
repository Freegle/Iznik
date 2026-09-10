<template>
  <NuxtLayout v-if="isChat" name="chat">
    <NearbyScreen :term="term" />
  </NuxtLayout>
  <NuxtLayout v-else name="login">
    <ClassicBrowse />
  </NuxtLayout>
</template>
<script setup>
// Browse. In chat mode this is the Nearby screen: a proper scrolling list with search,
// the place the chat sends people when a handful of cards is not enough. The classic
// page is untouched.
import { computed, useRoute } from '#imports'
import NearbyScreen from '~/components/chatshell/NearbyScreen.vue'
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
