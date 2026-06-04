<template>
  <div>
    <client-only>
      <ScrollToTop />
      <div class="d-flex justify-content-between">
        <ModGroupSelect
          v-model="groupid"
          all
          modonly
          remember="trusted"
          :url-override="urlOverride"
        />
        <ModtoolsViewControl :misckey="summaryKey" />
      </div>
      <NoticeMessage
        v-if="!messages.length && !busy && groupsreceived"
        class="mt-2"
      >
        No posts from trusted members have gone live recently. These are posts
        from members on Group Settings that publish without moderation.
      </NoticeMessage>
      <div v-if="groupsreceived">
        <ModMessages />
        <infinite-loading
          direction="top"
          :identifier="bump"
          @infinite="loadMore"
        >
          <template #spinner>
            <Spinner :size="50" />
          </template>
        </infinite-loading>
      </div>
      <NoticeMessage v-else class="mt-2"> Please wait... </NoticeMessage>
    </client-only>
  </div>
</template>

<script setup>
// Oversight list of messages that went live without moderation from trusted
// (group-settings / DEFAULT posting status) members.
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from '#imports'
import { setupModMessages } from '~/composables/useModMessages'
import { useMessageStore } from '@/stores/message'
import { useMiscStore } from '@/stores/misc'
import { useModGroupStore } from '@/stores/modgroup'
import { useMe } from '~/composables/useMe'

const messageStore = useMessageStore()
const miscStore = useMiscStore()
const modGroupStore = useModGroupStore()
const route = useRoute()

const FILTER = 'trusted'
const summaryKey = 'modtoolsMessagesTrustedSummary'
// Oversight lists default to the compact summary view.
if (miscStore.get(summaryKey) === undefined) {
  miscStore.set({ key: summaryKey, value: true })
}

const {
  busy,
  context,
  group,
  groupid,
  show,
  collection,
  distance,
  summarykey,
  messages,
  listingIds,
} = setupModMessages(true)

summarykey.value = summaryKey
collection.value = 'Approved'

const { me } = useMe()

const id = computed(() =>
  'id' in route.params && route.params.id ? parseInt(route.params.id) : 0
)
const groupsreceived = computed(() => modGroupStore.received)

const bump = ref(0)
const urlOverride = ref(false)

watch(groupid, async (newVal) => {
  const router = useRouter()
  context.value = null
  await modGroupStore.fetchIfNeedBeMT(newVal)
  group.value = modGroupStore.get(newVal)
  show.value = 0
  bump.value++
  if (newVal !== id.value) {
    nextTick(() => {
      router.push(
        newVal === 0 ? '/messages/trusted/' : '/messages/trusted/' + newVal
      )
    })
  }
})

onMounted(() => {
  if (id.value) {
    groupid.value = id.value
    urlOverride.value = true
  }
  // Start from a clean slate so we don't show messages cached by another view.
  show.value = 0
  context.value = null
  listingIds.value = new Set()
  messageStore.clear()
  bump.value++
})

async function loadMore($state) {
  busy.value = true

  if (!me.value) {
    $state.loaded()
    busy.value = false
    return
  } else if (show.value < messages.value.length) {
    show.value = messages.value.length
    $state.loaded()
  } else {
    const currentCount = Object.keys(messageStore.list).length

    const params = {
      groupid: groupid.value,
      collection: collection.value,
      filter: FILTER,
      modtools: true,
      summary: false,
      context: context.value,
      limit: messages.value.length + distance.value,
    }

    const fetchedIds = await messageStore.fetchMessagesMT(params)
    if (fetchedIds) {
      fetchedIds.forEach((mid) => listingIds.value.add(mid))
    }
    context.value = messageStore.context

    const newCount = Object.keys(messageStore.list).length
    if (currentCount === newCount) {
      $state.complete()
    } else {
      $state.loaded()
      show.value++
    }
  }
  busy.value = false
}

defineExpose({
  bump,
  urlOverride,
  id,
  groupsreceived,
  me,
  busy,
  messages,
  groupid,
  loadMore,
})
</script>
