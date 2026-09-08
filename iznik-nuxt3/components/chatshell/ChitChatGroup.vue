<template>
  <div class="cc-group">
    <ShellHeader :title="title" :subtitle="subtitle" back="/chats">
      <template #menu>
        <b-dropdown variant="link" no-caret toggle-class="shell-menu-btn" right>
          <template #button-content>
            <v-icon icon="ellipsis-v" />
            <span class="visually-hidden">Menu</span>
          </template>
          <b-dropdown-item v-for="d in distances" :key="d.value" :data-testid="'distance-' + d.value" @click="setDistance(d)">
            {{ d.label }}<span v-if="d.value === minutes"> ✓</span>
          </b-dropdown-item>
          <b-dropdown-divider />
          <b-dropdown-item to="/communityevents">Community events</b-dropdown-item>
          <b-dropdown-item to="/volunteerings">Volunteering</b-dropdown-item>
        </b-dropdown>
      </template>
    </ShellHeader>

    <div v-if="!me" class="cc-signin">
      <Bubble from="freegle">Sign in to join the chit-chat.</Bubble>
      <div class="px-3 pb-3"><b-button variant="primary" data-testid="signin-button" @click="signIn">Sign in</b-button></div>
    </div>

    <div v-else ref="scroller" class="cc-scroll" data-testid="chitchat-stream">
      <div v-if="!stream.length && loaded" class="cc-empty">Nothing here yet. Say hello to your neighbours.</div>
      <template v-for="entry in stream" :key="entry.item.id">
        <div v-if="entry.dayLabel" class="cc-day">{{ entry.dayLabel }}</div>
        <ChitChatBubble
          :id="'cc-item-' + entry.item.id"
          :item="entry.item"
          :quote="entry.quote"
          :mine="entry.item.userid === me?.id"
          @love="toggleLove"
          @reply="startReply"
          @report="report"
          @hide="hide"
          @jump="jump"
        />
      </template>
      <div class="chat-end" />
    </div>

    <div v-if="replyTo" class="cc-replying" data-testid="cc-replying">
      <span>Replying to <strong>{{ replyTo.displayname }}</strong></span>
      <button type="button" class="cc-cancel" aria-label="Cancel reply" @click="replyTo = null">✕</button>
    </div>
    <ShellComposer
      v-if="me"
      ref="composer"
      :placeholder="replyTo ? 'Your reply' : 'Say something to people nearby'"
      :actions="[]"
      :allow-photo="!replyTo"
      :busy="sending"
      @send="send"
      @photo="uploading = true"
    />
    <OurUploader v-if="uploading" v-model="photos" type="Newsfeed" :start-open="true" @closed="uploading = false" />
    <NewsReportModal v-if="reporting" :id="reporting" @hidden="reporting = null" />
  </div>
</template>
<script setup>
import { computed, ref, onMounted, watch } from '#imports'
import { nextTick } from 'vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ShellComposer from '~/components/chatshell/ShellComposer.vue'
import Bubble from '~/components/chatshell/Bubble.vue'
import ChitChatBubble from '~/components/chatshell/ChitChatBubble.vue'
import { useAuthStore } from '~/stores/auth'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { untwem } from '~/composables/useTwem'
const OurUploader = defineAsyncComponent(() => import('~/components/OurUploader'))
const NewsReportModal = defineAsyncComponent(() => import('~/components/NewsReportModal'))

// Your local chit-chat as a group chat: one stream of bubbles, replies quoting what
// they answer, ❤ to love, long-press style menu for report and hide. The distance is
// in the menu, the way WhatsApp keeps group settings behind the header.
const props = defineProps({
  focus: { type: Number, required: false, default: 0 },
})

const authStore = useAuthStore()
const newsfeedStore = useNewsfeedStore()
const me = computed(() => authStore.user)
const scroller = ref(null)
const composer = ref(null)
const loaded = ref(false)
const sending = ref(false)
const uploading = ref(false)
const photos = ref([])
const replyTo = ref(null)
const reporting = ref(null)

const distances = [
  { value: 15, label: 'Within 15 minutes', metres: 8000 },
  { value: 30, label: 'Within 30 minutes', metres: 16000 },
  { value: 45, label: 'Within 45 minutes', metres: 32000 },
  { value: 0, label: 'Anywhere', metres: 0 },
]
const minutes = computed(() => {
  const m = me.value?.settings?.newsfeedMinutes
  const area = me.value?.settings?.newsfeedarea
  if (area === 0) return 0
  return m || 30
})
const title = computed(() => {
  const area = me.value?.settings?.mylocation?.area?.name
  return area ? `${area} ChitChat` : 'ChitChat'
})
const subtitle = computed(() => (minutes.value === 0 ? 'Anywhere · tap ⋮ to change' : `Within ${minutes.value} min · tap ⋮ to change`))

