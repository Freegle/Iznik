<template>
  <div>
    <b-modal
      id="banMemberModal"
      ref="modal"
      title="Ban Member"
      size="lg"
      no-stacking
    >
      <template #default>
        <NoticeMessage variant="info" class="mb-2">
          Please be responsible in how you use this feature - it should be a
          last resort.
        </NoticeMessage>
        <p>You must enter a reason for banning a member.</p>
        <b-form-input
          v-model="reason"
          type="text"
          placeholder="Enter a reason"
          class="mt-2 mb-2"
        />
      </template>
      <template #footer>
        <b-button variant="white" @click="hide"> Close </b-button>
        <b-button variant="primary" :disabled="!userid" @click="ban">
          Ban
        </b-button>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { ref } from 'vue'
import { useOurModal } from '~/composables/useOurModal'

defineProps({
  userid: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['confirm'])

const { modal, show, hide } = useOurModal()

const reason = ref(null)

function ban() {
  if (reason.value) {
    emit('confirm', reason.value)
    hide()
  }
}

defineExpose({ show, hide })
</script>
