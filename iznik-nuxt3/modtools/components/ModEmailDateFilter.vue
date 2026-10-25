<template>
  <b-card class="mb-3 filter-card">
    <b-form class="filter-form" inline @submit.prevent="onFetch">
      <label :for="`${formId}-period`" class="filter-label">Period:</label>
      <b-form-select
        :id="`${formId}-period`"
        v-model="datePreset"
        :options="datePresetOptions"
        size="sm"
        style="width: 130px"
        @change="onPresetChange"
      />
      <template v-if="datePreset === 'custom'">
        <label :for="`${formId}-from`" class="filter-label">From:</label>
        <b-form-input
          :id="`${formId}-from`"
          v-model="startDate"
          :type="daily ? 'date' : 'datetime-local'"
          :max="daily ? yesterday : undefined"
          size="sm"
          style="width: 175px"
        />
        <label :for="`${formId}-to`" class="filter-label">To:</label>
        <b-form-input
          :id="`${formId}-to`"
          v-model="endDate"
          :type="daily ? 'date' : 'datetime-local'"
          :max="daily ? yesterday : undefined"
          size="sm"
          style="width: 175px"
        />
      </template>
      <slot name="extra-filters" />
      <b-button type="submit" variant="primary" size="sm" :disabled="loading">
        <span v-if="loading">
          <span class="spinner-border spinner-border-sm me-1" />
          Loading...
        </span>
        <span v-else>{{ fetchLabel }}</span>
      </b-button>
    </b-form>
  </b-card>
</template>

<script setup>
import { computed, ref, onMounted, useId } from 'vue'

// Unique per instance so label/for pairs never clash when the component renders twice.
const formId = useId()

const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  fetchLabel: {
    type: String,
    default: 'Fetch',
  },
  defaultPreset: {
    type: String,
    default: '7days',
  },
  // The preset values to offer. The default is all six.
  presets: {
    type: Array,
    default: () => ['hour', 'day', '7days', '30days', '90days', 'custom'],
  },
  // Whole days only. A preset ends yesterday (UTC) and is n days long, so "Last 7 days" is seven
  // complete days and the period before it is the same length. The custom inputs are dates, never
  // a time of day, and stop at yesterday. Rollups are one row a day and none exists for today.
  daily: {
    type: Boolean,
    default: false,
  },
  // The range to start on when defaultPreset is custom, so a linked range is restored.
  defaultStart: {
    type: String,
    default: '',
  },
  defaultEnd: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['fetch'])

const allPresetOptions = [
  { text: 'Last hour', value: 'hour' },
  { text: 'Last 24 hours', value: 'day' },
  { text: 'Last 7 days', value: '7days' },
  { text: 'Last 30 days', value: '30days' },
  { text: 'Last 90 days', value: '90days' },
  { text: 'Custom dates', value: 'custom' },
]
const datePresetOptions = computed(() =>
  allPresetOptions.filter((o) => props.presets.includes(o.value))
)

const DAILY_PRESET_DAYS = { '7days': 7, '30days': 30, '90days': 90 }
const isoDay = (d) => d.toISOString().split('T')[0]

// Yesterday in UTC, the last day a rollup can hold.
const yesterday = (() => {
  const now = new Date()
  return isoDay(
    new Date(
      Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - 1)
    )
  )
})()

// Map presets to Loki-compatible relative time strings.
const presetToLoki = {
  hour: '1h',
  day: '24h',
  '7days': '7d',
  '30days': '30d',
  '90days': '90d',
}

const datePreset = ref(props.defaultPreset)
const startDate = ref('')
const endDate = ref('')

function formatDateTimeForAPI(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return (
    date.getFullYear() +
    '-' +
    pad(date.getMonth() + 1) +
    '-' +
    pad(date.getDate()) +
    'T' +
    pad(date.getHours()) +
    ':' +
    pad(date.getMinutes()) +
    ':' +
    pad(date.getSeconds())
  )
}

// A preset in daily mode: n whole days ending yesterday (UTC).
function resolveDailyDates(days) {
  const end = new Date(`${yesterday}T00:00:00Z`)
  const start = new Date(end)
  start.setUTCDate(start.getUTCDate() - (days - 1))
  return { start: isoDay(start), end: isoDay(end) }
}

function resolvePresetDates(preset) {
  if (props.daily && DAILY_PRESET_DAYS[preset]) {
    return resolveDailyDates(DAILY_PRESET_DAYS[preset])
  }

  const now = new Date()
  let start

  switch (preset) {
    // The two short presets are a number of hours, not a calendar step. Stepping
    // the local date or hour back gives the same clock time a day or an hour ago,
    // which on the day the clocks change is 25 hours, or 2, or 0.
    case 'hour':
      start = new Date(now.getTime() - 60 * 60 * 1000)
      return {
        start: formatDateTimeForAPI(start),
        end: formatDateTimeForAPI(now),
      }
    case 'day':
      start = new Date(now.getTime() - 24 * 60 * 60 * 1000)
      return {
        start: formatDateTimeForAPI(start),
        end: formatDateTimeForAPI(now),
      }
    case '7days':
      start = new Date(now)
      start.setDate(start.getDate() - 7)
      return {
        start: start.toISOString().split('T')[0],
        end: now.toISOString().split('T')[0],
      }
    case '30days':
      start = new Date(now)
      start.setDate(start.getDate() - 30)
      return {
        start: start.toISOString().split('T')[0],
        end: now.toISOString().split('T')[0],
      }
    case '90days':
      start = new Date(now)
      start.setDate(start.getDate() - 90)
      return {
        start: start.toISOString().split('T')[0],
        end: now.toISOString().split('T')[0],
      }
    case 'custom':
      return { start: startDate.value, end: endDate.value }
    default:
      start = new Date(now)
      start.setDate(start.getDate() - 7)
      return {
        start: start.toISOString().split('T')[0],
        end: now.toISOString().split('T')[0],
      }
  }
}

function onPresetChange(preset) {
  if (preset === 'custom') return
  onFetch()
}

function onFetch() {
  const dates = resolvePresetDates(datePreset.value)
  // Custom dates that are not both filled in are not a range. Say nothing until they are.
  if (
    props.daily &&
    datePreset.value === 'custom' &&
    !(dates.start && dates.end)
  ) {
    return
  }
  const lokiRange = presetToLoki[datePreset.value] || null

  emit('fetch', {
    start: dates.start,
    end: dates.end,
    lokiRange,
    preset: datePreset.value,
  })
}

onMounted(() => {
  // Set initial dates and trigger initial fetch. A custom range that was handed in is used as it is.
  if (props.defaultPreset === 'custom') {
    startDate.value = props.defaultStart
    endDate.value = props.defaultEnd
  }
  const dates = resolvePresetDates(props.defaultPreset)
  startDate.value = dates.start
  endDate.value = dates.end
  onFetch()
})
</script>

<style scoped>
.filter-card {
  background: #f8f9fa;
}

.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.filter-label {
  font-size: 0.85rem;
  font-weight: 600;
  margin: 0;
}
</style>
