<template>
  <div class="d-flex flex-column text-center width position-relative">
    <label
      :for="$id('spinbutton')"
      :class="{
        'visually-hidden': labelSROnly,
      }"
      >{{ label }}</label
    >
    <vue-number-input
      :model-value="modelValue"
      controls
      inline
      center
      :step="1"
      rounded
      :min="min"
      :max="max"
      :size="size"
      :attrs="wholeNumberOnly"
      :class="'inputsize-' + size"
      @update:model-value="update"
    />
    <div v-if="appendText" class="available text-muted small">
      {{ appendText.trim() }}
    </div>
  </div>
</template>
<script setup>
import VueNumberInput from '@chenfengyuan/vue-number-input'
import { uid } from '~/composables/useId'

defineProps({
  modelValue: {
    type: Number,
    required: true,
  },
  min: {
    type: Number,
    required: false,
    default: 1,
  },
  max: {
    type: Number,
    required: false,
    default: 999,
  },
  label: {
    type: String,
    required: false,
    default: '',
  },
  labelSROnly: {
    type: Boolean,
    required: false,
    default: false,
  },
  appendText: {
    type: String,
    required: false,
    default: '',
  },
  size: {
    type: String,
    required: false,
    default: 'lg',
  },
})

const emit = defineEmits(['update:modelValue'])

const $id = (type) => {
  return uid(type)
}

// Every use is a count of items, and the API rejects a decimal with a 400
// (SR-UZFMH). Refuse anything but digits as it is typed, pasted or dropped,
// rather than accepting it and changing it afterwards. Android keyboards report
// keydown as "Unidentified", so beforeinput is what catches typing on a phone.
const DIGITS = /^\d*$/

function refuseNonDigits(event, text) {
  if (text && !DIGITS.test(text.trim())) {
    event.preventDefault()
  }
}

const wholeNumberOnly = {
  inputmode: 'numeric',
  onBeforeinput: (event) =>
    refuseNonDigits(event, event.data || event.dataTransfer?.getData('text')),
  onKeydown: (event) => {
    // Single characters only: Backspace, arrows, Tab and the like are named
    // keys, and Ctrl/Cmd shortcuts must keep working.
    if (
      event.key?.length === 1 &&
      !event.ctrlKey &&
      !event.metaKey &&
      !event.altKey
    ) {
      refuseNonDigits(event, event.key)
    }
  },
}

const update = (newVal, oldVal) => {
  emit('update:modelValue', newVal, oldVal)
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';

.border {
  border: 1px solid black;
}

:deep(input) {
  font-size: 1.6rem !important;
  line-height: 2rem !important;
  padding-top: 0.45rem !important;
  padding-bottom: 0.45rem !important;
  padding-left: 1rem;
  padding-right: 1rem;
}

:deep(.inputsize-small input) {
  font-size: 1rem !important;
  line-height: 1.5rem !important;
  padding-top: 0.2rem !important;
  padding-bottom: 0.5rem !important;
  padding-left: 0.5rem;
  padding-right: 0.5rem;
}

.available {
  //position: absolute;
  translate: 0 -14px;
  font-size: 0.6rem;
}
</style>
