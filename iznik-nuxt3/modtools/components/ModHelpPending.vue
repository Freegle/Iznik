<template>
  <NoticeMessage v-if="showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p>
      Posts waiting to go live. Clean posts from auto-moderated members publish
      automatically after a short delay — the countdown on each post shows how
      long you have. Opening this page guarantees at least 10 minutes on every
      post, so nothing disappears while you're looking.
    </p>
    <p class="mb-0">Reject or hold anything that shouldn't go live.</p>
  </NoticeMessage>
  <b-button v-else variant="link" class="float-end pt-0" @click="toggleHelp">
    Help
  </b-button>
</template>
<script setup>
import { useHelpBox } from '~/composables/useHelpBox'

// Defaults to shown (useHelpBox initialises showHelp = true); a mod can hide it.
const { toggleHelp, showHelp } = useHelpBox()
</script>
