<template>
  <div class="freegle-chat">
    <ShellHeader
      title="Freegle"
      subtitle="Give and get stuff for free, near you"
      avatar="/icon.png"
      :back="me ? '/chats' : null"
      :badge="unread"
    >
      <template #menu>
        <b-dropdown variant="link" no-caret toggle-class="shell-menu-btn" right>
          <template #button-content>
            <v-icon icon="ellipsis-v" />
            <span class="visually-hidden">Menu</span>
          </template>
          <b-dropdown-item @click="startAgain">Start again</b-dropdown-item>
          <b-dropdown-item v-if="me" to="/chats/posts"
            >Your posts</b-dropdown-item
          >
          <b-dropdown-item v-if="me" to="/settings">Settings</b-dropdown-item>
          <b-dropdown-item to="/help">Help</b-dropdown-item>
          <b-dropdown-item to="/about">About</b-dropdown-item>
          <b-dropdown-item data-testid="menu-classic" @click="goClassic"
            >Classic Freegle</b-dropdown-item
          >
          <b-dropdown-item v-if="!me" @click="signIn">Sign in</b-dropdown-item>
          <b-dropdown-item v-else @click="logout">Sign out</b-dropdown-item>
        </b-dropdown>
      </template>
    </ShellHeader>

    <div
      ref="scroller"
      class="chat-scroll"
      aria-live="polite"
      data-testid="chat-transcript"
    >
      <div class="chat-day">Today</div>
      <template v-if="!assistant.lines.length">
        <ShellBubble from="freegle"
          >Hello. Got something to give away, or after something?</ShellBubble
        >
        <PostStrip
          v-if="samples.length"
          kind="samples"
          :ids="samples"
          :count="samples.length"
          @look="openSheet()"
        />
      </template>
      <template v-for="line in assistant.lines" :key="line.id">
        <ShellBubble
          :from="line.who === 'freegle' ? 'freegle' : 'me'"
          :time="timeOf(line)"
          >{{ line.text }}</ShellBubble
        >
      </template>
      <ShellBubble v-if="assistant.streaming" from="freegle">
        <span v-if="assistant.streamText">{{ assistant.streamText }}</span>
        <span v-else class="typing" aria-label="Freegle is typing"
          ><span /><span /><span
        /></span>
      </ShellBubble>

      <!-- Widgets for where the conversation is now. -->
      <div v-if="!assistant.streaming" class="widgets">
        <ConfirmCard
          v-if="hostAction?.type === 'confirm_card'"
          :post-type="hostAction.postType"
          :slots="assistant.slots"
          :photos="assistant.photos"
          :location-name="assistant.facts?.locationName"
          :community="assistant.facts?.community"
          @edit="editField"
          @toggle="toggleField"
        />
        <PostStrip
          v-if="assistant.cards?.kind === 'posts'"
          :ids="assistant.cards.ids"
          :count="assistant.cards.count ?? assistant.cards.ids.length"
          :kind="assistant.cards.look || 'nearby'"
          :filter="assistant.cards.filter || ''"
          :term="assistant.cards.term || ''"
          @look="openSheet(assistant.cards)"
        />
        <template v-if="assistant.cards?.kind === 'groups'">
          <GroupCard
            v-for="id in assistant.cards.ids"
            :id="id"
            :key="'group-' + id"
            :expanded="expandedId === id"
            :miles="groupMiles(id)"
            @join="join"
            @about="expand"
          />
        </template>
        <PostcodeInput
          v-if="inputKind === 'postcode'"
          @selected="postcodeChosen"
        />
        <EmailInput v-if="inputKind === 'email'" @submit="emailEntered" />
        <div v-if="editing" class="edit-chips">
          <ShellChips :options="editChips" label="Change" @pick="editPick" />
        </div>
        <div v-else class="chips-wrap">
          <ShellChips
            :options="chips"
            :disabled="assistant.busy"
            label="Reply options"
            @pick="tap"
          />
        </div>
        <div v-if="assistant.error" class="chat-error" data-testid="chat-error">
          That didn't go through.
          <button type="button" class="retry" @click="retry">Try again</button>
        </div>
      </div>
      <div class="chat-end" />
    </div>

    <ShellComposer
      ref="composer"
      :progress="assistant.progress"
      :actions="mainChips"
      :busy="assistant.busy"
      :allow-photo="true"
      @send="send"
      @photo="openPhoto"
      @cancel="cancel"
      @action="tap"
    />
    <NearbySheet
      v-if="sheet"
      :term="sheet.term"
      :filter="sheet.filter"
      @close="closeSheet"
    />
    <OurUploader
      v-if="uploading"
      v-model="assistant.photos"
      type="Message"
      :start-open="true"
      :recognise="true"
      @closed="photoClosed"
      @photo-processed="photoProcessed"
    />
  </div>
