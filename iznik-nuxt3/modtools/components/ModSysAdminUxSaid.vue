<template>
  <div class="said small mb-1">
    <b-button
      variant="link"
      size="sm"
      class="p-0 said-toggle"
      :aria-expanded="open ? 'true' : 'false'"
      @click="open = !open"
    >
      {{ open ? 'Hide' : 'Show' }}: {{ label }}
    </b-button>
    <template v-if="open">
      <!-- Member text: interpolated, so it is escaped. Never v-html. -->
      <ul class="mb-1 ps-3">
        <li v-for="(c, i) in shown" :key="i">{{ c }}</li>
      </ul>
      <p v-if="comments.length > shown.length" class="text-muted mb-0">
        Showing {{ shown.length }} of {{ comments.length }}.
      </p>
      <p v-if="capped" class="text-muted mb-0">
        The newest {{ comments.length }} are all that is kept here.
      </p>
    </template>
  </div>
</template>

<script setup>
// Member free text behind a toggle, escaped, with the count shown so a reader knows how much is
// not on screen. Raw answers are for the person judging the numbers, not the first thing seen.
import { computed, ref } from 'vue'

const props = defineProps({
  comments: { type: Array, required: true },
  label: { type: String, default: 'What members wrote' },
  limit: { type: Number, default: 5 },
  // True when the list is a capped sample of more.
  capped: { type: Boolean, default: false },
})

const open = ref(false)
const shown = computed(() => props.comments.slice(0, props.limit))
</script>
