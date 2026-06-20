<template>
  <div v-if="summary.total > 0" class="helper-itemsummary small" data-testid="helper-itemsummary">
    <b-badge v-if="summary.allocated" variant="success" class="me-1" data-testid="sum-allocated">
      {{ summary.allocated }} allocated
    </b-badge>
    <b-badge v-if="summary.pool" variant="warning" class="me-1" data-testid="sum-pool">
      {{ summary.pool }} ready to decide
    </b-badge>
    <b-badge v-if="summary.outreach" variant="info" class="me-1" data-testid="sum-outreach">
      {{ summary.outreach }} being contacted
    </b-badge>
    <b-badge v-if="summary.inactive" variant="light" class="me-1" data-testid="sum-inactive">
      {{ summary.inactive }} no longer in the running
    </b-badge>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { summariseItemStates } from '~/composables/useClearance'

const props = defineProps({
  // The helper item_states for ONE bulk item.
  itemStates: { type: Array, default: () => [] },
})

const summary = computed(() => summariseItemStates(props.itemStates))

defineExpose({ summary })
</script>

<style scoped lang="scss">
.helper-itemsummary {
  margin: 0.25rem 0 0.4rem;
}
</style>
