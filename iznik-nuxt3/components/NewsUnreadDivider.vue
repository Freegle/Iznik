<template>
  <div
    class="unread-divider"
    data-unread-divider
    role="separator"
    :aria-label="label"
  >
    <span class="unread-divider__label">{{ label }}</span>
  </div>
</template>
<script setup>
import { computed } from 'vue'

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
})

const label = computed(() => {
  return props.count === 1
    ? '1 new reply since your last visit'
    : props.count + ' new replies since your last visit'
})
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.unread-divider {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0.5rem 0;

  &::before,
  &::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(
      to right,
      transparent,
      rgba($color-success, 0.5),
      transparent
    );
  }

  @media (prefers-reduced-motion: no-preference) {
    animation: unread-divider-in 0.4s ease-out;
  }
}

@keyframes unread-divider-in {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.unread-divider__label {
  font-size: 0.8rem;
  font-weight: 600;
  color: $color-success;
  white-space: nowrap;
}
</style>
