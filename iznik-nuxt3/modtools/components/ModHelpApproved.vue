<template>
  <NoticeMessage v-if="inTrial && showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p class="mb-0">
      Every live post on your communities. On communities in the trial, Check
      holds the ones that went live by themselves and that nobody has looked at
      yet.
    </p>
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

const { toggleHelp, showHelp } = useHelpBox()
const { inTrial } = useAutoapproveTrial(() => props.groupid)
</script>
