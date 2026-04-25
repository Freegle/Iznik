<template>
  <div class="donate-card">
    <button
      class="donate-card__dismiss"
      aria-label="Dismiss"
      @click="$emit('dismiss')"
    >
      ✕
    </button>

    <div class="donate-card__body">
      <span class="donate-card__emoji">☕</span>
      <p class="donate-card__message">
        {{ message }}
      </p>
    </div>

    <button
      class="donate-card__btn"
      @click="$emit('donate')"
    >
      Donate
    </button>
  </div>
</template>

<script setup>
defineProps({
  message: {
    type: String,
    default: 'You saved something from landfill! Buy Freegle a coffee?',
  },
})

defineEmits(['donate', 'dismiss'])
</script>

<style scoped lang="scss">
.donate-card {
  position: relative;
  padding: 14px 16px;
  margin: 8px 12px;
  border-radius: 12px;
  background: #fef3c7;

  &__dismiss {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: #999999;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;

    &:active {
      background: rgba(0, 0, 0, 0.06);
    }
  }

  &__body {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
    padding-right: 20px;
  }

  &__emoji {
    font-size: 24px;
    line-height: 1;
    flex-shrink: 0;
  }

  &__message {
    margin: 0;
    font-size: 14px;
    color: #333333;
    line-height: 1.4;
  }

  &__btn {
    display: block;
    width: 100%;
    padding: 10px 0;
    border: none;
    border-radius: 20px;
    background: var(--header-bg, #1d6607);
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;

    &:active {
      opacity: 0.85;
    }
  }
}
</style>
