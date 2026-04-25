<template>
  <Transition name="slide">
    <div
      v-if="visible"
      class="chat-slide-over"
    >
      <!-- Header -->
      <header class="chat-slide-over__header">
        <button
          class="chat-slide-over__back"
          aria-label="Close chat"
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
        <img
          v-if="userAvatar"
          :src="userAvatar"
          :alt="userName"
          class="chat-slide-over__avatar"
        />
        <div
          v-else
          class="chat-slide-over__avatar chat-slide-over__avatar--placeholder"
        >
          {{ userInitial }}
        </div>
        <div class="chat-slide-over__header-text">
          <span class="chat-slide-over__user-name">{{ userName }}</span>
          <span class="chat-slide-over__subtitle">&#x1F512; Private conversation</span>
        </div>
      </header>

      <!-- Quoted item -->
      <div
        v-if="quotedItem"
        class="chat-slide-over__quote"
      >
        <ItemQuote :item="quotedItem" />
      </div>

      <!-- Messages -->
      <div
        ref="messagesContainer"
        class="chat-slide-over__messages"
      >
        <template
          v-for="msg in messages"
          :key="msg.id"
        >
          <ChatBubble
            :text="msg.text"
            :time="msg.time"
            :outgoing="msg.outgoing"
          />
          <TakenPrompt
            v-if="msg.showTakenPrompt"
            @taken="$emit('mark-taken', msg.relatedItemId)"
            @dismiss="() => {}"
          />
        </template>

        <div
          v-if="!messages || messages.length === 0"
          class="chat-slide-over__empty"
        >
          <p>No messages yet. Say hello!</p>
        </div>
      </div>

      <!-- Composer -->
      <div class="chat-slide-over__composer">
        <input
          v-model="draft"
          class="chat-slide-over__input"
          type="text"
          placeholder="Type a message..."
          @keydown.enter.prevent="sendMessage"
        />
        <button
          class="chat-slide-over__send"
          :disabled="!draft.trim()"
          aria-label="Send message"
          @click="sendMessage"
        >
          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M2.01 21L23 12L2.01 3L2 10L17 12L2 14L2.01 21Z"
              fill="currentColor"
            />
          </svg>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import ChatBubble from '~/components/ChatBubble.vue'
import ItemQuote from '~/components/ItemQuote.vue'
import TakenPrompt from '~/components/TakenPrompt.vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  userName: {
    type: String,
    default: '',
  },
  userAvatar: {
    type: String,
    default: '',
  },
  quotedItem: {
    type: Object,
    default: null,
  },
  messages: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'send', 'mark-taken'])

const draft = ref('')
const messagesContainer = ref(null)

const userInitial = computed(() => {
  return props.userName ? props.userName.charAt(0).toUpperCase() : '?'
})

function sendMessage() {
  const text = draft.value.trim()
  if (!text) return
  emit('send', text)
  draft.value = ''
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

watch(
  () => props.messages.length,
  () => scrollToBottom()
)

watch(
  () => props.visible,
  (val) => {
    if (val) scrollToBottom()
  }
)
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

.chat-slide-over {
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

  &__avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;

    &--placeholder {
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.25);
      font-size: 16px;
      font-weight: 600;
      color: #ffffff;
    }
  }

  &__header-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  &__user-name {
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__subtitle {
    font-size: 12px;
    opacity: 0.85;
  }

  &__quote {
    padding: 8px 12px;
    background: #f5f5f5;
    border-bottom: 1px solid #e0e0e0;
    flex-shrink: 0;
  }

  &__messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    -webkit-overflow-scrolling: touch;
  }

  &__empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;

    p {
      margin: 0;
      font-size: 14px;
      color: #999999;
    }
  }

  &__composer {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-top: 1px solid #e0e0e0;
    background: #ffffff;
    flex-shrink: 0;
  }

  &__input {
    flex: 1;
    height: 40px;
    padding: 0 14px;
    border: 1px solid #dcdcdc;
    border-radius: 20px;
    font-size: 15px;
    outline: none;
    background: #f5f5f5;

    &:focus {
      border-color: var(--chat-header-bg, #1565c0);
      background: #ffffff;
    }
  }

  &__send {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: var(--chat-header-bg, #1565c0);
    color: #ffffff;
    cursor: pointer;
    flex-shrink: 0;

    &:disabled {
      opacity: 0.4;
      cursor: default;
    }

    &:not(:disabled):active {
      opacity: 0.8;
    }
  }
}
</style>
