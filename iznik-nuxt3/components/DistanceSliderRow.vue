<template>
  <div class="slider-row">
    <label v-if="label" :for="id" class="slider-row__label">{{ label }}</label>
    <RangeSlider
      :id="id"
      v-model="sliderValue"
      :min="BROWSE_MINUTES_MIN"
      :max="maxMinutes"
      :axis-max="axisMax"
      :step="BROWSE_MINUTES_STEP"
      :dead-zone-title="deadZoneTitle"
      :left-label="showScaleLabels ? leftLabel : ''"
      :right-label="showScaleLabels ? rightLabel : ''"
      :aria-label="ariaLabel"
      @change="handleChange"
    />
    <NearbyTowns :minutes="sliderValue" :perspective="perspective" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import RangeSlider from '~/components/RangeSlider.vue'
import NearbyTowns from '~/components/NearbyTowns.vue'
import { useReachDistance } from '~/composables/useReachDistance'
import {
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_STEP,
} from '~/constants'

// One travel-time slider for one distance axis, with the towns hint that makes its position
// concrete. Both axes render through this, so the inbound and outbound sliders cannot drift apart
// in behaviour - only their label, wording and reachable range differ.
//
// Deliberately a component per axis rather than two composable calls in one parent: useReachDistance
// fetches on mount, so instantiating the outbound axis in a parent that is not showing it would
// spend a routing pass on a slider nobody has asked for.
//
// Deliberately no numeric readout - Nearer/Further plus the (debounced) towns hint is enough, and a
// per-tick readout made the drag janky.
const props = defineProps({
  id: { type: String, required: true },
  // 'browse' (inbound) or 'myPosts' (outbound) - see DISTANCE_AXES.
  axis: { type: String, required: true },
  label: { type: String, default: '' },
  ariaLabel: { type: String, required: true },
  perspective: { type: String, default: 'inbound' },
  leftLabel: { type: String, default: 'Nearer' },
  rightLabel: { type: String, default: 'Further' },
  // Whether this row carries the Nearer/Further labels for the scale. They describe the shared
  // axis rather than an individual slider, so when rows are stacked only the last one shows them.
  showScaleLabels: { type: Boolean, default: true },
  // Awaited before this row persists its own change, so the owner can settle something that has to
  // happen FIRST. Used for pinning - see DistanceSliders.
  onBeforeChange: { type: Function, default: null },
  // Ask /town/near for the reach OUTLINE as well, for the browse map to shade.
  withPolygon: { type: Boolean, default: false },
  // Draw against the full ripple ceiling instead of this axis's own maximum, so two rows can be
  // read against each other. Off while the two axes are linked: there is only one slider then, and
  // giving it a dead zone would change what every member sees without them having asked for
  // anything.
  sharedAxis: { type: Boolean, default: false },
})

const emit = defineEmits(['persisted'])

const { sliderValue, maxMinutes, onSliderChange } = useReachDistance(
  (miles) => emit('persisted', miles),
  { withPolygon: props.withPolygon, axis: props.axis }
)

const axisMax = computed(() => (props.sharedAxis ? BROWSE_MINUTES_MAX : null))

// The owner gets first refusal on a change, because pinning has to read the OTHER axis's value
// before this one is written - once this row saves, a linked sibling has already followed it.
async function handleChange(minutes) {
  if (props.onBeforeChange) await props.onBeforeChange()
  await onSliderChange(minutes)
}

// So the owner can pin this row where it currently sits (see DistanceSliders.pinOutbound).
defineExpose({ sliderValue, onSliderChange })

// Only the band-capped (inbound) axis can have a dead zone, and only when this member's area caps
// them below the ripple ceiling. Explaining it on hover beats leaving a stretch of greyed track
// unaccounted for.
const deadZoneTitle = computed(() =>
  maxMinutes.value < BROWSE_MINUTES_MAX
    ? `Posts further than about ${maxMinutes.value} minutes away aren't shown where you live, because there are plenty of freeglers closer to you.`
    : ''
)
</script>

<style scoped lang="scss">
.slider-row__label {
  font-weight: 500;
  font-size: 0.9rem;
  margin-bottom: 0;
}

/* The rows stack on one shared scale, so they need to sit closer together than two independent
   controls would - the comparison between the two thumb positions is the point. */
.slider-row + .slider-row {
  margin-top: 0.25rem;
}
</style>
