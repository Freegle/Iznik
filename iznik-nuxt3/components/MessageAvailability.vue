<template>
  <b-badge v-if="show" variant="info" :class="badgeClass" :style="badgeStyle">
    <span v-if="partGone">Part gone, some still available</span>
    <span v-else>{{ availablenow }} available</span>
  </b-badge>
</template>
<script setup>
import { computed } from 'vue'

const props = defineProps({
  availablenow: {
    type: Number,
    required: false,
    default: 0,
  },
  availableinitially: {
    type: Number,
    required: false,
    default: 0,
  },
  // Bulk clearance offers are the one place we still show a running count.
  // Those posters are working through a catalogue of items and the numbers are
  // the point; ordinary posters are not asked to keep a tally at all.
  bulkcount: {
    type: Number,
    required: false,
    default: 0,
  },
  badgeClass: {
    type: String,
    required: false,
    default: '',
  },
  badgeStyle: {
    type: String,
    required: false,
    default: '',
  },
})

// availableinitially is the pool the post is offering. Fall back to the current
// count when we don't know the pool, so a post with the field missing says how
// many are there rather than wrongly claiming to be part gone.
const initially = computed(() => props.availableinitially || props.availablenow)

const partGone = computed(
  () => !props.bulkcount && props.availablenow < initially.value
)

const show = computed(() => initially.value > 1)
</script>
