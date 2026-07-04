<template>
  <div class="range-slider" :class="'range-slider--' + variant">
    <input
      :id="id"
      type="range"
      class="range-slider__input"
      :min="min"
      :max="max"
      :step="step"
      :value="localValue"
      :aria-label="ariaLabel"
      @input="onInput"
      @change="onChange"
    />
    <div v-if="leftLabel || rightLabel" class="range-slider__labels">
      <span class="range-slider__label">{{ leftLabel }}</span>
      <span class="range-slider__label">{{ rightLabel }}</span>
    </div>
  </div>
</template>
<script setup>
import { ref, watch } from '#imports'
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
})

const emit = defineEmits(['update:modelValue', 'change'])

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
