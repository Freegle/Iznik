<template>
  <div>
    <div class="intro-text mb-3">
      <p>
        Help us train Freegle's AI to spot electrical items needing
        recycling. For the item below, tell us what condition it's in,
        roughly how heavy it is, and how big it is.
      </p>
    </div>

    <div class="image-card mb-3">
      <div class="image-container">
        <img
          :src="item.imageUrl"
          :alt="item.itemName"
          class="review-image"
          @error="brokenImage"
        />
      </div>
      <div class="image-caption">
        <strong>{{ item.itemName }}</strong>
      </div>
    </div>

    <div class="question-block mb-3">
      <p class="question-label">Condition</p>
      <div class="option-row">
        <b-button
          v-for="opt in CONDITION_OPTIONS"
          :key="opt.value"
          :data-testid="`condition-${opt.value}`"
          :variant="condition === opt.value ? 'primary' : 'outline-primary'"
          @click="condition = opt.value"
        >
          {{ opt.label }}
        </b-button>
      </div>
    </div>

    <div class="question-block mb-3">
      <p class="question-label">Weight (rough estimate is fine)</p>
      <div class="option-row">
        <b-button
          v-for="opt in WEIGHT_OPTIONS"
          :key="opt.value"
          :data-testid="`weight-${opt.value}`"
          :variant="weight === opt.value ? 'primary' : 'outline-primary'"
          @click="weight = opt.value"
        >
          {{ opt.label }}
        </b-button>
      </div>
    </div>

    <div class="question-block mb-3">
      <p class="question-label">Size (longest side)</p>
      <div class="option-row">
        <b-button
          v-for="opt in SIZE_OPTIONS"
          :key="opt.value"
          :data-testid="`size-${opt.value}`"
          :variant="size === opt.value ? 'primary' : 'outline-primary'"
          @click="size = opt.value"
        >
          {{ opt.label }}
        </b-button>
      </div>
    </div>

    <div class="d-flex justify-content-end mb-3">
      <SpinButton
        data-testid="submit-labels"
        variant="success"
        icon-name="check"
        label="Submit"
        :disabled="!complete"
        @handle="submit"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import SpinButton from './SpinButton'
import { useMicroVolunteeringStore } from '~/stores/microvolunteering'

const props = defineProps({
  item: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['next'])

const microVolunteeringStore = useMicroVolunteeringStore()

const CONDITION_OPTIONS = [
  { value: 'as_new', label: 'As new' },
  { value: 'good',   label: 'Good' },
  { value: 'fair',   label: 'Fair' },
  { value: 'poor',   label: 'Poor' },
  { value: 'broken', label: 'Broken' },
  { value: 'unsure', label: "Can't tell" },
]

const WEIGHT_OPTIONS = [
  { value: 'under_1kg',  label: 'Under 1 kg' },
  { value: '1_5kg',      label: '1 - 5 kg' },
  { value: '5_20kg',     label: '5 - 20 kg' },
  { value: '20_100kg',   label: '20 - 100 kg' },
  { value: 'over_100kg', label: 'Over 100 kg' },
  { value: 'unsure',     label: "Can't tell" },
]

const SIZE_OPTIONS = [
  { value: 'tiny',   label: 'Tiny (< 20cm)' },
  { value: 'small',  label: 'Small (20 - 50cm)' },
  { value: 'medium', label: 'Medium (50 - 100cm)' },
  { value: 'large',  label: 'Large (> 100cm)' },
  { value: 'unsure', label: "Can't tell" },
]

const condition = ref(null)
const weight    = ref(null)
const size      = ref(null)

const complete = computed(() => condition.value && weight.value && size.value)

async function submit(callback) {
  if (!complete.value) {
    callback?.()
    return
  }

  await microVolunteeringStore.respond({
    messageid: props.item.messageid,
    attid: props.item.attid,
    eeelabels: {
      condition: condition.value,
      weight:    weight.value,
      size:      size.value,
    },
  })

  callback?.()
  emit('next')
}

function brokenImage(event) {
  event.target.src = '/defaultprofile.png'
}
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.intro-text {
  color: $color-gray--darker;
  line-height: 1.6;
}

.image-card {
  overflow: hidden;
  box-shadow: var(--shadow-md);
  background: $color-white;
}

.image-container {
  width: 100%;
  background: $color-gray--light;
}

.review-image {
  width: 100%;
  height: auto;
  display: block;
  max-height: 400px;
  object-fit: contain;
}

.image-caption {
  padding: 0.75rem 1rem;
  background: $color-gray--lighter;
  text-align: center;
  font-size: 1.1rem;
}

.question-label {
  font-weight: 600;
  color: $color-gray--darker;
  margin-bottom: 0.5rem;
}

.option-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
