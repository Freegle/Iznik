<template>
  <div class="your-posts">
    <ShellHeader
      title="Your posts"
      :subtitle="subtitle"
      avatar="/icon.png"
      back="/chats"
      :badge="unread"
    >
      <template #menu>
        <b-dropdown variant="link" no-caret toggle-class="shell-menu-btn" right>
          <template #button-content>
            <v-icon icon="ellipsis-v" />
            <span class="visually-hidden">Menu</span>
          </template>
          <b-dropdown-item to="/myposts">Classic My Posts</b-dropdown-item>
          <b-dropdown-item to="/?chat=1"
            >Give or ask for something</b-dropdown-item
          >
        </b-dropdown>
      </template>
    </ShellHeader>

    <div ref="scroller" class="posts-scroll" data-testid="yourposts-transcript">
      <div v-if="!posts.length && loaded" class="posts-empty">
        <ShellBubble from="freegle"
          >Nothing open just now. When you give or ask for something, it turns
          up here, and so do the replies.</ShellBubble
        >
        <div class="chips-wrap">
          <ShellChips
            :options="[
              { value: 'give', label: 'Give something' },
              { value: 'ask', label: 'Ask for something' },
            ]"
            @pick="goFreegle"
          />
        </div>
      </div>
      <template
        v-for="ev in events"
        :key="ev.kind + ev.id + ev.ts + (ev.userid || '')"
      >
        <div v-if="dayLabel(ev)" class="event-day">{{ dayLabel(ev) }}</div>
        <div class="event" :data-testid="'event-' + ev.kind + '-' + ev.id">
          <div class="event-card" :class="'event-' + ev.kind">
            <button type="button" class="event-post" @click="openPost(ev.post)">
              <ProxyImage
                v-if="photoOf(ev.post)"
                :src="photoOf(ev.post)"
                alt=""
                :width="40"
                :height="40"
                sizes="40px"
                class="event-thumb"
              />
              <span v-else class="event-thumb event-thumb-fallback"
                ><v-icon
                  :icon="ev.post.type === 'Wanted' ? 'shopping-cart' : 'gift'"
              /></span>
              <span class="event-title">{{ cleanTitle(ev.post.subject) }}</span>
              <span class="event-status">{{ statusOf(ev.post) }}</span>
            </button>
            <PersonCard
              v-if="ev.kind === 'reply'"
              :reply="replyFor(ev)"
              :reasons="[]"
            />
            <div v-else class="event-text">{{ ev.text }}</div>
            <div class="event-time">{{ timeOf(ev.ts) }}</div>
          </div>
          <div class="chips-wrap">
            <ShellChips
              :options="ev.chips"
              :label="ev.text"
              @pick="(chip) => act(chip, ev)"
            />
          </div>
        </div>
      </template>
      <div class="chat-end" />
    </div>

    <ShellComposer
      placeholder="Type to the person you're dealing with"
      :actions="[]"
      :allow-photo="false"
      :busy="sending"
      @send="typed"
    />

    <div v-if="sendTo" class="sendto" data-testid="sendto">
      <span>Send to:</span>
      <ShellChips :options="sendTo.options" @pick="sendToPick" />
    </div>

    <ChooserSheet
      v-if="chooser"
      :post="chooser.post"
      :replies="chooser.replies"
      :available="chooser.available"
      @promise="promiseMany"
      @chat="openChat"
      @close="chooser = null"
    />
    <OutcomeModal
      v-if="outcome"
      :id="outcome.id"
      :type="outcome.type"
      @hidden="outcomeDone"
    />
    <MessageEditModal v-if="editing" :id="editing" @hidden="editDone" />
    <PromiseModal
      v-if="tryst"
      :messages="[tryst.post]"
      :selected-message="tryst.post.id"
      :users="[tryst.user]"
      :selected-user="tryst.user.id"
      @hidden="tryst = null"
    />
  </div>
