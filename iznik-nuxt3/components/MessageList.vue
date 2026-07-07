<template>
  <div ref="feedRoot">
    <h2 v-if="group && showGroupHeader" class="visually-hidden">
      Community Information
    </h2>
    <GroupHeader
      v-if="group && showGroupHeader"
      :group="group"
      show-join
      :show-give-find="showGiveFind"
    />
    <h2 class="visually-hidden">List of wanteds and offers</h2>
    <div id="visobserver" v-observe-visibility="visibilityChanged" />

    <div
      v-if="
        initialFetchDone && selectedSort === 'Unseen' && showCountsUnseen && me
      "
    >
      <MessageListCounts
        v-if="browseCount && !search"
        :count="browseCount"
        @mark-seen="markSeen"
      />
    </div>

    <!-- Split view: unseen messages, divider, seen messages.
         Gated on initialFetchDone to prevent CLS from divider appearing then
         disappearing as message de-duplication changes seen/unseen split.
         Loading state is handled per-ScrollGrid below — the outer template
         must not hide once shown, as toggling it on re-fetch causes CLS. -->
    <template
      v-if="
        initialFetchDone && selectedSort === 'Unseen' && showCountsUnseen && me
      "
    >
      <!-- Unseen messages grid -->
      <ScrollGrid
        v-if="unseenMessages.length"
        :items="unseenMessages"
        key-field="id"
        :loading="loading"
        :distance="distance"
        :initial-count="MIN_TO_SHOW"
        @load-more="handleLoadMore"
      >
        <template #item="{ item: m, index: ix }">
          <div
            :id="'messagewrapper-' + m.id"
            :ref="'messagewrapper-' + m.id"
            class="messagewrapper"
          >
            <Suspense>
              <OurMessage
                :id="m.id"
                :matchedon="m.matchedon"
                :preload="ix < 2"
                record-view
                @view="onCardView(m.id)"
                @not-found="messageNotFound(m.id)"
              />
              <template #fallback>
                <MessageSkeleton />
              </template>
            </Suspense>
          </div>
        </template>
      </ScrollGrid>

      <!-- Divider between unseen and seen -->
      <MessageListUpToDate v-if="seenMessages.length" />

      <!-- Seen messages grid -->
      <ScrollGrid
        v-if="seenMessages.length"
        :items="seenMessages"
        key-field="id"
        :loading="loading"
        :distance="distance"
        :initial-count="MIN_TO_SHOW"
        @load-more="handleLoadMore"
      >
        <template #item="{ item: m, index: ix }">
          <div
            :id="'messagewrapper-' + m.id"
            :ref="'messagewrapper-' + m.id"
            class="messagewrapper"
          >
            <Suspense>
              <OurMessage
                :id="m.id"
                :matchedon="m.matchedon"
                :preload="ix < 2"
                record-view
                @view="onCardView(m.id)"
                @not-found="messageNotFound(m.id)"
              />
              <template #fallback>
                <MessageSkeleton />
              </template>
            </Suspense>
          </div>
        </template>
      </ScrollGrid>
    </template>

    <!-- Standard single grid view (not in Unseen sort mode) -->
    <ScrollGrid
      v-else
      :items="deDuplicatedMessages"
      key-field="id"
      :loading="loading"
      :distance="distance"
      :initial-count="MIN_TO_SHOW"
      @load-more="handleLoadMore"
    >
      <template #item="{ item: m, index: ix }">
        <div
          :id="'messagewrapper-' + m.id"
          :ref="'messagewrapper-' + m.id"
          class="messagewrapper"
        >
          <Suspense>
            <OurMessage
              :id="m.id"
              :matchedon="m.matchedon"
              :preload="ix < 2"
              record-view
              @view="onCardView(m.id)"
              @not-found="messageNotFound(m.id)"
            />
            <template #fallback>
              <MessageSkeleton />
            </template>
          </Suspense>
        </div>
      </template>
    </ScrollGrid>
  </div>
