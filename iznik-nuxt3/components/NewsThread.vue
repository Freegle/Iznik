<template>
  <div v-if="showThis" class="bg-white mt-2">
    <b-card v-if="newsfeed" :class="backgroundColor" no-body>
      <b-card-body class="p-1 p-sm-2">
        <b-card-text>
          <div v-if="mod && newsfeed.hidden" class="text-danger small mb-1">
            This has been hidden
            <UserName
              v-if="newsfeed?.hiddenby"
              :id="newsfeed.hiddenby"
              intro="by"
            />
            <span v-else>the system</span>
            and is only visible to volunteers and the person who posted it.
          </div>
          <!-- Volunteers only. Names one of the poster's own live posts, so it
               must never render for other members; the server also refuses the
               request for them. -->
          <NoticeMessage
            v-if="chitChatMod && duplicateOf && !newsfeed.hidden"
            variant="warning"
            class="mb-2"
          >
            <p class="mb-1">
              <strong>Volunteers only.</strong> This looks like the same thing
              as a post they already have live:
            </p>
            <p class="mb-1">
              <strong>{{ duplicateOf.type.toUpperCase() }}:</strong>
              {{ duplicateOf.subject }}
            </p>
            <p class="mb-2">
              If it's a repeat, hide this - their real post is already doing the
              work, and it reaches more people than ChitChat does.
            </p>
            <b-button variant="secondary" size="sm" @click="hide">
              Hide this post
            </b-button>
          </NoticeMessage>
          <div v-if="isNewsComponent">
            <b-dropdown
              lazy
              class="float-end thread-menu"
              right
              variant="link"
              no-caret
            >
              <template #button-content>
                <v-icon icon="ellipsis-h" class="text-muted" />
              </template>
              <b-dropdown-item
                v-if="duplicateCount > 1"
                @click="expandDuplicates"
              >
                Show {{ duplicateCount }} combined posts separately
              </b-dropdown-item>
              <b-dropdown-item
                v-if="!isApp"
                :href="'/chitchat/' + newsfeed?.id"
                target="_blank"
              >
                Open in new window
              </b-dropdown-item>
              <b-dropdown-item
                v-if="myid === parseInt(newsfeed.userid) || mod"
                :b-v-modal="'newsEdit' + newsfeed?.id"
                @click="show"
              >
                Edit
              </b-dropdown-item>
              <b-dropdown-item @click="unfollow">
                Unfollow this thread
              </b-dropdown-item>
              <b-dropdown-item @click="report">
                Report this thread or one of its replies
              </b-dropdown-item>
              <!-- Says what it leaves behind. This posts in the member's name
                   and adds a note to this public thread, so the menu shouldn't
                   read like a private mod action. -->
              <b-dropdown-item v-if="chitChatMod" @click="showConvert = true">
                Post this as an OFFER/WANTED for them
                <div class="small text-muted convert-hint">
                  Posts as them, and adds a note to this thread
                </div>
              </b-dropdown-item>
              <b-dropdown-item v-if="canRefer" @click="referToOffer">
                Refer to OFFER
              </b-dropdown-item>
              <b-dropdown-item v-if="canRefer" @click="referToWanted">
                Refer to WANTED
              </b-dropdown-item>
              <b-dropdown-item v-if="canRefer" @click="referToTaken">
                Refer to TAKEN
              </b-dropdown-item>
              <b-dropdown-item v-if="canRefer" @click="referToReceived">
                Refer to RECEIVED
              </b-dropdown-item>
              <b-dropdown-item v-if="canStory" @click="createStory">
                Turn this into a Story
              </b-dropdown-item>
              <b-dropdown-item
                v-if="chitChatMod && !newsfeed.hidden"
                @click="hide"
              >
                Hide this thread
              </b-dropdown-item>
              <b-dropdown-item
                v-if="chitChatMod && newsfeed.hidden"
                @click="unhide"
              >
                Unhide this thread
              </b-dropdown-item>
              <b-dropdown-item
                v-if="myid === parseInt(newsfeed.userid) || mod"
                @click="deleteIt"
              >
                Delete this thread
              </b-dropdown-item>
              <b-dropdown-item
                v-if="chitChatMod && !newsfeed.hidden"
                @click="mute"
              >
                Mute user on ChitChat
              </b-dropdown-item>
              <b-dropdown-item
                v-if="chitChatMod && newsfeed.hidden"
                @click="unmute"
              >
                Unmute user on ChitChat
              </b-dropdown-item>
            </b-dropdown>
            <component
              :is="newsComponentName"
              v-if="newsfeed"
              :id="newsfeed?.id"
              :newsfeed="newsfeed"
              :class="newsfeed.deleted ? 'strike' : ''"
              @focus-comment="focusComment"
              @hide="showThis = false"
            />
            <div v-else>Bad feed item {{ newsfeed }}</div>
            <NewsPreviews
              v-if="newsfeed.previews?.length && !newsfeed.html"
              :previews="newsfeed.previews"
              class="mt-1"
            />
          </div>
          <notice-message v-else variant="danger">
            Unknown item type {{ newsfeed?.type }}
          </notice-message>
        </b-card-text>
      </b-card-body>
      <template #footer>
        <NoticeMessage v-if="targetGone" variant="info" class="mb-2">
          That reply has been removed. Here's the rest of the conversation.
        </NoticeMessage>
        <NewsReplies
          v-if="newsfeed?.replies?.length"
          :id="id"
          :threadhead="newsfeed.id"
          :scroll-to="scrollTo"
          :reply-to="replyingTo"
          :depth="1"
          :context="context"
          :class="newsfeed.deleted ? 'strike me-1' : 'me-1'"
          @rendered="rendered"
          @subtree-rendered="repliesRendered = true"
        />
        <span v-if="!newsfeed?.closed" class="comment-box">
          <div v-if="enterNewLine">
            <OurAtTa
              v-if="!newsfeed.deleted"
              ref="at"
              :members="tagusers"
              class="flex-shrink-2 input-group"
              :filter-match="filterMatch"
            >
              <b-input-group>
                <slot name="prepend">
                  <span class="input-group-text ps-1 pe-1">
                    <ProfileImage
                      v-if="me?.profile?.path"
                      :image="me?.profile?.path"
                      class="m-0 inline"
                      is-thumbnail
                      size="sm"
                      :lazy="false"
                    />
                  </span>
                </slot>
                <AutoHeightTextarea
                  ref="threadcommentautoheight"
                  v-model="threadcomment"
                  size="sm"
                  rows="1"
                  max-rows="8"
                  maxlength="2048"
                  spellcheck="true"
                  placeholder="Write a comment on this thread..."
                  class="p-0 ps-1 pt-1 enternewline"
                  @focus="focusedComment"
                />
              </b-input-group>
            </OurAtTa>
          </div>
          <div
            v-else
            @keyup.enter.exact.prevent
            @keydown.enter.exact.prevent="sendComment"
          >
            <OurAtTa
              v-if="!newsfeed.deleted"
              ref="at"
              :members="tagusers"
              class="flex-shrink-2 input-group"
              :filter-match="filterMatch"
            >
              <b-input-group>
                <slot name="prepend">
                  <span class="input-group-text ps-2 pe-1">
                    <ProfileImage
                      v-if="me?.profile?.path"
                      :image="me?.profile?.path"
                      class="m-0 inline"
                      is-thumbnail
                      size="sm"
                    />
                  </span>
                </slot>
                <AutoHeightTextarea
                  ref="threadcommentautoheight"
                  v-model="threadcomment"
                  size="sm"
                  rows="1"
                  max-rows="8"
                  maxlength="2048"
                  spellcheck="true"
                  :placeholder="commentPlaceholder"
                  class="p-0 ps-2 pt-2 entersend"
                  :autocapitalize="autocapitalizeMode"
                  @keydown.enter.shift.exact.prevent="newlineComment"
                  @keydown.alt.shift.enter.exact.prevent="newlineComment"
                  @focus="focusedComment"
                />
              </b-input-group>
            </OurAtTa>
          </div>
          <div v-if="threadcomment" class="comment-toolbar">
            <button class="toolbar-btn" title="Add photo" @click="photoAdd">
              <v-icon icon="camera" />
              <span class="toolbar-label">Photo</span>
            </button>
            <button
              v-if="enterNewLine"
              class="toolbar-btn send-btn"
              :disabled="!threadcomment?.trim()"
              @click="sendComment"
            >
              <v-icon icon="paper-plane" />
              <span class="toolbar-label">Send</span>
            </button>
          </div>
          <OurUploadedImage
            v-if="ouruid"
            :src="ouruid"
            :modifiers="imagemods"
            alt="ChitChat Photo"
            width="100"
            class="mt-1 ms-4 image__uploaded"
          />
          <OurUploader
            v-if="uploading"
            v-model="currentAtts"
            class="bg-white m-0 pondrow"
            type="Newsfeed"
          />
        </span>
        <notice-message v-else>
          This thread is now closed. Thanks to everyone who contributed.
        </notice-message>
      </template>
    </b-card>
    <NewsEditModal
      v-if="showEditModal"
      :id="id"
      :threadhead="newsfeed?.threadhead"
    />
    <NewsReportModal
      v-if="showReportModal"
      :id="newsfeed.id"
      @hidden="showReportModal = false"
    />
    <NewsConvertModal
      v-if="showConvert"
      :newsfeed="newsfeed"
      :poster-name="newsfeed?.displayname || 'them'"
      @posted="onConverted"
      @hidden="showConvert = false"
    />
    <ConfirmModal
      v-if="showDeleteModal"
      :title="'Delete thread started by ' + starter"
      @confirm="deleteConfirmed"
      @hidden="showDeleteModal = false"
    />
  </div>
