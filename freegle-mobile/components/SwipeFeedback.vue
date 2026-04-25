<template>
  <Transition name="fade">
    <div v-if="visible" class="swipe-feedback" @click.self="$emit('dismiss')">
      <div class="swipe-feedback__panel">
        <h3>{{ direction === 'left' ? 'Show less like this?' : 'More like this!' }}</h3>

        <template v-if="direction === 'left'">
          <p class="swipe-feedback__hint">Help us show you better items</p>
          <div class="swipe-feedback__options">
            <button @click="$emit('feedback', 'too-far')">Too far away</button>
            <button @click="$emit('feedback', 'not-interested')">Not interested</button>
            <button @click="$emit('feedback', 'wrong-type')">Wrong type of item</button>
            <button @click="$emit('feedback', 'hide')">Just hide this one</button>
          </div>
        </template>

        <template v-else>
          <p class="swipe-feedback__hint">We'll show more items like this</p>
          <div class="swipe-feedback__confirm">
            <span class="swipe-feedback__emoji">&#128077;</span>
            <span>Noted! We'll learn your preferences.</span>
          </div>
        </template>

        <button class="swipe-feedback__cancel" @click="$emit('dismiss')">Cancel</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  visible: Boolean,
  direction: { type: String, default: 'left' },
})

defineEmits(['feedback', 'dismiss'])
</script>

<style scoped lang="scss">
.swipe-feedback {
  position: fixed;
  inset: 0;
  z-index: 400;
  background: rgba(0, 0, 0, 0.3);
  display: flex;
  align-items: flex-end;
  padding-bottom: env(safe-area-inset-bottom, 0);

  &__panel {
    background: white;
    width: 100%;
    border-radius: 16px 16px 0 0;
    padding: 20px 16px;
    text-align: center;

    h3 {
      font-size: 16px;
      font-weight: 600;
      margin: 0 0 4px;
      color: #333;
    }
  }

  &__hint {
    font-size: 13px;
    color: #888;
    margin: 0 0 16px;
  }

  &__options {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 12px;

    button {
      padding: 12px;
      border: 1px solid #e8e8e8;
      border-radius: 10px;
      background: #fafafa;
      font-size: 14px;
      color: #333;
      cursor: pointer;
      text-align: left;

      &:active { background: #f0f0f0; }
    }
  }

  &__confirm {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px;
    margin-bottom: 12px;
    font-size: 14px;
    color: #338808;
    font-weight: 500;
  }

  &__emoji {
    font-size: 24px;
  }

  &__cancel {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 10px;
    background: none;
    font-size: 14px;
    color: #999;
    cursor: pointer;
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
