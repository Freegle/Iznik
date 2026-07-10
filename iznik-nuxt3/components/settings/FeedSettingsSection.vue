<template>
  <div class="settings-section">
    <div class="section-header">
      <v-icon icon="sliders" class="section-icon" />
      <h2>Feed</h2>
    </div>

    <div class="section-content">
      <div class="option-info mb-2">
        <span class="option-label">How far away</span>
        <span class="option-desc">
          You'll see mostly nearby posts, but some from further away.
          <span class="drag-hint"
            >Drag towards <strong>Nearer</strong> or
            <strong>Further</strong>. </span
          >We use road distance and travel time, not crow flies. Applies to
          Browse, notifications and who sees your posts.
        </span>
      </div>

      <div class="slider-frame">
        <RangeSlider
          v-model="sliderValue"
          :min="0.5"
          :max="feedMax"
          :step="0.5"
          left-label="Nearer"
          right-label="Further"
          aria-label="How far away"
          @change="onSliderChange"
        />
        <NearbyTowns :miles="sliderValue" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, defineEmits } from 'vue'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import RangeSlider from '~/components/RangeSlider.vue'
import NearbyTowns from '~/components/NearbyTowns.vue'
import { BROWSE_DISTANCE_UNLIMITED } from '~/constants'

const emit = defineEmits(['update'])

const authStore = useAuthStore()
const { me } = useMe()

// The browse filter's slider scales its max to the current feed's extent. There's
// no feed here, so we use a fixed extent; the far-right stop always means "no
// distance limit" (BROWSE_DISTANCE_UNLIMITED), matching the browse behaviour.
const feedMax = 30

const maxDistance = computed(
  () => me.value?.settings?.browseMaxDistance ?? BROWSE_DISTANCE_UNLIMITED
)

// Far-right when unlimited, or when a saved value is at/above our fixed extent
// (e.g. it was set from a wider browse feed); otherwise the real mile value.
function sliderPositionFor(distance, max) {
  return distance === BROWSE_DISTANCE_UNLIMITED || distance >= max
    ? max
    : distance
}

const sliderValue = ref(sliderPositionFor(maxDistance.value, feedMax))

watch(maxDistance, (d) => {
  sliderValue.value = sliderPositionFor(d, feedMax)
})

// Deliberately no numeric readout - the Nearer/Further labels are enough, and a
// per-tick "within N miles" text made the drag janky (matches the browse slider,
// which is numeric-readout-free for the same reason).
// Only persist on release/keyup (RangeSlider's `change`), not every drag tick.
async function onSliderChange(val) {
  const settings = me.value?.settings
  if (!settings) {
    return
  }
  settings.browseMaxDistance = val >= feedMax ? BROWSE_DISTANCE_UNLIMITED : val
  await authStore.saveAndGet({ settings })
  emit('update')
}
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.settings-section {
  background: white;
  border-radius: var(--radius-lg, 0.75rem);
  box-shadow: var(--shadow-md);
  margin-bottom: 1rem;
  overflow: hidden;
}

.section-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);

  h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: $color-green-background;
  }

  .section-icon {
    color: $color-green-background;
  }
}

.section-content {
  padding: 1rem 1.25rem;
}

.option-info {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.option-label {
  font-weight: 500;
  font-size: 0.95rem;
}

.option-desc {
  font-size: 0.8rem;
  color: var(--color-gray-600);
}

/* The drag instruction is redundant on a touch screen and costs a line, so hide it on mobile. */
.drag-hint {
  display: none;
}
@media (min-width: 768px) {
  .drag-hint {
    display: inline;
  }
}

/* Framed rounded box around the slider + its reach hint, matching the settings card curves. */
.slider-frame {
  border: 1px solid rgba(0, 0, 0, 0.1);
  border-radius: var(--radius-lg, 0.75rem);
  padding: 0.75rem 1rem 0.5rem;
  margin-top: 0.5rem;
}

/* Centre the reach hint under the slider, within the frame. text-align centres the two-line block
   layout on mobile and the single inline line on tablet+ (when it fits). */
.slider-frame :deep(.nearby-towns) {
  text-align: center;
}
</style>
