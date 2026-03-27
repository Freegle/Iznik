<template>
  <!-- Taken: collapsed single line -->
  <div v-if="post.taken" class="feed-card feed-card--taken">
    <span class="taken-icon">&#127881;</span>
    <span class="taken-title">{{ post.title }}</span>
    <span class="taken-label">TAKEN</span>
    <span class="taken-time">{{ post.timeAgo }}</span>
  </div>

  <!-- Normal post: person-focused chat-style layout -->
  <div v-else class="feed-card" :class="cardClass">
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

    <!-- Person line: avatar + name + badge + time -->
    <div class="feed-card__person">
      <NuxtImg
        v-if="post.userAvatar"
        :src="post.userAvatar"
        :alt="post.userName"
        width="56"
        height="56"
        class="feed-card__avatar-img"
      />
      <div v-else class="feed-card__avatar" :class="`feed-card__avatar--${post.type.toLowerCase()}`">
        {{ initial }}
      </div>
      <span class="feed-card__name">{{ displayName }}</span>
      <span v-if="post.type === 'Offer'" class="badge badge--offer">OFFER</span>
      <span v-else-if="post.type === 'Wanted'" class="badge badge--wanted">WANTED</span>
      <span class="feed-card__time">{{ post.timeAgo }}</span>
    </div>

    <!-- Item content -->
    <div class="feed-card__item">
      <div class="feed-card__text">
        <h3 class="feed-card__title">{{ post.title }}</h3>
        <p v-if="post.description" class="feed-card__desc">{{ post.description }}</p>
        <div v-if="post.groupName" class="feed-card__group">{{ post.groupName }}</div>
      </div>
      <div v-if="post.imageUrls && post.imageUrls.length" class="feed-card__thumb-wrap">
        <NuxtImg
          :src="post.imageUrls[0]"
          :alt="post.title"
          width="144"
          height="144"
          fit="cover"
          loading="lazy"
          class="feed-card__thumb"
        />
        <span v-if="post.imageUrls.length > 1 && !post.isSampleImage" class="feed-card__photo-count">
          {{ post.imageUrls.length }}
        </span>
      </div>
    </div>

    <!-- Reply — subtle, not transactional -->
    <div class="feed-card__footer">
      <button class="reply-link" @click.stop="$emit('reply', post.id)">
        Reply to {{ firstName }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  post: { type: Object, required: true },
})

const emit = defineEmits(['reply', 'report', 'hide'])
const showMenu = ref(false)

const displayName = computed(() => {
  const name = props.post.userName || 'Someone'
  // Truncate garbled hashes (no spaces, >15 chars)
  if (!name.includes(' ') && name.length > 15) return name.substring(0, 10) + '...'
  return name
})

const firstName = computed(() => {
  return displayName.value.split(' ')[0]
})

const initial = computed(() => {
  return (displayName.value || '?').charAt(0).toUpperCase()
})

const cardClass = computed(() => {
  switch (props.post.type) {
    case 'Offer': return 'feed-card--offer'
    case 'Wanted': return 'feed-card--wanted'
    default: return 'feed-card--discussion'
  }
})

function handleReport() { showMenu.value = false; emit('report', props.post.id) }
function handleHide() { showMenu.value = false; emit('hide', props.post.id) }
</script>

<style scoped>
.feed-card {
  position: relative;
  padding: 10px 12px;
  margin: 0 8px 6px;
  border-radius: 12px;
}

.feed-card--offer { background: #f5faf0; }
.feed-card--wanted { background: #f0f4fc; }
.feed-card--discussion { background: #f7f7f7; }

/* Taken (collapsed) */
.feed-card--taken {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #f0f0f0;
  padding: 8px 12px;
  margin: 0 8px 4px;
  border-radius: 8px;
  font-size: 13px;
  color: #888;
}
.taken-icon { flex-shrink: 0; }
.taken-title { font-weight: 600; color: #666; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.taken-label { font-weight: 600; flex-shrink: 0; }
.taken-time { flex-shrink: 0; font-size: 11px; color: #aaa; }

/* Three-dot menu */
.menu-trigger {
  position: absolute;
  top: 8px;
  right: 6px;
  background: none;
  border: none;
  padding: 4px;
  cursor: pointer;
  border-radius: 50%;
  z-index: 2;
  opacity: 0.6;
}

/* Person line */
.feed-card__person {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  padding-right: 20px;
}

.feed-card__avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  color: white;
  flex-shrink: 0;
}
.feed-card__avatar--offer { background: #338808; }
.feed-card__avatar--wanted { background: #2563eb; }
.feed-card__avatar--discussion { background: #888; }

.feed-card__avatar-img {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}

.feed-card__name {
  font-size: 14px;
  font-weight: 600;
  color: #333;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.badge {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 5px;
  border-radius: 3px;
  flex-shrink: 0;
}
.badge--offer { background: #d4edbc; color: #2d6b0e; }
.badge--wanted { background: #c8ddfb; color: #1a4fa0; }

.feed-card__time {
  font-size: 11px;
  color: #999;
  flex-shrink: 0;
}

/* Item content */
.feed-card__item {
  display: flex;
  gap: 10px;
}

.feed-card__text {
  flex: 1;
  min-width: 0;
}

.feed-card__title {
  font-size: 15px;
  font-weight: 600;
  color: #222;
  margin: 0 0 2px;
  line-height: 1.3;
}

.feed-card__desc {
  font-size: 13px;
  color: #555;
  line-height: 1.35;
  margin: 0 0 3px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.feed-card__group {
  font-size: 11px;
  color: #999;
}

/* Thumbnail */
.feed-card__thumb-wrap {
  position: relative;
  flex-shrink: 0;
  width: 64px;
  height: 64px;
  align-self: center;
}

.feed-card__thumb {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  object-fit: cover;
}

.feed-card__photo-count {
  position: absolute;
  bottom: 2px;
  right: 2px;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  min-width: 16px;
  height: 16px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 3px;
}

/* Reply link */
.feed-card__footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 4px;
}

.reply-link {
  font-size: 12px;
  font-weight: 500;
  color: #338808;
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 0;
}
.feed-card--wanted .reply-link { color: #2563eb; }
.feed-card--discussion .reply-link { color: #666; }
</style>
