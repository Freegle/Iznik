<template>
  <div />
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from '#imports'

// Kept as a redirect only. This used to be the entry point for the voice-vs-
// keyboard compose experiment, which assigned a variant here and sent the voice
// arm to /voicepost. That experiment has been removed, so anything still
// pointing at /give/mobile - an old link, a bookmark - lands on the typed photos
// flow rather than a 404.
const router = useRouter()

onMounted(() => {
  router.replace('/give/mobile/photos')
})
</script>
