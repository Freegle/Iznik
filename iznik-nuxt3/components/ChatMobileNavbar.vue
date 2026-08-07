<template>
  <div>
    <!-- Main navbar row -->
    <div
      type="dark"
      class="ourBack layout fixed-top d-flex justify-content-between align-items-center"
    >
      <OfflineIndicator v-if="!online" class="offline" />
      <div v-else class="nav-back-btn" @click="backButton">
        <v-icon icon="arrow-left" class="back-icon" />
        <b-badge v-if="backButtonCount" variant="danger" class="back-badge">
          {{ backButtonCount }}
        </b-badge>
      </div>
      <div class="chat-name-area">
        <h1 class="text-white m-0 other-user-name">
          {{ chat.name }}
        </h1>
      </div>
      <button
        v-if="unseen && (!profileCardExpanded || showProfileHint)"
        class="navbar-mark-read"
        @click.stop="markRead"
      >
        <v-icon icon="check" class="navbar-mark-read-icon" />
        <b-badge variant="danger" class="navbar-mark-read-badge">{{
          unseen
        }}</b-badge>
      </button>
      <div
        id="other-user-group"
        ref="expandBtnRef"
        class="other-user-group"
        :class="{ clickme: chat.chattype === 'User2User' }"
        @click="chat.chattype === 'User2User' ? toggleProfileCard() : null"
      >
        <ProfileImage
          v-if="chat.icon"
          :image="chat.icon"
          :name="chat.name"
          class="other-user-avatar"
          is-thumbnail
          size="lg"
        />
      </div>
    </div>

    <!-- Profile popover — only for User2User where other user info is available.
         A User2Mod (contact-the-volunteers) chat has no single other-user profile,
         so this rendered as an empty white box under the header (Discourse 9918). -->
    <b-popover
      v-if="cssReady && chat.chattype === 'User2User'"
      v-model="profileCardExpanded"
      target="other-user-group"
      placement="bottom"
      custom-class="profile-popover"
      manual
    >
      <div v-if="otheruser && otheruser.info" class="profile-card-content">
        <!-- Until this was here the card could only be dismissed by tapping the
             avatar again, taking one of the actions, or scrolling the thread -
             none of which look like a way out, so it read as stuck open.
             Hidden while the first-visit hint is up: "Got it" already closes the
             whole card (dismissHint), so a second control there would only
             overlap it. -->
        <button
          v-if="!showProfileHint"
          class="profile-card-close"
          type="button"
          aria-label="Close profile info"
          title="Close"
          @click="profileCardExpanded = false"
        >
          <v-icon icon="times" />
        </button>
        <!-- Hint tip for first-time visitors -->
        <div v-if="showProfileHint" class="profile-hint-tip">
          <span>Tap here to show profile info.</span>
          <button class="profile-hint-btn" @click="dismissHint">Got it</button>
        </div>
        <div class="profile-card-main">
          <div class="profile-card-avatar-section">
            <div class="avatar-wrapper">
              <ProfileImage
                :image="chat.icon"
                :name="chat.name"
                class="profile-card-avatar clickme"
                is-thumbnail
                size="lg"
                @click="showInfo"
              />
              <v-icon
                v-if="otheruser.supporter"
                icon="trophy"
                class="supporter-icon"
              />
            </div>
            <SupporterInfo v-if="otheruser.supporter" class="supporter-badge" />
          </div>
          <div class="profile-card-details">
            <div class="profile-card-badges">
              <UserRatings
                :id="chat.otheruid"
                :key="'otheruser-' + chat.otheruid"
                size="sm"
                no-tooltips
                external-modals
                @show-down-modal="handleShowDownModal"
                @show-remove-modal="handleShowRemoveModal"
              />
            </div>
            <div class="profile-card-stats">
              <span
                v-if="otheruser.lastaccess"
                v-b-tooltip.bottom="LAST_SEEN_TOOLTIP"
                class="stat-chip"
              >
                <v-icon icon="clock" class="stat-icon" />
                <span class="stat-label">Last seen</span>
                {{ lastSeenAgo }}
              </span>
              <span
                v-if="replytimeFull"
                v-b-tooltip.bottom="REPLY_TIME_TOOLTIP"
                class="stat-chip"
              >
                <v-icon icon="reply" class="stat-icon" />
                <span class="stat-label">Replies in</span>
                {{ replytimeFull }}
              </span>
              <span
                v-if="!otheruser?.deleted && milesaway"
                v-b-tooltip.bottom="DISTANCE_TOOLTIP"
                class="stat-chip"
              >
                <v-icon icon="map-marker-alt" class="stat-icon" />
                {{ milesaway }} miles away
              </span>
            </div>
          </div>
        </div>
      </div>
      <!-- Action buttons -->
      <div class="profile-card-actions">
        <button
          v-if="unseen"
          class="action-btn action-btn--mark-read"
          @click="markRead"
        >
          <v-icon icon="check" class="action-icon" />
          <span>Mark read</span>
          <b-badge variant="danger" class="mark-read-badge">{{
            unseen
          }}</b-badge>
        </button>
        <button
          v-if="otheruser && otheruser.info && !otheruser?.deleted"
          class="action-btn"
          @click="showInfo"
        >
          <v-icon icon="user" class="action-icon" />
          <span>Profile</span>
        </button>
        <button
          v-if="chat.chattype !== 'User2Mod' || chat.status === 'Closed'"
          class="action-btn"
          @click="chat.status === 'Closed' ? unhide() : showhide()"
        >
          <v-icon
            :icon="chat.status === 'Closed' ? 'eye' : 'eye-slash'"
            class="action-icon"
          />
          <span>{{ chat.status === 'Closed' ? 'Show' : 'Hide' }}</span>
        </button>
        <button
          v-if="chat.chattype === 'User2User' && otheruser"
          class="action-btn"
          @click="chat.status === 'Blocked' ? unhide() : showblock()"
        >
          <v-icon icon="ban" class="action-icon" />
          <span>{{ chat.status === 'Blocked' ? 'Unblock' : 'Block' }}</span>
        </button>
        <button
          v-if="
            chat.chattype === 'User2User' && otheruser && !otheruser?.deleted
          "
          class="action-btn action-btn--danger"
          @click="report"
        >
          <v-icon icon="flag" class="action-icon" />
          <span>Report</span>
        </button>
      </div>
    </b-popover>

    <!-- Modals -->
    <ChatBlockModal
      v-if="showChatBlock && chat.chattype === 'User2User'"
      :id="id"
      :user="otheruser"
      @confirm="block"
      @hidden="showChatBlock = false"
    />
    <ChatHideModal
      v-if="
        showChatHide &&
        (chat.chattype === 'User2User' || chat.chattype === 'User2Mod')
      "
      :id="id"
      :user="otheruser"
      @confirm="hide"
      @hidden="showChatHide = false"
    />
    <ChatReportModal
      v-if="showChatReport && chat.chattype === 'User2User'"
      :id="'report-' + id"
      :user="otheruser"
      :chatid="chat.id"
      @confirm="hide"
      @hidden="showChatReport = false"
    />

    <ProfileModal
      v-if="showProfileModal"
      :id="otheruser?.id"
      close-on-message
      @hidden="showProfileModal = false"
    />

    <UserRatingsDownModal
      v-if="showRatingsDownModal && ratingsUserId"
      :id="ratingsUserId"
      @hidden="showRatingsDownModal = false"
    />
    <UserRatingsRemoveModal
      v-if="showRatingsRemoveModal && ratingsUserId"
      :id="ratingsUserId"
      @hidden="showRatingsRemoveModal = false"
    />
  </div>
