<template>
  <NoticeMessage v-if="showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p class="mb-0">
      Posts that went live automatically from auto-moderated members. Review
      them here as a safety net; "Mark as checked" clears them. Posts older than
      a week drop off this queue.
    </p>
  </NoticeMessage>
  <b-button v-else variant="link" class="float-end pt-0" @click="toggleHelp">
    Help
  </b-button>
</template>
<script setup>
import { useHelpBox } from '~/composables/useHelpBox'

const { toggleHelp, showHelp } = useHelpBox()
</script>
