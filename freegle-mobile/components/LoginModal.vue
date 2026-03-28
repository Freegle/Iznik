<template>
  <Transition name="fade">
    <div v-if="visible" class="login-modal" @click.self="$emit('close')">
      <div class="login-modal__panel">
        <h2>Log in to Freegle</h2>
        <p class="login-modal__hint">{{ hint || 'Log in to reply, post, and see your chats' }}</p>

        <form @submit.prevent="doLogin">
          <input
            v-model="email"
            type="email"
            placeholder="Email address"
            class="login-modal__input"
            required
          />
          <input
            v-model="password"
            type="password"
            placeholder="Password"
            class="login-modal__input"
            required
          />
          <p v-if="error" class="login-modal__error">{{ error }}</p>
          <button type="submit" class="login-modal__btn" :disabled="loading">
            {{ loading ? 'Logging in...' : 'Log in' }}
          </button>
        </form>

        <button class="login-modal__cancel" @click="$emit('close')">Not now</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '~/stores/auth'

const props = defineProps({
  visible: Boolean,
  hint: { type: String, default: null },
})

const emit = defineEmits(['close', 'logged-in'])

const authStore = useAuthStore()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function doLogin() {
  error.value = ''
  loading.value = true

  try {
    await authStore.login({ email: email.value, password: password.value })
    emit('logged-in')
    emit('close')
  } catch (e) {
    error.value = 'Login failed. Check your email and password.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped lang="scss">
.login-modal {
  position: fixed;
  inset: 0;
  z-index: 500;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;

  &__panel {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    max-width: 340px;
    width: 100%;
    text-align: center;

    h2 { font-size: 1.2rem; margin: 0 0 0.25rem; color: #338808; }
  }

  &__hint { font-size: 0.85rem; color: #888; margin: 0 0 1rem; }

  &__input {
    display: block;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    margin-bottom: 8px;
    outline: none;

    &:focus { border-color: #338808; }
  }

  &__error { font-size: 0.8rem; color: #e53935; margin: 0 0 8px; }

  &__btn {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 10px;
    background: #338808;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 8px;

    &:disabled { opacity: 0.6; }
  }

  &__cancel {
    background: none;
    border: none;
    color: #999;
    font-size: 14px;
    cursor: pointer;
  }
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
