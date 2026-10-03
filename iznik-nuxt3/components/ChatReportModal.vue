<template>
  <b-modal
    ref="modal"
    scrollable
    title="Oh dear..."
    size="lg"
    no-stacking
    modal-class="confirm-modal"
  >
    <template #default>
      <b-row>
        <b-col>
          <p>Sorry you're having trouble.</p>
          <p class="text-muted">
            Tell us what's wrong and we'll let you know what happens.
          </p>
          <h4>Why are you reporting this?</h4>
          <b-form-select
            v-model="reason"
            class="mt-1 mb-1"
            data-testid="reason-select"
          >
            <option :value="null">-- Please choose --</option>
            <option value="Spam">It's Spam</option>
            <option value="Other">Something else</option>
          </b-form-select>
          <h4>What's wrong?</h4>
          <b-form-textarea
            v-model="comments"
            placeholder="Please tell us what's wrong."
          />
        </b-col>
      </b-row>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Close </b-button>
      <b-button variant="primary" :disabled="loading" @click="send">
        Send Report
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref } from 'vue'
import { useChatStore } from '~/stores/chat'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  user: {
    type: Object,
    required: true,
  },
  chatid: {
    type: Number,
    required: true,
  },
})

const chatStore = useChatStore()
const { modal, hide } = useOurModal()

const reason = ref(null)
const comments = ref(null)
const loading = ref(false)

async function send() {
  if (!reason.value) {
    return
  }

  loading.value = true

  try {
    const chatid = await chatStore.openChatToMods()
    await chatStore.report(chatid, reason.value, comments.value || '', props.chatid)
  } finally {
    loading.value = false
  }

  hide()
}
</script>
