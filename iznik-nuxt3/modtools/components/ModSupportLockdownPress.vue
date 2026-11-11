<template>
  <div>
    <div class="mb-3" data-testid="lockdown-press-explanation">
      <p>
        Use this when spam or abuse is getting past the usual checks and has to
        be stopped now. Pressing holds everything below at once. Freegle stays
        up: members can still post, reply and chat, and what they send waits
        until it is lifted.
      </p>
      <ModSupportLockdownWhatHappens />
    </div>

    <b-form-group label="Why (goes to geeks@ and the history)">
      <b-form-textarea
        v-model="reason"
        rows="2"
        placeholder="What's happening"
        data-testid="lockdown-reason"
      />
    </b-form-group>

    <b-form-group
      label="Member notice (optional, shown across the top of the member site)"
      class="mt-2"
    >
      <div class="d-flex flex-wrap gap-2 mb-2">
        <b-button
          variant="outline-secondary"
          size="sm"
          data-testid="lockdown-press-notice-none"
          @click="notice = ''"
        >
          No notice
        </b-button>
        <b-button
          v-for="w in LOCKDOWN_NOTICE_WORDINGS"
          :key="w.label"
          variant="outline-secondary"
          size="sm"
          @click="notice = w.text"
        >
          {{ w.label }}
        </b-button>
      </div>
      <b-form-textarea
        v-model="notice"
        rows="3"
        :maxlength="LOCKDOWN_NOTICE_MAX"
        placeholder="No notice"
        data-testid="lockdown-press-notice"
      />
    </b-form-group>

    <b-button
      variant="danger"
      size="lg"
      class="fw-bold mt-3"
      data-testid="lockdown-press-button"
      :disabled="!reason.trim() || pressing"
      @click="showConfirmModal = true"
    >
      <v-icon icon="triangle-exclamation" /> Press: hold everything now
    </b-button>

    <ConfirmModal
      v-if="showConfirmModal"
      ref="confirmModal"
      title="Lock down Freegle?"
      confirm-label="Press"
      confirm-testid="lockdown-confirm-press"
      :confirm-disabled="confirmText.trim() !== 'LOCKDOWN'"
      @confirm="press"
      @hidden="showConfirmModal = false"
    >
      <div data-testid="lockdown-confirm-modal">
        <ModSupportLockdownWhatHappens />
        <b-form-group label="Type LOCKDOWN to confirm">
          <b-form-input
            v-model="confirmText"
            data-testid="lockdown-confirm-input"
          />
        </b-form-group>
      </div>
    </ConfirmModal>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useLockdownStore } from '~/stores/lockdown'
import {
  LOCKDOWN_NOTICE_MAX,
  LOCKDOWN_NOTICE_WORDINGS,
} from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: the not-yet-
// pressed state of the Support Lockdown tab. The page says what will happen
// before the button, in the same words as the confirm dialog. A reason is
// required (it's the record); the notice is optional and free text.
const emit = defineEmits(['pressed'])

const lockdownStore = useLockdownStore()

const reason = ref('')
const notice = ref('')
const pressing = ref(false)
const confirmModal = ref(null)
// ConfirmModal is v-if-gated (house pattern - see e.g. ModMember.vue's
// unbanConfirm) rather than always-mounted: useOurModal() defaults
// autoShow to true, so an always-mounted ConfirmModal pops open on page
// load instead of waiting for the Press button.
const showConfirmModal = ref(false)
// The Press button inside the confirm dialog stays disabled until this is
// typed exactly.
const confirmText = ref('')

async function press() {
  pressing.value = true

  try {
    const data = { action: 'press', reason: reason.value.trim() }
    const text = notice.value.trim()
    if (text) data.notice = text

    await lockdownStore.patch(data)
    confirmText.value = ''
    emit('pressed')
  } finally {
    pressing.value = false
  }
}

defineExpose({ press })
</script>
