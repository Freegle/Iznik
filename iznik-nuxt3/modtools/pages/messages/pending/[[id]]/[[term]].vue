<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModAimsModal
        v-if="showAimsModal"
        ref="showAimsModal"
        @hidden="showAimsModal = false"
      />
      <!--      <ModFreeStock class="mb-2" />-->
      <!--NoticeMessage variant="info" class="mb-2 d-block d-md-none">
        <ModZoomStock color-class="text-black" />
      </NoticeMessage-->
      <div class="d-flex justify-content-between">
        <ModGroupSelect
          v-model="groupid"
          all
          modonly
          :work="['pending', 'pendingother']"
          :remember="REMEMBER_KEY"
          :url-override="urlOverride"
        />
        <ModtoolsViewControl misckey="modtoolsMessagesPendingSummary" />
        <b-button variant="link" @click="loadAll"> Load all </b-button>
      </div>
      <NoticeMessage v-if="loadError" variant="warning" class="mt-2">
        <p>We couldn't fetch the pending messages just now.</p>
        <b-button variant="primary" @click="retryLoad"> Try again </b-button>
      </NoticeMessage>
      <NoticeMessage
        v-else-if="!messages.length && !busy && groupsreceived"
        class="mt-2"
      >
        <template v-if="filterHidingWork">
          <p>
            There are no pending messages in {{ groupname }}, but
            {{
              outstanding === 1
                ? 'there is 1 waiting'
                : `there are ${outstanding} waiting`
            }}
            across your communities. The dropdown above is filtering this page
            to one community.
          </p>
          <b-button variant="primary" @click="showAllCommunities">
            Show all my communities
          </b-button>
        </template>
        <template v-else>
          There are no messages at the moment. This will refresh automatically.
        </template>
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

      <!--ModRulesModal v-if="rulesGroup" ref="rules" /-->

      <div ref="end" />
    </client-only>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import dayjs from 'dayjs'
import { useRoute, useRouter } from '#imports'
import { setupModMessages } from '~/composables/useModMessages'
import { useAuthStore } from '@/stores/auth'
import { useMessageStore } from '@/stores/message'
import { useMiscStore } from '@/stores/misc'
import { useModGroupStore } from '@/stores/modgroup'
import { useMe } from '~/composables/useMe'

const authStore = useAuthStore()
const messageStore = useMessageStore()
const miscStore = useMiscStore()
const modGroupStore = useModGroupStore()
const route = useRoute()

const {
  busy,
  context,
  group,
  groupid,
  limit,
  workType,
  show,
  collection,
  distance,
  summarykey,
  messages,
  listingIds,
  visibleMessages,
  nextAfterRemoved,
  getMessages,
} = setupModMessages(true)

// ModGroupSelect stores the chosen community under 'groupselect-' + remember.
// We need the same key to forget it again - see showAllCommunities().
const REMEMBER_KEY = 'pending'

summarykey.value = 'modtoolsMessagesPendingSummary'
collection.value = 'Pending' // Pending also gets PendingOther
workType.value = ['pending', 'pendingother']

const { me, myGroups } = useMe()

// Computed - route params
const id = computed(() => {
  if ('id' in route.params && route.params.id) {
    return parseInt(route.params.id)
  }
  return 0
})

// Data
const showAimsModal = ref(false)
const shownRulePopup = ref(false)
const bump = ref(0)
const highlightMsgId = ref(null)
const urlOverride = ref(false)
const loadError = ref(false)

// Template refs
const end = ref(null)
const rules = ref(null)

// Computed
const groups = computed(() => {
  return Object.values(modGroupStore.list)
})

const groupsreceived = computed(() => {
  return modGroupStore.received
})

const groupname = computed(() => {
  return group.value?.namedisplay || 'this community'
})

