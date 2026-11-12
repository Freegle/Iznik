<template>
  <NoticeMessage v-if="showHelp" variant="info" class="mt-2 mb-2">
    <b-button variant="white" class="float-end" @click="toggleHelp">
      Hide Help
    </b-button>
    <p>
      Posts that went live by themselves from auto-moderated members, waiting
      for someone to check them. Review them here as a safety net. Press
      <strong>Checked</strong> on a post you have looked at, or
      <strong>Mark these as checked</strong> for every post listed. A post that
      two microvolunteers have approved also clears, and comes back if a
      microvolunteer then rejects it, and approving, rejecting or deleting the
      post on another of your communities counts as a look too. Posts older than
      a week drop off this queue.
    </p>
    <p class="mb-0">
      <strong>Pull back</strong> takes a post off the live site and puts it back
      in Pending, held by you, to approve, edit or reject.
      <strong>Should not have published</strong>, under the automated review
      line, tells us the review got it wrong.
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