</template>
<script setup>
import { computed, ref, watch, onMounted, useRoute, useRouter } from '#imports'
import { nextTick } from 'vue'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import ShellComposer from '~/components/chatshell/ShellComposer.vue'
import ShellBubble from '~/components/chatshell/ShellBubble.vue'
import ShellChips from '~/components/chatshell/ShellChips.vue'
import PostStrip from '~/components/chatshell/PostStrip.vue'
import NearbySheet from '~/components/chatshell/NearbySheet.vue'
import GroupCard from '~/components/chatshell/GroupCard.vue'
import ConfirmCard from '~/components/chatshell/ConfirmCard.vue'
import PostcodeInput from '~/components/chatshell/PostcodeInput.vue'
import EmailInput from '~/components/chatshell/EmailInput.vue'
import { useAssistantStore } from '~/stores/assistant'
import { useAuthStore } from '~/stores/auth'
import { useChatStore } from '~/stores/chat'
import { useGroupStore } from '~/stores/group'
import { useHostActions } from '~/composables/useHostActions'
import { useUiMode } from '~/composables/useUiMode'
import { milesAway } from '~/composables/useDistance'
const OurUploader = defineAsyncComponent(
  () => import('~/components/OurUploader')
)

// The chat with Freegle. The store holds the lines; the service decides; this renders
// and does what Freegle asks the browser to do.
const props = defineProps({
  samples: { type: Array, required: false, default: () => [] },
  // Open with the Nearby sheet up, as /browse does in chat mode.
  nearby: { type: Boolean, required: false, default: false },
  nearbyTerm: { type: String, required: false, default: '' },
})

const assistant = useAssistantStore()
const authStore = useAuthStore()
const chatStore = useChatStore()
const groupStore = useGroupStore()
const host = useHostActions()
const uiMode = useUiMode()
const router = useRouter()
const route = useRoute()

const me = computed(() => authStore.user)
const unread = computed(() => chatStore.unreadCount || 0)
const scroller = ref(null)
const composer = ref(null)
const uploading = ref(false)
const editing = ref(false)
const expandedId = ref(null)
const lastBody = ref(null)
// The Nearby sheet, when it is up: what it searches for and which filter it opens on.
const sheet = ref(null)

const MAIN = [
  { value: 'give', label: 'Give' },
  { value: 'ask', label: 'Ask' },
  { value: 'nearby', label: 'Nearby' },
]
const mainChips = computed(() => (assistant.progress ? [] : MAIN))
const hostAction = computed(() => assistant.hostAction)
// Chips the composer row already offers (Give, Ask, Nearby) are not repeated in the
// transcript: two rows of the same buttons is clutter.
const chips = computed(() => {
  const composerHas = new Set(mainChips.value.map((c) => c.value))
  return (assistant.chips || []).filter(
    (c) => (!c.kind || c.kind === 'photo') && !composerHas.has(c.value)
  )
})
const inputKind = computed(
  () =>
    (assistant.chips || []).find(
      (c) => c.kind === 'postcode' || c.kind === 'email'
    )?.kind || null
)
const editChips = [
  { value: 'edit:item', label: 'What it is' },
  { value: 'edit:description', label: 'Details' },
  { value: 'edit:where', label: 'Where' },
]

if (!assistant.photos) assistant.photos = []
if (!assistant.cards) assistant.cards = null

