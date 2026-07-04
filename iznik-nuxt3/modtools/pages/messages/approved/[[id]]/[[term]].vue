<template>
  <client-only>
    <ScrollToTop :prepend="groupName" />
    <div class="d-flex justify-content-between flex-wrap">
      <ModGroupSelect
        v-model="chosengroupid"
        all
        modonly
        remember="approved"
        :url-override="urlOverride"
      />
      <ModFindMessagesFromMember @searched="searchedMember" />
      <ModFindMessage
        v-if="groupid"
        :groupid="groupid"
        :message-term="messageTerm"
        @searched="searchedMessage"
        @changed="changedMessageTerm"
      />
      <span v-else class="mt-2"> Select a community to search messages. </span>
      <ModtoolsViewControl misckey="modtoolsMessagesApprovedSummary" />
    </div>
    <div>
      <NoticeMessage v-if="loaded && !messages.length && !busy" class="mt-2">
        Nothing found. Almost always this is because the member or message
        doesn't exist (or has been very deleted).
      </NoticeMessage>
      <NoticeMessage v-else-if="!loaded" class="mt-2">
        Please wait...
      </NoticeMessage>
      <ModMessages :group="group" />
      <infinite-loading
        direction="top"
        :distance="10"
        :identifier="bump"
        @infinite="loadMore"
      >
        <template #spinner>
          <Spinner :size="50" />
        </template>
      </infinite-loading>
    </div>
  </client-only>
</template>

<script setup>
// Handles:
//  /messages/approved
//  /messages/approved/<groupid>
//  /messages/approved/<groupid>/<term>
// Once mounted:
//  - changing group changes URL - though sometimes doesn't work
//  - Email/name/id search doesn't change URL
//  - Message id/subject search changes URL <term>

import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from '#imports'
import { useMessageStore } from '@/stores/message'
import { setupModMessages } from '@/composables/useModMessages'
import { useMe } from '~/composables/useMe'

// Stores
const messageStore = useMessageStore()

// Composables
const modMessages = setupModMessages(true)
modMessages.summarykey.value = 'modtoolsMessagesApprovedSummary'
modMessages.collection.value = 'Approved'

const { me } = useMe()

// Destructure modMessages for template access
const {
  busy,
  context,
  group,
  groupid,
  show,
  collection,
  messageTerm,
  memberTerm,
  distance,
  messages,
  visibleMessages,
} = modMessages

// Local state (formerly data())
const chosengroupid = ref(0)
const bump = ref(0)
const urlOverride = ref(false)
const loaded = ref(false)
const highlightMsgId = ref(null)

// Computed properties
const id = computed(() => {
  const route = useRoute()
  if (!route) {
    console.warn(
      'messages/approved: useRoute() returned undefined (SSR hydration race)'
    )
    return 0
  }
  if ('id' in route.params && route.params.id) {
    return parseInt(route.params.id)
  }
  return 0
})

const groupName = computed(() => {
  if (group.value) {
    return group.value.namedisplay
  }
  return null
})

// Watchers
watch(chosengroupid, (newVal) => {
  const router = useRouter()
  if (newVal !== id.value) {
    nextTick(() => {
      if (newVal === 0) {
        router.push('/messages/approved/')
      } else {
        groupid.value = newVal // Sometimes route change does not work so save as groupid just in case
        router.push('/messages/approved/' + newVal)
      }
    })
  }
})

// Scroll to highlighted message when it appears in the list.
watch(visibleMessages, (newVal) => {
  if (highlightMsgId.value && newVal?.length) {
    const found = newVal.find((m) => m.id === highlightMsgId.value)
    if (found) {
      nextTick(() => {
        const el = document.getElementById('msg-' + highlightMsgId.value)
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' })
          highlightMsgId.value = null
        }
      })
    }
  }
})

// Lifecycle
onMounted(() => {
  const route = useRoute()
  groupid.value = id.value
  chosengroupid.value = id.value
  memberTerm.value = ''
  messageTerm.value = ''
  // Mark that URL explicitly set the group (even if 0 for "All").
  if (route?.params && 'id' in route.params && route.params.id !== undefined) {
    urlOverride.value = true
  }
  // Support query params from duplicate message links (?groupid=&msgid=).
  if (route.query.groupid !== undefined) {
    groupid.value = parseInt(route.query.groupid)
    chosengroupid.value = groupid.value
    urlOverride.value = true
  }
  if (route.query.msgid) {
    highlightMsgId.value = parseInt(route.query.msgid)
    messageTerm.value = route.query.msgid
  }
  if (route?.params && 'term' in route.params && route.params.term) {
    messageTerm.value = route.params.term
    highlightMsgId.value = parseInt(route.params.term)
  }
  if (messageTerm.value) {
    // Clear existing messages and reset state for fresh search.
    // Without this, the store may have old messages that get shown
    // instead of searching for the specific message from the URL.
    // listingIds must be reset too (not just the store) — it is the filter the
    // listing renders through, so a stale entry survives a store-only clear and
    // paints the wrong post (Discourse 9518/366). Matches searchedMessage().
    show.value = 0
    context.value = null
    modMessages.listingIds.value = new Set()
    modMessages.listingIdOrder.value = []
    messageStore.clear()
    bump.value++
  }
})

