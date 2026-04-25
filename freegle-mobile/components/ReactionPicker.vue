<template>
  <Transition name="reactions">
    <div v-if="visible" class="reactions" @click.self="$emit('close')">
      <div class="reactions__bar">
        <button
          v-for="reaction in reactions"
          :key="reaction.emoji"
          class="reactions__btn"
          :class="{ 'reactions__btn--active': selected === reaction.type }"
          @click="select(reaction)"
        >
          <span class="reactions__emoji">{{ reaction.emoji }}</span>
          <span class="reactions__label">{{ reaction.label }}</span>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  visible: Boolean,
})

const emit = defineEmits(['react', 'close'])

const selected = ref(null)

const reactions = [
  { type: 'thanks', emoji: '🙏', label: 'Thanks' },
  { type: 'love', emoji: '💚', label: 'Love this' },
  { type: 'want', emoji: '🙋', label: 'Interested' },
  { type: 'kind', emoji: '🌟', label: 'So kind' },
]

function select(reaction) {
  selected.value = selected.value === reaction.type ? null : reaction.type
  emit('react', { type: reaction.type, emoji: reaction.emoji })
  setTimeout(() => emit('close'), 600)
}
</script>

<style scoped>
.reactions {
  position: absolute;
  bottom: 100%;
  left: 0;
  right: 0;
  z-index: 10;
  padding-bottom: 4px;
}

.reactions__bar {
  display: flex;
  gap: 4px;
  background: white;
  border-radius: 20px;
  padding: 4px 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
  width: fit-content;
}

.reactions__btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  padding: 6px 8px;
  border: none;
  border-radius: 12px;
  background: none;
  cursor: pointer;
  transition: all 0.15s;
}

.reactions__btn:active,
.reactions__btn--active {
  background: #f0f9e8;
  transform: scale(1.15);
}

.reactions__emoji {
  font-size: 20px;
}

.reactions__label {
  font-size: 9px;
  color: #999;
  white-space: nowrap;
}

.reactions-enter-active,
.reactions-leave-active {
  transition: opacity 0.15s, transform 0.15s;
}

.reactions-enter-from,
.reactions-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
</style>
