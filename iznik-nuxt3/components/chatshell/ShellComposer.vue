<template>
  <div class="composer-wrap">
    <div v-if="progress" class="progress-line" data-testid="flow-progress">
      <span class="progress-text">
        {{ progress.label
        }}<span v-if="progress.item"> · {{ progress.item }}</span> ·
        {{ progress.step }} of {{ progress.total }}
      </span>
      <button
        type="button"
        class="progress-cancel"
        aria-label="Stop"
        data-testid="flow-cancel"
        @click="$emit('cancel')"
      >
        ✕
      </button>
    </div>
    <ShellChips
      v-if="actions?.length"
      :options="actions"
      class="action-row"
      label="What would you like to do"
      @pick="$emit('action', $event)"
    />
    <form class="composer" @submit.prevent="submit">
      <button
        v-if="allowPhoto"
        type="button"
        class="composer-photo"
        aria-label="Add a photo"
        data-testid="composer-photo"
        :disabled="busy"
        @click="$emit('photo')"
      >
        <v-icon icon="camera" />
      </button>
      <textarea
        ref="input"
        v-model="text"
        class="composer-input"
        :placeholder="placeholder"
        rows="1"
        enterkeyhint="send"
        autocapitalize="none"
        data-testid="composer-input"
        :aria-label="placeholder"
        @keydown.enter.exact.prevent="submit"
        @input="grow"
      />
      <button
        type="submit"
        class="composer-send"
        aria-label="Send"
        data-testid="composer-send"
        :disabled="busy || !text.trim()"
      >
        <v-icon icon="paper-plane" />
      </button>
    </form>
  </div>
</template>
<script setup>
import { ref } from '#imports'
import ShellChips from '~/components/chatshell/ShellChips.vue'

// The bottom of the phone: the persistent action row, the progress line during a
// flow, and the box. Typing always works; sending is only held while Freegle is
// composing so replies do not overlap.
defineProps({
  placeholder: {
    type: String,
    required: false,
    default: 'Type or tap a button',
  },
  progress: { type: Object, required: false, default: null },
  actions: { type: Array, required: false, default: () => [] },
  allowPhoto: { type: Boolean, required: false, default: true },
  busy: { type: Boolean, required: false, default: false },
})
const emit = defineEmits(['send', 'photo', 'cancel', 'action'])

const text = ref('')
const input = ref(null)

function submit() {
  const t = text.value.trim()
  if (!t) return
  emit('send', t)
  text.value = ''
  if (input.value) input.value.style.height = 'auto'
}

function grow() {
  const el = input.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = Math.min(el.scrollHeight, 120) + 'px'
}

defineExpose({ focus: () => input.value?.focus() })
</script>
<style scoped lang="scss">
.composer-wrap {
  flex: 0 0 auto;
  background: #f0f2f5;
  border-top: 1px solid #e0e4e8;
  padding: 0.4rem 0.5rem 0.5rem;
}

.progress-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.78rem;
  color: #4a5561;
  padding: 0 0.25rem 0.3rem;
}

.progress-cancel {
  border: 0;
  background: transparent;
  color: #4a5561;
  font-size: 0.95rem;
  padding: 0 0.3rem;
}

.action-row {
  margin: 0 0 0.4rem;
}

.composer {
  display: flex;
  align-items: flex-end;
  gap: 0.4rem;
}

.composer-photo,
.composer-send {
  border: 0;
  background: transparent;
  color: #1e7c4f;
  font-size: 1.2rem;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  flex: 0 0 auto;

  &:disabled {
    opacity: 0.4;
  }
}

.composer-send {
  background: #1e7c4f;
  color: #fff;
}

.composer-input {
  flex: 1 1 auto;
  border: 1px solid #d5dbe0;
  border-radius: 20px;
  padding: 0.55rem 0.9rem;
  resize: none;
  font-size: 1rem;
  line-height: 1.3;
  max-height: 120px;
  background: #fff;

  &:focus {
    outline: 2px solid #1e7c4f;
    outline-offset: 1px;
  }
}
</style>
