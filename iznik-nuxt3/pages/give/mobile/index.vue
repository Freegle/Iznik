<template>
  <div>
    <!-- Redirect: the voice-experiment cohort gets the choice screen; everyone
         else goes straight to the existing typed photos flow. -->
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from '#imports'
import { useComposeChoice } from '~/composables/useComposeChoice'

const router = useRouter()
const { experimentActive, assign, recordShown, isMobile } = useComposeChoice()

onMounted(() => {
  // Experiment off (default): behave exactly as before - straight to the existing
  // typed photos flow, with no assignment and no tracking side effects. This is
  // what makes merging safe: the existing route is unchanged until the rollout is
  // deliberately raised.
  if (!experimentActive()) {
    router.replace('/give/mobile/photos')
    return
  }

  const variant = assign()
  // Only record exposure for the eligible (mobile) population so the experiment
  // rates aren't diluted by desktop control traffic.
  if (isMobile()) recordShown(variant)
  router.replace(variant === 'voice' ? '/voicepost' : '/give/mobile/photos')
})
</script>