</template>
<script setup>
import {
  ref,
  computed,
  defineAsyncComponent,
  watch,
  onMounted,
  onBeforeUnmount,
  nextTick,
} from 'vue'
import AutoHeightTextarea from './AutoHeightTextarea'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import NewsReplies from '~/components/NewsReplies'
import { untwem } from '~/composables/useTwem'
import {
  scrollToAndPin,
  fixedHeaderOffset,
  imagesComplete,
  whenImagesComplete,
  whenAllSettled,
} from '~/composables/useScrollAnchor'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import { isIOS } from '~/composables/useIsIOS'

// Use standard import to avoid screen-flicker
import NewsRefer from '~/components/NewsRefer'
import NewsMessage from '~/components/NewsMessage'
import NewsAboutMe from '~/components/NewsAboutMe'
import NewsCommunityEvent from '~/components/NewsCommunityEvent'
import NewsVolunteerOpportunity from '~/components/NewsVolunteerOpportunity'
import NewsStory from '~/components/NewsStory'
import NewsAlert from '~/components/NewsAlert'
import NewsNoticeboard from '~/components/NewsNoticeboard'
import NoticeMessage from '~/components/NoticeMessage'
import NewsPreviews from '~/components/NewsPreviews'
import ProfileImage from '~/components/ProfileImage'
import { useTeamStore } from '~/stores/team'
import { useUserStore } from '~/stores/user'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  scrollTo: {
    type: String,
    required: false,
    default: '',
  },
  // 'thread' = the full conversation page; 'feed' = a short card in the
  // ChitChat feed. Forwarded to NewsReplies, which does the shortening.
  context: {
    type: String,
    required: false,
    default: 'thread',
  },
  // The reader asked to be taken to the new replies (the feed's "#new"
  // link), rather than just opening the thread.
  jumpToNew: {
    type: Boolean,
    required: false,
    default: false,
  },
  duplicateCount: {
    type: Number,
    required: false,
    default: 0,
  },
})

