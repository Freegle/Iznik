<template>
  <div class="settings-group">
    <div class="setting-row">
      <span class="setting-label">{{ label }}</span>
      <b-form-select
        v-model="emailfreq"
        :class="highlightEmailFrequencyIfOn"
        class="frequency-select"
      >
        <option value="-1">Immediately</option>
        <option value="24">Daily</option>
        <option value="0">Never</option>
      </b-form-select>
    </div>

    <div v-if="!eventshide" class="setting-row">
      <span class="setting-label">Community events</span>
      <OurToggle
        v-model="eventsallowed"
        size="sm"
        :labels="{ checked: 'On', unchecked: 'Off' }"
      />
    </div>

    <div v-if="!volunteerhide" class="setting-row">
      <span class="setting-label">Volunteer opportunities</span>
      <OurToggle
        v-model="volunteeringallowed"
        size="sm"
        :labels="{ checked: 'On', unchecked: 'Off' }"
      />
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
import OurToggle from '~/components/OurToggle'

const props = defineProps({
  membershipMT: {
    // ModTools passes the member row here so it can control persistence itself.
    type: Object,
    required: false,
    default: null,
  },
  emailfrequency: {
    type: Number,
    required: false,
    default: null,
  },
  eventsallowed: {
    type: Boolean,
    required: false,
    default: false,
  },
  volunteeringallowed: {
    type: Boolean,
    required: false,
    default: false,
  },
  label: {
    type: String,
    required: false,
    default: 'OFFER/WANTED emails',
  },
  eventshide: {
    type: Boolean,
    required: false,
    default: false,
  },
  volunteerhide: {
    type: Boolean,
    required: false,
    default: false,
  },
})

const emit = defineEmits([
  'update:emailfrequency',
  'update:eventsallowed',
  'update:volunteeringallowed',
])

// ModTools passes the member row as membershipMT; the national site passes
// the equivalent values directly as props. This component never persists
// anything itself - it just emits, and the caller decides how to save it.
const membership = computed(() => props.membershipMT)

const highlightEmailFrequencyIfOn = computed(() => {
  return props.emailfrequency === 0 ? 'frequency-off' : 'frequency-on'
})

const emailfreq = computed({
  get() {
    if (membership.value && membership.value.emailfrequency != null) {
      return membership.value.emailfrequency.toString()
    }
    return (props.emailfrequency ?? 24).toString()
  },
  set(newval) {
    changeValue('emailfrequency', newval)
  },
})

const eventsallowed = computed({
  get() {
    if (membership.value && membership.value.eventsallowed != null) {
      return Boolean(membership.value.eventsallowed)
    }
    return Boolean(props.eventsallowed)
  },
  set(newval) {
    changeValue('eventsallowed', newval ? 1 : 0)
  },
})

const volunteeringallowed = computed({
  get() {
    if (membership.value && membership.value.volunteeringallowed != null) {
      return Boolean(membership.value.volunteeringallowed)
    }
    return Boolean(props.volunteeringallowed)
  },
  set(newval) {
    changeValue('volunteeringallowed', newval ? 1 : 0)
  },
})

function changeValue(param, val) {
  emit('update:' + param, val)
}
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.settings-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.setting-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.setting-label {
  font-size: 0.9rem;
  color: $color-gray--darker;
}

.frequency-select {
  width: auto;
  min-width: 120px;
  font-size: 0.9rem;

  &.frequency-on {
    border: 2px solid $color-green-background;
  }

  &.frequency-off {
    border: 1px solid var(--color-gray-600);
  }
}
</style>
