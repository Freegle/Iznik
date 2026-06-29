<template>
  <div class="helper-proposal" :data-testid="'proposal-' + proposal.id">
    <div class="helper-proposal__head">
      <b-badge :variant="typeVariant" class="me-2">{{ typeLabel }}</b-badge>
      <span class="helper-proposal__summary">{{ proposal.summary || typeLabel }}</span>
    </div>

    <p v-if="proposal.rationale" class="helper-proposal__rationale small text-muted">
      <v-icon icon="lightbulb" class="me-1" />{{ proposal.rationale }}
    </p>

    <!-- The draft message the Helper wrote, editable before sending. -->
    <b-form-textarea
      v-if="hasText"
      v-model="text"
      :rows="3"
      max-rows="8"
      class="helper-proposal__text"
      data-testid="proposal-text"
    />

    <div class="helper-proposal__actions">
      <b-button
        variant="success"
        size="sm"
        :disabled="busy"
        data-testid="proposal-send"
        @click="send"
      >
        <v-icon icon="paper-plane" class="me-1" />{{ sendLabel }}
      </b-button>
      <b-button
        variant="outline-secondary"
        size="sm"
        :disabled="busy"
        class="ms-1"
        data-testid="proposal-dismiss"
        @click="dismiss"
      >
        Dismiss
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  // One helper_proposals row (status 'pending').
  proposal: { type: Object, required: true },
})

const emit = defineEmits(['resolve'])

const busy = ref(false)
const text = ref(props.proposal.proposed_text || '')

const hasText = computed(() => props.proposal.proposed_text != null)

const typeLabel = computed(() => {
  switch (props.proposal.type) {
    case 'allocation':
      return 'Allocate'
    case 'rejection':
      return 'Decline'
    case 'escalation':
      return 'Needs you'
    case 'reminder':
      return 'Reminder'
    case 'withdrawal_notice':
      return 'Withdrawn'
    default:
      return 'Message'
  }
})

const typeVariant = computed(() => {
  switch (props.proposal.type) {
    case 'allocation':
      return 'success'
    case 'rejection':
      return 'danger'
    case 'escalation':
      return 'warning'
    default:
      return 'info'
  }
})

// An allocation actually promises the item; other types just send a message.
const sendLabel = computed(() =>
  props.proposal.type === 'allocation' ? 'Confirm & send' : 'Send'
)

async function send() {
  if (busy.value) return
  busy.value = true
  try {
    // Only pass edited text when there's a message to send.
    await emit('resolve', {
      id: props.proposal.id,
      decision: 'send',
      ...(hasText.value ? { text: text.value } : {}),
    })
  } finally {
    busy.value = false
  }
}

async function dismiss() {
  if (busy.value) return
  busy.value = true
  try {
    await emit('resolve', { id: props.proposal.id, decision: 'dismiss' })
  } finally {
    busy.value = false
  }
}

defineExpose({ text, hasText, typeLabel, send, dismiss, busy })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.helper-proposal {
  border: 1px solid $color-gray--lighter;
  border-left: 3px solid $color-green--dark;
  border-radius: 6px;
  padding: 0.6rem 0.75rem;
  margin-bottom: 0.5rem;
}

.helper-proposal__summary {
  font-weight: 600;
}

.helper-proposal__rationale {
  margin: 0.25rem 0;
}

.helper-proposal__text {
  margin-bottom: 0.5rem;
}
</style>
