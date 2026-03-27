<template>
  <div class="feed-filter">
    <button class="feed-filter__view" @click="toggleView">
      {{ view === 'feed' ? 'Near me' : 'My stuff' }}
      <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
        <path d="M3 5l3 3 3-3z"/>
      </svg>
    </button>
    <div class="feed-filter__type">
      <button
        v-for="t in types"
        :key="t.value"
        :class="{ active: typeFilter === t.value }"
        @click="$emit('update:typeFilter', t.value)"
      >
        {{ t.label }}
      </button>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  view: { type: String, default: 'feed' },
  typeFilter: { type: String, default: 'All' },
})
const emit = defineEmits(['update:view', 'update:typeFilter'])

const types = [
  { value: 'All', label: 'All' },
  { value: 'Offer', label: 'Offers' },
  { value: 'Wanted', label: 'Wanted' },
]

function toggleView() {
  emit('update:view', props.view === 'feed' ? 'mine' : 'feed')
}
</script>

<style scoped lang="scss">
.feed-filter {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  flex-shrink: 0;
  border-bottom: 1px solid #f0f0f0;

  &__view {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    border: none;
    background: none;
    font-size: 15px;
    font-weight: 700;
    color: #333;
    cursor: pointer;

    svg { opacity: 0.5; }
  }

  &__type {
    display: flex;
    gap: 4px;

    button {
      padding: 5px 12px;
      border: none;
      border-radius: 16px;
      background: #f0f0f0;
      font-size: 12px;
      font-weight: 500;
      color: #666;
      cursor: pointer;
      transition: all 0.15s;

      &.active {
        background: #338808;
        color: white;
      }
    }
  }
}
</style>