const emit = defineEmits(['rendered', 'expand-duplicates'])

// iOS auto-capitalise engages the virtual Shift key, so Return at a sentence
// start arrives as shift+enter and the keydown.enter.exact send never fires
// (angular/angular#32963 - iOS keyboard design, not a fixed bug). Keep the
// autocapitalize="none" workaround on iOS only.
const autocapitalizeMode = isIOS() ? 'none' : 'sentences'

const NewsReportModal = defineAsyncComponent(() => import('./NewsReportModal'))
const NewsConvertModal = defineAsyncComponent(
  () => import('./NewsConvertModal')
)
const ConfirmModal = defineAsyncComponent(
  () => import('~/components/ConfirmModal.vue')
)
const OurUploader = defineAsyncComponent(
  () => import('~/components/OurUploader')
)
const OurAtTa = defineAsyncComponent(() => import('~/components/OurAtTa'))

// Setup stores
const newsfeedStore = useNewsfeedStore()
const teamStore = useTeamStore()
const authStore = useAuthStore()
const userStore = useUserStore()
const miscStore = useMiscStore()
const mobileStore = useMobileStore()
const { chitChatMod, supportOrAdmin } = useMe()

// In the app there is no browser to open a new window in - target="_blank"
// bounces through the OS back into the app, which just reopens it. Hide the
// option there.
const isApp = computed(() => mobileStore.isApp)