</template>
<script setup>
import { nextTick } from 'vue'
import { useRouter } from '#imports'
import {
  clearNavBarTimeout,
  setNavBarHidden,
  useNavbar,
  navBarHidden,
} from '~/composables/useNavbar'
import { useChatStore } from '~/stores/chat'
import { useMiscStore } from '~/stores/misc'
import { setupChat } from '~/composables/useChat'
import { timeago } from '~/composables/useTimeFormat'

const router = useRouter()

const ChatBlockModal = defineAsyncComponent(() => import('./ChatBlockModal'))
const ChatHideModal = defineAsyncComponent(() => import('./ChatHideModal'))
const ChatReportModal = defineAsyncComponent(() =>
  import('~/components/ChatReportModal')
)
const UserRatingsDownModal = defineAsyncComponent(() =>
  import('~/components/UserRatingsDownModal')
)
const UserRatingsRemoveModal = defineAsyncComponent(() =>
  import('~/components/UserRatingsRemoveModal')
)

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const chatStore = useChatStore()
const chat = chatStore.byChatId(props.id)

const { online, backButtonCount, backButton } = useNavbar()

const { otheruser, milesaway, unseen } = await setupChat(props.id)

// Modal states
const showChatBlock = ref(false)
const showChatHide = ref(false)
const showChatReport = ref(false)
const showRatingsDownModal = ref(false)
const showRatingsRemoveModal = ref(false)
const ratingsUserId = ref(null)

