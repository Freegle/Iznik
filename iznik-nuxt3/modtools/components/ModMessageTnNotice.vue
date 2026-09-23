<template>
  <NoticeMessage
    v-if="!modMessagingAllowed"
    variant="warning"
    class="mt-1 mb-2"
    data-test="tn-unaddressed-warning"
  >
    <span v-if="!live" data-test="tn-unaddressed-pending">
      This came from Trash Nothing. The person who posted it hasn't joined
      Freegle and didn't choose
      <strong>{{ groupName || 'this community' }}</strong> - we matched it here
      from where they are. So it's <strong>approve or delete</strong> on what
      you can see: you can't edit it, and there's no way to ask them for a photo
      or a postcode first.
    </span>
    <span v-else data-test="tn-unaddressed-approved">
      This came from Trash Nothing and is live on
      <strong>{{ groupName || 'this community' }}</strong
      >. The person who posted it hasn't joined Freegle and didn't choose this
      community - we matched it here from where they are. You can
      <strong>delete</strong> it if it shouldn't be here, but you can't edit it
      or message them. If members report it, it comes off Freegle automatically
      rather than back to you.
    </span>
  </NoticeMessage>
</template>
<script setup>
// The moderator-facing explanation for a TN post placed on a community its poster never
// chose: what they can still do with it, and what they can't. Its own component because
// it is self-contained copy with two states, and ModMessage is long enough already.
defineProps({
  // False for such a post. True (the default) for every ordinary post, which renders
  // nothing at all - see modmessaging in the Go API for how it is derived.
  modMessagingAllowed: {
    type: Boolean,
    required: false,
    default: true,
  },
  // Whether the copy being administered is live on the community (Approved). Pending and
  // Spam are both still awaiting a decision, and read as not live.
  live: {
    type: Boolean,
    required: false,
    default: false,
  },
  groupName: {
    type: String,
    required: false,
    default: null,
  },
})
</script>