</template>
<script setup>
import { computed, ref, onMounted, watch, useRouter } from '#imports'
import { nextTick } from 'vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ShellComposer from '~/components/chatshell/ShellComposer.vue'
import ShellBubble from '~/components/chatshell/ShellBubble.vue'
import ShellChips from '~/components/chatshell/ShellChips.vue'
import PersonCard from '~/components/chatshell/PersonCard.vue'
import ChooserSheet from '~/components/chatshell/ChooserSheet.vue'
import ProxyImage from '~/components/ProxyImage.vue'
import { useAuthStore } from '~/stores/auth'
import { useMessageStore } from '~/stores/message'
import { useChatStore } from '~/stores/chat'
import { useUserStore } from '~/stores/user'
import { useTrystStore } from '~/stores/tryst'
import { useComposeStore } from '~/stores/compose'
import { useLocationStore } from '~/stores/location'
import { loadOwnActivePosts } from '~/composables/useCompose'
import {
  timeline,
  cleanTitle,
  wantedCountFrom,
  unreadCount,
  remaining,
} from '~/composables/yourposts'
import { milesAway } from '~/composables/useDistance'
const OutcomeModal = defineAsyncComponent(
  () => import('~/components/OutcomeModal')
)
const MessageEditModal = defineAsyncComponent(
  () => import('~/components/MessageEditModal')
)
const PromiseModal = defineAsyncComponent(
  () => import('~/components/PromiseModal')
)

// One chat about all your posts: what happened, who is interested, who gets what.
// Everything shown comes from the post records, the replies' chats, the repliers and
// the trysts; the chooser opens as a sheet.
const authStore = useAuthStore()
const messageStore = useMessageStore()
const chatStore = useChatStore()
const userStore = useUserStore()
const trystStore = useTrystStore()
const composeStore = useComposeStore()
const locationStore = useLocationStore()
const router = useRouter()

const me = computed(() => authStore.user)
const loaded = ref(false)
const scroller = ref(null)
const chooser = ref(null)
const outcome = ref(null)
const editing = ref(null)
const tryst = ref(null)
const sendTo = ref(null)
const sending = ref(false)
const seen = ref({})
const now = ref(Date.now())

// Per member, so a shared device does not carry one person's read marks to the next.
const seenKey = computed(() => 'freegle-yourposts-seen:' + (me.value?.id || 0))

// byUserList holds lean summaries; the full records (subject, replies, promises,
// outcomes) are loaded alongside by loadOwnActivePosts and win where present.
const posts = computed(() =>
  (messageStore.byUserList[me.value?.id] || [])
    .filter(Boolean)
    .map((m) => ({ ...m, ...(messageStore.byId(m.id) || {}) }))
    .filter((m) => !m.deleted && (!m.outcomes?.length || isRecent(m)))
)

function isRecent(m) {
  const t = m.outcomes?.[0]?.timestamp
  return t && Date.now() - new Date(t).getTime() < 3 * 24 * 3600000
}

function myLatLng() {
  const loc = me.value?.settings?.mylocation
  if (loc?.lat && loc?.lng) return loc
  return me.value?.lat ? { lat: me.value.lat, lng: me.value.lng } : null
}

// The replies to a post, enriched from the chat list and the user store.
function repliesFor(post) {
  const at = myLatLng()
  return (post.replies || []).map((r) => {
    const chat =
      chatStore.toUser?.(r.userid) ||
      Object.values(chatStore.list || {}).find((c) => c?.otheruid === r.userid)
    const u = userStore.byId(r.userid)
    const miles = at && u?.lat ? milesAway(at.lat, at.lng, u.lat, u.lng) : null
    const snippet = chat?.snippet || ''
    return {
      id: r.id,
      userid: r.userid,
      name: r.displayname,
      displayname: r.displayname,
      date: r.date,
      chatid: chat?.id || null,
      snippet,
      miles,
      ratings: u?.info?.ratings || null,
      wanted: wantedCountFrom(snippet),
      tn: !!u?.tnuserid,
    }
  })
}

function ctxFor(post) {
  return { replies: repliesFor(post), trysts: trystStore.list || [] }
}

const events = computed(() => timeline(posts.value, ctxFor, now.value))
const unread = computed(() =>
  unreadCount(posts.value, ctxFor, seen.value, now.value)
)
const subtitle = computed(() => {
  const open = posts.value.filter((p) => !p.outcomes?.length).length
  if (!open) return 'Nothing open'
  const interested = posts.value.filter((p) => p.replycount > 0).length
  return `${open} open · ${interested} with replies`
})

