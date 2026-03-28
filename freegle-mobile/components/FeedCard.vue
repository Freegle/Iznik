<template>
  <!-- Taken: collapsed single line -->
  <div v-if="post.taken" class="feed-card feed-card--taken">
    <span class="taken-icon">&#127881;</span>
    <span class="taken-title">{{ post.title }}</span>
    <span class="taken-label">TAKEN</span>
    <span class="taken-time">{{ post.timeAgo }}</span>
  </div>

  <!-- Normal post: person-focused chat-style layout -->
  <div v-else class="feed-card" :class="[cardClass, { 'feed-card--grouped': post.isGroupedWithPrev, 'feed-card--group-start': post.groupCount > 1 }]" @click="$emit('open-detail', post.id)">
    <button
      class="menu-trigger"
      aria-label="More options"
      @click.stop="showMenu = !showMenu"
    >
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <circle cx="8" cy="3" r="1.5" fill="#aaa" />
        <circle cx="8" cy="8" r="1.5" fill="#aaa" />
        <circle cx="8" cy="13" r="1.5" fill="#aaa" />
      </svg>
    </button>

    <ThreeDotMenu
      v-if="showMenu"
      @report="handleReport"
      @hide="handleHide"
      @close="showMenu = false"
    />

    <!-- Two-column layout: avatar rail (left) + content (right) -->
    <div class="feed-card__layout">
      <!-- Left: avatar + vertical connector line for groups -->
      <div class="feed-card__rail">
        <template v-if="!post.isGroupedWithPrev">
          <img
            v-if="post.userAvatar && !avatarBroken"
            :src="post.userAvatar"
            :alt="displayName"
            class="feed-card__avatar-img"
            @error="avatarBroken = true"
          />
          <GeneratedAvatar v-else :name="displayName" :size="32" class="feed-card__avatar-generated" />
        </template>
        <div v-if="post.groupCount > 1 || post.isGroupedWithPrev" class="feed-card__connector"></div>
      </div>

      <!-- Right: person info + item content -->
      <div class="feed-card__main">
        <!-- Person line -->
        <div v-if="!post.isGroupedWithPrev" class="feed-card__person">
          <span class="feed-card__name">{{ displayName }}</span>
          <span v-if="post.groupCount > 1" class="feed-card__posted">has {{ post.groupCount }} posts</span>
          <span v-else class="feed-card__posted">posted:</span>
        </div>

        <!-- Item content -->
        <div class="feed-card__item">
          <div class="feed-card__text">
            <h3 class="feed-card__title">{{ post.title }}</h3>
            <p v-if="post.description" class="feed-card__desc">{{ post.description }}</p>
            <span v-if="post.area" class="feed-card__area">{{ post.area }}</span>
          </div>
          <div v-if="post.imageUrls && post.imageUrls.length" class="feed-card__thumb-wrap">
            <img :src="post.imageUrls[0]" :alt="post.title" class="feed-card__thumb" loading="lazy" />
            <span v-if="post.type !== 'Discussion'" class="feed-card__type-overlay" :class="`feed-card__type-overlay--${post.type.toLowerCase()}`">
              {{ post.type }}
            </span>
            <span v-if="post.imageUrls.length > 1 && !post.isSampleImage" class="feed-card__photo-count">
              {{ post.imageUrls.length }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer: heart + time + replies + reply button -->
    <div class="feed-card__footer">
      <div class="feed-card__react-wrap">
        <ReactionPicker :visible="showReactions" @react="onReact" @close="showReactions = false" />
        <button
          class="feed-card__react"
          @click.stop="toggleLike"
          @touchstart.stop="startLongPress"
          @touchend.stop="cancelLongPress"
        >
          {{ reactionEmoji || (liked ? '❤️' : '♡') }}
        </button>
      </div>
      <span class="feed-card__time">{{ post.timeAgo }}</span>
      <span v-if="post.replies > 0" class="feed-card__replies">
        {{ post.replies }} {{ post.replies === 1 ? 'reply' : 'replies' }}
      </span>
      <span class="feed-card__spacer"></span>
      <button class="reply-link" @click.stop="$emit('reply', post.id)">
        {{ post.isChitchat ? 'Join thread' : 'Reply' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ReactionPicker from './ReactionPicker.vue'

const props = defineProps({
  post: { type: Object, required: true },
})

const emit = defineEmits(['reply', 'report', 'hide', 'open-detail'])
const showMenu = ref(false)
const expanded = ref(false)
const liked = ref(false)
const avatarBroken = ref(false)
const showReactions = ref(false)
const reactionEmoji = ref(null)
let longPressTimer = null

function startLongPress() {
  longPressTimer = setTimeout(() => { showReactions.value = true }, 400)
}

function cancelLongPress() {
  if (longPressTimer) clearTimeout(longPressTimer)
}

function onReact({ type, emoji }) {
  reactionEmoji.value = emoji
  liked.value = true
}

const displayName = computed(() => {
  const name = props.post.userName || 'Someone'
  if (!name.includes(' ') && name.length > 15) return name.substring(0, 10) + '...'
  return name
})

const firstName = computed(() => displayName.value.split(' ')[0])

const initial = computed(() => (displayName.value || '?').charAt(0).toUpperCase())

const cardClass = computed(() => {
  switch (props.post.type) {
    case 'Offer': return 'feed-card--offer'
    case 'Wanted': return 'feed-card--wanted'
    default: return 'feed-card--discussion'
  }
})

function toggleLike() { liked.value = !liked.value }
function handleReport() { showMenu.value = false; emit('report', props.post.id) }
function handleHide() { showMenu.value = false; emit('hide', props.post.id) }
</script>

<style scoped>
/* Card container */
.feed-card { position: relative; padding: 10px 12px; margin: 0 8px 6px; border-radius: 12px; cursor: pointer; }
.feed-card:active { box-shadow: 0 0 0 2px rgba(51, 136, 8, 0.2); }
.feed-card--offer { background: #f5faf0; }
.feed-card--wanted { background: #f0f4fc; }
.feed-card--discussion { background: #f7f7f7; }
.feed-card--group-start { margin-bottom: 0; border-radius: 12px 12px 0 0; }
.feed-card--grouped { margin-top: 0; padding-top: 4px; border-radius: 0 0 12px 12px; }

/* Taken (collapsed) */
.feed-card--taken { display: flex; align-items: center; gap: 6px; background: #f0f0f0; padding: 8px 12px; margin: 0 8px 4px; border-radius: 8px; font-size: 13px; color: #888; }
.taken-icon { flex-shrink: 0; }
.taken-title { font-weight: 600; color: #666; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.taken-label { font-weight: 600; flex-shrink: 0; }
.taken-time { flex-shrink: 0; font-size: 11px; color: #aaa; }

/* Three-dot menu */
.menu-trigger { position: absolute; top: 2px; right: 4px; background: none; border: none; padding: 4px; cursor: pointer; border-radius: 50%; z-index: 2; opacity: 0.5; }

/* Two-column layout */
.feed-card__layout { display: flex; gap: 10px; }
.feed-card__rail { display: flex; flex-direction: column; align-items: center; width: 32px; flex-shrink: 0; }
.feed-card__connector { width: 2px; flex: 1; background: #d0d0d0; min-height: 8px; margin-top: 4px; }
.feed-card__main { flex: 1; min-width: 0; }

/* Avatar */
.feed-card__avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: white; flex-shrink: 0; }
.feed-card__avatar--offer { background: #338808; }
.feed-card__avatar--wanted { background: #2563eb; }
.feed-card__avatar--discussion { background: #888; }
.feed-card__avatar-img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.feed-card__avatar-generated { border-radius: 50%; overflow: hidden; flex-shrink: 0; }

/* Person info */
.feed-card__person { display: flex; align-items: baseline; gap: 0; padding-right: 20px; margin-bottom: 2px; }
.feed-card__name { font-size: 14px; font-weight: 600; color: #333; }
.feed-card__posted { font-size: 12px; color: #bbb; font-weight: 400; margin-left: 4px; }
.feed-card__area { font-size: 11px; color: #999; margin-bottom: 4px; }

/* Item content */
.feed-card__item { display: flex; gap: 10px; }
.feed-card__text { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.feed-card__title { font-size: 15px; font-weight: 600; color: #222; margin: 0 0 3px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.feed-card__desc { font-size: 13px; color: #555; line-height: 1.4; margin: 0; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }

/* Thumbnail */
.feed-card__thumb-wrap { position: relative; flex-shrink: 0; width: 88px; height: 88px; }
.feed-card__thumb { width: 88px; height: 88px; border-radius: 10px; object-fit: cover; }
.feed-card__type-overlay { position: absolute; top: 4px; left: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; color: white; }
.feed-card__type-overlay--offer { background: rgba(51, 136, 8, 0.85); }
.feed-card__type-overlay--wanted { background: rgba(37, 99, 235, 0.85); }
.feed-card__photo-count { position: absolute; bottom: 4px; right: 4px; background: rgba(0,0,0,0.6); color: #fff; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }

/* Footer */
.feed-card__footer { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.feed-card__react-wrap { position: relative; }
.feed-card__react { background: none; border: none; cursor: pointer; font-size: 16px; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
.feed-card__time { font-size: 11px; color: #bbb; }
.feed-card__replies { font-size: 11px; color: #999; }
.feed-card__spacer { flex: 1; }
.reply-link { font-size: 12px; font-weight: 600; padding: 5px 16px; border-radius: 999px; background: transparent; cursor: pointer; border: 1.5px solid #338808; color: #338808; }
.feed-card--wanted .reply-link { border-color: #2563eb; color: #2563eb; }
.feed-card--discussion .reply-link { border-color: #888; color: #666; }
</style>