const isMobile = computed(() => {
  return miscStore.breakpoint === 'xs' || miscStore.breakpoint === 'sm'
})

const commentPlaceholder = computed(() => {
  return isMobile.value ? 'Comment...' : 'Write a comment...'
})
const me = computed(() => authStore.user)
const myid = computed(() => me.value?.id)

// References
const at = ref(null)
const threadcomment = ref(null)
const threadcommentref = ref(null)
const threadcommentautoheight = ref(null)

// Reactive state
const replyingTo = ref(null)
const uploading = ref(false)
// Guards sendComment against double-submit (Enter binds keydown here; a stray double-fire or
// double-click would otherwise post twice before threadcomment is cleared). See NewsReply.vue.
const sending = ref(false)
const imageid = ref(null)
const ouruid = ref(null)
const imageuid = ref(null)
const imagemods = ref(null)
const showDeleteModal = ref(false)
const showEditModal = ref(false)
const showReportModal = ref(false)
const showConvert = ref(false)
const showThis = ref(true)
const currentAtts = ref([])

// Constants
const newsComponents = {
  AboutMe: NewsAboutMe,
  Message: NewsMessage,
  CommunityEvent: NewsCommunityEvent,
  VolunteerOpportunity: NewsVolunteerOpportunity,
  Story: NewsStory,
  Alert: NewsAlert,
  Noticeboard: NewsNoticeboard,
  NewsRefer,
}

const elementBackgroundColor = {
  CommunityEvent: 'card__community-event',
  VolunteerOpportunity: 'card__volunteer-opportunity',
}

// Computed properties
const canRefer = computed(() => {
  return (
    (mod.value && newsfeed.value?.type !== 'AboutMe') || supportOrAdmin.value
  )
})

const canStory = computed(() => {
  return mod.value && newsfeed.value?.type !== 'Story'
})

const enterNewLine = computed({
  get() {
    return me.value?.settings?.enterNewLine
  },
  async set(newVal) {
    const settings = me.value.settings
    settings.enterNewLine = newVal

    await authStore.saveAndGet({
      settings,
    })
  },
})

const newsfeed = computed(() => {
  return newsfeedStore?.byId(props.id)
})

const tagusers = computed(() => {
  return newsfeedStore?.tagusers?.map((u) => u.displayname)
})

const mod = computed(() => {
  return (
    me.value &&
    (me.value.systemrole === 'Moderator' ||
      me.value.systemrole === 'Admin' ||
      me.value.systemrole === 'Support')
  )
})

const backgroundColor = computed(() => {
  return elementBackgroundColor[newsfeed.value?.type] || 'card__default'
})

const isNewsComponent = computed(() => {
  return newsfeed.value?.type in newsComponents
})

const newsComponentName = computed(() => {
  return isNewsComponent.value ? newsComponents[newsfeed.value?.type] : ''
})

