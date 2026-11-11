<template>
  <b-modal
    ref="modal"
    scrollable
    :title="props.title"
    modal-class="confirm-modal"
  >
    <template #default>
      <slot name="default">
        <!-- eslint-disable-next-line -->
        <div v-html="props.message" />
      </slot>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Cancel </b-button>
      <b-button
        variant="primary"
        :disabled="confirmDisabled"
        :data-testid="confirmTestid"
        @click="confirm"
      >
        {{ confirmLabel }}
      </b-button>
    </template>
  </b-modal>
</template>

<script setup>
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  title: {
    type: String,
    required: false,
    default: 'Are you sure?',
  },
  message: {
    type: String,
    required: false,
    default: '<p>Are you sure you want to do this?</p>',
  },
  // General-purpose guard for a dangerous confirm (e.g. "type LOCKDOWN to
  // confirm" - plans/active/2026-09-27-lockdown-switch.md section 11.6).
  // Default false so every existing caller is unaffected.
  confirmDisabled: {
    type: Boolean,
    required: false,
    default: false,
  },
  // Lets a caller rename the confirm button (e.g. "Press" for the lockdown
  // switch) instead of the generic "Confirm". Default keeps every existing
  // caller unaffected.
  confirmLabel: {
    type: String,
    required: false,
    default: 'Confirm',
  },
  // Lets a caller give the confirm button its own data-testid (e.g. for the
  // lockdown press dialog) so it can be found without relying on its label
  // text. Left unset, Vue omits the attribute entirely.
  confirmTestid: {
    type: String,
    required: false,
    default: undefined,
  },
})

const emit = defineEmits(['confirm'])

const { modal, show: showmodal, hide } = useOurModal()

function confirm() {
  emit('confirm')
  hide()
}

function show() {
  showmodal()
}

defineExpose({ show })
</script>