// Flatten each thread: the head, then its replies (in time order) quoting the head or
// the reply they answer.
const stream = computed(() => {
  const out = []
  let lastDay = null
  const push = (item, quote) => {
    const t = item.timestamp || item.added
    const day = t ? new Date(t).toDateString() : null
    let dayLabel = null
    if (day && day !== lastDay) {
      lastDay = day
      dayLabel = day === new Date().toDateString() ? 'Today' : new Date(t).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })
    }
    out.push({ item, quote, dayLabel })
  }
  const walk = (replies, parent) => {
    for (const r of replies || []) {
      const full = newsfeedStore.list?.[r.id] || r
      if (full.deleted || full.hidden) continue
      push(full, parent)
      walk(full.replies, full)
    }
  }
  for (const head of newsfeedStore.feed || []) {
    const full = newsfeedStore.list?.[head.id]
    if (!full || full.deleted || full.hidden || full.unfollowed) continue
    if (full.type && !['Message', 'AboutMe', 'CommunityEvent', 'VolunteerOpportunity', 'Story', 'Alert', 'Noticeboard', 'ConvertedToPost'].includes(full.type)) continue
    push(full, null)
    walk(full.replies, full)
  }
  return out
})

async function load() {
  const area = me.value?.settings?.newsfeedarea
  await newsfeedStore.fetchFeed(area === undefined ? 0 : area)
  await Promise.all((newsfeedStore.feed || []).slice(0, 30).map((h) => newsfeedStore.fetch(h.id)))
  loaded.value = true
  await nextTick()
  if (props.focus) jump(props.focus)
  else scrollToEnd()
}

async function scrollToEnd() {
  await nextTick()
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

function jump(id) {
  const el = document.getElementById('cc-item-' + id)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function toggleLove(item) {
  const head = item.threadhead || item.id
  if (item.loved) await newsfeedStore.unlove(item.id, head)
  else await newsfeedStore.love(item.id, head)
}

function startReply(item) {
  replyTo.value = item
  composer.value?.focus?.()
}

function report(item) {
  reporting.value = item.id
}

async function hide(item) {
  await newsfeedStore.unfollow(item.id)
  await newsfeedStore.fetch(item.threadhead || item.id, true)
}

async function send(text) {
  sending.value = true
  try {
    const msg = untwem(text)
    const imageid = photos.value?.[0]?.id || null
    if (replyTo.value) {
      const head = replyTo.value.threadhead || replyTo.value.id
      await newsfeedStore.send(msg, replyTo.value.id, head, null)
      await newsfeedStore.fetch(head, true)
      replyTo.value = null
    } else {
      await newsfeedStore.send(msg, null, null, imageid)
      photos.value = []
      await load()
    }
    scrollToEnd()
  } finally {
    sending.value = false
  }
}

async function setDistance(d) {
  const settings = { ...(me.value?.settings || {}), newsfeedMinutes: d.value || 45, newsfeedarea: d.metres }
  await authStore.saveAndGet({ settings })
  await newsfeedStore.reset?.()
  await load()
}

function signIn() {
  authStore.forceLogin = true
}

watch(me, (now, before) => {
  if (now && !before) load()
})

onMounted(() => {
  if (me.value) load()
  else loaded.value = true
})
</script>
<style scoped lang="scss">
.cc-group {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #e5ddd5;
}

.cc-scroll {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 0.4rem 0 0.6rem;
}

.cc-day {
  text-align: center;
  font-size: 0.72rem;
  color: #5a6470;
  margin: 0.4rem 0;
}

.cc-empty {
  padding: 1rem 0.8rem;
  color: #5a6470;
}

.cc-signin {
  padding-top: 0.5rem;
}

.cc-replying {
  background: #fff;
  border-top: 1px solid #e0e4e8;
  padding: 0.3rem 0.7rem;
  font-size: 0.85rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.cc-cancel {
  border: 0;
  background: transparent;
}

:deep(.shell-menu-btn) {
  color: #fff;
  padding: 0.25rem 0.5rem;
}
</style>