const starter = computed(() => {
  if (newsfeed.value?.userid === myid.value) {
    return 'you'
  } else if (newsfeed.value?.displayname) {
    return newsfeed.value.displayname
  } else {
    return 'someone'
  }
})

// Watchers
watch(
  currentAtts,
  (newVal) => {
    if (newVal.length > 0) {
      uploading.value = false
      imageid.value = newVal[0].id
      imageuid.value = newVal[0].ouruid
      ouruid.value = newVal[0].ouruid
      imagemods.value = newVal[0].externalmods
    }
  },
  { deep: true }
)

// Setup
// Get ChitChat moderation team so that we can show extra options for them.
if (
  me.value &&
  (me.value.systemrole === 'Moderator' ||
    me.value.systemrole === 'Support' ||
    me.value.systemrole === 'Admin')
) {
  teamStore.fetch('ChitChat Moderation')
}

// Fetch the newsfeed
await newsfeedStore.fetch(props.id)

// Lifecycle hooks
// One of the poster's own live posts saying the same thing, or null. Only
// fetched for ChitChat moderators - the server refuses it for anyone else, so
// a member's browser never holds it.
const duplicateOf = ref(null)

onMounted(async () => {
  // Scroll down now that the child components are rendered.
  emit('rendered')

  if (chitChatMod.value && isNewsComponent.value && !newsfeed.value?.hidden) {
    try {
      duplicateOf.value = await newsfeedStore.duplicate(props.id)
    } catch (e) {
      // Advisory only. If we can't work out whether this repeats one of their
      // own posts, the moderator still gets the thread and every control on it.
      duplicateOf.value = null
    }
  }
})

// True once the whole reply tree has reported mounting (or there was
// nothing to mount). Feeds the deep-link pin's completion signal.
const repliesRendered = ref(!newsfeed.value?.replies?.length)

// The pin this component started, if any, so unmount can stop it.
let ownPin = null
let deepLinkPinned = false

// A deep link whose target row never mounted: the reply was deleted (and is
// filtered out for non-mods) or otherwise gone. Explains the silent landing.
const targetGone = ref(false)

// scrollTo naming the thread itself means "open this thread", not "find this
// reply", so there is no target row to hunt for.
const isGeneralLanding = computed(() => {
  return !!props.scrollTo && parseInt(props.scrollTo) === parseInt(props.id)
})

onMounted(() => {
  if (!props.scrollTo) return

  threadContentSettled().then(() => {
    if (deepLinkPinned) return

    if (isGeneralLanding.value) {
      // Only jump to the new replies if the reader actually asked for them
      // (the feed's "N new" link, which says so via #new). Opening a thread
      // any other way should start at the top, like any other page.
      const divider = props.jumpToNew
        ? document.querySelector('[data-unread-divider]')
        : null

      if (divider) {
        deepLinkPinned = true
        ownPin = scrollToAndPin(
          () => document.querySelector('[data-unread-divider]'),
          {
            block: 'start',
            offset: fixedHeaderOffset(),
            done: threadContentSettled(),
          }
        )
      }
    } else {
      // Specific reply requested and everything has settled. If the target's
      // row is not in the DOM by now it never will be - deleted and filtered
      // out for non-mods, typically from a stale notification.
      const row = document.querySelector(
        `[data-reply-id="${props.scrollTo}"], [data-combined-ids~="${props.scrollTo}"]`
      )
      if (!row) {
        targetGone.value = true
      }
    }
  })
})

onBeforeUnmount(() => {
  if (ownPin) ownPin()
})

// An {ok, wait} condition from any reactive getter, for whenAllSettled.
function condition(get) {
  return {
    ok: () => !!get(),
    wait: () =>
      new Promise((resolve) => {
        const stop = watch(get, (v) => {
          if (v) {
            stop()
            resolve()
          }
        })
      }),
  }
}

