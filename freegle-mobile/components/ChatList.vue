<template>
  <Transition name="slide">
    <div
      v-if="visible"
      class="chat-list"
    >
      <!-- Header -->
      <header class="chat-list__header">
        <button
          class="chat-list__back"
          aria-label="Close messages"
          @click="$emit('close')"
        >
          <svg
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M20 11H7.83L13.42 5.41L12 4L4 12L12 20L13.41 18.59L7.83 13H20V11Z"
              fill="currentColor"
            />
          </svg>
        </button>
        <span class="chat-list__title">Chats</span>
      </header>

      <!-- Conversation list -->
      <div class="chat-list__scroll">
        <div
          v-if="!conversations || conversations.length === 0"
          class="chat-list__empty"
        >
          <svg
            width="48"
            height="48"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2ZM20 16H5.17L4 17.17V4H20V16Z"
              fill="#cccccc"
            />
          </svg>
          <p>No conversations yet</p>
        </div>

        <button
          v-for="convo in conversations"
          :key="convo.userId"
          class="chat-list__row"
          @click="$emit('open-chat', convo.userId)"
        >
          <!-- Avatar -->
          <img
            v-if="convo.avatar && !brokenAvatars.has(convo.userId)"
            :src="convo.avatar"
            :alt="convo.userName"
            class="chat-list__avatar"
            @error="brokenAvatars.add(convo.userId)"
          />
          <GeneratedAvatar
            v-else
            :name="convo.generatedName || convo.userName || 'User'"
            :size="40"
            class="chat-list__avatar chat-list__avatar--generated"
          />

          <!-- Name + preview -->
          <div class="chat-list__content">
            <span
              class="chat-list__name"
              :class="{ 'chat-list__name--unread': convo.unread }"
            >
              {{ convo.userName }}
            </span>
            <span
              class="chat-list__preview"
              :class="{ 'chat-list__preview--unread': convo.unread }"
            >
              {{ convo.lastMessage }}
            </span>
          </div>

          <!-- Time + unread badge -->
          <div class="chat-list__meta">
            <span class="chat-list__time">{{ convo.timeAgo }}</span>
            <span
              v-if="convo.unread"
              class="chat-list__badge"
            >
              {{ convo.unread }}
            </span>
          </div>

          <!-- Item thumbnail -->
          <img
            v-if="convo.itemThumb"
            :src="convo.itemThumb"
            alt=""
            class="chat-list__item-thumb"
          />
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { reactive } from 'vue'

const brokenAvatars = reactive(new Set())

defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  conversations: {
    type: Array,
    default: () => [],
  },
})

defineEmits(['close', 'open-chat'])
</script>

<style scoped lang="scss">
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}

.chat-list {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  background: #ffffff;

  &__header {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 56px;
    padding: 0 12px;
    background: var(--chat-header-bg, #1565c0);
    color: #ffffff;
    flex-shrink: 0;
  }

  &__back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: #ffffff;
    cursor: pointer;
    flex-shrink: 0;

    &:active {
      background: rgba(255, 255, 255, 0.15);
    }
  }

  &__title {
    font-size: 18px;
    font-weight: 600;
  }

  &__scroll {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 64px 24px;

    p {
      margin: 0;
      font-size: 15px;
      color: #999999;
    }
  }

  &__row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 12px;
    border: none;
    border-bottom: 1px solid #f0f0f0;
    background: #ffffff;
    text-align: left;
    cursor: pointer;

    &:active {
      background: #f5f5f5;
    }
  }

  &__avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;

    &--placeholder {
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e0e0e0;
      font-size: 18px;
      font-weight: 600;
      color: #666666;
    }

    &--generated {
      border-radius: 50%;
      overflow: hidden;
    }
  }

  &__content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  &__name {
    font-size: 14px;
    font-weight: 500;
    color: #1a1a1a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;

    &--unread {
      font-weight: 700;
    }
  }

  &__preview {
    font-size: 13px;
    color: #888888;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;

    &--unread {
      color: #1a1a1a;
      font-weight: 500;
    }
  }

  &__meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
  }

  &__time {
    font-size: 11px;
    color: #999999;
    white-space: nowrap;
  }

  &__badge {
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background: #22c55e;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    line-height: 18px;
    text-align: center;
  }

  &__item-thumb {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    object-fit: cover;
    flex-shrink: 0;
  }
}
</style>
