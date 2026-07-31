<template>
  <NoticeMessage variant="primary">
    <p>
      One of our volunteers has posted this properly for you, so more people
      will see it. You don't need to do anything.
    </p>
    <p>
      You'll find it in My Posts, along with any replies, and you can edit or
      withdraw it from there.
    </p>
    <b-button
      variant="primary"
      :to="preview ? null : '/myposts'"
      :disabled="preview"
      class="mb-1"
    >
      Go to My Posts
    </b-button>
  </NoticeMessage>
</template>
<script setup>
// The note left on a ChitChat thread when a moderator posts the item properly
// for the member.
//
// It lives here rather than inline in NewsRefer so that NewsConvertModal can
// show the moderator exactly what the member will read before they commit to
// it. Two copies of the wording would drift, and the moderator would end up
// previewing something other than what actually gets posted.
import NoticeMessage from '~/components/NoticeMessage'

defineProps({
  // Preview mode: rendered inside the convert modal rather than on the thread,
  // so the button is inert - the moderator is not the one going to My Posts.
  preview: {
    type: Boolean,
    required: false,
    default: false,
  },
})
</script>
