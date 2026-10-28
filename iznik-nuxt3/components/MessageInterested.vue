<template>
  <div v-if="showButton || interested" class="interested-control">
    <b-button
      v-if="showButton"
      variant="outline-primary"
      size="lg"
      class="interested-button"
      data-testid="interested-button"
      @click="mark"
    >
      I'm interested
    </b-button>
    <span v-else class="interested-marked" data-testid="interested-marked">
      <v-icon icon="check" /> Interested
      <button
        type="button"
        class="interested-undo btn btn-link p-0 ms-1"
        data-testid="interested-undo"
        @click="undo"
      >
        Undo
      </button>
    </span>
    <p
      v-if="interested && justMarked"
      class="interested-note small mb-0"
      data-testid="interested-note"
    >
      Saved. Find it in My Posts. We'll remind you tomorrow if it's still there.
    </p>
  </div>
</template>

<script setup>
import { useInterested } from '~/composables/useInterested'

const props = defineProps({
  id: { type: Number, required: true },
})

const { showButton, interested, justMarked, mark, undo } = useInterested(
  () => props.id
)
</script>

<style scoped lang="scss">
/* The parent's flex row lays the pieces out, so this adds no box of its own. */
.interested-control {
  display: contents;
}

.interested-button {
  flex: 0 0 auto;
}

.interested-marked {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
  gap: 0.25rem;
  white-space: nowrap;
}

.interested-note {
  /* Above the buttons, whichever order they come in. */
  order: -1;
  flex: 0 0 100%;
}
</style>