// Resolves when nothing that could still move the layout is outstanding:
// the reply tree has fully mounted, no API requests are in flight and no
// images are still loading. Entirely event-driven - component rendered
// events, store reactivity and image load/error events. No timers: if a
// chunk takes ten seconds, the pin simply holds for ten seconds.
function threadContentSettled() {
  return whenAllSettled([
    condition(() => repliesRendered.value),
    condition(() => !miscStore.apiCount),
    {
      ok: () => imagesComplete(),
      wait: () => whenImagesComplete(),
    },
  ])
}

// Methods
function rendered(id) {
  // Deep link (/chitchat/<replyid>): hold the reply centred while the
  // rest of the thread streams in around it, releasing only when the
  // content is provably complete. The scrollTo prop flows down from mount
  // (NOT set after the target renders - the collapse must know the target
  // up front, or a target hidden behind "Show older replies" could never
  // mount to tell us). Once per page - re-mounts of the same target must
  // not restart a released pin.
  if (parseInt(id) === parseInt(props.scrollTo) && !deepLinkPinned) {
    deepLinkPinned = true
    // Compound selector: a combined block renders one row keyed by its FIRST
    // id and advertises the rest via data-combined-ids.
    ownPin = scrollToAndPin(
      () =>
        document.querySelector(
          `[data-reply-id="${props.scrollTo}"], [data-combined-ids~="${props.scrollTo}"]`
        ),
      {
        block: 'center',
        offset: fixedHeaderOffset(),
        done: threadContentSettled(),
      }
    )
  }
}

function focusComment() {
  console.log('Focus comment', threadcommentref.value)
  if (threadcommentref.value?.$el) {
    threadcommentref.value.$el.focus()
  }

  if (threadcommentautoheight.value) {
    threadcommentautoheight.value.focus()
  }
}

function focusedComment() {
  replyingTo.value = newsfeed.value.id
}

async function sendComment(callback) {
  // Re-entrancy guard against double-submit (see the sending ref above).
  if (sending.value) {
    return
  }
  if (threadcomment.value && threadcomment.value.trim()) {
    sending.value = true
    try {
      // Encode up any emojis.
      const msg = untwem(threadcomment.value)
      const newid = await newsfeedStore.send(
        msg,
        replyingTo.value,
        props.id,
        imageid.value
      )

      // New message will be shown because it's in the store and we have a computed
      // property. Keep the poster anchored to their reply: the post-send refetch
      // re-renders in the server's new order (replied-to parents get bumped), so
      // without this the viewport content swaps and the reply lands off-screen.
      // The pin re-resolves the selector on every correction, so it waits for
      // the refetch to render the new reply and holds it through the shuffle,
      // releasing when the refetch and image loads are complete.
      if (newid) {
        nextTick(() => {
          // Compound selector: the fresh reply may have combined into the
          // poster's previous block, whose row is keyed by the OLDER id.
          ownPin = scrollToAndPin(
            () =>
              document.querySelector(
                `[data-reply-id="${newid}"], [data-combined-ids~="${newid}"]`
              ),
            {
              block: 'center',
              done: whenAllSettled([
                condition(() => !miscStore.apiCount),
                {
                  ok: () => imagesComplete(),
                  wait: () => whenImagesComplete(),
                },
              ]),
            }
          )
        })
      }

      // Clear the textarea now it's sent.
      threadcomment.value = null

      // And any image id
      imageid.value = null
      imageuid.value = null
      ouruid.value = null
      imagemods.value = null
    } finally {
      sending.value = false
    }
  }

  if (typeof callback === 'function') {
    callback()
  }
}

function newlineComment() {
  if (threadcomment.value?.$el) {
    const p = threadcomment.value.$el.selectionStart
    if (p) {
      threadcomment.value.$el.value =
        threadcomment.value.$el.value.substring(0, p) +
        '\n' +
        threadcomment.value.$el.value.substring(p)
      nextTick(() => {
        threadcomment.value.$el.selectionStart = p + 1
        threadcomment.value.$el.selectionEnd = p + 1
      })
    } else {
      threadcomment.value.$el.value += '\n'
    }
  }
}

