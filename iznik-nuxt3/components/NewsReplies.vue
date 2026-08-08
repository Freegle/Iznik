<template>
  <div class="replies-container" :class="'depth-' + depth">
    <!--
      Ordered render plan. When collapsed, middle replies WITHOUT new activity in
      their subtree hide behind one expander; a parent whose nested replies contain
      anything new is exempted and stays visible in place, so new activity is never
      hidden (ChitChat threads nest - 40% of replies are replies-to-replies - so
      newness must be judged per subtree, not per top-level row).
    -->
    <template v-for="entry in renderEntries" :key="entryKey(entry)">
      <div v-if="entry.expander" class="show-more-replies">
        <button
          class="show-more-btn"
          :aria-expanded="showAllReplies ? 'true' : 'false'"
          @click="expandReplies"
        >
          {{ expanderLabel }}
        </button>
      </div>
      <NewsUnreadDivider v-else-if="entry.divider" :count="entry.count" />
      <nuxt-link
        v-else-if="entry.feedCta"
        :to="'/chitchat/' + threadhead"
        class="view-all-replies"
      >
        {{ feedCtaLabel(entry) }}
      </nuxt-link>
      <div
        v-else
        class="reply-thread"
        :data-reply-id="entry.reply.id"
        :data-combined-ids="
          entry.reply.combinedIds ? entry.reply.combinedIds.join(' ') : null
        "
        :class="{ 'reply-thread--new': isReplyNew(entry.reply) }"
      >
        <NewsRefer
          v-if="isReferNotice(entry.reply)"
          :id="entry.reply.id"
          :type="entry.reply.type"
          :threadhead="threadhead"
          class="reply-content"
        />
        <NewsReply
          v-else
          :id="entry.reply.id"
          :reply-data="entry.reply"
          :threadhead="threadhead"
          :scroll-to="scrollTo"
          class="reply-content"
          :depth="depth"
          :suppress-children="feedMode"
          @rendered="rendered"
          @subtree-rendered="childSubtreeRendered"
          @expand-combined="expandCombined"
        />
      </div>
    </template>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { useAuthStore } from '~/stores/auth'
import NewsRefer from '~/components/NewsRefer'
import NewsUnreadDivider from '~/components/NewsUnreadDivider'

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
  // 'thread' renders the full conversation with head/tail collapsing.
  // 'feed' keeps cards short: post + the two most recent replies + one
  // honestly-counted "View all N replies" link to the thread page.
  context: {
    type: String,
    required: false,
    default: 'thread',
  },
})

const emit = defineEmits(['rendered', 'subtree-rendered'])

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
    return (
      reply.combinedIds[reply.combinedIds.length - 1] > seenBeforeVisit.value
    )
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
    // Return all - hiding is handled by collapsePlan.
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
// A deep link (scrollTo set) collapses too: the target's own row is exempted
// below, so a notification lands on a focused view rather than the full wall.
const shouldCollapse = computed(() => {
  return (
    !showAllReplies.value &&
    !props.replyTo &&
    combinedReplies.value.length > COLLAPSE_THRESHOLD
  )
})

// A reply (or combined group) counts as containing new activity if it, or ANY
// descendant in its nested reply tree, is newer than the pre-visit baseline.
function resolveReply(r) {
  return r && typeof r === 'object' ? r : newsfeedStore.byId(r)
}

function childrenHaveNew(reply) {
  const kids = reply?.replies || []
  for (const kid of kids) {
    const child = resolveReply(kid)
    if (child && subtreeHasNew(child)) return true
  }
  return false
}

function subtreeHasNew(entry) {
  if (!seenBeforeVisit.value) return false
  if (isReplyNew(entry)) return true
  if (entry.combinedIds) {
    for (const cid of entry.combinedIds) {
      const r = newsfeedStore.byId(cid)
      if (r && childrenHaveNew(r)) return true
    }
    return false
  }
  return childrenHaveNew(entry)
}