function timeOf(line) {
  if (!line.ts) return null
  const d = new Date(line.ts)
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function scrollToEnd() {
  await nextTick()
  const el = scroller.value
  if (el) el.scrollTop = el.scrollHeight
}

watch(
  () => [
    assistant.lines.length,
    assistant.streamText,
    assistant.chips,
    assistant.cards,
  ],
  scrollToEnd,
  { deep: true }
)

// Freegle asked the browser to do something: do it.
watch(
  () => assistant.hostAction,
  async (action) => {
    if (!action || assistant.busy) return
    const autonomous = [
      'lookup_postcode',
      'check_email',
      'create_post',
      'find_matches',
      'list_nearby',
      'list_communities',
      'search',
      'sign_in',
      'open',
    ]
    if (!autonomous.includes(action.type)) return
    if (action.type === 'create_post' && !assistant.slots?.item) return
    assistant.hostAction = null
    await host.run(action)
  }
)

async function send(text) {
  editing.value = false
  lastBody.value = { text }
  await assistant.sendText(text)
}

async function tap(chip) {
  editing.value = false
  if (chip.value === 'add_photo' || chip.kind === 'photo') {
    openPhoto()
    return
  }
  if (chip.value === 'change') {
    editing.value = true
    return
  }
  if (chip.value === 'nearby') {
    // Straight to the sheet, as an attachment button opens its tray.
    openSheet()
    return
  }
  if (['offers', 'wanted', 'nearest'].includes(chip.value)) {
    assistant.push({ who: 'member', text: chip.label })
    await host.listNearby(chip.value)
    return
  }
  if (chip.value === 'yourposts') {
    router.push('/chats/posts')
    return
  }
  if (chip.value === 'volunteers' && assistant.state === 'HELP') {
    router.push('/help')
    return
  }
  lastBody.value = { tap: chip.value }
  await assistant.sendTap(chip)
}

async function editPick(chip) {
  editing.value = false
  lastBody.value = { tap: chip.value }
  await assistant.sendTap({
    value: chip.value,
    label: 'Change ' + chip.label.toLowerCase(),
  })
}

function editField(field) {
  editPick({ value: 'edit:' + field, label: field })
}

function toggleField(field, value) {
  assistant.slots = { ...assistant.slots, [field]: value }
}

async function cancel() {
  // A tap, not a typed word: nothing to show as the member's line.
  await assistant.send({ tap: 'cancel' }, null)
}

function startAgain() {
  assistant.reset()
  assistant.cards = null
  assistant.photos = []
}

async function retry() {
  assistant.error = null
  if (lastBody.value) await assistant.send(lastBody.value, null)
}

function openPhoto() {
  uploading.value = true
}

function photoClosed() {
  uploading.value = false
}

async function photoProcessed(id) {
  uploading.value = false
  const att = assistant.photos?.find?.((p) => p.id === id)
  const recognised = att?.info?.recognised || att?.info?.labels || []
  await assistant.sendEvent(
    {
      type: 'photo_added',
      attachmentId: id,
      recognised: Array.isArray(recognised) ? recognised.slice(0, 3) : [],
    },
    'Added a photo'
  )
}

async function postcodeChosen(pc) {
  await host.postcodeChosen(pc)
}

async function emailEntered(email) {
  await host.checkEmail(email)
}

function openSheet(cards) {
  sheet.value = { term: cards?.term || '', filter: cards?.filter || 'all' }
}
function closeSheet() {
  sheet.value = null
  // /browse in chat mode is the chat with the sheet up; closing it is the chat,
  // which for a member means the Freegle chat rather than the chat list.
  if (route.path.startsWith('/browse')) {
    router.replace(me.value ? '/?chat=1' : '/')
  }
}

function expand(id) {
  expandedId.value = expandedId.value === id ? null : id
}

async function join(id) {
  await host.joinCommunity(id)
}

function groupMiles(id) {
  const at = host.myLatLng()
  const g =
    groupStore.get(id) || groupStore.summaryList?.find?.((x) => x.id === id)
  if (!at || !g?.lat) return null
  return milesAway(at.lat, at.lng, g.lat, g.lng)
}

function signIn() {
  authStore.forceLogin = true
}

async function logout() {
  await authStore.logout()
  assistant.reset()
}

async function goClassic() {
  await uiMode.setMode('classic')
  router.push('/browse')
}

onMounted(() => {
  scrollToEnd()
  if (props.nearby) openSheet({ term: props.nearbyTerm })
  // Your posts can send someone here to start something: /?do=give.
  const wanted = MAIN.find((c) => c.value === route.query.do)
  if (wanted && !assistant.busy && !assistant.progress) tap(wanted)
  // A member who signs in mid-conversation: tell Freegle so the flow can skip what
  // it now knows.
  watch(me, async (now, before) => {
    if (now && !before && assistant.conversation) {
      await assistant.sendEvent(
        {
          type: 'signed_in',
          name: now.displayname,
          locationName: now.settings?.mylocation?.name || '',
          community: now.memberships?.[0]?.namedisplay || '',
        },
        null
      )
    }
  })
})
</script>
<style scoped lang="scss">
.freegle-chat {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #e5ddd5
    url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="1" fill="%23d7cfc6"/></svg>');
}

.chat-scroll {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 0.4rem 0 0.6rem;
  -webkit-overflow-scrolling: touch;
}

.chat-day {
  text-align: center;
  font-size: 0.72rem;
  color: #5a6470;
  margin: 0.2rem 0 0.4rem;

  &::before {
    content: '';
  }
}

.widgets {
  padding-bottom: 0.3rem;
}

.chips-wrap,
.edit-chips {
  padding: 0 0.6rem;
}

.chat-error {
  padding: 0.4rem 0.7rem;
  font-size: 0.9rem;
  color: #7a3a3a;
}

.retry {
  border: 0;
  background: transparent;
  color: #1e7c4f;
  font-weight: 600;
}

.typing {
  display: inline-flex;
  gap: 3px;
  align-items: center;
  height: 1em;

  span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #8a949c;
    animation: blink 1.2s infinite ease-in-out;

    &:nth-child(2) {
      animation-delay: 0.2s;
    }

    &:nth-child(3) {
      animation-delay: 0.4s;
    }
  }
}

@keyframes blink {
  0%,
  80%,
  100% {
    opacity: 0.3;
  }
  40% {
    opacity: 1;
  }
}

@media (prefers-reduced-motion: reduce) {
  .typing span {
    animation: none;
    opacity: 0.7;
  }
}

:deep(.shell-menu-btn) {
  color: #fff;
  padding: 0.25rem 0.5rem;
}
</style>