// Keeps the "ago" so the chip reads "Last seen 2 hours ago", and copes with
// timeago's non-numeric forms like "a few seconds ago".
const lastSeenAgo = computed(() => {
  if (!otheruser.value?.lastaccess) return null
  return timeago(otheruser.value.lastaccess)
})

const replytimeFull = computed(() => {
  let ret = null
  let secs = null

  if (otheruser?.value?.info) {
    secs = otheruser.value.info.replytime
  }

  if (secs) {
    if (secs < 60) {
      const val = Math.round(secs)
      ret = val + (val === 1 ? ' second' : ' seconds')
    } else if (secs < 60 * 60) {
      const val = Math.round(secs / 60)
      ret = val + (val === 1 ? ' minute' : ' minutes')
    } else if (secs < 24 * 60 * 60) {
      const val = Math.round(secs / 60 / 60)
      ret = val + (val === 1 ? ' hour' : ' hours')
    } else {
      const val = Math.round(secs / 60 / 60 / 24)
      ret = val + (val === 1 ? ' day' : ' days')
    }
  }

  return ret
})

const showProfileModal = ref(false)
const profileCardExpanded = ref(false)
const showProfileHint = ref(false)
const cssReady = ref(false)
const expandBtnRef = ref(null)

const miscStore = useMiscStore()

// Check if we should show the profile hint (not dismissed in last 7 days)
const shouldShowHint = computed(() => {
  const dismissed = miscStore.vals?.profileHintDismissed
  if (!dismissed) return true
  const sevenDaysAgo = Date.now() - 7 * 24 * 60 * 60 * 1000
  return dismissed < sevenDaysAgo
})

async function showInfo() {
  profileCardExpanded.value = false
  await nextTick()
  showProfileModal.value = true
}

async function handleShowDownModal(userId) {
  profileCardExpanded.value = false
  ratingsUserId.value = userId
  await nextTick()
  showRatingsDownModal.value = true
}

async function handleShowRemoveModal(userId) {
  profileCardExpanded.value = false
  ratingsUserId.value = userId
  await nextTick()
  showRatingsRemoveModal.value = true
}

function toggleProfileCard() {
  profileCardExpanded.value = !profileCardExpanded.value
  // Hide hint when user interacts with profile card
  if (showProfileHint.value) {
    showProfileHint.value = false
  }
}

function dismissHint() {
  showProfileHint.value = false
  profileCardExpanded.value = false
  miscStore.set({ key: 'profileHintDismissed', value: Date.now() })
}