function replyFor(ev) {
  return (
    repliesFor(ev.post).find((r) => r.userid === ev.userid) || {
      userid: ev.userid,
      name: ev.name,
      snippet: ev.snippet,
      miles: ev.miles,
    }
  )
}

function photoOf(post) {
  return (
    post?.attachments?.[0]?.paththumb || post?.attachments?.[0]?.path || null
  )
}

function statusOf(post) {
  if (post.outcomes?.length)
    return post.outcomes[0].outcome === 'Withdrawn'
      ? 'Withdrawn'
      : post.type === 'Wanted'
        ? 'Received'
        : 'Taken'
  if (post.promises?.length)
    return remaining(post) > 0
      ? `${remaining(post)} still available`
      : 'Promised'
  if (post.replycount > 0) return `${post.replycount} interested`
  return 'Waiting'
}

let lastDay = null
function dayLabel(ev) {
  const d = new Date(ev.ts).toDateString()
  if (d === lastDay) return null
  lastDay = d
  const today = new Date().toDateString()
  return d === today
    ? 'Today'
    : new Date(ev.ts).toLocaleDateString([], {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
      })
}

function timeOf(ts) {
  return new Date(ts).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  })
}

function openPost(post) {
  router.push('/message/' + post.id)
}

function openChat(chatid) {
  if (chatid) router.push('/chats/' + chatid)
}

function goFreegle(chip) {
  router.push('/?chat=1&do=' + chip.value)
}

async function act(chip, ev) {
  const [kind, msgid, userid] = chip.value.split(':')
  const id = parseInt(msgid)
  const uid = userid ? parseInt(userid) : null
  const post = posts.value.find((p) => p.id === id)
  switch (kind) {
    case 'promise':
      await promiseMany([{ userid: uid, count: 1 }], post)
      break
    case 'chat':
      openChat(id)
      break
    case 'choose':
      chooser.value = {
        post,
        replies: repliesFor(post),
        available: remaining(post) || 1,
      }
      break
    case 'taken':
      outcome.value = {
        id,
        type: post.type === 'Wanted' ? 'Received' : 'Taken',
      }
      break
    case 'notyet':
      break
    case 'noshow':
    case 'unpromise':
      await messageStore.renege(id, uid)
      await messageStore.fetch(id, true)
      break
    case 'tryst': {
      await userStore.fetch(uid)
      tryst.value = {
        post,
        user: userStore.byId(uid) || { id: uid, displayname: 'them' },
      }
      break
    }
    case 'repost':
      await repost(post)
      break
    case 'withdraw':
      outcome.value = { id, type: 'Withdrawn' }
      break
    case 'edit':
      editing.value = id
      break
    default:
      break
  }
  markSeen(id)
}

async function promiseMany(allocs, post) {
  const p = post || chooser.value?.post
  chooser.value = null
  if (!p) return
  for (const a of allocs) {
    if (!a.count) continue
    await messageStore.update({
      id: p.id,
      action: 'Promise',
      userid: a.userid,
      count: a.count,
    })
  }
  await messageStore.fetch(p.id, true)
  markSeen(p.id)
}

async function repost(post) {
  const msg = await messageStore.fetch(post.id, true)
  if (!msg) return
  await composeStore.clearMessages()
  await composeStore.setMessage(
    0,
    {
      id: msg.id,
      savedBy: msg.fromuser,
      item: msg.item?.name?.trim(),
      description: msg.textbody?.trim() || null,
      availablenow: msg.availablenow,
      type: msg.type,
      repostof: post.id,
      deadline: null,
    },
    me.value
  )
  if (msg.location?.name) {
    const locs = await locationStore.typeahead(msg.location.name)
    composeStore.postcode = locs[0]
  }
  if (msg.groups?.length)
    composeStore.group = [...msg.groups].sort(
      (a, b) => new Date(b.arrival || 0) - new Date(a.arrival || 0)
    )[0].groupid
  await composeStore.setAttachmentsForMessage(0, msg.attachments)
  await composeStore.submit({ type: msg.type })
  await messageStore.fetch(post.id, true)
}

async function outcomeDone() {
  const id = outcome.value?.id
  outcome.value = null
  if (id) await messageStore.fetch(id, true)
}

async function editDone() {
  const id = editing.value
  editing.value = null
  if (id) await messageStore.fetch(id, true)
}

