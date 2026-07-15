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
          :min="BROWSE_MINUTES_MIN"
          :max="BROWSE_MINUTES_MAX"
          :step="BROWSE_MINUTES_STEP"
          left-label="Nearer"
          right-label="Further"
          aria-label="How far away"
          @change="onSliderChange"
        />
        <NearbyTowns :minutes="sliderValue" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineEmits } from 'vue'
import RangeSlider from '~/components/RangeSlider.vue'
import NearbyTowns from '~/components/NearbyTowns.vue'
import {
  BROWSE_MINUTES_MIN,
  BROWSE_MINUTES_MAX,
  BROWSE_MINUTES_STEP,
} from '~/constants'
import { useReachDistance } from '~/composables/useReachDistance'

const emit = defineEmits(['update'])

// Time-based "How far away" slider - a travel-time budget in MINUTES (matching the reach system), not
// miles. The shared composable persists both the chosen minutes (so the slider restores) and the
// routing-derived crow-flies mile radius (settings.browseMaxDistance, for the fast feed filter). The
// far-right stop means "no limit". Deliberately no numeric readout - Nearer/Further is enough, and a
// per-tick readout made the drag janky. Only persists on release (RangeSlider's `change`).
const { sliderValue, onSliderChange } = useReachDistance(() => emit('update'))
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
