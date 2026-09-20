<template>
  <NoticeMessage variant="primary">
    <p>
      One of our volunteers has posted {{ whatWasPosted }} for you, so more
      people will see it. You don't need to do anything.
    </p>
    <!-- The member posted in ChitChat because they didn't know an OFFER/WANTED
         was the thing to use, so say what one is and where the button lives.
         Without this the notice tells them something happened but not what,
         and they post in ChitChat again next time. -->
    <p v-if="msgtype === 'Offer'">
      An OFFER is how you give something away on Freegle. It goes out to
      everyone nearby who might want it, so you get a better response. Next time
      you can post one yourself with the <strong>Give</strong> button.
    </p>
    <p v-else-if="msgtype === 'Wanted'">
      A WANTED is how you ask for something on Freegle. It goes out to everyone
      nearby who might have one, so you get a better response. Next time you can
      post one yourself with the <strong>Ask</strong> button.
    </p>
    <p v-else>
      OFFERs and WANTEDs are how you give and ask for things on Freegle. They go
      out to everyone nearby who can help, so you get a better response. Next
      time you can post one yourself with the <strong>Give</strong> or
      <strong>Ask</strong> buttons.
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
