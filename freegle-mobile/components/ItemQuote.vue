<template>
  <div
    class="item-quote"
    :class="{
      'item-quote--offer': item.type === 'offer',
      'item-quote--wanted': item.type === 'wanted',
    }"
  >
    <img
      v-if="item.imageUrl"
      :src="item.imageUrl"
      :alt="item.title"
      class="item-quote__thumb"
    />
    <div class="item-quote__body">
      <span class="item-quote__type">{{ typeLabel }}</span>
      <span class="item-quote__title">{{ item.title }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: {
    type: Object,
    required: true,
    validator: (v) => v && typeof v.title === 'string',
  },
})

const typeLabel = computed(() => {
  if (props.item.type === 'offer') return 'OFFER'
  if (props.item.type === 'wanted') return 'WANTED'
  return (props.item.type || '').toUpperCase()
})
</script>

<style scoped lang="scss">
.item-quote {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px;
  background: #ffffff;
  border-left: 3px solid #cccccc;
  border-radius: 4px;

  &--offer {
    border-left-color: #2e7d32;
  }

  &--wanted {
    border-left-color: #1565c0;
  }

  &__thumb {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    object-fit: cover;
    flex-shrink: 0;
  }

  &__body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  &__type {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #666666;
  }

  &__title {
    font-size: 13px;
    font-weight: 500;
    color: #1a1a1a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}
</style>
