<template>
  <div class="notify-choice">
    <div class="notify-choice__panel">
      <div class="notify-choice__emoji">
        🎉
      </div>

      <h2 class="notify-choice__heading">
        Posted!
      </h2>

      <p class="notify-choice__subtitle">
        People nearby can see your post now
      </p>

      <p class="notify-choice__question">
        How should we tell you when someone replies?
      </p>

      <div class="notify-choice__options">
        <button
          class="notify-choice__option"
          @click="$emit('choose', 'push')"
        >
          <span class="notify-choice__option-icon">🔔</span>
          <span class="notify-choice__option-text">
            <strong>Push notifications</strong>
            <span>Instant alerts on your phone</span>
          </span>
        </button>

        <button
          class="notify-choice__option"
          @click="$emit('choose', 'email')"
        >
          <span class="notify-choice__option-icon">📧</span>
          <span class="notify-choice__option-text">
            <strong>Email</strong>
            <span>Get a message when someone replies</span>
          </span>
        </button>
      </div>

      <p class="notify-choice__footer">
        You can change this anytime
      </p>
    </div>
  </div>
</template>

<script setup>
defineEmits(['choose'])
</script>

<style scoped lang="scss">
.notify-choice {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(0, 0, 0, 0.4);

  &__panel {
    width: 100%;
    max-width: 340px;
    padding: 28px 20px 20px;
    border-radius: 16px;
    background: #ffffff;
    text-align: center;
  }

  &__emoji {
    font-size: 40px;
    line-height: 1;
    margin-bottom: 8px;
  }

  &__heading {
    margin: 0 0 4px;
    font-size: 22px;
    font-weight: 700;
    color: #222222;
  }

  &__subtitle {
    margin: 0 0 20px;
    font-size: 14px;
    color: #666666;
  }

  &__question {
    margin: 0 0 16px;
    font-size: 15px;
    font-weight: 600;
    color: #333333;
  }

  &__options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
  }

  &__option {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 14px;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    background: #fafafa;
    text-align: left;
    cursor: pointer;

    &:active {
      background: #f0f0f0;
    }
  }

  &__option-icon {
    font-size: 24px;
    line-height: 1;
    flex-shrink: 0;
  }

  &__option-text {
    display: flex;
    flex-direction: column;
    gap: 2px;

    strong {
      font-size: 14px;
      font-weight: 600;
      color: #222222;
    }

    span {
      font-size: 12px;
      color: #888888;
    }
  }

  &__footer {
    margin: 0;
    font-size: 12px;
    color: #aaaaaa;
  }
}
</style>
