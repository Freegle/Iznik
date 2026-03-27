<template>
  <div class="feed-composer">
    <button class="feed-composer__camera" aria-label="Add photo" @click="openFilePicker">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path
          d="M9 2L7.17 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4H16.83L15 2H9Z"
          stroke="#555"
          stroke-width="1.8"
          stroke-linejoin="round"
          fill="none"
        />
        <circle cx="12" cy="12" r="4" stroke="#555" stroke-width="1.8" fill="none" />
      </svg>
    </button>

    <input
      ref="fileInput"
      type="file"
      accept="image/*"
      capture="environment"
      multiple
      class="feed-composer__file-input"
      @change="onFilesSelected"
    />

    <input
      v-model="text"
      type="text"
      class="feed-composer__input"
      placeholder="Got something to offer or need?"
      @keydown.enter.prevent="submit"
    />

    <button
      v-if="text.trim().length > 0"
      class="feed-composer__send"
      aria-label="Send"
      @click="submit"
    >
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
        <path
          d="M3 10L17 3L10 17L9 11L3 10Z"
          fill="#fff"
          stroke="#fff"
          stroke-width="1.2"
          stroke-linejoin="round"
        />
      </svg>
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const emit = defineEmits(['submit'])

const text = ref('')
const images = ref([])
const fileInput = ref(null)

function openFilePicker() {
  fileInput.value?.click()
}

function onFilesSelected(event) {
  const files = Array.from(event.target.files || [])
  images.value = [...images.value, ...files]
  // Reset the input so selecting the same file again triggers change
  event.target.value = ''
}

function submit() {
  const trimmed = text.value.trim()
  if (!trimmed && images.value.length === 0) return

  emit('submit', {
    text: trimmed,
    images: [...images.value],
  })

  text.value = ''
  images.value = []
}
</script>

<style scoped>
.feed-composer {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: #fff;
  border-top: 1px solid #e0e0e0;
}

.feed-composer__camera {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background: #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}

.feed-composer__camera:hover {
  background: #e0e0e0;
}

.feed-composer__file-input {
  display: none;
}

.feed-composer__input {
  flex: 1;
  min-width: 0;
  height: 40px;
  border-radius: 20px;
  border: 1px solid #ddd;
  padding: 0 16px;
  font-size: 14px;
  color: #222;
  background: #f7f7f7;
  outline: none;
  transition: border-color 0.15s;
}

.feed-composer__input::placeholder {
  color: #999;
}

.feed-composer__input:focus {
  border-color: #3a8a14;
  background: #fff;
}

.feed-composer__send {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background: #3a8a14;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}

.feed-composer__send:hover {
  background: #2d6b0e;
}
</style>
