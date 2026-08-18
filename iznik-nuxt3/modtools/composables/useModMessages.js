// Try to make Messages+Pending page as smooth as possible with correct work counts:
// - Normally the background timed update of authStore.work forces a complete page reload as detected in useModMessages watch(work)
// - The page reload happens because the message store list is updated in messageStore.fetchMessagesMT()
// To avoid this problem, when a message is approved for example:
// - the store removes the approved message from the list
// - ModMessageButton approveIt()  calls modme checkWorkDeferGetMessages()
// - This sets miscStore.deferGetMessages
// - which in turn stops useModMessages watch(work) from updating the messages list
// - until another timed update occurs

import { onScopeDispose, getCurrentScope } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '@/stores/auth'
// import { useModGroupStore } from '@/stores/modgroup'
import { useMiscStore } from '@/stores/misc'

// All values need to be reset by one caller of setupModMessages()
const summarykey = ref(false)
const busy = ref(false)
const context = ref(null)
const groupid = ref(0)
const group = ref(null)
const limit = ref(10)
const workType = ref(null)
const show = ref(0)

const collection = ref(null)
const listingIds = ref(new Set())
const listingIdOrder = ref([])
const messageTerm = ref(null)
const memberTerm = ref(null)
const nextAfterRemoved = ref(null)

const distance = ref(10)

// Holds a workdetail refresh that arrived while a modal was open (e.g. a
// moderator typing a rejection reason). The list is NOT refreshed while a modal
// is open — re-rendering would unmount the modal and lose the draft — so the
// pending refresh is parked here and applied when the modal closes.
const pendingWorkRefresh = ref(null)

// Bootstrap sets <body> overflow:hidden while a modal is open, so use that as a
// cross-component "is any modal open?" signal. The typeof guard keeps it
// SSR-safe (no document on the server). A moderator editing a Pending message
// in place (ModMessage.vue's inline Edit/Save - not a modal) sets
// miscStore.modtoolsediting instead ("do not check for work" - stores/misc.js).
// Treat that the same as a modal being open: otherwise a workdetail change
// landing while editing (or in the instant after Save flips modtoolsediting
// back off and the work-poll catches up) forces an unprotected full-list
// reload that can drop the message the moderator just saved, reappearing only
// on a manual page reload (Discourse 10001/2).
function refreshMustWait() {
  const miscStore = useMiscStore()
  return (
    (typeof document !== 'undefined' &&
      document.body?.style?.overflow === 'hidden') ||
    !!miscStore.modtoolsediting
  )
}

const summary = computed(() => {
  if (!summarykey.value) return false
  const miscStore = useMiscStore()
  const ret = miscStore.get(summarykey.value)
  return ret === undefined ? false : ret
})

// Get arrival time for the contextual group (multi-group support).
function getContextArrival(msg, contextGid) {
  if (msg.groups?.length) {
    if (contextGid) {
      const g = msg.groups.find((g) => parseInt(g.groupid) === contextGid)
      if (g) return g.arrival
    }
    return msg.groups[0].arrival
  }
  return msg.arrival
}

const messages = computed(() => {
  const messageStore = useMessageStore()
  let messages

  if (groupid.value) {
    messages = messageStore.getByGroup(groupid.value)
  } else {
    messages = messageStore.all
  }

  // Filter to only messages from the current listing request.
  // The store accumulates messages from various sources (user history,
  // crossposts, other pages) — only show ones from our listing.
  if (listingIds.value.size > 0) {
    messages = messages.filter((m) => listingIds.value.has(m.id))
  }

  // Defensive collection filter: a concurrent refetch (e.g. watch(expanded) in
  // ModMessage.vue) can re-add an approved message to the store with
  // collection='Approved' after messageStore.approve() already called remove().
  // listingIds is only reset by a full getMessages(), so the resurrected message
  // stays in listingIds and would render with the wrong buttons.  Filter it out.
  //
  // Only applies to views whose name matches a real messages_groups.collection
  // value (Pending, PendingOther, Approved, Spam, Rejected). The Edits view
  // is virtual — its messages are Approved on the group with a pending row
  // in messages_edits — so a string-equality filter would strip everything.
  const REAL_COLLECTIONS = ['Pending', 'Approved', 'Spam', 'Rejected']
  if (collection.value && REAL_COLLECTIONS.includes(collection.value)) {
    const allowed =
      collection.value === 'Pending'
        ? ['Pending', 'PendingOther', 'Spam']
        : [collection.value]
    const contextGid = groupid.value ? parseInt(groupid.value) : null
    messages = messages.filter((m) => {
      if (!m.groups?.length) return true
      if (contextGid) {
        const g = m.groups.find((g) => parseInt(g.groupid) === contextGid)
        return g ? allowed.includes(g.collection) : true
      }
      return m.groups.some((g) => allowed.includes(g.collection))
    })
  }

  // console.log('---messages groupid:', groupid.value, 'messages:', messages.length)
  if (listingIdOrder.value.length > 0) {
    // Vector search: sort by score order (position in listingIdOrder)
    const orderMap = new Map(listingIdOrder.value.map((id, i) => [id, i]))
    messages.sort((a, b) => {
      const ai = orderMap.has(a.id) ? orderMap.get(a.id) : Infinity
      const bi = orderMap.has(b.id) ? orderMap.get(b.id) : Infinity
      return ai - bi
    })
  } else {
    // Normal: sort by arrival date, newest first.
    // Use the contextual group's arrival if filtering by group (multi-group support).
    const contextGid = groupid.value ? parseInt(groupid.value) : null
    messages.sort((a, b) => {
      const arrivalA = getContextArrival(a, contextGid)
      const arrivalB = getContextArrival(b, contextGid)
      return new Date(arrivalB).getTime() - new Date(arrivalA).getTime()
    })
  }
  // console.log('###messages sort:', messages[0]?.groups[0]?.arrival)
  return messages
})

