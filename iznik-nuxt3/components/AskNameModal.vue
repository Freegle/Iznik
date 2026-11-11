<template>
  <b-modal
    ref="modal"
    title="What's your name?"
    no-close-on-backdrop
    @hidden="skipName"
  >
    <p>Other freeglers see it when you post or reply. A first name is fine.</p>
    <b-form-input
      id="askname"
      v-model="name"
      autocomplete="given-name"
      maxlength="60"
      placeholder="Your name"
      @keyup.enter="save"
    />
    <p v-if="lockdownError" class="text-danger mt-2">{{ lockdownError }}</p>
    <template #footer>
      <b-button variant="secondary" @click="hide">Not now</b-button>
      <b-button variant="primary" :disabled="!trimmed || saving" @click="save">
        Save
      </b-button>
    </template>
  </b-modal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useOurModal } from '~/composables/useOurModal'
import { useAskName } from '~/composables/useAskName'
import { useLockdownNotice } from '~/composables/useLockdownNotice'

const { modal, hide } = useOurModal()
const { askNameOpen, saveName, skipName: skip } = useAskName()
const { lockdownError, guardLockdown } = useLockdownNotice()

const name = ref('')
const saving = ref(false)
const trimmed = computed(() => name.value.trim())
let saved = false

async function save() {
  if (!trimmed.value || saving.value) return
  saving.value = true
  try {
    await guardLockdown(async () => {
      await saveName(trimmed.value)
      saved = true
      hide()
    })
  } finally {
    saving.value = false
  }
}

// Closing the modal any way but Save is a "not now".
function skipName() {
  if (!saved && askNameOpen.value) skip()
}
</script>
