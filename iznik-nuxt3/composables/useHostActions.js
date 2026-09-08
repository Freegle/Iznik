// What the browser does when Freegle asks it to. The service is the brain; the browser
// is the hands, using the stores that already exist: the compose store to post, the
// location store to resolve a postcode, the user store to check an email, the message
// store for matches, nearby and search, the group store for communities.
import { useAssistantStore } from '~/stores/assistant'
import { useComposeStore } from '~/stores/compose'
import { useLocationStore } from '~/stores/location'
import { useUserStore } from '~/stores/user'
import { useMessageStore } from '~/stores/message'
import { useGroupStore } from '~/stores/group'
import { useAuthStore } from '~/stores/auth'
import { milesAway } from '~/composables/useDistance'
import { trackConversion } from '~/composables/useTrackConversion'

export function useHostActions() {
  const assistant = useAssistantStore()
  const composeStore = useComposeStore()
  const locationStore = useLocationStore()
  const userStore = useUserStore()
  const messageStore = useMessageStore()
  const groupStore = useGroupStore()
  const authStore = useAuthStore()
  const router = useRouter()

  const me = computed(() => authStore.user)

  // Where the member is, for matches, nearby and communities: the postcode they gave
  // in this conversation, else their saved location.
  function myLatLng() {
    const pc = assistant.slots?.postcodeLatLng
    if (pc?.lat && pc?.lng) return pc
    const loc = me.value?.settings?.mylocation
    if (loc?.lat && loc?.lng) return { lat: loc.lat, lng: loc.lng }
    if (me.value?.lat && me.value?.lng) return { lat: me.value.lat, lng: me.value.lng }
    return null
  }

  async function lookupPostcode(text) {
    const results = await locationStore.typeahead(text)
    const pc = results?.find?.((r) => r.id) || results?.[0]
    if (!pc?.id) {
      return assistant.sendEvent({ type: 'postcode_not_found' }, null)
    }
    await postcodeChosen(pc)
  }

  async function postcodeChosen(pc) {
    // Same as the give pages: the compose store keeps a trimmed copy and the group is
    // derived from it.
    composeStore.setPostcode(pc)
    if (pc.groupsnear?.length) composeStore.group = pc.groupsnear[0].id
    assistant.slots.postcodeLatLng = { lat: pc.lat, lng: pc.lng }
    const community = pc.groupsnear?.[0]?.namedisplay || null
    return assistant.sendEvent(
      { type: 'postcode_confirmed', postcode: pc.name, name: pc.area?.name || pc.name, community },
      pc.name
    )
  }

  async function checkEmail(email) {
    const inuse = await userStore.emailIsInUse(email)
    if (inuse && !me.value) {
      return assistant.sendEvent({ type: 'email_in_use', email }, email)
    }
    composeStore.email = email
    return assistant.sendEvent({ type: 'email_confirmed', email }, email)
  }

  // Post through the compose store, exactly as the give and ask pages do.
  async function createPost(postType, slots) {
    const id = composeStore.add()
    composeStore.setType({ id, type: postType })
    composeStore.setItem({ id, item: slots.item })
    composeStore.setDescription({ id, description: slots.description || '' })
    if (postType === 'Offer') composeStore.setAvailableNow(id, slots.quantity || 1)
    if (slots.delivery) composeStore.setDeliveryPossible(id, true)
    for (const attid of slots.attachments || []) {
      const att = assistant.photos?.find?.((p) => p.id === attid)
      composeStore.addAttachment({ id, attachment: att || { id: attid } })
    }
    if (slots.email && !composeStore.email) composeStore.email = slots.email
    if (!composeStore.postcode?.id && me.value?.settings?.mylocation?.id) {
      composeStore.setPostcode(me.value.settings.mylocation)
      const near = me.value.settings.mylocation.groupsnear
      if (near?.length) composeStore.group = near[0].id
    }
    try {
      const results = await composeStore.submit({ type: postType })
      const first = results?.[0]
      let newuser = false
      if (first?.newuser) {
        newuser = true
        await authStore.login({ email: composeStore.email, password: first.newpassword })
        if (composeStore.postcode?.id) {
          const settings = authStore.user?.settings || {}
          settings.mylocation = composeStore.postcode
          await authStore.saveAndGet({ settings })
        }
        trackConversion('Register with Website')
      }
      trackConversion(postType === 'Offer' ? 'Give an Item' : 'Find an Item')
      let pending = false
      let community = null
      if (first?.id) {
        const msg = await messageStore.fetch(first.id, true)
        pending = !!msg?.groups?.some((g) => g.collection === 'Pending')
        community = msg?.groups?.[0]?.namedisplay || groupStore.get(first.groupid)?.namedisplay || null
      }
      assistant.lastPosted = { id: first?.id, type: postType }
      return assistant.sendEvent({ type: 'posted', msgid: first?.id, community, pending, newuser }, null)
    } catch (e) {
      console.error('Posting from chat failed', e)
      const reason = e?.response?.status === 403 ? 'not allowed' : 'failed'
      return assistant.sendEvent({ type: 'post_failed', reason }, null)
    }
  }

  async function findMatches(item) {
    const at = myLatLng()
    if (!at) return assistant.sendEvent({ type: 'matches', matches: [] }, null)
    let list = []
    try {
      list = (await messageStore.matches(item, at.lat, at.lng, 3)) || []
    } catch (e) {
      list = []
    }
    await Promise.all(list.map((m) => messageStore.fetch(m.id)))
    const matches = list.map((m) => {
      const full = messageStore.byId(m.id)
      return { id: m.id, title: full?.subject || item, miles: at ? Math.round(milesAway(at.lat, at.lng, full?.lat, full?.lng) || 0) : null }
    })
    assistant.cards = { kind: 'posts', ids: matches.map((m) => m.id) }
    return assistant.sendEvent({ type: 'matches', matches }, null)
  }

  async function listNearby(filter) {
    const at = myLatLng()
    if (!at) return assistant.sendEvent({ type: 'nearby', posts: [] }, null)
    const box = 0.15
    let list = []
    try {
      list = (await messageStore.fetchInBounds(at.lat - box, at.lng - box, at.lat + box, at.lng + box, null, 40, true)) || []
    } catch (e) {
      list = []
    }
    if (filter === 'offers') list = list.filter((m) => m.type === 'Offer')
    if (filter === 'wanted') list = list.filter((m) => m.type === 'Wanted')
    list = list.map((m) => ({ ...m, miles: milesAway(at.lat, at.lng, m.lat, m.lng) }))
    if (filter === 'nearest') list.sort((a, b) => (a.miles ?? 999) - (b.miles ?? 999))
    const top = list.slice(0, 8)
    await Promise.all(top.map((m) => messageStore.fetch(m.id)))
    assistant.cards = { kind: 'posts', ids: top.map((m) => m.id) }
    const posts = top.map((m) => ({ id: m.id, title: messageStore.byId(m.id)?.subject || '', type: m.type, miles: m.miles == null ? null : Math.round(m.miles) }))
    return assistant.sendEvent({ type: 'nearby', posts, filter: filter || '' }, null)
  }

  async function search(term) {
    const at = myLatLng()
    let list = []
    try {
      const params = { search: term }
      if (at) {
        params.swlat = at.lat - 0.3
        params.swlng = at.lng - 0.3
        params.nelat = at.lat + 0.3
        params.nelng = at.lng + 0.3
      }
      list = (await messageStore.search(params)) || []
    } catch (e) {
      list = []
    }
    const top = list.slice(0, 8)
    await Promise.all(top.map((m) => messageStore.fetch(m.id)))
    assistant.cards = { kind: 'posts', ids: top.map((m) => m.id) }
    const posts = top.map((m) => ({ id: m.id, title: messageStore.byId(m.id)?.subject || '', type: m.type, miles: at && m.lat ? Math.round(milesAway(at.lat, at.lng, m.lat, m.lng)) : null }))
    return assistant.sendEvent({ type: 'nearby', posts, filter: 'search:' + term }, null)
  }

  async function listCommunities() {
    await groupStore.fetch()
    const at = myLatLng()
    const all = (groupStore.summaryList || []).filter((g) => g.onmap && g.publish && g.lat && g.lng)
    const withMiles = all.map((g) => ({ ...g, miles: at ? milesAway(at.lat, at.lng, g.lat, g.lng) : null }))
    withMiles.sort((a, b) => (a.miles ?? 9999) - (b.miles ?? 9999))
    const top = withMiles.slice(0, 5)
    assistant.cards = { kind: 'groups', ids: top.map((g) => g.id) }
    const communities = top.map((g) => ({ id: g.id, name: g.namedisplay, members: g.membercount, miles: g.miles == null ? null : Math.round(g.miles) }))
    return assistant.sendEvent({ type: 'communities', communities }, null)
  }

  async function joinCommunity(groupid) {
    if (!me.value) {
      authStore.forceLogin = true
      return null
    }
    await authStore.joinGroup(me.value.id, groupid, true)
    const g = groupStore.get(groupid)
    return assistant.sendEvent({ type: 'joined', community: g?.namedisplay || null }, 'Joined ' + (g?.namedisplay || 'the community'))
  }

  function replyTo(msgid) {
    router.push('/chats/reply?replyto=' + msgid)
  }

  function signIn() {
    authStore.forceLogin = true
  }

  // Run whatever the last turn asked for. Returns true when something was started.
  async function run(action) {
    if (!action) return false
    switch (action.type) {
      case 'lookup_postcode':
        await lookupPostcode(action.text)
        return true
      case 'check_email':
        await checkEmail(action.email)
        return true
      case 'create_post':
        await createPost(action.postType, action.slots || assistant.slots)
        return true
      case 'find_matches':
        await findMatches(action.item)
        return true
      case 'list_nearby':
        await listNearby(action.filter)
        return true
      case 'list_communities':
        await listCommunities()
        return true
      case 'search':
        await search(action.term)
        return true
      case 'sign_in':
        signIn()
        return true
      case 'open':
        if (action.target) router.push(action.target)
        return true
      default:
        return false
    }
  }

  return { run, lookupPostcode, postcodeChosen, checkEmail, createPost, findMatches, listNearby, search, listCommunities, joinCommunity, replyTo, signIn, myLatLng }
}