const visibleMessages = computed(() => {
  const msgs = messages.value
  // console.log('---visibleMessages', show.value, msgs?.length)
  if (show.value === 0 || !msgs || msgs.length === 0) return []
  return msgs.slice(0, show.value)
})

export function setupModMessages(reset) {
  // The refresh machinery below is registered ONLY for reset=true, i.e. for the
  // page that owns this queue. Everything here is shared module-level state, so
  // a watcher registered by a second caller is a duplicate that does the same
  // clear-then-refetch again. setupModMessages() is called by the page, by
  // ModMessages.vue, and by useKeywords.js from a MODULE-LEVEL computed that
  // re-evaluates on every group change - so the duplicates accumulated as a
  // moderator worked. Measured in production over one day: a single work-count
  // tick fired 2 identical listing requests for a moderator who used one group
  // filter, and up to 13 for one who used seven. Registering per call also
  // leaks when there is no active effect scope to dispose them.

  /* watch(group, async (newValue, oldValue) => {
    console.log("===useModMessages watch group", newValue?.id, oldValue?.id, groupid.value)
    // We have this watch because we may need to fetch a group that we have remembered.  The mounted()
    // call may happen before we have restored the persisted state, so we can't initiate the fetch there.
    if (!oldValue || oldValue.id !== groupid.value) {
      const modGroupStore = useModGroupStore()
      await modGroupStore.get(groupid.value)
    }
  }) */

  // CAREFUL: All refs are remembered from the previous page so one caller has to reset all unused ref
  if (reset) {
    summarykey.value = false
    busy.value = false
    context.value = null
    groupid.value = 0
    group.value = null
    limit.value = 10
    workType.value = null
    show.value = 0
    listingIds.value = new Set()
    listingIdOrder.value = []

    collection.value = null
    messageTerm.value = null
    memberTerm.value = null
    nextAfterRemoved.value = null

    distance.value = 10
  }

  const getMessages = async (workdetail) => {
    // console.log('<><><> getMessages', collection.value, groupid.value, workdetail)

    const messageStore = useMessageStore()
    messageStore.clearContext()
    context.value = null

    const params = {
      groupid: groupid.value,
      collection: collection.value, // Pending also gets PendingOther
      modtools: true,
      summary: false,
      // limit: Math.max(limit.value, newVal)
    }
    if (workdetail && workdetail.total) {
      params.limit = Math.max(limit.value, workdetail.total)
    }
    // console.log('uMM getMessages',params.limit)
    // params.debug = 'uMM getMessages',
    messageStore.clear()
    listingIds.value = new Set()
    let fetchedIds
    try {
      fetchedIds = await messageStore.fetchMessagesMT(params)
    } catch (e) {
      if (e?.response?.status === 401) {
        // Session expired — BaseAPI already cleared auth state.
        // The layout's loginStateKnown watcher will show the login modal.
        show.value = 0
        return
      }
      throw e
    }
    if (fetchedIds) {
      fetchedIds.forEach((id) => listingIds.value.add(id))
    }

    // Sync pagination context so loadMore() continues from where getMessages() left off.
    context.value = messageStore.context

    // Force them to show.
    let msgs

    if (groupid.value) {
      msgs = messageStore.getByGroup(groupid.value)
    } else {
      msgs = messageStore.all
    }

    // Filter to listing IDs only.
    if (listingIds.value.size > 0) {
      msgs = msgs.filter((m) => listingIds.value.has(m.id))
    }

    show.value = msgs.length
  }

  const work = computed(() => {
    // Count for the type of work we're interested in.
    try {
      const authStore = useAuthStore()
      const work = authStore.work
      if (!work) return 0
      if (!workType.value) return 0
      if (Array.isArray(workType.value)) {
        let count = 0
        for (const worktype of workType.value) {
          count += work[worktype]
        }
        return count
      }
      return work[workType.value]
    } catch (e) {
      console.log('>>>>useModMessages work exception', e.message)
      return 0
    }
  })

  const workdetail = computed(() => {
    // console.log('uMM workdetail',workType.value)
    const ret = {}
    try {
      const authStore = useAuthStore()
      const work = authStore.work
      if (!work) return ret
      if (!workType.value) return ret
      ret.total = 0
      if (Array.isArray(workType.value)) {
        for (const worktype of workType.value) {
          ret[worktype] = work[worktype]
          ret.total += work[worktype]
        }
      } else {
        ret[workType.value] = work[workType.value]
        ret.total += work[workType.value]
      }
      // console.log('uMM workdetail',ret)
      return ret
    } catch (e) {
      console.log('>>>>useModMessages workdetail exception', e.message)
      return {}
    }
  })

  if (reset) {
    watch(workdetail, (newVal, oldVal) => {
      if (JSON.stringify(oldVal) === JSON.stringify(newVal)) return // Not actually changed

      const miscStore = useMiscStore()

      // When the work total INCREASES, genuinely new work has arrived (e.g. a new
      // pending message). The list must refresh to show it - otherwise the count /
      // red alert updates but the message stays invisible until a manual reload
      // (Discourse #9737).
      const newTotal = Number(newVal?.total ?? 0)
      const oldTotal = Number(oldVal?.total ?? 0)
      if (newTotal > oldTotal) {
        // ...but NOT while a modal is open, or a message is being edited in
        // place. Refreshing re-renders the message list, which unmounts an
        // open modal (e.g. a moderator part-way through typing a rejection
        // reason) - or the ModMessage a moderator is editing - and loses their
        // draft. Park the refresh and apply it when the modal closes / editing
        // finishes (see the observers below), so the new message still appears
        // without a manual reload.
        if (refreshMustWait()) {
          pendingWorkRefresh.value = newVal
          return
        }
        getMessages(newVal)
        return
      }

      // No new work (count unchanged or decreased by a mod action the component
      // already handled): keep the existing suppression so the list does not
      // reload under the user's feet.
      if (miscStore.deferGetMessages) return
      if (refreshMustWait()) {
        // Park it, exactly as the total-increased branch above does. Do NOT drop
        // it: another moderator holding a message moves it from `pending` to
        // `pendingother` (see groupWork.go), so the total never changes and this
        // branch is the ONLY one that can surface a hold. Dropping it lost the
        // hold permanently — the watcher's oldVal advances, so every later tick
        // compares equal and early-returns — leaving the other moderator looking
        // at a card with no "Held" banner until they manually reloaded. They
        // moderated the post out from under the holding mod (Discourse #9946).
        pendingWorkRefresh.value = newVal
        return
      }
      getMessages(newVal)
    })

    // Apply a refresh that was deferred because a modal was open, as soon as the
    // modal closes. Bootstrap toggles <body> overflow:hidden around modals, so we
    // observe that attribute; when it clears and a refresh is pending, run it.
    // This keeps an open rejection modal (and its draft) intact during editing
    // while still surfacing the new pending message the moment it is dismissed.
    if (
      typeof document !== 'undefined' &&
      typeof MutationObserver !== 'undefined'
    ) {
      const bodyOverflowObserver = new MutationObserver(() => {
        if (pendingWorkRefresh.value && !refreshMustWait()) {
          const deferred = pendingWorkRefresh.value
          pendingWorkRefresh.value = null
          getMessages(deferred)
        }
      })
      bodyOverflowObserver.observe(document.body, {
        attributes: true,
        attributeFilter: ['style'],
      })
      if (getCurrentScope()) {
        onScopeDispose(() => bodyOverflowObserver.disconnect())
      }
    }

    // Apply a refresh that was deferred because a message was being edited in
    // place, as soon as editing finishes (mirrors the modal-close observer
    // above). ModMessage.vue's save()/cancelEdit() clear modtoolsediting.
    watch(
      () => useMiscStore().modtoolsediting,
      (editing) => {
        if (!editing && pendingWorkRefresh.value && !refreshMustWait()) {
          const deferred = pendingWorkRefresh.value
          pendingWorkRefresh.value = null
          getMessages(deferred)
        }
      }
    )
  }

  return {
    busy,
    context,
    group,
    groupid,
    limit,
    workType,
    show,
    collection,
    messageTerm,
    memberTerm,
    nextAfterRemoved,
    distance,
    summarykey,
    summary,
    messages,
    listingIds,
    listingIdOrder,
    visibleMessages,
    work,
    getMessages,
  }
}
