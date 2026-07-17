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
import { useClientLog } from '~/composables/useClientLog'

const router = useRouter()
const {
  loadRollout,
  experimentActive,
  assign,
  recordShown,
  markConversionPending,
  isMobile,
} = useComposeChoice()
const { action: logEvent } = useClientLog()

onMounted(async () => {
  // Pull the current rollout % from server config first so the decision below
  // reflects the live value (no rebuild needed to change it).
  await loadRollout()

  // Experiment off (rollout 0): behave exactly as before - straight to the
  // existing typed photos flow, with no assignment and no tracking side effects,
  // so the existing route is unchanged until the rollout is deliberately raised.
  if (!experimentActive()) {
    router.replace('/give/mobile/photos')
    return
  }

  const variant = assign()
  const mobile = isMobile()
  // Only record exposure for the eligible (mobile) population so the experiment
  // rates aren't diluted by desktop control traffic.
  if (mobile) {
    recordShown(variant)
    // Stash the arm so the shared final "Freegle it!" step records the conversion at
    // the real post-creation point — for control as well as voice. This is what makes
    // the completion rate comparable between the two arms.
    markConversionPending(variant)
  }
  logEvent('voicepost_entry', { variant, is_mobile: mobile })
  router.replace(variant === 'voice' ? '/voicepost' : '/give/mobile/photos')
})
</script>
