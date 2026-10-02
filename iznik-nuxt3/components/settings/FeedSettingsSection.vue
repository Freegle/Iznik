<template>
  <div class="settings-section">
    <div class="section-header">
      <v-icon icon="sliders" class="section-icon" />
      <h2>Feed</h2>
    </div>

    <div class="section-content">
      <div class="option-info mb-2">
        <span class="option-label">How far away</span>
      </div>

      <div class="slider-frame">
        <DistanceSliders
          id-prefix="feedDistance"
          @persisted="() => emit('update')"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineEmits } from 'vue'
import DistanceSliders from '~/components/DistanceSliders.vue'

const emit = defineEmits(['update'])

// The "How far away" control - travel-time budgets in MINUTES (matching the reach system), not
// miles, and two of them once the member separates "posts I see" from "who sees my posts". See
// DistanceSliders for the linked-by-default behaviour and DistanceSliderRow for why there is
// deliberately no numeric readout. No polygon here: Feed settings has no map to shade, so it does
// not pay for the boundary trace the browse page needs.
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

/* Framed rounded box around the sliders + their reach hints, matching the settings card curves. */
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
