<template>
  <div class="swipeable-card-outer">
    <!-- Swipe hint backgrounds -->
    <div v-if="swiping && offsetX > 20" class="swipe-hint swipe-hint--right">
      <span>👍 More</span>
    </div>
    <div v-if="swiping && offsetX < -20" class="swipe-hint swipe-hint--left">
      <span>Less 👎</span>
    </div>

    <div
      class="swipeable-card"
      :style="cardStyle"
      @touchstart="onTouchStart"
      @touchmove="onTouchMove"
      @touchend="onTouchEnd"
    >
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useSwipeGesture } from '~/composables/useSwipeGesture'

const emit = defineEmits(['swipe-left', 'swipe-right'])

const props = defineProps({
  threshold: { type: Number, default: 80 },
})

const { offsetX, swiping, onTouchStart, onTouchMove, onTouchEnd } = useSwipeGesture({
  threshold: props.threshold,
  onSwipeLeft() { emit('swipe-left') },
  onSwipeRight() { emit('swipe-right') },
})

const cardStyle = computed(() => {
  if (!swiping.value) return {}
  const opacity = Math.max(0.4, 1 - Math.abs(offsetX.value) / 200)
  return {
    transform: `translateX(${offsetX.value}px)`,
    opacity,
    transition: 'none',
  }
})
</script>

<style scoped lang="scss">
.swipeable-card-outer {
  position: relative;
  overflow: hidden;
}

.swipeable-card {
  position: relative;
  z-index: 1;
  transition: transform 0.2s ease, opacity 0.2s ease;
  will-change: transform;
}

.swipe-hint {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  font-size: 14px;
  font-weight: 600;
  padding: 0 20px;
  border-radius: 12px;

  &--right {
    background: #e8f5e9;
    color: #338808;
    justify-content: flex-start;
  }

  &--left {
    background: #fce4ec;
    color: #c62828;
    justify-content: flex-end;
  }
}
</style>
