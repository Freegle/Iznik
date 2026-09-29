<template>
  <div class="range-slider" :class="'range-slider--' + variant">
    <!-- The input holds the FUNCTIONAL range (min..max). When axisMax is wider than max the input
         is narrowed to its share of the axis and the remainder is drawn as an inert grey stub, so
         several sliders with different maxima can be stacked on one shared scale and still be read
         against each other. The stub is not part of the input, so keyboard and assistive tech
         cannot land on a value the caller has said is unavailable. -->
    <div class="range-slider__track-row">
      <!-- The wrapper carries BOTH the input's share of the axis and the handle's position along
           it (--range-slider-fraction), because the shields are positioned from that fraction and
           have to be measured against the input's own width, not the whole row. -->
      <div
        class="range-slider__input-wrap"
        :style="[usableWidth, { '--range-slider-fraction': thumbFraction }]"
      >
        <input
          :id="id"
          type="range"
          class="range-slider__input"
          :min="min"
          :max="max"
          :step="step"
          :value="localValue"
          :aria-label="ariaLabel"
          :style="{ touchAction: 'pan-y' }"
          @input="onInput"
          @change="onChange"
          @wheel="onWheel"
        />
        <!-- The track either side of the handle is covered by these two inert shields, so the
             only press the input can receive is one on the handle itself. They have no handlers,
             so a press on the track reaches a div that does nothing and the page scrolls as it
             would anywhere else. See the comment on onWheel for why this is needed. -->
        <div
          class="range-slider__shield range-slider__shield--before"
          aria-hidden="true"
        />
        <div
          class="range-slider__shield range-slider__shield--after"
          aria-hidden="true"
        />
      </div>
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

// Where the handle sits along the track, 0..1. This is the same fraction the browser uses to lay
// the handle out, so the gap the shields leave for it is derived from the handle's own position
// rather than guessed - they cannot drift apart. Clamped, because a caller's clamp can hand us a
// value briefly outside the range and a gap off the end of the track would leave the handle
// unreachable.
const thumbFraction = computed(() => {
  const span = props.max - props.min
  if (!(span > 0)) return 0
  const f = (localValue.value - props.min) / span
  return Math.min(1, Math.max(0, f))
})

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

// Members reported this slider changing by itself while they scrolled the page past it. The cause
// is the native control: a press ANYWHERE on a range input's track jumps the handle to that point,
// so a tap that was only the beginning of a scroll moved their distance. The value must move only
// on a deliberate drag of the handle, so:
//
//  - the track either side of the handle is covered by the two inert shields above, leaving a gap
//    just wide enough to grab the handle by. A press on the track now reaches a div that does
//    nothing, and the page scrolls as it would anywhere else;
//  - the input is marked touch-action: pan-y, so a vertical touch drag STARTING on the handle is
//    left to the page as scroll, while a sideways drag is the control's;
//  - a wheel over the handle is prevented here, because Chrome/Safari opt a range input into
//    wheel-changes-value the moment any wheel listener overlaps its box. Over the rest of the
//    track the wheel lands on a shield instead, so the page scrolls normally.
function onWheel(e) {
  e.preventDefault()
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.range-slider {
  width: 100%;

  /* The handle's size and the gap the shields leave for it are the same measurements, so they are
     declared once here and everything else reads them. Changing the handle size in a media query
     moves its grab area with it. */
  --range-slider-track: 8px;
  --range-slider-thumb: 24px;
  /* A little margin either side of the handle, so it can be grabbed without pixel accuracy. Small
     enough that the grab area still reads as "the blob", not "the track". */
  --range-slider-grab: 6px;
}

/* Holds the input and (when the caller gave a wider axis) the greyed stub that continues the track
   to the end of that axis. align-items: center keeps the 8px stub on the same centre line as the
   input's 8px track, whose own box is taller because the thumb overflows it. */
.range-slider__track-row {
  display: flex;
  align-items: center;
  width: 100%;
}

/* The input's own box, so the shields inside can be positioned against the track's width rather
   than the row's - the two differ whenever there is a dead zone. */
.range-slider__input-wrap {
  position: relative;
  flex: 1 1 auto;
  min-width: 0;
}

/* The unavailable tail of a shared axis. Inert: no pointer events, hidden from AT (the input's
   aria-label and max already describe what is reachable). Dashed rather than solid so it reads as
   "not part of this control" instead of "track you have not filled yet". */
.range-slider__deadzone {
  flex: 1 1 auto;
  height: var(--range-slider-track);
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
  height: var(--range-slider-track);
  border-radius: var(--radius-sm, 0.375rem);
  background: transparent;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
  margin: 0.75rem 0 0.25rem;
}

/* The covers that leave only the handle pressable. Transparent, so the track still shows through,
   and positioned from --range-slider-fraction: the handle's centre travels across the input minus
   its own width, exactly as the browser lays it out, so the gap sits over the handle at every
   value. Painted above the input by being positioned siblings of it. */
.range-slider__shield {
  position: absolute;
  top: 0;
  bottom: 0;
  background: transparent;
}

.range-slider__shield--before {
  left: 0;
  width: max(
    0px,
    calc(
      var(--range-slider-fraction) *
        (100% - var(--range-slider-thumb)) - var(--range-slider-grab)
    )
  );
}

.range-slider__shield--after {
  left: calc(
    var(--range-slider-fraction) * (100% - var(--range-slider-thumb)) +
      var(--range-slider-thumb) + var(--range-slider-grab)
  );
  right: 0;
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
  width: var(--range-slider-thumb);
  height: var(--range-slider-thumb);
  border-radius: 50%;
  background: $colour;
  cursor: grab;
  border: none;
  box-shadow: 0 2px 8px $shadow-colour;
  transition: all var(--transition-normal);
  /* Vertically centre the thumb on the track. */
  margin-top: calc((var(--range-slider-track) - var(--range-slider-thumb)) / 2);

  &:hover {
    transform: scale(1.15);
    box-shadow: 0 4px 12px $shadow-colour;
  }

  &:active {
    transform: scale(1.05);
    cursor: grabbing;
  }
}

@mixin range-slider-track($fill-colour) {
  width: 100%;
  height: var(--range-slider-track);
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

/* Larger touch target on mobile (easier to drag accurately). The grab area follows, because the
   shields are sized from the same variable. */
@include media-breakpoint-down(sm) {
  .range-slider {
    --range-slider-track: 10px;
    --range-slider-thumb: 28px;
    --range-slider-grab: 8px;
  }
}
</style>
