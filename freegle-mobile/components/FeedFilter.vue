<template>
  <div class="feed-filter">
    <button
      v-for="t in types"
      :key="t.value"
      class="feed-filter__chip"
      :class="{ 'feed-filter__chip--on': isActive(t.value), [`feed-filter__chip--${t.color}`]: true }"
      @click="toggle(t.value)"
    >
      <span class="feed-filter__icon">{{ t.icon }}</span>
      {{ t.label }}
    </button>
  </div>
</template>

<script setup>
import { reactive } from 'vue'

const emit = defineEmits(['update:typeFilter'])

const activeTypes = reactive(new Set(['Offer', 'Wanted', 'Discussion']))

const types = [
  { value: 'Offer', label: 'Offers', icon: '🎁', color: 'green' },
  { value: 'Wanted', label: 'Wanted', icon: '🔍', color: 'blue' },
  { value: 'Discussion', label: 'Social', icon: '👥', color: 'grey' },
  { value: 'Mine', label: 'Mine', icon: '👤', color: 'purple' },
]

function isActive(type) {
  return activeTypes.has(type)
}

function toggle(type) {
  if (activeTypes.has(type)) {
    if (activeTypes.size > 1) {
      activeTypes.delete(type)
    }
  } else {
    activeTypes.add(type)
  }
  emit('update:typeFilter', new Set(activeTypes))
}
</script>

<style scoped lang="scss">
.feed-filter {
  display: flex;
  gap: 6px;
  padding: 8px 12px;
  flex-shrink: 0;
  overflow-x: auto;
  border-bottom: 1px solid #f0f0f0;

  &__chip {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border: 1.5px solid;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
    background: white;

    /* Deselected state */
    border-color: #ddd;
    color: #bbb;

    &--on {
      /* Selected — each type has its own colour */
    }

    &--on.feed-filter__chip--green {
      background: #f0f9e8;
      border-color: #338808;
      color: #338808;
    }
    &--on.feed-filter__chip--blue {
      background: #e8f0fd;
      border-color: #2563eb;
      color: #2563eb;
    }
    &--on.feed-filter__chip--grey {
      background: #f5f5f5;
      border-color: #888;
      color: #666;
    }
    &--on.feed-filter__chip--purple {
      background: #f3eeff;
      border-color: #7c3aed;
      color: #7c3aed;
    }
  }

  &__icon {
    font-size: 13px;
    line-height: 1;
    vertical-align: middle;
  }
}
</style>