// Action methods
const hide = async () => {
  await chatStore.hide(props.id)
  router.push('/chats')
}

const block = async () => {
  await chatStore.block(props.id)
  router.push('/chats')
}

const unhide = async () => {
  await chatStore.unhide(props.id)
}

const markRead = async () => {
  await chatStore.markRead(props.id)
  profileCardExpanded.value = false
}

const showhide = () => {
  profileCardExpanded.value = false
  showChatHide.value = true
}

const showblock = () => {
  profileCardExpanded.value = false
  showChatBlock.value = true
}

const report = () => {
  profileCardExpanded.value = false
  showChatReport.value = true
}

function handleScroll() {
  const scrollY = window.scrollY

  if (scrollY > 200 && !navBarHidden.value) {
    setNavBarHidden(true)
  } else if (scrollY < 100 && navBarHidden.value) {
    setNavBarHidden(false)
  }

  // Also collapse profile card on scroll
  if (scrollY > 50 && profileCardExpanded.value) {
    profileCardExpanded.value = false
  }
}

onMounted(() => {
  window.addEventListener('scroll', handleScroll)

  // Use double requestAnimationFrame to ensure CSS is fully applied before showing
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      cssReady.value = true

      // Show the profile hint if not dismissed recently
      if (shouldShowHint.value) {
        setTimeout(() => {
          showProfileHint.value = true
          profileCardExpanded.value = true
        }, 500)
      }
    })
  })
})

onBeforeUnmount(() => {
  clearNavBarTimeout()
  window.removeEventListener('scroll', handleScroll)
})
</script>
<style scoped lang="scss">
@import 'assets/css/navbar.scss';
@import 'assets/css/_color-vars.scss';

.layout {
  min-height: $navbar-mobile-chat-height;
  padding: 0.5rem 0.75rem;

  .expand-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
    margin-right: 0.5rem;
  }
}

.chat-name-area {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  padding: 0 8px;
  text-align: center;
}

.nav-back-btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  cursor: pointer;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.2);
  border: 2px solid rgba(255, 255, 255, 0.6);
  flex-shrink: 0;
  transition: background-color var(--transition-fast);

  &:hover {
    background-color: rgba(255, 255, 255, 0.3);
  }

  &:active {
    background-color: rgba(255, 255, 255, 0.4);
  }
}

.back-icon {
  color: white;
  font-size: 1.1rem;
}

.back-badge {
  position: absolute;
  bottom: -4px;
  right: -4px;
  font-size: 0.6rem;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  line-height: 18px;
  border-radius: 50%;
}

.navbar-mark-read {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  background: rgba(255, 255, 255, 0.9);
  border: 2px solid $color-red;
  border-radius: 50%;
  cursor: pointer;
  margin-right: 8px;
  flex-shrink: 0;
}

.navbar-mark-read-icon {
  color: $color-red;
  font-size: 1rem;
}

.navbar-mark-read-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  font-size: 0.6rem;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  line-height: 18px;
}

.navbar-avatar {
  transition: transform var(--transition-slow);
}

.other-user-group {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 4px;
  cursor: default;

  &.clickme {
    cursor: pointer;
    border-radius: var(--radius-xl, 1.25rem);

    &:hover {
      background: rgba(255, 255, 255, 0.15);
    }
  }
}

.other-user-avatar {
  flex-shrink: 0;
}

.other-user-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: clamp(0.75rem, 4vw, 1.2rem);
  font-weight: 600;
  line-height: 1.3;
  letter-spacing: 0.02em;
}

/* Hint tip at top of profile card */
.profile-hint-tip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  margin-bottom: 12px;
  background: $color-gray--lighter;
  border: 1px solid $color-gray-4;
  font-size: 0.9rem;
  color: $color-gray--darker;
}

