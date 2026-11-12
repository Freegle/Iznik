<template>
  <NoticeMessage v-if="inTrial && showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p>
      Posts waiting to go live. On communities in the trial, a clean post from
      an auto-moderated member publishes by itself after a short delay, and the
      countdown on the post shows how long you have. Opening this page gives
      every one of those posts at least 10 more minutes, so nothing publishes
      while you are looking.
    </p>
    <p class="mb-0">Reject or hold anything that should not go live.</p>
  </NoticeMessage>
  <b-button
    v-else-if="inTrial"
    variant="link"
    class="float-end pt-0"
    @click="toggleHelp"
  >
    Help
  </b-button>
</template>
<script setup>
import { useHelpBox } from '~/composables/useHelpBox'
import { useAutoapproveTrial } from '~/modtools/composables/useAutoapproveTrial'

const props = defineProps({
  // The community the page is filtered to; 0 for all of them. The help describes
  // post-moderation, so it shows only when that community (or, for all, any of them) is
  // in the trial - not on a neighbouring community a trial moderator also runs.
  groupid: {
    type: Number,
    required: false,
    default: 0,
  },
})

// Defaults to shown (useHelpBox initialises showHelp = true); a mod can hide it.
const { toggleHelp, showHelp } = useHelpBox()
const { inTrial } = useAutoapproveTrial(() => props.groupid)
</script>