// Typing here goes to the person you are dealing with; with several, one tap says who.
async function typed(text) {
  const live = []
  for (const p of posts.value) {
    if (p.outcomes?.length) continue
    for (const r of repliesFor(p)) if (r.chatid) live.push(r)
  }
  const unique = [...new Map(live.map((r) => [r.chatid, r])).values()]
  if (!unique.length) {
    router.push('/chats')
    return
  }
  if (unique.length === 1) {
    await sendChat(unique[0].chatid, text)
    return
  }
  sendTo.value = {
    text,
    options: unique
      .slice(0, 3)
      .map((r) => ({ value: String(r.chatid), label: r.name })),
  }
}

async function sendToPick(chip) {
  const text = sendTo.value?.text
  sendTo.value = null
  await sendChat(parseInt(chip.value), text)
}

async function sendChat(chatid, text) {
  sending.value = true
  try {
    await chatStore.send(chatid, text)
    router.push('/chats/' + chatid)
  } finally {
    sending.value = false
  }
}

function markSeen(id) {
  seen.value = { ...seen.value, [id]: Date.now() }
  try {
    localStorage.setItem(seenKey.value, JSON.stringify(seen.value))
  } catch (e) {
    // ignore
  }
  if (me.value) {
    const settings = { ...(me.value.settings || {}), postChatSeen: seen.value }
    authStore.saveAndGet({ settings }).catch(() => {})
  }
}

async function scrollToEnd() {
  await nextTick()
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

watch(() => events.value.length, scrollToEnd)

onMounted(async () => {
  try {
    seen.value = {
      ...(me.value?.settings?.postChatSeen || {}),
      ...JSON.parse(localStorage.getItem(seenKey.value) || '{}'),
    }
  } catch (e) {
    seen.value = me.value?.settings?.postChatSeen || {}
  }
  if (me.value) {
    await Promise.all([
      loadOwnActivePosts(messageStore, me.value.id),
      chatStore.listChats?.(),
      trystStore.fetch(),
    ])
    const ids = new Set()
    for (const p of posts.value)
      for (const r of p.replies || []) ids.add(r.userid)
    await Promise.all([...ids].map((id) => userStore.fetch(id)))
  }
  loaded.value = true
  now.value = Date.now()
  scrollToEnd()
  // Everything shown has now been seen.
  for (const p of posts.value) seen.value[p.id] = Date.now()
})
</script>
<style scoped lang="scss">
.your-posts {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #e5ddd5;
}

.posts-scroll {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 0.4rem 0 0.6rem;
}

.posts-empty {
  padding-top: 0.5rem;
}

.event-day {
  text-align: center;
  font-size: 0.72rem;
  color: #5a6470;
  margin: 0.4rem 0;
}

.event {
  margin: 0.3rem 0.6rem;
}

.event-card {
  background: #fff;
  border-radius: 14px;
  border-top-left-radius: 4px;
  padding: 0.5rem 0.6rem;
  box-shadow: 0 1px 1px rgba(0, 0, 0, 0.06);
  max-width: 92%;
}

.event-post {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: 0;
  background: transparent;
  padding: 0;
  width: 100%;
  text-align: left;
}

.event-thumb {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  object-fit: cover;
  flex: 0 0 auto;
}

.event-thumb-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e8f5ee;
  color: #1e7c4f;
}

.event-title {
  font-weight: 600;
  flex: 1 1 auto;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.event-status {
  font-size: 0.72rem;
  color: #1e7c4f;
  background: #e8f5ee;
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
  flex: 0 0 auto;
}

.event-text {
  margin-top: 0.3rem;
  font-size: 0.95rem;
}

.event-time {
  font-size: 0.68rem;
  color: #7a838c;
  text-align: right;
}

.chips-wrap {
  padding: 0 0.1rem;
}

.sendto {
  background: #fff;
  padding: 0.4rem 0.6rem;
  display: flex;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.9rem;
  border-top: 1px solid #e0e4e8;
}

:deep(.shell-menu-btn) {
  color: #fff;
  padding: 0.25rem 0.5rem;
}

:deep(.person-card) {
  margin: 0.3rem 0 0;
  padding: 0.3rem 0;
  box-shadow: none;
}
</style>
