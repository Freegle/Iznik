<template>
  <div class="replies-container" :class="'depth-' + depth">
    <!-- Head replies: first HEAD_COUNT replies, visible only when collapsed -->
    <div
      v-for="reply in headReplies"
      :key="'newsfeed-head-' + reply.id"
      class="reply-thread"
    >
      <NewsRefer
        v-if="reply.type && reply.type.indexOf('ReferTo') === 0"
        :id="reply.id"
        :type="reply.type"
        :threadhead="threadhead"
        class="reply-content"
      />
      <NewsReply
        v-else
        :id="reply.id"
        :reply-data="reply"
        :threadhead="threadhead"
        :scroll-to="scrollTo"
        class="reply-content"
        :depth="depth"
        @rendered="rendered"
        @expand-combined="expandCombined"
      />
    </div>

    <!-- Middle expander: shown between head and tail when collapsed -->
    <div v-if="showExpander" class="show-more-replies">
      <button
        class="show-more-btn"
        :aria-expanded="showAllReplies ? 'true' : 'false'"
        @click="expandReplies"
      >
        {{ expanderLabel }}
      </button>
    </div>

    <!-- Tail replies: last TAIL_COUNT when collapsed, or ALL when expanded -->
    <div
      v-for="reply in tailReplies"
      :key="'newsfeed-tail-' + reply.id"
      class="reply-thread"
      :data-reply-id="reply.id"
      :class="{ 'reply-thread--new': isReplyNew(reply) }"
    >
      <NewsRefer
        v-if="reply.type && reply.type.indexOf('ReferTo') === 0"
        :id="reply.id"
        :type="reply.type"
        :threadhead="threadhead"
        class="reply-content"
      />
      <NewsReply
        v-else
        :id="reply.id"
        :reply-data="reply"
        :threadhead="threadhead"
        :scroll-to="scrollTo"
        class="reply-content"
        :depth="depth"
        @rendered="rendered"
        @expand-combined="expandCombined"
      />
    </div>
  </div>
</template>
<script setup>
import { ref, computed, defineAsyncComponent, nextTick } from 'vue'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { useAuthStore } from '~/stores/auth'
import NewsRefer from '~/components/NewsRefer'

const NewsReply = defineAsyncComponent(() =>
  import('~/components/NewsReply.vue')
)

// Show first HEAD_COUNT + last TAIL_COUNT replies; collapse when total > COLLAPSE_THRESHOLD.
const HEAD_COUNT = 2
const TAIL_COUNT = 3
const COLLAPSE_THRESHOLD = 6

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  threadhead: {
    type: Number,
    required: true,
  },
  replyTo: {
    type: Number,
    required: false,
    default: null,
  },
  scrollTo: {
    type: String,
    required: false,
    default: '',
  },
  depth: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['rendered'])

const newsfeedStore = useNewsfeedStore()
const authStore = useAuthStore()
const showAllReplies = ref(false)
const expandedCombinedIds = ref(new Set())

const me = authStore.user

const mod = computed(() => {
  return (
    me &&
    (me.systemrole === 'Moderator' ||
      me.systemrole === 'Support' ||
      me.systemrole === 'Admin')
  )
})

const newsfeed = computed(() => {
  return newsfeedStore.byId(props.id)
})

const replies = computed(() => {
  return newsfeed.value?.replies || []
})

const seenBeforeVisit = computed(() => newsfeedStore.seenBeforeVisit)

// Whether a reply (or combined group) counts as new to the user this session.
// seenBeforeVisit === null or 0 means we have no baseline - treat nothing as new.
function isReplyNew(reply) {
  if (!seenBeforeVisit.value) return false
  if (reply.combinedIds) {
    return reply.combinedIds[reply.combinedIds.length - 1] > seenBeforeVisit.value
  }
  return reply.id > seenBeforeVisit.value
}

const visiblereplies = computed(() => {
  const ret = []
  for (let i = 0; i < replies.value.length; i++) {
    const reply = newsfeedStore.byId(replies.value[i])
    if (!reply.deleted || mod.value) {
      ret.push(reply)
    }
  }
  return ret
})

const combinedReplies = computed(() => {
  const TEN_MINUTES = 10 * 60 * 1000
  const combined = []

  for (let i = 0; i < filteredReplies.value.length; i++) {
    const currentReply = filteredReplies.value[i]
    const currentTime = new Date(currentReply.added).getTime()
    const lastCombined = combined[combined.length - 1]

    const isExpanded =
      expandedCombinedIds.value.has(currentReply.id) ||
      (lastCombined?.combinedIds &&
        lastCombined.combinedIds.some((id) =>
          expandedCombinedIds.value.has(id)
        ))

    const canCombine =
      !isExpanded &&
      lastCombined &&
      lastCombined.userid === currentReply.userid &&
      !currentReply.image &&
      !lastCombined.image &&
      !currentReply.replies?.length &&
      !lastCombined.replies?.length &&
      currentTime -
        new Date(lastCombined.originalAdded || lastCombined.added).getTime() <=
        TEN_MINUTES

    if (canCombine) {
      combined[combined.length - 1] = {
        id: lastCombined.id,
        userid: lastCombined.userid,
        displayname: lastCombined.displayname,
        profile: lastCombined.profile,
        added: currentReply.added,
        originalAdded: lastCombined.originalAdded || lastCombined.added,
        message: lastCombined.message + '\n\n' + currentReply.message,
        isCombined: true,
        combinedIds: [
          ...(lastCombined.combinedIds || [lastCombined.id]),
          currentReply.id,
        ],
        image: null,
        replies: [],
        deleted: lastCombined.deleted,
        hidden: lastCombined.hidden,
        loves: lastCombined.loves,
        loved: lastCombined.loved,
        type: lastCombined.type,
        replyto: lastCombined.replyto,
        threadhead: lastCombined.threadhead,
        previews: lastCombined.previews,
      }
    } else {
      combined.push({
        id: currentReply.id,
        userid: currentReply.userid,
        displayname: currentReply.displayname,
        profile: currentReply.profile,
        added: currentReply.added,
        message: currentReply.message,
        image: currentReply.image,
        replies: currentReply.replies,
        deleted: currentReply.deleted,
        hidden: currentReply.hidden,
        loves: currentReply.loves,
        loved: currentReply.loved,
        type: currentReply.type,
        replyto: currentReply.replyto,
        threadhead: currentReply.threadhead,
        previews: currentReply.previews,
      })
    }
  }

  return combined
})

