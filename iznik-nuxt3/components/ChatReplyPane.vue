<template>
  <!-- Hidden while the forced-login or new-user welcome modal is up so the
       overlay can't sit over (and intercept clicks on) those teleported modals;
       the pane stays mounted so reply state is preserved, and it reappears once
       they're dismissed. -->
  <div
    v-show="!forceLogin && !stateMachine.showWelcomeModal.value"
    class="reply-overlay"
    role="dialog"
    aria-modal="true"
    aria-label="Reply"
    @click.self="close"
  >
    <div class="reply-card">
      <!-- Header: matches the real chat header so the transition is seamless -->
      <header class="reply-card__header">
        <button
          type="button"
          class="reply-card__back"
          aria-label="Go back"
          @click="close"
        >
          <v-icon icon="arrow-left" />
        </button>
        <ProfileImage
          v-if="poster"
          :image="poster.profile?.paththumb"
          :externaluid="poster.profile?.externaluid"
          :ouruid="poster.profile?.ouruid"
          :externalmods="poster.profile?.externalmods"
          :name="poster.displayname"
          class="reply-card__avatar"
          is-thumbnail
          size="md"
        />
        <div class="reply-card__heading">
          <div class="reply-card__name-row">
            <span class="reply-card__name">{{
              poster?.displayname || 'Freegler'
            }}</span>
            <SupporterInfo
              v-if="poster?.supporter"
              size="sm"
              class="reply-card__supporter"
            />
          </div>
          <!-- Same profile info the chat header shows: ratings, when they were last
               seen, typical reply time and how far away they are - so replying from
               browse/email carries the same reassurance as being in the chat. -->
          <div v-if="poster && poster.info" class="reply-card__stats">
            <UserRatings :id="poster.id" size="sm" />
            <span v-if="posterLastSeen" class="reply-stat-chip">
              <v-icon icon="clock" class="reply-stat-icon" />
              {{ posterLastSeen }}
            </span>
            <span v-if="replytime" class="reply-stat-chip">
              <v-icon icon="reply" class="reply-stat-icon" />
              {{ replytime }}
            </span>
            <span v-if="milesaway" class="reply-stat-chip">
              <v-icon icon="map-marker-alt" class="reply-stat-icon" />
              {{ milesaway }} miles
            </span>
          </div>
        </div>
      </header>

      <!-- Body: chat-style background with the post shown as a received message -->
      <div class="reply-card__body">
        <p v-if="message" class="reply-card__intro">
          Send {{ poster?.displayname || 'this freegler' }} a message about
          {{ message.type === 'Offer' ? 'their offer' : 'what they want' }}.
        </p>

        <!-- The post being replied to, shown as a chat message from the poster.
             We swallow the card's own click (which would navigate to the post
             page — pointless while you're already replying to it) but, when the
             post has photos, repurpose the tap to open the photo zoom modal so
             people arriving from an email can still enlarge the picture. -->
        <div
          v-if="message"
          class="reply-card__incoming"
          @click.capture.stop.prevent="onPostClick"
        >
          <ChatMessageCard :id="messageId" class="reply-card__post" />
        </div>

        <!-- Rippled-in explainer, aligned under the post card: it reached the
             viewer's area on a later day than it was first posted, so make that
             gap explicit rather than leaving the "posted N days ago" read feel
             wrong. Only rendered when rippleDates is set (see rippledInAreaDates):
             hidden for non-rippled posts and same-day ripples. -->
        <p v-if="rippleDates" class="reply-card__ripple">
          Posted {{ fmt(rippleDates.firstPosted) }}, available in your area from
          {{ fmt(rippleDates.availableFrom) }}
        </p>

        <!-- Delivery notice -->
        <NoticeMessage
          v-if="message?.deliverypossible"
          variant="info"
          class="reply-card__notice"
        >
          <v-icon icon="info-circle" /> Delivery may be possible
        </NoticeMessage>

        <!-- Distance warning -->
        <NoticeMessage
          v-if="milesaway > faraway && message?.type === 'Offer'"
          variant="warning"
          class="reply-card__notice"
        >
          This item is {{ milesaway }} miles away. Before replying, are you sure
          you can collect from there?
        </NoticeMessage>

        <!-- Promised warning -->
        <NoticeMessage
          v-if="message?.promised && !message?.promisedtome"
          variant="warning"
          class="reply-card__notice"
        >
          Already promised — you might not get it.
        </NoticeMessage>

        <!-- Account deleted notice -->
        <NoticeMessage
          v-if="me?.deleted"
          variant="danger"
          class="reply-card__notice"
        >
          You can't reply until you've decided whether to restore your account.
        </NoticeMessage>
      </div>

      <!-- Rippling-out reply hold (#5): the reach hasn't reached this viewer yet. We no longer
           hide the composer — the reply is accepted and HELD, then delivered when the post
           ripples to them (the server records a rippling_held_replies row). Tell them what
           will happen so the send isn't a surprise. -->
      <NoticeMessage
        v-if="reachBlocked && !me?.deleted"
        variant="info"
        class="reply-card__reach-blocked"
      >
        This hasn't reached your area yet — but go ahead and reply. We'll pass
        it on to the owner as soon as it does.
      </NoticeMessage>

      <!-- Composer: matches the real chat footer. The fields scroll inside
           composer-scrollable; the error notice and Send row stay pinned
           below so the primary action is never scrolled out of view, even
           in very short windows. -->
      <div v-if="!me?.deleted" class="reply-card__composer">
        <div class="composer-scrollable">
          <!-- Email for logged-out users -->
          <div v-if="!me" class="composer-field">
            <EmailValidator
              ref="emailValidatorRef"
              v-model:email="stateMachine.email.value"
              v-model:valid="stateMachine.emailValid.value"
              size="lg"
              label="Your email address"
              class="test-email-reply-validator"
            />
          </div>

          <VeeForm ref="form" class="composer-form">
            <!-- Reply text -->
            <div class="composer-field">
              <label
                :for="'replytomessage-' + messageId"
                class="composer-label"
              >
                Your message
              </label>
              <Field
                :id="'replytomessage-' + messageId"
                v-model="stateMachine.replyText.value"
                name="reply"
                :rules="validateReply"
                :validate-on-mount="false"
                :validate-on-model-update="false"
                as="textarea"
                rows="3"
                class="composer-input"
                :placeholder="
                  message?.type === 'Offer'
                    ? 'Explain why you\'d like it…'
                    : 'Can you help? Let them know…'
                "
                @input="stateMachine.startTyping"
              />
              <ErrorMessage name="reply" class="composer-error" />
            </div>

            <!-- Collection time (Offers only) -->
            <div
              v-if="message?.type === 'Offer'"
              class="composer-field composer-field--collect"
            >
              <label
                :for="'replytomessage2-' + messageId"
                class="composer-label"
              >
                <v-icon icon="calendar-alt" class="composer-label-icon" />
                When could you collect?
              </label>
              <Field
                :id="'replytomessage2-' + messageId"
                v-model="stateMachine.collectText.value"
                name="collect"
                :rules="validateCollect"
                :validate-on-mount="false"
                :validate-on-model-update="false"
                as="textarea"
                rows="2"
                class="composer-input"
                placeholder="e.g. weekday evenings or this weekend"
              />
              <ErrorMessage name="collect" class="composer-error" />
            </div>
          </VeeForm>

          <p v-if="me && !alreadyAMember" class="composer-hint">
            You're not yet a member of this community; we'll join you. Change
            emails or leave communities from <em>Settings</em>.
          </p>

          <NewFreegler v-if="!me" class="composer-hint" />
        </div>

        <!-- Error message -->
        <NoticeMessage
          v-if="stateMachine.error.value"
          variant="danger"
          class="reply-card__notice mb-0"
        >
          {{ stateMachine.error.value }}
          <b-button
            variant="link"
            size="sm"
            class="p-0 ms-2"
            @click="stateMachine.retry"
          >
            Try again
          </b-button>
        </NoticeMessage>

        <!-- Send -->
        <div class="composer-send">
          <SpinButton
            variant="primary"
            size="lg"
            done-icon=""
            icon-name="angle-double-right"
            :disabled="
              !stateMachine.canSend.value || stateMachine.isProcessing.value
            "
            iconlast
            class="composer-send-btn"
            @handle="handleSend"
          >
            Send
          </SpinButton>
        </div>
      </div>
    </div>

    <!-- Welcome modal for new users -->
    <b-modal
      v-if="stateMachine.showWelcomeModal.value"
      id="newUserModal"
      ref="newUserModal"
      scrollable
      ok-only
      ok-title="Close and Continue"
      @ok="handleNewUserModalOk"
    >
      <template #title>
        <h2>Welcome to Freegle!</h2>
      </template>
      <NewUserInfo :password="stateMachine.newUserPassword.value" />
    </b-modal>

    <!-- Hidden ChatButton for state machine -->
    <div class="d-none">
      <ChatButton ref="replyToPostChatButton" :userid="replyToUser" />
    </div>

    <!-- Photo zoom: lets people enlarge the post's photo from inside the reply
         pane, matching the behaviour on Browse. -->
    <MessagePhotosModal
      v-if="showPhotos && attachmentCount"
      :id="messageId"
      @hidden="showPhotos = false"
    />
  </div>
</template>

<script setup>
import { Form as VeeForm, Field, ErrorMessage } from 'vee-validate'
import {
  defineAsyncComponent,
  ref,
  computed,
  watch,
  nextTick,
  onMounted,
  onUnmounted,
} from 'vue'
import dayjs from 'dayjs'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { useMiscStore } from '~/stores/misc'
import { useAuthStore } from '~/stores/auth'
import { milesAway } from '~/composables/useDistance'
import { useMe } from '~/composables/useMe'
import {
  useReplyStateMachine,
  ReplyState,
} from '~/composables/useReplyStateMachine'
import { useRoute } from '#imports'
import { action } from '~/composables/useClientLog'
import { replySurfaceForRoute } from '~/composables/useReplySurface'
import EmailValidator from '~/components/EmailValidator'
import NewUserInfo from '~/components/NewUserInfo'
import ChatButton from '~/components/ChatButton'
import ChatMessageCard from '~/components/ChatMessageCard'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage'
import ProfileImage from '~/components/ProfileImage'
import UserRatings from '~/components/UserRatings'
import SupporterInfo from '~/components/SupporterInfo'
import { timeago } from '~/composables/useTimeFormat'
import { rippledInAreaDates } from '~/composables/rippleStatus'
import { FAR_AWAY } from '~/constants'

const NewFreegler = defineAsyncComponent(
  () => import('~/components/NewFreegler')
)
const MessagePhotosModal = defineAsyncComponent(
  () => import('~/components/MessagePhotosModal')
)

const props = defineProps({
  messageId: {
    type: Number,
    required: true,
  },
  // When the reply was started from a list page (browse / explore), send the
  // reply without navigating to the chat, so the opener can keep the user on
  // that list to reply to more items.
  stayOnSend: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'sent'])

const faraway = FAR_AWAY

const messageStore = useMessageStore()
const userStore = useUserStore()
// Captured at setup (useRoute needs the component context); read at send time -
// the route object is reactive so it reflects wherever the user actually is then.
const route = useRoute()
const miscStore = useMiscStore()
const authStore = useAuthStore()
const forceLogin = computed(() => authStore.forceLogin)
const { me, myGroups } = useMe()

// Initialize state machine
const stateMachine = useReplyStateMachine(props.messageId, {
  stayOnPage: props.stayOnSend,
})

// References
const form = ref(null)
const newUserModal = ref(null)
const replyToPostChatButton = ref(null)
const emailValidatorRef = ref(null)

// Fetch the message data. Guarded: this is a top-level await in async setup
// under <Suspense>, so an unhandled rejection (e.g. a transient connectivity
// blip) would mean the overlay never mounts and the Reply click is a silent
// no-op. The message is almost always already in the store from the page
// that opened this pane, so on failure render with cached data instead of
// nothing; the composer gates itself if data is genuinely missing.
try {
  await messageStore.fetch(props.messageId)
} catch (e) {
  action('chatreplypane_fetch_failed', { message_id: props.messageId })
}

const message = computed(() => {
  return messageStore?.byId(props.messageId)
})

// Rippling-out reply gate (#5): the API returns replyeligible === false when the post is
// visible to this viewer but its reach hasn't reached them yet. Gate the composer so no entry
// path shows a reply box whose send the server-side reach check would reject.
const reachBlocked = computed(() => message.value?.replyeligible === false)

const attachmentCount = computed(() => message.value?.attachments?.length || 0)

// Tapping the post card would normally navigate to the post page; inside the
// reply pane that's pointless (you're already replying), so we open the photo
// zoom modal instead when there's a photo to enlarge.
const showPhotos = ref(false)
function onPostClick() {
  if (attachmentCount.value) {
    showPhotos.value = true
  }
}

// Fetch poster info
watch(
  () => message.value?.fromuser,
  (userId) => {
    if (userId) {
      userStore.fetch(userId)
    }
  },
  { immediate: true }
)

const poster = computed(() => {
  return message.value?.fromuser
    ? userStore?.byId(message.value?.fromuser)
    : null
})

// When the poster was last seen, formatted like the chat header (no trailing "ago").
const posterLastSeen = computed(() => {
  if (!poster.value?.lastaccess) return null
  return timeago(poster.value.lastaccess).replace(/ ago$/, '')
})

// How quickly the poster typically replies - mirrors the chat header's phrasing.
const replytime = computed(() => {
  const secs = poster.value?.info?.replytime
  if (!secs) return null

  let val
  let unit
  if (secs < 60) {
    val = Math.round(secs)
    unit = 'second'
  } else if (secs < 60 * 60) {
    val = Math.round(secs / 60)
    unit = 'minute'
  } else if (secs < 24 * 60 * 60) {
    val = Math.round(secs / 60 / 60)
    unit = 'hour'
  } else {
    val = Math.round(secs / 60 / 60 / 24)
    unit = 'day'
  }

  return `${val} ${unit}${val === 1 ? '' : 's'}`
})

// Cross-day rippled-in dates for the note under the post card (null when not applicable).
const rippleDates = computed(() =>
  rippledInAreaDates(message.value?.groups, myGroups.value)
)

function fmt(val) {
  const d = dayjs(val)
  return d.year() === dayjs().year()
    ? d.format('D MMM')
    : d.format('D MMM YYYY')
}

const milesaway = computed(() => {
  return milesAway(
    me.value?.lat,
    me.value?.lng,
    message.value?.lat,
    message.value?.lng
  )
})

const alreadyAMember = computed(() => {
  let found = false

  if (message.value?.groups) {
    for (const messageGroup of message.value.groups) {
      Object.keys(myGroups.value).forEach((key) => {
        const group = myGroups.value[key]

        if (messageGroup.groupid === group.id) {
          found = true
        }
      })
    }
  }

  return found
})

const replyToUser = computed(() => {
  return message.value?.fromuser
})

// Watch for login state changes to resume authentication flow
watch(me, async (newVal, oldVal) => {
  if (
    !oldVal &&
    newVal &&
    stateMachine.state.value === ReplyState.AUTHENTICATING
  ) {
    try {
      await stateMachine.onLoginSuccess()
    } catch (e) {
      console.error(
        '[ChatReplyPane] onLoginSuccess failed, falling back to COMPOSING:',
        e
      )
    }
  }
})

// Watch for chat button ref becoming available
watch(replyToPostChatButton, (newVal) => {
  if (newVal) {
    stateMachine.setRefs({ chatButton: newVal })
  }
})

// Watch for form ref
watch(form, (newVal) => {
  if (newVal) {
    stateMachine.setRefs({ form: newVal })
  }
})

// Set refs on mount
onMounted(() => {
  // Hide the sticky ad/jobs banner while the overlay is open.
  miscStore.setReplyOverlayOpen(true)

  stateMachine.setRefs({
    form: form.value,
    chatButton: replyToPostChatButton.value,
    emailValidator: emailValidatorRef.value,
  })

  action('chat_reply_pane_viewed', {
    message_id: props.messageId,
    reply_source: replySurfaceForRoute(route),
    message_type: message.value?.type,
    is_logged_in: !!me.value,
  })
})

onUnmounted(() => {
  miscStore.setReplyOverlayOpen(false)
})

// Watch for state machine completion - the ChatButton's openChat already
// navigates to /chats/:id, so we just let the opener know the reply was sent.
watch(
  () => stateMachine.isComplete.value,
  (isComplete) => {
    if (isComplete) {
      emit('sent')
    }
  }
)

// Watch for welcome modal state
watch(
  () => stateMachine.showWelcomeModal.value,
  async (showModal) => {
    if (showModal) {
      await nextTick()
      newUserModal.value?.show()
    }
  }
)

function validateCollect(value) {
  if (value && value.trim()) {
    return true
  }
  return 'Please suggest some days and times when you could collect.'
}

function validateReply(value) {
  if (!value?.trim()) {
    return 'Please fill out your reply.'
  }

  if (
    message.value?.type === 'Offer' &&
    value &&
    value.length <= 35 &&
    value.toLowerCase().includes('still available')
  ) {
    return (
      "You don't need to ask if things are still available. Just write whatever you " +
      "would have said next - explain why you'd like it and when you could collect."
    )
  }

  return true
}

async function handleSend(callback) {
  // Ensure refs are set before submitting
  stateMachine.setRefs({
    form: form.value,
    chatButton: replyToPostChatButton.value,
    emailValidator: emailValidatorRef.value,
  })

  // The surface the user is committing the reply from (browse, search, message_page,
  // email deep link, ...) - sent to the server as advisory provenance for the rippling
  // reply attribution, and logged with the Loki reply_* events.
  stateMachine.setReplySource(replySurfaceForRoute(route))
  await stateMachine.submit(callback)
}

function handleNewUserModalOk() {
  stateMachine.closeWelcomeModal()
}

function close() {
  emit('close')
}
</script>

<style scoped lang="scss">
$reply-header-green: #61ae24;
$reply-body-bg: #f5f5f5;
$reply-input-bg: #faf9f7;
$reply-border: #cdcdcd;

.reply-overlay {
  position: fixed;
  inset: 0;
  /* Above the sticky ad banner and bottom nav (z 10000 / 1030) but below the
     forced-login modal (.verytop, z 10000 teleported after us). */
  z-index: 9999;
  display: flex;
  background: $color-white;

  /* On larger screens, present as a focused card on a dimmed backdrop. */
  @media (min-width: 992px) {
    background: rgba(0, 0, 0, 0.45);
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
}

.reply-card {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
  min-height: 0;
  background: $color-white;
  overflow: hidden;

  @media (min-width: 992px) {
    width: 100%;
    max-width: 640px;
    height: 100%;
    max-height: 780px;
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.28);
  }
}

/* ---- Header ---- */
/* Light profile header, matching the chat page's own header so replying feels
   like a continuation of the same conversation. */
.reply-card__header {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  padding: 10px 14px;
  background: $color-white;
  color: $color-gray--darker;
  border-bottom: 1px solid $reply-border;
  min-height: 60px;
}

.reply-card__back {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: $color-gray--lighter;
  color: $color-gray--darker;
  font-size: 1.1rem;
  cursor: pointer;
  transition: background 0.15s ease;

  &:hover,
  &:focus-visible {
    background: $color-gray--light;
    outline: none;
  }
}

.reply-card__avatar {
  flex-shrink: 0;
}

.reply-card__heading {
  display: flex;
  flex-direction: column;
  gap: 3px;
  /* Take the space between the avatar and the edge, and allow shrinking so a long
     display name ellipsises and the stat chips wrap instead of overflowing. */
  flex: 1 1 auto;
  min-width: 0;
  line-height: 1.25;
}

.reply-card__name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.reply-card__name {
  font-weight: 700;
  font-size: 1.05rem;
  color: $color-gray--darker;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.reply-card__supporter {
  flex-shrink: 0;
}

.reply-card__stats {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.reply-stat-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  background: $color-gray--lighter;
  font-size: 0.75rem;
  color: $color-gray--darker;
  font-weight: 500;
  border-radius: var(--radius-sm, 0.375rem);
}

.reply-stat-icon {
  font-size: 0.7rem;
  color: $color-green--dark;
}

/* ---- Body (chat conversation area) ---- */
.reply-card__body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 16px 14px;
  background-color: $reply-body-bg;
  background-image: url('/chat-pattern.svg');
  background-repeat: repeat;
}

/* Body content is left-aligned as a single column so the intro, the post card and
   the rippled-in caption all share one edge (no mix of centred and left-aligned). */
.reply-card__intro {
  margin: 0 0 12px;
  text-align: left;
  font-size: 0.8rem;
  color: $color-gray--dark;
}

.reply-card__incoming {
  display: flex;
  justify-content: flex-start;
  margin-bottom: 8px;
}

.reply-card__post {
  width: 100%;
  max-width: 280px;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.16);
  cursor: default;
}

