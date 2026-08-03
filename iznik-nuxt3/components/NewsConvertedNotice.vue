<template>
  <NoticeMessage variant="primary">
    <p>
      One of our volunteers has posted {{ whatWasPosted }} for you, so more
      people will see it. You don't need to do anything.
    </p>
    <p>
      You'll find it in My Posts, along with any replies, and you can edit or
      withdraw it from there.
    </p>
    <!-- Two buttons rather than one with :to bound to null. A null `to` still
         reaches the router, which does `'path' in to` and throws
         "Cannot use 'in' operator to search for 'path' in null" - that took the
         whole page down as soon as the convert modal opened. -->
    <b-button v-if="preview" variant="primary" class="mb-1" disabled>
      Go to My Posts
    </b-button>
    <b-button v-else variant="primary" to="/myposts" class="mb-1">
      Go to My Posts
    </b-button>
  </NoticeMessage>
</template>
<script setup>
// The note left on a ChitChat thread when a moderator posts an OFFER/WANTED
// for the member.
//
// It lives here rather than inline in NewsRefer so that NewsConvertModal can
// show the moderator exactly what the member will read before they commit to
// it. Two copies of the wording would drift, and the moderator would end up
// previewing something other than what actually gets posted.
import { computed } from 'vue'
import NoticeMessage from '~/components/NoticeMessage'

const props = defineProps({
  // Preview mode: rendered inside the convert modal rather than on the thread,
  // so the button is inert - the moderator is not the one going to My Posts.
  preview: {
    type: Boolean,
    required: false,
    default: false,
  },
  // 'Offer' or 'Wanted' - what the ChitChat post became. Notices written
  // before this was recorded have no type, so the wording must survive
  // without it.
  msgtype: {
    type: String,
    required: false,
    default: null,
  },
})

const whatWasPosted = computed(() => {
  if (props.msgtype === 'Wanted') {
    return 'a WANTED'
  } else if (props.msgtype === 'Offer') {
    return 'an OFFER'
  }

  return 'this'
})
</script>
