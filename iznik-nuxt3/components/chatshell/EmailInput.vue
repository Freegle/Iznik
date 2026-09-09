<template>
  <form class="inline-input" data-testid="email-input" @submit.prevent="submit">
    <label class="visually-hidden" for="chat-email">Email</label>
    <div class="email-row">
      <input
        id="chat-email"
        v-model="email"
        type="email"
        class="form-control"
        placeholder="you@example.com"
        autocomplete="email"
        required
      />
      <button type="submit" class="email-btn" :disabled="!valid">OK</button>
    </div>
  </form>
</template>
<script setup>
import { ref, computed } from '#imports'

// An email field inside a bubble, for replies to reach a visitor.
const emit = defineEmits(['submit'])
const email = ref('')
const valid = computed(() =>
  /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())
)
function submit() {
  if (valid.value) emit('submit', email.value.trim().toLowerCase())
}
</script>
<style scoped lang="scss">
.inline-input {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  padding: 0.5rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.email-row {
  display: flex;
  gap: 0.4rem;
}

.email-btn {
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0 0.9rem;
}
</style>
