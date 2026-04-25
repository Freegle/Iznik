<template>
  <div class="three-dot-menu" @click.stop>
    <div
      class="three-dot-menu__backdrop"
      @click.stop="$emit('close')"
    />
    <div class="three-dot-menu__panel">
      <button
        class="three-dot-menu__item"
        @click="$emit('report')"
      >
        Report
      </button>
      <button
        class="three-dot-menu__item"
        @click="$emit('hide')"
      >
        Hide
      </button>
      <button
        class="three-dot-menu__item three-dot-menu__item--cancel"
        @click="$emit('close')"
      >
        Cancel
      </button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['close', 'report', 'hide'])
</script>

<style scoped lang="scss">
.three-dot-menu {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;

  &__backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.25);
  }

  &__panel {
    position: absolute;
    top: 0;
    right: 0;
    z-index: 101;
    min-width: 140px;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
    overflow: hidden;
  }

  &__item {
    display: block;
    width: 100%;
    padding: 12px 16px;
    border: none;
    background: transparent;
    color: #333333;
    font-size: 14px;
    text-align: left;
    cursor: pointer;

    &:active {
      background: #f5f5f5;
    }

    & + & {
      border-top: 1px solid #f0f0f0;
    }

    &--cancel {
      color: #999999;
    }
  }
}
</style>