// The reply a deep link (notification click) points at. Never hide it, or
// anything on the path to it: the whole point of the landing is to see it.
const scrollTargetId = computed(() => parseInt(props.scrollTo) || 0)

function replyTreeContains(reply, target) {
  if (!reply) return false
  if (reply.id === target) return true
  for (const kid of reply.replies || []) {
    const child = resolveReply(kid)
    if (child && replyTreeContains(child, target)) return true
  }
  return false
}

function subtreeHasScrollTarget(entry) {
  const target = scrollTargetId.value
  if (!target) return false
  if (entry.id === target) return true
  if (entry.combinedIds) {
    if (entry.combinedIds.includes(target)) return true
    for (const cid of entry.combinedIds) {
      if (replyTreeContains(newsfeedStore.byId(cid), target)) return true
    }
    return false
  }
  return replyTreeContains(entry, target)
}

// Ordered render plan: hide only middle entries whose whole subtree is old
// and does not contain the deep-link target. A single expander sits where
// the first hidden entry was.
const collapsePlan = computed(() => {
  const list = combinedReplies.value
  const passthrough = { entries: list.map((r) => ({ reply: r })), hidden: [] }
  if (!shouldCollapse.value) return passthrough

  const hidden = []
  const entries = []
  let expanderPlaced = false

  list.forEach((r, idx) => {
    const inMiddle = idx >= HEAD_COUNT && idx < list.length - TAIL_COUNT
    if (inMiddle && !subtreeHasNew(r) && !subtreeHasScrollTarget(r)) {
      hidden.push(r)
      if (!expanderPlaced) {
        entries.push({ expander: true })
        expanderPlaced = true
      }
    } else {
      entries.push({ reply: r })
    }
  })

  return hidden.length ? { entries, hidden } : passthrough
})

// Feed cards must stay short. Above this many replies (counted across the
// whole tree) the card switches to a view-all link plus the two most recent
// top-level entries; at or below it, small threads render exactly as before.
const FEED_TOTAL_THRESHOLD = 3
const FEED_VISIBLE_TAIL = 2

// Recursive {total, fresh} for one combined entry: how many reply rows its
// subtree holds, and how many of them are newer than the visit baseline.
function countDescendants(reply) {
  let total = 0
  let fresh = 0
  for (const kid of reply?.replies || []) {
    const child = resolveReply(kid)
    if (!child) continue
    total++
    if (seenBeforeVisit.value && child.id > seenBeforeVisit.value) fresh++
    const nested = countDescendants(child)
    total += nested.total
    fresh += nested.fresh
  }
  return { total, fresh }
}

function countEntry(entry) {
  if (entry.combinedIds) {
    // Combined blocks never have children (combining requires none).
    const total = entry.combinedIds.length
    const fresh = seenBeforeVisit.value
      ? entry.combinedIds.filter((cid) => cid > seenBeforeVisit.value).length
      : 0
    return { total, fresh }
  }
  const own = seenBeforeVisit.value && entry.id > seenBeforeVisit.value ? 1 : 0
  const nested = countDescendants(entry)
  return { total: 1 + nested.total, fresh: own + nested.fresh }
}

const treeCounts = computed(() => {
  let total = 0
  let fresh = 0
  for (const entry of combinedReplies.value) {
    const c = countEntry(entry)
    total += c.total
    fresh += c.fresh
  }
  return { total, fresh }
})

const feedMode = computed(() => {
  return (
    props.context === 'feed' &&
    props.depth === 1 &&
    treeCounts.value.total > FEED_TOTAL_THRESHOLD
  )
})

// The ONE unread divider (WhatsApp convention). It promises "everything new is
// below here", so it sits above the first entry whose SUBTREE contains anything
// new - not merely the first new top-level reply. Replies to an old comment
// partway up the thread are new but live under an old parent, so anchoring on
// the parent keeps that promise true and keeps the parent visible as context.
const firstNewIndex = computed(() => {
  if (!seenBeforeVisit.value) return -1
  return combinedReplies.value.findIndex((r) => subtreeHasNew(r))
})

