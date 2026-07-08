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
const { assign, recordShown, isMobile } = useComposeChoice()

onMounted(() => {
  const variant = assign()
  // Only record exposure for the eligible (mobile) population so the experiment
  // rates aren't diluted by desktop control traffic.
  if (isMobile()) recordShown(variant)

  if (variant === 'voice') {
    // Offer the voice-or-keyboard choice.
    router.replace('/voicepost')
  } else {
    // Control: the existing typed compose flow, unchanged.
    router.replace('/give/mobile/photos')
  }
})
</script>
