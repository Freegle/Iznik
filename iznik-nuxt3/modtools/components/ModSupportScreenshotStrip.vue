<template>
  <div class="screenshot-strip">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <input
        ref="fileInput"
        type="file"
        accept="image/png,image/jpeg,image/gif,image/webp"
        multiple
        class="d-none"
        data-testid="screenshot-input"
        @change="onPick"
      />
      <b-button
        size="sm"
        variant="outline-secondary"
        :disabled="disabled || screenshots.length >= max"
        @click="fileInput.click()"
      >
        <v-icon icon="camera" />
        Attach screenshots
      </b-button>
      <small class="text-muted">
        Up to {{ max }}. You can also paste them into the box.
      </small>
    </div>
    <div v-if="screenshots.length" class="d-flex flex-wrap gap-2 mt-2">
      <div
        v-for="(shot, idx) in screenshots"
        :key="idx"
        class="screenshot-thumb"
      >
        <img :src="shot.preview" :alt="shot.name" />
        <button
          type="button"
          class="screenshot-remove"
          :aria-label="`Remove ${shot.name}`"
          :disabled="disabled"
          @click="$emit('remove', idx)"
        >
          &times;
        </button>
      </div>
    </div>
    <small v-if="error" class="text-danger d-block mt-1">{{ error }}</small>
  </div>
</template>
<script setup>
import { ref } from 'vue'

defineProps({
  screenshots: { type: Array, required: true },
  error: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  max: { type: Number, default: 5 },
})
const emit = defineEmits(['add', 'remove'])

const fileInput = ref(null)

function onPick(event) {
  emit('add', event.target.files)
  // So picking the same file again still fires a change.
  event.target.value = ''
}
</script>
<style scoped>
.screenshot-thumb {
  position: relative;
  width: 72px;
  height: 72px;
  border: 1px solid #ccc;
  border-radius: 4px;
  overflow: hidden;
  background: #f8f9fa;
}

.screenshot-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.screenshot-remove {
  position: absolute;
  top: 0;
  right: 0;
  border: 0;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  width: 20px;
  height: 20px;
  line-height: 18px;
  padding: 0;
  cursor: pointer;
}
</style>
