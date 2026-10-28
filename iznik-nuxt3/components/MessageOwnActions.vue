<template>
  <div class="own-actions" data-testid="own-post-actions">
    <p class="mb-2 small text-muted">
      This is your post. If it's all gone, mark it here.
    </p>
    <div class="d-flex flex-wrap gap-2">
      <b-button
        variant="primary"
        size="lg"
        data-testid="own-post-mark"
        @click="open(message.type === 'Offer' ? 'Taken' : 'Received')"
      >
        <v-icon icon="check" />
        Mark as {{ message.type === 'Offer' ? 'TAKEN' : 'RECEIVED' }}
      </b-button>
      <b-button variant="secondary" size="lg" to="/myposts">
        My posts
      </b-button>
    </div>
    <OutcomeModal
      v-if="outcomeType"
      :id="message.id"
      :type="outcomeType"
      @hidden="outcomeType = null"
    />
  </div>
</template>
<script setup>
import { ref, defineAsyncComponent } from 'vue'

const OutcomeModal = defineAsyncComponent(() => import('./OutcomeModal'))

// The poster looking at their own post from Browse or a link sees the public
// view, which has no Reply for them. Without this they had no way to mark it
// from here, and a part-gone post looked impossible to finish (Discourse 10284).
defineProps({
  message: {
    type: Object,
    required: true,
  },
})

const outcomeType = ref(null)

function open(type) {
  outcomeType.value = type
}
</script>
<style scoped lang="scss">
.own-actions {
  width: 100%;
}
</style>
