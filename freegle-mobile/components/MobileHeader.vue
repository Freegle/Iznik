<template>
  <header class="mobile-header">
    <div class="mobile-header__left">
      <img
        src="/icon.png"
        alt="Freegle"
        class="mobile-header__logo"
      />
      <span class="mobile-header__title">Freegle</span>
    </div>

    <div class="mobile-header__right">
      <button
        class="mobile-header__btn"
        aria-label="Open chats"
        @click="$emit('open-chats')"
      >
        <svg
          class="mobile-header__chat-icon"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2Z"
            fill="currentColor"
          />
        </svg>
        <span
          v-if="unreadCount > 0"
          class="mobile-header__badge"
        >
          {{ unreadCount }}
        </span>
      </button>

      <button
        class="mobile-header__btn"
        aria-label="Open settings"
        @click="$emit('open-settings')"
      >
        <svg
          class="mobile-header__dots-icon"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <circle cx="12" cy="5" r="2" fill="currentColor" />
          <circle cx="12" cy="12" r="2" fill="currentColor" />
          <circle cx="12" cy="19" r="2" fill="currentColor" />
        </svg>
      </button>
    </div>
  </header>
</template>

<script setup>
defineProps({
  unreadCount: {
    type: Number,
    default: 0,
  },
})

defineEmits(['open-chats', 'open-settings'])
</script>

<style scoped lang="scss">
.mobile-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 48px;
  padding: 0 12px;
  background: var(--header-bg, #1d6607);
  color: #ffffff;
  flex-shrink: 0;

  &__left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
  }

  &__logo {
    width: 28px;
    height: 28px;
    border-radius: 4px;
    flex-shrink: 0;
  }

  &__title {
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 0.02em;
  }

  &__right {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
  }

  &__btn {
    position: relative;
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

    &:active {
      background: rgba(255, 255, 255, 0.15);
    }
  }

  &__chat-icon,
  &__dots-icon {
    display: block;
  }

  &__badge {
    position: absolute;
    top: 2px;
    right: 2px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 8px;
    background: #e53935;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    line-height: 16px;
    text-align: center;
    animation: pulse-badge 2s ease-in-out infinite;
  }
}

@keyframes pulse-badge {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}
</style>