const filteredReplies = computed(() => {
  if (!visiblereplies.value.length) return []

  let ret = []

  if (props.scrollTo || showAllReplies.value) {
    ret = visiblereplies.value
  } else if (props.replyTo) {
    // Show the reply we're replying to and everything after it.
    let seen = false
    for (let i = 0; i < visiblereplies.value.length; i++) {
      const reply = visiblereplies.value[i]
      if (reply?.id === props.replyTo || seen) {
        seen = true
        ret.push(reply)
      }
    }
    if (!seen) {
      ret = visiblereplies.value
    }
  } else {
    // Return all - head/tail splitting is handled by headReplies / tailReplies.
    ret = visiblereplies.value
  }

  // Suppress replies where the message is identical to the previous.
  let lastMessage = null
  let i = ret.length
  while (i--) {
    if (!ret[i].message.localeCompare(lastMessage)) {
      ret.splice(i, 1)
    } else {
      lastMessage = ret[i].message
    }
  }

  return ret
})

// Collapse only at depth 1 (top-level replies), not for nested reply trees.
const shouldCollapse = computed(() => {
  return (
    !showAllReplies.value &&
    !props.scrollTo &&
    !props.replyTo &&
    combinedReplies.value.length > COLLAPSE_THRESHOLD
  )
})

const headReplies = computed(() => {
  if (!shouldCollapse.value) return []
  return combinedReplies.value.slice(0, HEAD_COUNT)
})

const tailReplies = computed(() => {
  if (!shouldCollapse.value) return combinedReplies.value
  return combinedReplies.value.slice(-TAIL_COUNT)
})

// The middle chunk that is hidden behind the expander when collapsed.
const hiddenMiddle = computed(() => {
  if (!shouldCollapse.value) return []
  return combinedReplies.value.slice(HEAD_COUNT, -TAIL_COUNT)
})

const hiddenCount = computed(() => hiddenMiddle.value.length)

const hiddenNewCount = computed(() => {
  if (!seenBeforeVisit.value) return 0
  return hiddenMiddle.value.filter((r) => isReplyNew(r)).length
})

const showExpander = computed(() => shouldCollapse.value && hiddenCount.value > 0)

const expanderLabel = computed(() => {
  const n = hiddenCount.value
  const replyWord = n === 1 ? 'reply' : 'replies'
  const newSuffix =
    hiddenNewCount.value > 0 ? ` - ${hiddenNewCount.value} new` : ''
  return `Show ${n} more ${replyWord}${newSuffix}`
})

async function expandReplies() {
  // Remember the first new reply that was hidden, so we can scroll to it after expansion.
  const firstNewHidden = hiddenMiddle.value.find((r) => isReplyNew(r))
  showAllReplies.value = true

  if (firstNewHidden) {
    await nextTick()
    const el = document.querySelector(
      `.reply-thread[data-reply-id="${firstNewHidden.id}"]`
    )
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    }
  }
}

function rendered(id) {
  emit('rendered', id)
}

function expandCombined(combinedIds) {
  combinedIds.forEach((id) => expandedCombinedIds.value.add(id))
}
</script>
<style lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.replies-container {
  margin-left: 0.5rem;
  padding-left: 0.75rem;
  border-left: 2px solid rgba($color-success, 0.4);

  @include media-breakpoint-up(md) {
    margin-left: 1rem;
    padding-left: 1rem;
  }

  &.depth-2 {
    border-left-color: rgba($color-success, 0.25);
  }

  &[class*='depth-']:not(.depth-1):not(.depth-2) {
    margin-left: 0;
    padding-left: 0;
    border-left: none;
  }
}

.show-more-replies {
  margin: 0.25rem 0;
}

.show-more-btn {
  background: none;
  border: none;
  padding: 0.25rem 0;
  color: $color-success;
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  line-height: 1.4;

  &:hover {
    text-decoration: underline;
    color: $color-success-hover;
  }

  &:focus-visible {
    outline: 2px solid $color-success;
    outline-offset: 2px;
    border-radius: 2px;
  }
}

.reply-thread {
  padding: 0.5rem 0;

  &:not(:last-child) {
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
  }
}

.reply-thread--new {
  background: rgba($color-success-bg, 0.35);
  border-radius: 4px;
  padding-left: 0.25rem;
  margin-left: -0.25rem;
}

.reply-content {
  /* Content styling handled by NewsReply */
}
</style>
