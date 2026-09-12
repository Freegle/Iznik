<template>
  <div v-if="options?.length" class="chips" role="group" :aria-label="label">
    <button
      v-for="option in options.slice(0, 3)"
      :key="option.value"
      type="button"
      class="chip"
      :class="{ 'chip-input': option.kind }"
      :disabled="disabled"
      :data-testid="'chip-' + option.value"
      @click="$emit('pick', option)"
    >
      <v-icon v-if="option.kind === 'photo'" icon="camera" class="me-1" />
      {{ option.label }}
    </button>
  </div>
</template>
<script setup>
// Tappable options under a Freegle message. At most three, always real buttons.
defineProps({
  options: { type: Array, required: false, default: () => [] },
  disabled: { type: Boolean, required: false, default: false },
  label: { type: String, required: false, default: 'Options' },
})
defineEmits(['pick'])
</script>
<style scoped lang="scss">
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.4rem;
}

.chip {
  border: 1px solid #1e7c4f;
  background: #fff;
  color: #1e7c4f;
  border-radius: 999px;
  padding: 0.35rem 0.85rem;
  font-size: 0.9rem;
  line-height: 1.2;
  min-height: 36px;

  &:hover,
  &:focus-visible {
    background: #e8f5ee;
  }

  &:disabled {
    opacity: 0.5;
  }
}
</style>
