<template>
  <div class="totalbox rounded p-2" :class="variantClass">
    <div class="small text-muted">{{ label }}</div>
    <div class="fs-4 fw-bold"><span v-if="money">£</span>{{ formatted }}</div>
  </div>
</template>
<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: Number,
    required: true,
  },
  // Money is rounded to whole pounds - pence are noise at this scale.
  money: {
    type: Boolean,
    required: false,
    default: false,
  },
  variant: {
    type: String,
    required: false,
    default: null,
  },
})

const formatted = computed(() =>
  (props.value || 0).toLocaleString('en-GB', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })
)

const variantClass = computed(() =>
  props.variant ? 'border-' + props.variant : 'border-secondary'
)
</script>
<style scoped lang="scss">
.totalbox {
  border: 1px solid;
  min-width: 8rem;
}
</style>
