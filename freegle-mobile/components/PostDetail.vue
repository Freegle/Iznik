<template>
  <Transition name="detail">
    <div v-if="post" class="post-detail">
      <header class="post-detail__header">
        <button class="post-detail__back" @click="$emit('close')">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 11H7.83L13.42 5.41L12 4L4 12L12 20L13.41 18.59L7.83 13H20V11Z" />
          </svg>
        </button>
        <div class="post-detail__header-info">
          <span v-if="post.type === 'Offer'" class="badge badge--offer">OFFER</span>
          <span v-else-if="post.type === 'Wanted'" class="badge badge--wanted">WANTED</span>
          <span class="post-detail__header-title">{{ post.title }}</span>
        </div>
      </header>

      <!-- Photos -->
      <div v-if="post.imageUrls?.length" class="post-detail__photos">
        <div class="post-detail__photo-scroll">
          <img
            v-for="(url, i) in post.imageUrls"
            :key="i"
            :src="url"
            :alt="`${post.title} photo ${i + 1}`"
            class="post-detail__photo"
            loading="lazy"
          />
        </div>
        <div v-if="post.imageUrls.length > 1" class="post-detail__photo-count">
          {{ post.imageUrls.length }} photos
        </div>
      </div>

      <div class="post-detail__body">
        <!-- Person -->
        <div class="post-detail__person">
          <img v-if="post.userAvatar" :src="post.userAvatar" class="post-detail__avatar" />
          <div v-else class="post-detail__avatar-fallback" :class="`post-detail__avatar-fallback--${post.type?.toLowerCase()}`">
            {{ (post.userName || '?').charAt(0).toUpperCase() }}
          </div>
          <div class="post-detail__person-info">
            <span class="post-detail__name">{{ post.userName }}</span>
            <span v-if="post.area" class="post-detail__area">{{ post.area }}</span>
          </div>
          <span class="post-detail__time">{{ post.timeAgo }}</span>
        </div>

        <!-- Title + description -->
        <h2 class="post-detail__title">{{ post.title }}</h2>
        <p v-if="post.description" class="post-detail__desc">{{ post.description }}</p>

        <div v-if="post.groupName" class="post-detail__group">
          {{ post.groupName }}
        </div>
      </div>

      <!-- Reply bar -->
      <div class="post-detail__reply-bar">
        <button class="post-detail__reply-btn" @click="$emit('reply', post.id)">
          Reply
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  post: { type: Object, default: null },
})

defineEmits(['close', 'reply'])
</script>

<style scoped lang="scss">
.post-detail {
  position: fixed;
  inset: 0;
  z-index: 300;
  background: white;
  display: flex;
  flex-direction: column;

  &__header {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 48px;
    padding: 0 12px;
    background: var(--header-bg, #1d6607);
    color: white;
    flex-shrink: 0;
  }

  &__back {
    background: none;
    border: none;
    color: white;
    padding: 4px;
    cursor: pointer;
    border-radius: 50%;
    display: flex;
    align-items: center;
  }

  &__header-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
  }

  &__header-title {
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__photos {
    position: relative;
    flex-shrink: 0;
  }

  &__photo-scroll {
    display: flex;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
  }

  &__photo {
    min-width: 100%;
    max-height: 300px;
    object-fit: cover;
    scroll-snap-align: start;
  }

  &__photo-count {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 12px;
  }

  &__body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
  }

  &__person {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }

  &__avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
  }

  &__avatar-fallback {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 700;
    color: white;

    &--offer { background: #338808; }
    &--wanted { background: #2563eb; }
    &--discussion { background: #888; }
  }

  &__person-info {
    flex: 1;
  }

  &__name {
    display: block;
    font-size: 15px;
    font-weight: 600;
    color: #333;
  }

  &__area {
    display: block;
    font-size: 12px;
    color: #999;
  }

  &__time {
    font-size: 12px;
    color: #999;
  }

  &__title {
    font-size: 20px;
    font-weight: 700;
    color: #222;
    margin: 0 0 8px;
    line-height: 1.3;
  }

  &__desc {
    font-size: 15px;
    color: #555;
    line-height: 1.5;
    margin: 0 0 12px;
    white-space: pre-wrap;
  }

  &__group {
    font-size: 13px;
    color: #999;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
  }

  &__reply-bar {
    padding: 10px 16px;
    border-top: 1px solid #e8e8e8;
    flex-shrink: 0;
  }

  &__reply-btn {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 12px;
    background: #338808;
    color: white;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
  }
}

.badge {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 5px;
  border-radius: 3px;
  flex-shrink: 0;
}
.badge--offer { background: rgba(255,255,255,0.2); color: white; }
.badge--wanted { background: rgba(255,255,255,0.2); color: white; }

.detail-enter-active,
.detail-leave-active {
  transition: transform 0.25s ease;
}
.detail-enter-from,
.detail-leave-to {
  transform: translateY(100%);
}
</style>