// Methods
function changedMessageTerm(term) {
  messageTerm.value = term.trim()
}

function searchedMessage(term) {
  const router = useRouter()
  term = term.trim()
  // Start a fresh message search from a clean slate.  The store accumulates
  // messages from other sources (notably a prior "messages from member"
  // lookup, whose historical posts are not in any search index), and the
  // listing is filtered by listingIds.  Without resetting here, those leaked
  // results stay visible during the search — mirrors searchedMember(), which
  // already resets this state.
  show.value = 0
  context.value = null
  memberTerm.value = null
  modMessages.listingIds.value = new Set()
  modMessages.listingIdOrder.value = []
  messageStore.clear()
  bump.value++
  if (term.length > 0) {
    router.push('/messages/approved/' + groupid.value + '/' + term)
  } else if (groupid.value) {
    router.push('/messages/approved/' + groupid.value)
  } else {
    router.push('/messages/approved/')
  }
}

function searchedMember(term) {
  show.value = 0
  messageTerm.value = null
  memberTerm.value = term?.trim()
  context.value = null
  // Reset the listing filter too, like searchedMessage(): otherwise a previous
  // search's ids survive in listingIds and get painted alongside/over the member
  // results (Discourse 9518/366).
  modMessages.listingIds.value = new Set()
  modMessages.listingIdOrder.value = []
  messageStore.clear()

  // Need to rerender the infinite scroll
  bump.value++
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
    // This means that we will gradually add the messages that we have fetched from the server into the DOM.
    // Doing that means that we will complete our initial render more rapidly and thus appear faster.
    show.value++
    $state.loaded()
  } else {
    const currentCount = Object.keys(messageStore.list).length

    let params

    if (messageTerm.value) {
      // Message-term search is always semantic now: it goes through the V2
      // vector search API via searchMT (the keyword toggle has been retired).
      const ids = await messageStore.searchMT({
        term: messageTerm.value,
        groupid: groupid.value,
      })
      if (ids) {
        ids.forEach((id) => modMessages.listingIds.value.add(id))
        modMessages.listingIdOrder.value = ids
      }
      show.value = messages.value.length
      $state.complete()
      // This branch returns early, so it must clear the loading state itself —
      // otherwise `loaded` never becomes true and the "Please wait..." banner
      // stays up even though the search results have rendered.
      busy.value = false
      loaded.value = true
      return
    } else if (memberTerm.value) {
      params = {
        subaction: 'searchmemb',
        search: memberTerm.value,
      }
      // Scope to the selected group when one is chosen. Omitting groupid makes the
      // backend run the name search across ALL the mod's groups via a leading-wildcard
      // fullname LIKE joined per group, which for a mod of many groups takes 20-45s and
      // leaves the spinner stuck ("whirling circle of doom", Discourse 9518/366). Scoped
      // to a single group it is ~0.2s. When "All" is selected (groupid=0) the cross-group
      // search is intentional and is bounded by the error handling below.
      if (groupid.value) {
        params.groupid = groupid.value
      }
    } else {
      params = {
        groupid: groupid.value,
        collection: collection.value,
        modtools: true,
        summary: false,
      }
    }

    params.context = context.value
    params.limit = messages.value.length + distance.value

    // Snapshot the search generation. Every search/reset (searchedMessage,
    // searchedMember, the vector-search toggle, a term arriving via the URL)
    // increments bump. If bump changes while this request is in flight, the user
    // has moved on and this response is stale.
    const gen = bump.value
    let fetchedIds
    try {
      fetchedIds = await messageStore.fetchMessagesMT(params)
    } catch (e) {
      // A slow or failed fetch (notably an all-groups member-name search the
      // backend can take 20s+ on) must not leave the infinite-scroll spinner and
      // "Please wait..." banner up forever (Discourse 9518/366). Surface
      // "Nothing found" instead of an eternal "whirling circle of doom".
      console.log('fetchMessagesMT failed', e?.message)
      $state.complete()
      busy.value = false
      loaded.value = true
      return
    }
    if (bump.value !== gen) {
      // A newer search superseded this request while it was in flight — e.g. a
      // slow all-groups member search landing ~90s late, or a prior deep-scroll
      // page load. Discard its ids so the stale response does not re-populate
      // listingIds and paint an unrelated post (the "wrong post shown" symptom,
      // Discourse 9518/366) over the current search result.
      busy.value = false
      loaded.value = true
      return
    }
    if (fetchedIds) {
      fetchedIds.forEach((id) => modMessages.listingIds.value.add(id))
    }
    context.value = messageStore.context

    const newCount = Object.keys(messageStore.list).length
    if (currentCount === newCount) {
      console.log('InfiniteScroll complete:', {
        collection: collection.value,
        groupid: groupid.value,
        currentCount,
        newCount,
        fetchedIds: fetchedIds?.length ?? 0,
        contextBefore: params.context,
        contextAfter: messageStore.context,
        limit: params.limit,
        shown: show.value,
        messagesLen: messages.value.length,
      })
      $state.complete()
    } else {
      $state.loaded()
      show.value++
    }
  }
  busy.value = false
  loaded.value = true
}
</script>
