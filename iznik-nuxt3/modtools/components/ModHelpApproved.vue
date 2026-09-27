<template>
  <NoticeMessage v-if="inTrial && showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p class="mb-0">
      Every live post on your group(s). Check is a focused oversight subset of
      this: the posts that went live by themselves and nobody has looked at.
    </p>
  </NoticeMessage>
  <b-button v-else-if="inTrial" variant="link" class="float-end pt-0" @click="toggleHelp">
    Help
  </b-button>
</template>
<script setup>
import { useHelpBox } from '~/composables/useHelpBox'
import { useAutoapproveTrial } from '~/modtools/composables/useAutoapproveTrial'

const { toggleHelp, showHelp } = useHelpBox()
// This help describes post-moderation, so it shows only on trial communities.
const { inTrial } = useAutoapproveTrial()
</script>
