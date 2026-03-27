<template>
  <div
    class="swipeable-card"
    :style="cardStyle"
    @touchstart="onTouchStart"
    @touchmove="onTouchMove"
    @touchend="onTouchEnd"
  >
    <slot />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useSwipeGesture } from '~/composables/useSwipeGesture'

const emit = defineEmits(['swipe-left', 'swipe-right'])

const props = defineProps({
  threshold: {
    type: Number,
    default: 80,
  },
})

const { offsetX, swiping, onTouchStart, onTouchMove, onTouchEnd } = useSwipeGesture({
  threshold: props.threshold,
  onSwipeLeft() {
    emit('swipe-left')
  },
  onSwipeRight() {
    emit('swipe-right')
  },
})

const cardStyle = computed(() => {
  if (!swiping.value) return {}

  return {
    transform: `translateX(${offsetX.value}px)`,
    transition: 'none',
  }
})
</script>

<style scoped lang="scss">
.swipeable-card {
  transition: transform 0.2s ease;
  will-change: transform;
}
</style>