// The Pending badge in the menu counts work across every community we
// moderate, but this page shows only the community picked in the dropdown -
// and that choice is remembered in local storage and re-applied on every
// visit. When the two disagree the moderator is left staring at an empty
// page while the badge nags them, with nothing on screen to say why
// (Discourse 10037: two moderators reported it as "can't moderate on
// desktop"; one worked around it by changing community and changing back).
// Count what this queue actually holds, which is not the same as the composable's
// `work`: workType here is pending+pendingother, but the listing also includes
// Spam-collection messages (see message_list.go) and the menu badge counts those
// too (layouts/default.vue passes count=['pending','spam']). Leaving spam out
// would make the notice stay silent in exactly the case where the badge is
// loudest about spam sitting on another community.
const outstanding = computed(() => {
  const w = authStore.work
  if (!w) return 0
  return ['pending', 'pendingother', 'spam'].reduce(
    (total, type) => total + (w[type] || 0),
    0
  )
})

const filterHidingWork = computed(() => {
  return Boolean(groupid.value) && outstanding.value > 0
})

const rulesGroup = computed(() => {
  if (!modGroupStore.received) return null
  let ret = null
  const mygroupsList = myGroups.value // myGroups has correct role
  for (const groupItem of groups.value) {
    const mygroup = mygroupsList.find((g) => g.id === groupItem.id)
    const groupRules = groupItem.rules || null
    const missingRules = groupItem.rules
      ? [
          'limitgroups',
          'wastecarrier',
          'carboot',
          'chineselanterns',
          'carseats',
          'pondlife',
          'copyright',
          'porn',
        ].filter((rule) => !Object.keys(groupRules).includes(rule))
      : null

    if (
      groupItem.type === 'Freegle' &&
      mygroup?.role === 'Owner' &&
      groupItem.publish &&
      (!groupItem.rules || (missingRules && missingRules.length))
    ) {
      ret = groupItem.id
      break
    }
  }
  return ret
})

// Watch for rulesGroup changes and show popup when needed
watch(rulesGroup, (newVal) => {
  if (newVal && !shownRulePopup.value) {
    shownRulePopup.value = true
    rules.value?.show()
  }
})

// Watchers
watch(groupid, async (newVal) => {
  const router = useRouter()
  context.value = null
  await modGroupStore.fetchIfNeedBeMT(newVal)
  group.value = modGroupStore.get(newVal)
  show.value = 0
  bump.value++

  // Keep URL in sync with selected group.
  if (newVal !== id.value) {
    nextTick(() => {
      if (newVal === 0) {
        router.push('/messages/pending/')
      } else {
        router.push('/messages/pending/' + newVal)
      }
    })
  }
})

watch(visibleMessages, (newVal) => {
  // Scroll to highlighted message when it appears in the list.
  if (highlightMsgId.value && newVal?.length) {
    const found = newVal.find((m) => m.id === highlightMsgId.value)
    if (found) {
      nextTick(() => {
        const el = document.getElementById('msg-' + highlightMsgId.value)
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' })
          // Clear highlight so we don't scroll again on future updates.
          highlightMsgId.value = null
        }
      })
    }
  }
})

// Lifecycle
onMounted(async () => {
  // Read group from route params (e.g. /messages/pending/456).
  if (id.value) {
    groupid.value = id.value
    urlOverride.value = true
  }
  // Read message highlight from route term (e.g. /messages/pending/456/123).
  if ('term' in route.params && route.params.term) {
    highlightMsgId.value = parseInt(route.params.term)
  }

  // AIMS
  const user = authStore.user
  const lastaimsshow = user?.settings?.lastaimsshow

  if (!lastaimsshow || dayjs().diff(dayjs(lastaimsshow), 'days') > 365) {
    showAimsModal.value = true

    const settings = user.settings
    settings.lastaimsshow = dayjs().toISOString()
    await authStore.saveAndGet({
      settings,
    })
  }

  // Note: Don't restore remembered group here - ModGroupSelect handles it
  // via its remember prop. Doing it here would override URL params.
})