// Every new reply in the thread, nested ones included, so this number agrees
// with the "View all N replies - M new" link that brought the reader here.
const newCount = computed(() => treeCounts.value.fresh)

const showDivider = computed(() => props.depth === 1 && firstNewIndex.value > 0)

const renderEntries = computed(() => {
  let entries

  if (feedMode.value) {
    // Feed card: view-all link first, then only the most recent entries.
    entries = [
      {
        feedCta: true,
        total: treeCounts.value.total,
        fresh: treeCounts.value.fresh,
      },
      ...combinedReplies.value
        .slice(-FEED_VISIBLE_TAIL)
        .map((r) => ({ reply: r })),
    ]
  } else {
    entries = [...collapsePlan.value.entries]
  }

  if (showDivider.value) {
    const first = combinedReplies.value[firstNewIndex.value]
    const idx = entries.findIndex((e) => e.reply && e.reply.id === first.id)
    if (idx >= 0) {
      entries.splice(idx, 0, { divider: true, count: newCount.value })
    }
  }

  return entries
})

// Deterministic completion for the deep-link scroll: this list's subtree is
// rendered once every NewsReply row in the current render plan has reported
// its own subtree mounted. NewsRefer rows are synchronous imports (mounted
// with this component) and expander rows render nothing async, so neither
// is waited on. Re-evaluated when the render plan changes (e.g. expanding
// the collapse adds rows, regressing completion until they mount too).
const completedSubtrees = new Set()
let subtreeSatisfied = false

// A moderator-generated notice rather than someone's reply: the ReferTo family,
// and the note left when a volunteer posts a ChitChat item properly for the
// member. Rendered by NewsRefer and excluded from the reply subtree count.
function isReferNotice(reply) {
  const type = reply?.type
  if (!type) return false
  return type.indexOf('ReferTo') === 0 || type === 'ConvertedToPost'
}

function expectedSubtreeIds() {
  return renderEntries.value
    .filter((e) => e.reply && !isReferNotice(e.reply))
    .map((e) => e.reply.id)
}

function evaluateSubtree() {
  const done = expectedSubtreeIds().every((id) => completedSubtrees.has(id))
  if (done && !subtreeSatisfied) {
    subtreeSatisfied = true
    emit('subtree-rendered', props.id)
  } else if (!done) {
    subtreeSatisfied = false
  }
}

function childSubtreeRendered(id) {
  completedSubtrees.add(id)
  evaluateSubtree()
}

watch(renderEntries, evaluateSubtree)

onMounted(() => {
  // Nothing async to wait for (all rows NewsRefer, or none) - report now.
  evaluateSubtree()
})

const hiddenCount = computed(() => collapsePlan.value.hidden.length)

// Hidden entries never contain new activity (they are exempted above), so the
// label only ever describes older conversation.
const expanderLabel = computed(() => {
  const n = hiddenCount.value
  const replyWord = n === 1 ? 'reply' : 'replies'
  return `Show ${n} older ${replyWord}`
})

function entryKey(entry) {
  if (entry.expander) return 'reply-expander'
  if (entry.divider) return 'unread-divider'
  if (entry.feedCta) return 'feed-cta'
  return 'newsfeed-' + entry.reply.id
}

function feedCtaLabel(entry) {
  const replyWord = entry.total === 1 ? 'reply' : 'replies'
  let label = `View all ${entry.total} ${replyWord}`
  if (entry.fresh > 0) {
    label += ` · ${entry.fresh} new`
  }
  return label
}

function expandReplies() {
  showAllReplies.value = true
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

/* Full-width, comfortably tappable row (44px+) linking to the thread page. */
.view-all-replies {
  display: flex;
  align-items: center;
  min-height: 44px;
  width: 100%;
  padding: 0.25rem 0;
  color: $color-success;
  font-size: 0.9rem;
  font-weight: 600;
  text-decoration: none;

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