/* Caption under the card, sharing the body's left edge. Kept to a single line
   (the body is wider than the card, so it doesn't wrap once "First" is dropped). */
.reply-card__ripple {
  margin: 0 0 12px;
  text-align: left;
  white-space: nowrap;
  font-size: 0.72rem;
  color: $color-gray--dark;
}

.reply-card__notice {
  margin-bottom: 10px;
}

/* ---- Composer (chat footer) ---- */
.reply-card__composer {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  max-height: 62%;
  padding: 12px 14px calc(12px + env(safe-area-inset-bottom, 0px));
  background: $color-white;
  border-top: 1px solid $reply-border;
}

/* The fields scroll; the error notice and Send row below stay pinned so the
   primary action can't end up below the fold in short windows (e.g. 820x420,
   an email client's embedded browser). */
.composer-scrollable {
  min-height: 0;
  overflow-y: auto;
}

.composer-form {
  display: flex;
  flex-direction: column;
}

.composer-field {
  margin-bottom: 10px;

  &:last-child {
    margin-bottom: 0;
  }
}

.composer-label {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
  font-weight: 600;
  font-size: 0.85rem;
  color: $color-gray--dark;
}

.composer-label-icon {
  color: $reply-header-green;
}

.composer-input {
  width: 100%;
  border: 1px solid $reply-border;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 0.95rem;
  background: $reply-input-bg;
  resize: none;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    background 0.15s ease;

  &::placeholder {
    color: $color-gray--normal;
  }

  &:focus {
    outline: none;
    background: $color-white;
    border-color: $reply-header-green;
    box-shadow: 0 0 0 3px rgba(97, 174, 36, 0.16);
  }
}

.composer-error {
  display: block;
  margin-top: 4px;
  color: $color-red;
  font-weight: 700;
  font-size: 0.85rem;
}

.composer-hint {
  margin: 8px 0 0;
  font-size: 0.78rem;
  color: $color-gray--dark;

  :deep(p) {
    margin: 0;
    font-size: 0.78rem;
    color: $color-gray--dark;
  }
}

.composer-send {
  display: flex;
  justify-content: flex-end;
  flex-shrink: 0;
  margin-top: 12px;
}

.composer-send-btn {
  min-width: 140px;
}

@media (max-width: 575px) {
  .composer-send-btn {
    width: 100%;
  }
}
</style>
