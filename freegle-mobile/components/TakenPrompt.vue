<template>
  <div class="taken-prompt">
    <p class="taken-prompt__text">Did this get collected?</p>
    <div class="taken-prompt__actions">
      <button
        class="taken-prompt__btn taken-prompt__btn--yes"
        @click="$emit('taken')"
      >
        Yes
      </button>
      <button
        class="taken-prompt__btn taken-prompt__btn--not-yet"
        @click="$emit('dismiss')"
      >
        Not yet
      </button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['taken', 'dismiss'])
</script>

<style scoped lang="scss">
.taken-prompt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;

  &__text {
    margin: 0;
    font-size: 13px;
    font-weight: 500;
    color: #92400e;
  }

  &__actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }

  &__btn {
    padding: 6px 14px;
    border: none;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;

    &--yes {
      background: #22c55e;
      color: #ffffff;

      &:active {
        background: #16a34a;
      }
    }

    &--not-yet {
      background: #e5e7eb;
      color: #374151;

      &:active {
        background: #d1d5db;
      }
    }
  }
}
</style>