.profile-hint-btn {
  background: $color-green-background;
  color: white;
  border: none;
  padding: 8px 14px;
  border-radius: var(--radius-sm, 0.375rem);
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
  white-space: nowrap;
  transition: all var(--transition-fast);

  &:hover {
    background: darken($color-green-background, 5%);
  }
}

/* Profile popover styling */
:deep(.profile-popover) {
  max-width: calc(100vw - 24px);
  width: 400px;
  border-radius: var(--radius-md, 0.5rem);
  box-shadow: var(--shadow-lg);

  .popover-body {
    padding: 12px;
  }

  .popover-arrow::before,
  .popover-arrow::after {
    border-bottom-color: white;
  }
}

.profile-card-content {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid $color-gray--lighter;
  margin-bottom: 10px;
}

/* Top-right of the card. 44px is the minimum comfortable touch target, which
   matters more here than on desktop - this popover only exists below md. Given
   a solid background and a border so it reads as a control rather than as a
   stray glyph, and stacked above the first-visit hint tip, which it would
   otherwise sit on top of unnoticed. */
.profile-card-close {
  position: absolute;
  top: -8px;
  right: -8px;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border: 1px solid $color-gray--light;
  border-radius: 50%;
  background-color: white;
  color: $color-gray--darker;
  font-size: 1.1rem;
  line-height: 1;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}

.profile-card-close:hover,
.profile-card-close:focus {
  background-color: $color-gray--lighter;
  color: black;
}

.profile-card-main {
  display: flex;
  align-items: center;
  gap: 12px;
}

.profile-card-avatar-section {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.avatar-wrapper {
  position: relative;
}

.supporter-icon {
  position: absolute;
  bottom: -2px;
  right: -2px;
  font-size: 0.75rem;
  color: #ffd700;
  background: white;
  border-radius: 50%;
  padding: 2px;
}

.supporter-badge {
  font-size: 0.85rem;
}

.profile-card-avatar {
  flex-shrink: 0;
}

.profile-card-details {
  flex: 1;
  min-width: 0;
}

.profile-card-badges {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.profile-card-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.stat-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  background: $color-gray--lighter;
  font-size: 0.75rem;
  color: $color-gray--darker;
  font-weight: 500;
  border-radius: var(--radius-sm, 0.375rem);

  /* Spelling the labels out costs width, so shrink rather than wrap on phones. */
  @include media-breakpoint-down(sm) {
    gap: 3px;
    padding: 3px 6px;
    font-size: 0.65rem;
  }
}

.stat-icon {
  font-size: 0.7rem;
  color: $color-green--darker;

  @include media-breakpoint-down(sm) {
    font-size: 0.6rem;
  }
}

/* The label is what tells "last seen" apart from "replies in" - both render as a
   bare duration - so it stays visible on mobile and the chip shrinks instead. */
.stat-label {
  display: inline;
  color: var(--color-gray-600);
  font-weight: 400;
}

.profile-card-ratings {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

// Action buttons row
.profile-card-actions {
  display: flex;
  justify-content: space-around;
  gap: 8px;
}

.action-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 6px 12px;
  border: none;
  background: transparent;
  color: var(--color-gray-600);
  font-size: 0.65rem;
  font-weight: 500;
  cursor: pointer;
  border-radius: var(--radius-md, 0.5rem);
  transition: background-color var(--transition-fast);

  &:hover {
    background-color: $color-gray--lighter;
  }

  &:active {
    background-color: $color-gray--light;
  }

  &--danger {
    color: var(--color-danger);
  }

  &--mark-read {
    position: relative;
    color: $color-red;
    border: 1px solid $color-red;
    background: rgba(220, 53, 69, 0.05);

    .action-icon {
      color: $color-red;
    }
  }
}

.mark-read-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  font-size: 0.6rem;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  line-height: 16px;
}

.action-icon {
  font-size: 1rem;
  margin-bottom: 2px;
}

:deep(.badge) {
  font-size: 0.6em;
}
</style>
