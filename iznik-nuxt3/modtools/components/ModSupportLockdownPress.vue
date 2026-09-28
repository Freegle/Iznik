<template>
  <div>
    <NoticeMessage variant="danger" class="mb-3">
      Freegle stays up. Members can still post, reply and chat - it looks sent,
      but reaches nobody until it's lifted, surface by surface. Moderators still
      see and approve everything. This lasts hours, not days, and nothing lifts
      itself.
    </NoticeMessage>

    <b-form-group label="Why (goes to geeks@ and the closing report)">
      <b-form-textarea
        v-model="reason"
        rows="2"
        placeholder="What's happening"
        data-testid="lockdown-reason"
      />
    </b-form-group>

    <b-form-group
      label="Member notice - a choice for the day, not a default"
      class="mt-2"
    >
      <b-form-select
        v-model="notice"
        :options="noticeOptions"
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
        <ul>
          <li>
            Every member's chat messages, posts and ChitChat posts will stop
            reaching anyone. Members will think they have been sent.
          </li>
          <li>
            No emails or app notifications will go to members, except sign-in
            and password emails.
          </li>
          <li>
            Moderators will only be able to use the basic Approve button.
            Downloads will stop.
          </li>
          <li>
            Nothing lifts on its own. Someone with Support tools has to lift it,
            step by step, and every hour it is on delays thousands of genuine
            messages.
          </li>
          <li>
            geeks@ will be emailed now, and every hour until it is lifted.
          </li>
        </ul>
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

// plans/active/2026-09-27-lockdown-switch.md section 10.1/10.9/11.2: the
// not-yet-pressed state of the Support Lockdown tab. Any Support or Admin
// user presses; reason is required (it's the record); notice defaults to
// none because "a choice on the day, not a default" (10.8).
const emit = defineEmits(['pressed'])

const lockdownStore = useLockdownStore()

const reason = ref('')
const notice = ref(null)
const pressing = ref(false)
const confirmModal = ref(null)
// ConfirmModal is v-if-gated (house pattern - see e.g. ModMember.vue's
// unbanConfirm) rather than always-mounted: useOurModal() defaults
// autoShow to true, so an always-mounted ConfirmModal pops open on page
// load instead of waiting for the Press button.
const showConfirmModal = ref(false)
// plans/active/2026-09-27-lockdown-switch.md section 11.6: the Press button
// inside the confirm dialog stays disabled until this is typed exactly.
const confirmText = ref('')

const noticeOptions = [
  { value: null, text: 'None' },
  {
    value: 'delay',
    text: 'Delay: "Freegle is running slowly today..."',
  },
  {
    value: 'security',
    text: 'Security: "We\'re dealing with a spam attack..."',
  },
]

async function press() {
  pressing.value = true

  try {
    const data = { action: 'press', reason: reason.value.trim() }
    if (notice.value) data.notice = notice.value

    await lockdownStore.patch(data)
    confirmText.value = ''
    emit('pressed')
  } finally {
    pressing.value = false
  }
}

defineExpose({ press })
</script>