// loadMore() calls $state.complete() when a fetch comes back empty, and
// InfiniteLoading stops its retry loop for good once complete - only a change
// to :identifier revives it. So one empty or failed fetch leaves the list dead
// until the moderator touches the group dropdown (which bumps it via
// watch(groupid) above). If the work count says there is something to show and
// we are showing nothing, the loader is wrong: bump it so it tries again.
// Guarded on an empty list so a healthy page does not refetch on every tick.
watch(outstanding, (newVal) => {
  if (newVal > 0 && !messages.value.length && !busy.value) {
    bump.value++
  }
})

// Methods
function retryLoad() {
  loadError.value = false
  bump.value++
}

function showAllCommunities() {
  // Clear the remembered community as well as the current filter. Only a
  // choice made through the dropdown goes through ModGroupSelect's setter,
  // so without this the filter would come straight back on the next visit.
  miscStore.set({ key: 'groupselect-' + REMEMBER_KEY, value: 0 })
  groupid.value = 0
}

async function loadAll() {
  // This is a bit of a hack - we clear the store and fetch 1000 messages, which is likely to be all of them.
  limit.value = 1000
  await getMessages()

  end.value?.scrollIntoView()
}

function destroy(oldid, nextid) {
  nextAfterRemoved.value = nextid
}

async function loadMore($state) {
  busy.value = true

  if (!me.value) {
    // Auth hasn't hydrated yet — don't call complete() as that permanently
    // stops the observer. Call loaded() and let it retry on next scroll.
    $state.loaded()
    busy.value = false
    return
  } else if (show.value < messages.value.length) {
    // Show all fetched messages at once.  Previously we incremented by 1, but that caused the
    // InfiniteLoading loader div to scroll below the viewport after ~9 messages, making
    // visible=false and stopping further progressive reveal.
    show.value = messages.value.length
    $state.loaded()
  } else {
    const params = {
      groupid: groupid.value,
      collection: collection.value,
      modtools: true,
      summary: false,
      context: context.value,
      limit: messages.value.length + distance.value,
    }

    let fetchedIds
    try {
      fetchedIds = await messageStore.fetchMessagesMT(params)
      loadError.value = false
    } catch (e) {
      busy.value = false

      if (e?.response?.status === 401) {
        // Session expired. BaseAPI has already cleared auth state and the
        // layout will put up the login modal, so say nothing here.
        $state.complete()
        return
      }

      // Say so rather than leaving a spinner. The exception used to escape
      // this function, which left InfiniteLoading stuck in 'loading' and busy
      // stuck true - and busy true suppresses every notice below, so the page
      // looked like it was still working when it had given up.
      loadError.value = true
      $state.complete()
      return
    }
    if (fetchedIds) {
      fetchedIds.forEach((id) => listingIds.value.add(id))
    }
    context.value = messageStore.context

    // Whether there is more to fetch is a property of THIS request/response,
    // not of the shared cross-group messageStore.list. Rippled/cross-posted
    // messages mean a page's ids are often already keys in that store (e.g.
    // fetched earlier for a sibling group this session), so comparing its
    // size before/after used to declare "complete" on a page that was
    // genuinely non-empty and had more pages after it (Discourse 9954/5) -
    // a moderator scrolling one group's queue could have the scroll silently
    // stop long before reaching an older, still-live message.
    if (!fetchedIds || fetchedIds.length === 0) {
      $state.complete()
    } else if (!context.value) {
      // Backend returned fewer than a full page, so this genuinely was the
      // last batch. Reveal it before stopping - otherwise the newest fetch
      // stays hidden with no further scroll trigger to show it.
      show.value = messages.value.length
      $state.complete()
    } else {
      $state.loaded()
      show.value++
    }
  }
  busy.value = false
}

// Expose for template and tests
defineExpose({
  showAimsModal,
  bump,
  highlightMsgId,
  urlOverride,
  loadError,
  retryLoad,
  id,
  groups,
  groupsreceived,
  groupname,
  outstanding,
  filterHidingWork,
  rulesGroup,
  me,
  myGroups,
  busy,
  messages,
  groupid,
  limit,
  showAllCommunities,
  loadAll,
  destroy,
  loadMore,
})
</script>