function show() {
  showEditModal.value = true
}

function expandDuplicates() {
  emit('expand-duplicates', props.id)
}

function deleteIt() {
  showDeleteModal.value = true
}

function deleteConfirmed() {
  newsfeedStore.delete(props.id, props.id)
}

async function unfollow() {
  await newsfeedStore.unfollow(props.id)
}

function report() {
  showReportModal.value = true
}

function referToOffer() {
  referTo('Offer')
}

function referToWanted() {
  referTo('Wanted')
}

function referToTaken() {
  referTo('Taken')
}

function referToReceived() {
  referTo('Received')
}

async function createStory() {
  await newsfeedStore.convertToStory(props.id)
}

async function unhide() {
  await newsfeedStore.unhide(props.id)
}

async function hide() {
  await newsfeedStore.hide(props.id)
}

// A volunteer has just posted this properly for the member. The thread now
// carries the note telling them, and the duplicate warning would be stale
// (their new post IS the thing this repeats), so drop it.
async function onConverted() {
  duplicateOf.value = null
  await newsfeedStore.fetch(props.id, true)
}

async function referTo(type) {
  await newsfeedStore.referTo(props.id, type)
}

function filterMatch(name, chunk) {
  // Only match at start of string.
  return name.toLowerCase().indexOf(chunk.toLowerCase()) === 0
}

function photoAdd() {
  // Flag that we're uploading.  This will trigger the render of the filepond instance and subsequently the
  // init callback below.
  uploading.value = true
}

async function mute() {
  await userStore.muteOnChitChat(newsfeed.value.userid)
  await newsfeedStore.fetch(props.id)
}

async function unmute() {
  await userStore.unMuteOnChitChat(newsfeed.value.userid)
  await newsfeedStore.fetch(props.id)
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

:deep(.img-thumbnail) {
  cursor: pointer;

  max-width: 100px;

  @include media-breakpoint-up(lg) {
    max-width: 100%;
  }
}

.card__community-event {
  background-color: $color-gray-1;
  border-radius: var(--radius-md, 0.375rem);
}

.card__volunteer-opportunity {
  background-color: $color-gray-2;
  border-radius: var(--radius-md, 0.375rem);
}

/* The comment box sat flush against the last reply, so the two ran together.
   A span is inline by default, hence display: block for the margin to apply. */
.comment-box {
  display: block;
  margin-top: 0.75rem;
}

.card__default {
  background-color: $color-white;
  border-radius: var(--radius-md, 0.375rem);
}

.image__uploaded {
  width: 100px;
}

:deep(.dropdown-toggle) {
  padding-right: 13px;
  padding-left: 10px;
}

:deep(.dropdown-menu) {
  z-index: 10000;
}

.thread-menu {
  /* The "..." menu is floated to the end; the post text wraps beside it on the
     first line (and reclaims full width below, so no space is wasted). Give it a
     clear gap so it does not sit right up against the text — 0.5rem (the first
     attempt) was too small on inline name+body replies at narrow/iPad widths
     (Discourse #9749 - chitchat niggle). */
  margin-left: 1.5rem;
}

:deep(.strike) {
  text-decoration: line-through;
}

.comment-toolbar {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
  margin-left: 0.5rem;
}

.toolbar-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.625rem;
  background: $color-gray--lighter;
  border: none;
  border-radius: var(--radius-sm, 0.375rem);
  color: $color-gray--darker;
  font-size: 0.875rem;
  cursor: pointer;
  transition: background-color var(--transition-fast);

  &:hover {
    background: darken($color-gray--lighter, 5%);
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

.send-btn {
  background: $color-success;
  color: white;

  &:hover:not(:disabled) {
    background: darken($color-success, 8%);
  }
}

.toolbar-label {
  @include media-breakpoint-down(sm) {
    display: none;
  }
}

/* Wraps rather than stretching the dropdown to the width of the sentence. */
.convert-hint {
  white-space: normal;
  line-height: 1.2;
  max-width: 16rem;
}
</style>
