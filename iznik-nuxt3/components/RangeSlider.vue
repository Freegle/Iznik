<template>
  <div class="range-slider" :class="'range-slider--' + variant">
    <!-- The input holds the FUNCTIONAL range (min..max). When axisMax is wider than max the input
         is narrowed to its share of the axis and the remainder is drawn as an inert grey stub, so
         several sliders with different maxima can be stacked on one shared scale and still be read
         against each other. The stub is not part of the input, so keyboard and assistive tech
         cannot land on a value the caller has said is unavailable. -->
    <div class="range-slider__track-row">
      <input
        :id="id"
        type="range"
        class="range-slider__input"
        :min="min"
        :max="max"
        :step="step"
        :value="localValue"
        :aria-label="ariaLabel"
        :style="usableWidth"
        @input="onInput"
        @change="onChange"
      />
      <div
        v-if="hasDeadZone"
        class="range-slider__deadzone"
        :title="deadZoneTitle"
        aria-hidden="true"
      />
    </div>
    <div v-if="leftLabel || rightLabel" class="range-slider__labels">
      <span class="range-slider__label">{{ leftLabel }}</span>
      <span class="range-slider__label">{{ rightLabel }}</span>
    </div>
  </div>
</template>
<script setup>
import { ref, watch, computed } from '#imports'
// Generic, accessible native-range wrapper. Lifted out of the inline sliders that used
// to be duplicated in MyPostsDonationAsk.vue/DonationAskStripe.vue (identical
// .amount-slider/.slider-labels SCSS) so new sliders (e.g. the browse distance filter)
// don't add a third copy. Those two donation components are left untouched for now -
// they're wrapped around payment flows - but could adopt this later.
//
// Deliberately has no numeric readout: callers that want one can render it themselves
// next to the component. Emits update:modelValue on every drag tick (for an instant
// visual) and a separate change event only when the drag/keypress ends, so callers can
// debounce anything expensive (e.g. persisting to the server) on change alone.
const props = defineProps({
  modelValue: {
    type: Number,
    required: true,
  },
  min: {
    type: Number,
    default: 0,
  },
  max: {
    type: Number,
    default: 10,
  },
  step: {
    type: Number,
    default: 1,
  },
  leftLabel: {
    type: String,
    default: '',
  },
  rightLabel: {
    type: String,
    default: '',
  },
  // Colour scheme: 'green' (default - matches the browse filters panel) or 'blue'
  // (matches the existing donation sliders, for when they're migrated to this).
  variant: {
    type: String,
    default: 'green',
  },
  ariaLabel: {
    type: String,
    default: 'Range',
  },
  id: {
    type: String,
    default: null,
  },
  // The full scale this slider is drawn against, when that is wider than its own `max`. Defaults to
  // `max` (no dead zone, geometry unchanged). Only affects layout: the reachable values stay
  // min..max.
  axisMax: {
    type: Number,
    default: null,
  },
  // Tooltip on the greyed stub, explaining why the rest of the scale is unavailable.
  deadZoneTitle: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue', 'change'])

// A dead zone only exists when the caller gave a wider axis than this slider's own maximum, and
// there is actually room between them.
const hasDeadZone = computed(
  () =>
    props.axisMax !== null && props.axisMax > props.max && props.max > props.min
)

// The input's share of the shared axis. Flex-basis rather than width so the stub takes the rest
// without either of them shrinking below its share.
const usableWidth = computed(() => {
  if (!hasDeadZone.value) return null
  const share = (props.max - props.min) / (props.axisMax - props.min)
  return { flex: `0 0 ${(share * 100).toFixed(4)}%` }
})

// The native <input type="range"> drag must NOT be fought by parent reactivity. If we bind
// :value directly to modelValue, then every parent re-render during a drag (e.g. a recomputed
// feedMax, a store update, the [maxDistance,feedMax] watch) rewrites the input's value and
// yanks the thumb back to an earlier position - the janky "clicking back" drag members saw.
//
// So we keep an internal localValue that DRIVES the input, update it locally on every drag
// tick, and only accept modelValue from the PARENT when it differs from the value we last
// emitted (a genuine external change - a reset, a clamp, a programmatic set). Echoes of our
// own drag are ignored, so the native drag is never interrupted.
const localValue = ref(props.modelValue)
let lastEmitted = props.modelValue

watch(
  () => props.modelValue,
  (v) => {
    if (v !== lastEmitted) {
      localValue.value = v
      lastEmitted = v
    }
  }
)

function onInput(e) {
  const v = parseFloat(e.target.value)
  localValue.value = v
  lastEmitted = v
  emit('update:modelValue', v)
}

function onChange(e) {
  const v = parseFloat(e.target.value)
  localValue.value = v
  lastEmitted = v
  emit('change', v)
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.range-slider {
  width: 100%;
}

/* Holds the input and (when the caller gave a wider axis) the greyed stub that continues the track
   to the end of that axis. align-items: center keeps the 8px stub on the same centre line as the
   input's 8px track, whose own box is taller because the thumb overflows it. */
.range-slider__track-row {
  display: flex;
  align-items: center;
  width: 100%;
}

/* The unavailable tail of a shared axis. Inert: no pointer events, hidden from AT (the input's
   aria-label and max already describe what is reachable). Dashed rather than solid so it reads as
   "not part of this control" instead of "track you have not filled yet". */
.range-slider__deadzone {
  flex: 1 1 auto;
  height: 8px;
  margin: 0.75rem 0 0.25rem;
  border-radius: 0 var(--radius-sm, 0.375rem) var(--radius-sm, 0.375rem) 0;
  background: repeating-linear-gradient(
    45deg,
    var(--color-gray-200, #e9ecef) 0 4px,
    var(--color-gray-300, #dee2e6) 4px 8px
  );
  pointer-events: none;
}

.range-slider__input {
  width: 100%;
  height: 8px;
  border-radius: var(--radius-sm, 0.375rem);
  background: transparent;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
  cursor: pointer;
  margin: 0.75rem 0 0.25rem;
}

.range-slider__labels {
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
  color: var(--color-gray-600);
  font-weight: 500;
}

/* Shared thumb/track shape - only the colours differ between variants. */
@mixin range-slider-thumb($colour, $shadow-colour) {
  -webkit-appearance: none;
  appearance: none;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: $colour;
  cursor: pointer;
  border: none;
  box-shadow: 0 2px 8px $shadow-colour;
  transition: all var(--transition-normal);
  margin-top: -8px; /* Vertically centre thumb on the 8px track. */

  &:hover {
    transform: scale(1.15);
    box-shadow: 0 4px 12px $shadow-colour;
  }

  &:active {
    transform: scale(1.05);
  }
}

@mixin range-slider-track($fill-colour) {
  width: 100%;
  height: 8px;
  border-radius: var(--radius-sm, 0.375rem);
  /* Solid on the left ("Nearer"), fading out towards the right ("Further"). */
  background: linear-gradient(to right, $fill-colour 0%, $color-gray-3 100%);
}

.range-slider--green .range-slider__input {
  &::-webkit-slider-thumb {
    @include range-slider-thumb($color-success, rgba(51, 136, 8, 0.5));
  }

  &::-moz-range-thumb {
    @include range-slider-thumb($color-success, rgba(51, 136, 8, 0.5));
  }

  &::-webkit-slider-runnable-track {
    @include range-slider-track($color-green--darker);
  }

  &::-moz-range-track {
    @include range-slider-track($color-green--darker);
  }
}

.range-slider--blue .range-slider__input {
  &::-webkit-slider-thumb {
    @include range-slider-thumb($color-blue--bright, rgba(0, 123, 255, 0.5));
  }

  &::-moz-range-thumb {
    @include range-slider-thumb($color-blue--bright, rgba(0, 123, 255, 0.5));
  }

  &::-webkit-slider-runnable-track {
    @include range-slider-track($color-blue--bright);
  }

  &::-moz-range-track {
    @include range-slider-track($color-blue--bright);
  }
}

/* Larger touch target on mobile (easier to drag accurately). */
@include media-breakpoint-down(sm) {
  /* Keep the dead-zone stub the same height as the track it continues. */
  .range-slider__deadzone {
    height: 10px;
  }

  .range-slider__input {
    height: 10px;

    &::-webkit-slider-thumb {
      width: 28px;
      height: 28px;
      margin-top: -9px;
    }

    &::-moz-range-thumb {
      width: 28px;
      height: 28px;
    }

    &::-webkit-slider-runnable-track {
      height: 10px;
    }

    &::-moz-range-track {
      height: 10px;
    }
  }
}
</style>
