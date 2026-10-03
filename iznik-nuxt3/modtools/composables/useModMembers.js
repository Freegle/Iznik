// Simplified from MT2 mixin/modMembersPage
//
// Self-moderating rework: /modtools/members (ModMembersAPI.js's fetch()) has
// no cursor/paging concept - it takes filter ('new'|'flagged'|'banned'|
// 'search'), q (search term) and since (hours, new/flagged only) and returns
// a flat set. There is no "next page" to ask for. context/collection below
// are legacy MT2 leftovers (member.js's store has no context state to feed
// them - context.value was already always undefined before this rework) and
// are kept only because related.vue/feedback.vue still read them; those two
// pages piggy-backed on a per-group "collection" query model that no longer
// exists server-side and need their own dedicated rework (flagged separately,
// not attempted here).

import { useMemberStore } from '~/modtools/stores/member'

const bump = ref(0)
const busy = ref(false)
const context = ref(null)
const limit = ref(10)
const search = ref('')
const filter = ref('new')
const since = ref(null)
const show = ref(0)
const sort = ref(true)

const collection = ref(null)
const messageTerm = ref(null)
const memberTerm = ref(null)
const nextAfterRemoved = ref(null)

const distance = ref(10)

const members = computed(() => {
  // console.log('UMM members', bump.value)
  const memberStore = useMemberStore()
  const members = Object.values(memberStore.list)
  if (!members) {
    return []
  }
  // We need to sort as otherwise new members may appear at the end.
  if (sort.value) {
    members.sort((a, b) => {
      return (
        new Date(b.added || b.joined).getTime() -
        new Date(a.added || a.joined).getTime()
      )
    })
  } else {
    members.sort((a, b) => {
      return a.rawindex - b.rawindex
    })
  }

  // console.log('UMM members sorted', members.length)
  // for( const member of members){
  //  console.log('UMM', member)
  // }
  return members
})

const visibleMembers = computed(() => {
  const mbrs = members.value
  // console.log('UMM visibleMembers', show.value, mbrs?.length)
  if (show.value === 0 || !mbrs || mbrs.length === 0) return []
  return mbrs.slice(0, show.value)
})

const loadMore = async function ($state) {
  if (show.value < members.value.length) {
    // We already have more fetched than shown - just reveal more of it.
    show.value = Math.min(show.value + 20, members.value.length)
    $state.loaded()
  } else {
    // Ask the server for this filter's set. There's no cursor to advance -
    // /modtools/members always returns the same set for the same
    // filter/q/since, so a repeat call that adds nothing new means we're
    // done (handled below), not that there's a further page to request.
    const membersstart = members.value.length
    const memberStore = useMemberStore()
    const params = {
      filter: filter.value,
      q: search.value || undefined,
    }
    if (
      (filter.value === 'new' || filter.value === 'flagged') &&
      since.value
    ) {
      params.since = since.value
    }
    await memberStore.fetchMembers(params)

    if (show.value < members.value.length) {
      show.value = Math.min(show.value + 20, members.value.length)
    }
    if (show.value > members.value.length) {
      show.value = members.value.length
    }

    if (membersstart === members.value.length) {
      $state.complete()
    } else {
      $state.loaded()
    }
  }
}

export function setupModMembers(reset) {
  // CAREFUL: All refs are remembered from the previous page so one caller has to reset all unused ref
  if (reset) {
    bump.value = 0
    busy.value = false
    context.value = null
    limit.value = 10
    search.value = ''
    filter.value = 'new'
    since.value = null
    show.value = 0
    sort.value = true

    collection.value = null
    messageTerm.value = null
    memberTerm.value = null
    nextAfterRemoved.value = null

    distance.value = 10
  }

  return {
    bump,
    busy,
    context,
    limit,
    search,
    filter,
    since,
    show,
    sort,
    collection,
    messageTerm,
    memberTerm,
    nextAfterRemoved,
    distance,
    members,
    visibleMembers,
    loadMore,
  }
}
