<template>
  <NoticeMessage v-if="since" variant="info" class="mb-2">
    <p>
      <strong>Email delayed since {{ dateshort(since) }}</strong>
      <span v-if="provider">
        - {{ provider }} is not currently accepting our mail.</span
      >
      <span v-else> - their email provider is not currently accepting our mail.</span>
    </p>
    <p class="mb-0">
      This is a problem at our end, not with their address, so there's nothing
      for them or for you to fix. We've paused
      <span v-if="count">the {{ count }} emails we'd otherwise have sent them</span>
      <span v-else>their emails</span>
      rather than pile up mail that can't be delivered, and we'll send a
      catch-up once it clears.
    </p>
  </NoticeMessage>
</template>
<script setup>
import { dateshort } from '~/composables/useTimeFormat'

// Deliberately a separate component from ModBouncing, and deliberately
// "info" rather than "danger". Moderators read "bouncing" as "this address
// is bad" and act on it - chase the member, remove them. A deferral is our
// sending reputation with their provider, the address is fine, and the only
// correct action is to wait. Showing the two the same way would get members
// chased for our problem.
defineProps({
  since: {
    type: String,
    required: false,
    default: null,
  },
  provider: {
    type: String,
    required: false,
    default: null,
  },
  count: {
    type: Number,
    required: false,
    default: 0,
  },
})
</script>