</template>
<script setup>
import {
  ref,
  computed,
  watch,
  defineAsyncComponent,
  onMounted,
  onBeforeUnmount,
} from 'vue'
import MessageListUpToDate from './MessageListUpToDate'
import ScrollGrid from '~/components/ScrollGrid'
import { useGroupStore } from '~/stores/group'
import { useMessageStore } from '~/stores/message'
import { useNearbyStore } from '~/stores/nearby'
import { throttleFetches } from '~/composables/useThrottle'
import { useMe } from '~/composables/useMe'
import { useScrollDepth } from '~/composables/useScrollDepth'
import {
  deduplicateMessages,
  findDuplicates,
  dedupKey,
} from '~/composables/useMessageDedup'

const OurMessage = defineAsyncComponent(() =>
  import('~/components/OurMessage.vue')
)
const GroupHeader = defineAsyncComponent(() =>
  import('~/components/GroupHeader.vue')
)
const MessageSkeleton = defineAsyncComponent(() =>
  import('~/components/MessageSkeleton.vue')
)

const MIN_TO_SHOW = 10

const props = defineProps({
  messagesForList: {
    type: Array,
    required: true,
  },
  firstSeenMessage: {
    type: Number,
    required: false,
    default: null,
  },
  selectedGroup: {
    type: Number,
    required: false,
    default: null,
  },
  selectedType: {
    type: String,
    required: false,
    default: 'All',
  },
  selectedSort: {
    type: String,
    required: false,
    default: 'Unseen',
  },
  loading: {
    type: Boolean,
    required: false,
    default: false,
  },
  bump: {
    type: Number,
    required: false,
    default: 0,
  },
  exclude: {
    type: Number,
    required: false,
    default: null,
  },
  visible: {
    type: Boolean,
    required: false,
    default: true,
  },
  jobs: {
    type: Boolean,
    required: false,
    default: true,
  },
  showGiveFind: {
    type: Boolean,
    required: false,
    default: false,
  },
  showGroupHeader: {
    type: Boolean,
    required: false,
    default: true,
  },
  none: {
    type: Boolean,
    required: false,
    default: false,
  },
  showCountsUnseen: {
    type: Boolean,
    required: false,
    default: false,
  },
  search: {
    type: String,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['update:none', 'update:visible'])

const groupStore = useGroupStore()
const messageStore = useMessageStore()
const nearbyStore = useNearbyStore()
const { me, myid, myGroups: myMemberships } = useMe()

// Browse-feed scroll-depth instrumentation: record how far down the feed this
// session scrolls. 'search' vs 'browse' so the sysadmin "Scrolling" tab can tell
// the two feeds apart. The composable debounces the send and upserts one row per
// session (keyed on its session id), so repeat sends never double-count.
const runtimeConfig = useRuntimeConfig()
const { record: recordScrollDepth } = useScrollDepth(
  runtimeConfig?.public?.APIv2,
  () => (props.search ? 'search' : 'browse')
)

// Record the furthest feed position actually reached as the member scrolls -
// not just at infinite-scroll batch boundaries (handleLoadMore), so even small
// scrolls register. The message wrappers are in feed order, so the furthest one
// whose top has entered the viewport is the deepest position reached; binary
// search keeps this to O(log n) layout reads. The composable debounces the send.
const feedRoot = ref(null)
let scrollDepthTimer = null
function captureScrollDepth() {
  const root = feedRoot.value
  if (!root || typeof window === 'undefined') return
  const wrappers = root.querySelectorAll('.messagewrapper')
  if (!wrappers.length) return
  const vh = window.innerHeight || 0
  let lo = 0
  let hi = wrappers.length - 1
  let furthest = -1
  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    if (wrappers[mid].getBoundingClientRect().top < vh) {
      furthest = mid
      lo = mid + 1
    } else {
      hi = mid - 1
    }
  }
  if (furthest >= 0) {
    recordScrollDepth(furthest, wrappers.length)
  }
}
function onFeedScroll() {
  if (scrollDepthTimer) clearTimeout(scrollDepthTimer)
  scrollDepthTimer = setTimeout(captureScrollDepth, 200)
}
onMounted(() => {
  if (typeof window !== 'undefined') {
    window.addEventListener('scroll', onFeedScroll, { passive: true })
    // Count what's already on screen at landing (reached without scrolling).
    captureScrollDepth()
  }
})
onBeforeUnmount(() => {
  if (scrollDepthTimer) clearTimeout(scrollDepthTimer)
  if (typeof window !== 'undefined') {
    window.removeEventListener('scroll', onFeedScroll)
  }
})

// Get the initial messages to show in a single call.
// Wait for fetch to complete before enabling the split view (unseen/seen),
// because de-duplication after fetch can change seen/unseen categorization,
// causing CLS when the divider section appears then disappears.
const initialFetchDone = ref(false)
const initialIds = props.messagesForList
  ?.slice(0, MIN_TO_SHOW)
  .map((message) => message.id)

if (initialIds?.length) {
  messageStore.fetchMultiple(initialIds).finally(() => {
    initialFetchDone.value = true
  })
} else {
  initialFetchDone.value = true
}

// Batch-fetch the groups referenced by the whole list in one request, so the per-post
// MessageTag components find their group cached instead of each firing its own
// /group/{id} call. This matters most for the nearby/reach feed: a post can be in a group
// the viewer isn't a member of, so it won't already be in the membership cache loaded at
// login - without this, a heavy-membership user saw dozens of separate group fetches. The
// feed summaries carry a groupid, so we can batch them upfront without waiting for the
// per-message detail. fetchBatch de-dupes against what's already cached and no-ops if all
// are present.
const listGroupIds = [
  ...new Set(
    (props.messagesForList ?? []).map((m) => m.groupid).filter(Boolean)
  ),
]
if (listGroupIds.length) {
  groupStore.fetchBatch(listGroupIds)
}

// Data
const myGroups = []
const failedIds = ref(new Set())
const distance = ref(2000)
const prefetched = ref(0)
const emitted = ref(false)
let markSeenTimer = null
let pollCount = 0
const MAX_POLL_COUNT = 30 // Poll for up to 30 seconds

// Computed properties
// Use the same count as the navbar - from the API via messageStore
const browseCount = computed(() => messageStore.count)

const group = computed(() => {
  let ret = null

  if (props.selectedGroup) {
    ret = groupStore?.get(props.selectedGroup)
  } else if (myGroups && myGroups.length === 1) {
    ret = groupStore?.get(myGroups[0].id)
  }

  return ret
})

const reduceSuccessful = computed(() => {
  const ret = []

  props.messagesForList.forEach((m) => {
    if (m.successful) {
      if (ret.length) {
        const lastfour = ret.slice(-4)
        let gotSuccessful = false

        lastfour.forEach((m) => {
          gotSuccessful |= m.successful
        })

        if (!gotSuccessful) {
          ret.push(m)
        }
      }
    } else {
      ret.push(m)
    }
  })

  return ret
})

const filteredMessagesToShow = computed(() => {
  const ret = []

  // Precompute the "older than a week" cutoff ONCE instead of calling dayjs() (which
  // re-creates "now") and parsing each message's arrival inside the loop. On a large
  // feed - a heavy-membership user's "all my communities" can be thousands of posts -
  // the per-item dayjs was a multi-hundred-millisecond main-thread block (seen in a CPU
  // profile). Date.parse on the ISO arrival string is a cheap native number compare.
  const weekAgoTs = Date.now() - 7 * 24 * 60 * 60 * 1000

  // ScrollGrid handles visibility limits, so we provide all filtered messages
  for (let i = 0; i < reduceSuccessful.value?.length; i++) {
    const m = reduceSuccessful.value[i]

    if (wantMessage(m)) {
      let addIt = true

      if (m.successful) {
        if (myid.value === m.fromuser) {
          addIt = true
        } else if (props.selectedType !== 'All') {
          addIt = false
        } else if (Date.parse(m.arrival) < weekAgoTs) {
          addIt = false
        }
      }

      if (addIt) {
        ret.push(m)
      }
    }
  }

  return ret
})

const filteredMessagesInStore = computed(() => {
  const ret = {}

  filteredMessagesToShow.value?.forEach((m) => {
    ret[m.id] = messageStore?.byId(m.id)
  })

  return ret
})

// Group ids the logged-in user is a member of, for duplicate-preference below.
const myGroupIdSet = computed(
  () => new Set((myMemberships?.value || []).map((g) => parseInt(g.id)))
)

// True if a message is posted to a group the user already belongs to.
function isOnMyGroup(message) {
  if (!message?.groups || !myGroupIdSet.value.size) {
    return false
  }
  return message.groups.some((g) => myGroupIdSet.value.has(parseInt(g.groupid)))
}

// Collapse a poster's crosspost/repost of the same item to one entry, preferring the copy
// on a group the viewer belongs to (Discourse 9733 / 9729); firstSeenMessage always wins.
// Delegates to the pure deduplicateMessages, whose member-group swap is O(1) (id->index
// Map) rather than the previous ret.findIndex() scan.
const deDuplicatedMessages = computed(() =>
  deduplicateMessages(filteredMessagesToShow.value, {
    getMessage: (id) => filteredMessagesInStore.value[id],
    exclude: props.exclude,
    firstSeenMessage: props.firstSeenMessage,
    isOnMyGroup,
    failedIds: failedIds.value,
  })
)

// For each rendered card, the ids of the crosspost/repost copies that deduplicateMessages
// collapsed under it (same dedupKey, i.e. same poster + item). The server counts every copy
// as its own unseen post, but only the one kept card is shown - so viewing that card marks
// only its copy seen and the hidden copies keep the unread count above zero forever. Mapping
// id -> siblings here lets a view of the shown card also mark the hidden copies seen, so the
// badge drains in step with what the member has actually seen. Uses the SAME dedupKey and
// message detail as deDuplicatedMessages so the grouping matches the feed exactly.
const siblingIdsById = computed(() => {
  const byKey = new Map()
  for (const m of reduceSuccessful.value || []) {
    const detail = messageStore?.byId(m.id)
    if (!detail) {
      continue
    }
    const key = dedupKey(detail)
    const arr = byKey.get(key)
    if (arr) {
      arr.push(m.id)
    } else {
      byKey.set(key, [m.id])
    }
  }

  const map = new Map()
  for (const ids of byKey.values()) {
    if (ids.length < 2) {
      continue
    }
    for (const id of ids) {
      map.set(
        id,
        ids.filter((other) => other !== id)
      )
    }
  }
  return map
})

// When a de-duped card registers a view, mark the hidden copies it stands in for as seen too.
function onCardView(id) {
  const siblings = siblingIdsById.value.get(id)
  if (siblings?.length) {
    messageStore.markSeenSiblings(siblings)
  }
}

const unseenMessages = computed(() => {
  return deDuplicatedMessages.value.filter((m) => m.unseen)
})

const seenMessages = computed(() => {
  return deDuplicatedMessages.value.filter((m) => !m.unseen)
})

const duplicates = computed(() =>
  // The items dropped by deduplication. O(n) via a Set of kept ids rather than the
  // previous O(n^2) find()-per-item scan over deDuplicatedMessages.
  findDuplicates(filteredMessagesToShow.value, deDuplicatedMessages.value)
)

const noneFound = computed(() => {
  return !props.loading && !deDuplicatedMessages.value?.length
})

// Methods
function wantMessage(m) {
  return (
    (props.selectedType === 'All' || props.selectedType === m?.type) &&
    (!props.selectedGroup ||
      parseInt(m?.groupid) === parseInt(props.selectedGroup))
  )
}

function messageNotFound(id) {
  failedIds.value = new Set([...failedIds.value, id])
}

function visibilityChanged(visible) {
  if (!visible) {
    if (!emitted.value) {
      emit('update:visible', visible)
      emitted.value = true
    }
  } else {
    emit('update:visible', visible)
  }
}

function markSeen() {
  // Mark the whole list the count is computed over (the full nearby/mygroups response),
  // not just the rendered/viewport subset — otherwise unseen posts that are off-screen or
  // filtered out keep the server count above zero and "Mark seen" can never clear it.
  const source = nearbyStore.messageList?.length
    ? nearbyStore.messageList
    : props.messagesForList
  const ids = []

  source.forEach((m) => {
    if (m.unseen) {
      ids.push(m.id)
    }
  })

  if (ids.length) {
    // Send markSeen once
    messageStore.markSeen(ids)

    // Clear the unseen flags in the cached feed immediately so these posts drop below the
    // "You're up to date" divider right away, rather than lingering above it until the
    // count-poll below refreshes the feed.
    nearbyStore.markSeen(ids)

    // Start polling the count - the server processes this in the background
    pollCount = 0
    pollUntilZero()
  }
}

function pollUntilZero() {
  if (markSeenTimer) {
    clearTimeout(markSeenTimer)
  }

  markSeenTimer = setTimeout(async () => {
    const count = await messageStore.fetchCount(
      me.value?.settings?.browseView,
      me.value?.settings?.browseMaxDistance,
      false
    )
    pollCount++

    if (count > 0 && pollCount < MAX_POLL_COUNT) {
      // Keep polling until count reaches 0 or we hit the limit
      pollUntilZero()
    } else {
      // The server has finished processing the mark-seen (count cleared, or we hit the
      // poll limit): refresh the cached feed so its `unseen` flags come from the server
      // rather than stale local state. This keeps the posts shown above the divider in
      // step with the badge - including on a second device, whose feed cache is otherwise
      // stale after the first device marked everything seen.
      await nearbyStore.fetchMessages(true)
    }
  }, 1000) // Poll once per second
}

async function handleLoadMore(currentIndex) {
  // Record the furthest feed position this session has reached (the infinite-scroll
  // index grows as the member scrolls down). The composable keeps the max and reports
  // it once on leave/hide.
  recordScrollDepth(currentIndex, reduceSuccessful.value?.length || 0)

  // Prefetch upcoming messages when scrolling.
  // ScrollGrid loads 10 items at a time, so we need to fetch at least 10 ahead.
  const batchSize = 15
  if (currentIndex + batchSize > prefetched.value) {
    const ids = []

    for (
      let i = Math.max(currentIndex, prefetched.value);
      i < reduceSuccessful.value.length && ids.length < batchSize;
      i++
    ) {
      if (wantMessage(reduceSuccessful.value[i])) {
        ids.push(reduceSuccessful.value[i].id)
      }

      prefetched.value = i + 1
    }

    if (ids.length) {
      await throttleFetches()
      await messageStore.fetchMultiple(ids)
    }
  }
}

watch(
  noneFound,
  (newVal) => {
    emit('update:none', newVal)
  },
  { immediate: true }
)

watch(
  duplicates,
  (newVal) => {
    if (me.value && newVal?.length) {
      const ids = []

      newVal.forEach((m) => {
        const message = filteredMessagesInStore.value[m.id]

        if (message?.unseen) {
          ids.push(m.id)
        }
      })

      if (ids.length) {
        messageStore.markSeen(ids)
      }
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  if (markSeenTimer) {
    clearTimeout(markSeenTimer)
  }
})
</script>
<style scoped lang="scss">
.messagewrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
}
</style>
