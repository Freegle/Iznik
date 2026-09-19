<template>
  <div class="chat-message-sensitive" data-testid="sensitive-warning">
    <div class="d-flex align-items-start">
      <v-icon icon="exclamation-triangle" class="me-2 mt-1 text-warning" />
      <div>
        <div class="fw-bold">This message {{ explanation }}.</div>
        <div class="small text-muted mb-2">
          Nobody has to check it before you can read it. Take a moment, and if
          something feels wrong you can report it or block this person.
        </div>
        <b-button
          variant="secondary"
          size="sm"
          data-testid="sensitive-reveal"
          @click="$emit('reveal')"
        >
          Show message
        </b-button>
      </div>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue'

// Experiment: warn, do not hold. The API delivers a chat message the content check flagged
// with a short reason instead of hiding it until a moderator looks. This is the warning the
// member taps through. The wording says what kind of care to take, never the text itself.

const props = defineProps({
  reason: {
    type: String,
    required: true,
  },
})

defineEmits(['reveal'])

const explanations = {
  money: 'mentions money. Freegle is always free, so be careful',
  link: 'contains a link. Only open links from people you trust',
  contact: 'contains contact details. Take care before sharing yours',
  language: 'may be in another language',
  concern: 'contains something our checks flagged as a possible concern',
  scam: 'looks like it could be a scam',
  checked: 'is being checked, so take care with it',
}

const explanation = computed(
  () => explanations[props.reason] || explanations.checked
)
</script>
<style scoped lang="scss">
.chat-message-sensitive {
  border: 1px solid $color-warning;
  background-color: $color-yellow-1;
  padding: 0.75rem 1rem;
  margin: 0.25rem 0;
  max-width: 90%;
}
</style>
