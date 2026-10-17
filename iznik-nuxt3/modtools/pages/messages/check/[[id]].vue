<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpCheck />
      <div class="d-flex justify-content-between">
        <!-- Only a community in the trial has a Check queue, so only those are offered. -->
        <ModGroupSelect
          v-model="groupid"
          all
          modonly
          :remember="REMEMBER_KEY"
          :url-override="urlOverride"
          :only-groupids="trialGroupIds"
        />
        <div class="d-flex">
          <ModtoolsViewControl :misckey="summaryKey" />
          <b-button
            variant="primary"
            size="sm"
            class="ms-2 mt-2"
            data-testid="mark-these-checked"
            :disabled="marking || !messages.length || modsHeld"
            @click="askMarkAll"
          >
            {{ marking ? 'Marking…' : 'Mark these as checked' }}
          </b-button>
        </div>
      </div>
      <NoticeMessage
        v-if="!messages.length && !busy && groupsreceived"
        class="mt-2"
      >
        <template v-if="filterHidingWork">
          <p>
            Nothing to check in {{ groupname }}, but
            {{
              outstanding === 1
                ? 'there is 1 waiting'
                : `there are ${outstanding} waiting`
            }}
            across your communities. The dropdown above is filtering this page
            to one community.
          </p>
          <b-button
            variant="primary"
            data-testid="show-all-communities"
            @click="showAllCommunities"
          >
            Show all my communities
          </b-button>
        </template>
        <template v-else>
          Nothing to check. These are posts that went live by themselves from
          auto-moderated members. Once you have checked them they drop off here,
          and anything older than a week is left out of this queue.
        </template>
      </NoticeMessage>
      <!-- Saying "I have looked at all of these" is one click, so it asks first, and names how
           many. The posts named are the ones marked, even if the list changes meanwhile. -->
      <ConfirmModal
        v-if="showMarkConfirm"
        :title="markTitle"
        confirm-label="Mark as checked"
        @confirm="markAllChecked(markIds)"
        @hidden="showMarkConfirm = false"
      >
        <p class="mb-0">
          This says you have looked at every post listed here. They leave the
          Check queue, and they are allowed to spread to more communities, which
          a post nobody has looked at is not.
        </p>
      </ConfirmModal>
      <div v-if="groupsreceived">
        <ModMessages :oversight="true" />
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
// The Check queue: messages that have already gone live via the automated
// content checks, that is auto-approved posts from auto-moderated (NULL posting
// status) members. Posts still inside their wait appear under Pending, where a
// moderator can stop them before they go live.
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from '#imports'
import { setupModMessages } from '~/composables/useModMessages'
import { useAuthStore } from '@/stores/auth'
import { useMessageStore } from '@/stores/message'
import { useMiscStore } from '@/stores/misc'
import { useModGroupStore } from '@/stores/modgroup'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/composables/useModMe'
import { useLockdown } from '~/modtools/composables/useLockdown'
import { useAutoapproveTrial } from '~/modtools/composables/useAutoapproveTrial'

const authStore = useAuthStore()
const messageStore = useMessageStore()
const miscStore = useMiscStore()
const modGroupStore = useModGroupStore()
const route = useRoute()
const { checkWork } = useModMe()
const { modsHeld } = useLockdown()
const { trialGroupIds } = useAutoapproveTrial()

// ModGroupSelect stores the chosen community under 'groupselect-' + remember. We need the
// same key to forget it again - see showAllCommunities().
const REMEMBER_KEY = 'check'
const FILTER = 'checked'
const summaryKey = 'modtoolsMessagesCheckSummary'
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
const marking = ref(false)
const showMarkConfirm = ref(false)
const markIds = ref([])

const groupname = computed(() => group.value?.namedisplay || 'this community')

// The menu badge counts the Check work of every community of mine, and the choice of
// community is remembered. When the two disagree the moderator is left staring at an empty
// page while the badge nags them, with nothing on screen to say why: the same trap the
// Pending page guards against.
const outstanding = computed(() => authStore.work?.checked || 0)
const filterHidingWork = computed(
  () => Boolean(groupid.value) && outstanding.value > 0
)

// Clear the remembered community as well as the current filter. Only a choice made through
// the dropdown goes through ModGroupSelect's setter, so without this the filter would come
// straight back on the next visit.
function showAllCommunities() {
  miscStore.set({ key: 'groupselect-' + REMEMBER_KEY, value: 0 })
  groupid.value = 0
}

const markTitle = computed(
  () =>
    `Mark ${markIds.value.length} post${markIds.value.length === 1 ? '' : 's'} as checked?`
)

// Ask first, naming the posts on screen now.
function askMarkAll() {
  markIds.value = messages.value.map((m) => m.id)
  showMarkConfirm.value = true
}

// Mark the posts on screen as checked, then refresh. Only the loaded ones: the list is
// paged, and a stamp says a moderator looked at the post (it leaves Check, it lifts the
// earned-reach hold, and a neighbour's Back to Pending leaves it alone), so it must never
// land on a post further down the queue that nobody has seen.
async function markAllChecked(ids = messages.value.map((m) => m.id)) {
  marking.value = true
  try {
    await messageStore.markChecked({
      groupid: groupid.value,
      ids,
    })
    show.value = 0
    context.value = null
    listingIds.value = new Set()
    messageStore.clear()
    bump.value++
    await checkWork(true)
  } finally {
    marking.value = false
  }
}

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
        newVal === 0 ? '/messages/check/' : '/messages/check/' + newVal
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
  marking,
  markAllChecked,
  askMarkAll,
  showAllCommunities,
  loadMore,
})
</script>
