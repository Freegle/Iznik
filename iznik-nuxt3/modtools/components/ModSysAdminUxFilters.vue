<template>
  <div class="ux-filters mb-3">
    <ModEmailDateFilter
      :loading="loading"
      fetch-label="Update"
      :presets="PRESETS"
      :daily="true"
      :default-preset="view.period"
      :default-start="view.from"
      :default-end="view.to"
      class="mb-1"
      @fetch="onFetch"
    >
      <!-- A label and its select are one group. The bar wraps on a phone, and a label left at the
           end of a line, away from its select, reads as unfinished. -->
      <template #extra-filters>
        <span v-if="view.uses.includes('device')" class="filter-pair">
          <label :for="`${id}-device`" class="filter-label">Device:</label>
          <b-form-select
            :id="`${id}-device`"
            :model-value="view.device"
            :options="DEVICE_OPTIONS"
            size="sm"
            class="filter-select"
            @update:model-value="
              (v) => emit('change', { device: v || undefined })
            "
          />
        </span>
        <span v-if="view.uses.includes('signed')" class="filter-pair">
          <label :for="`${id}-signed`" class="filter-label">Signed in:</label>
          <b-form-select
            :id="`${id}-signed`"
            :model-value="view.signed"
            :options="SIGNED_OPTIONS"
            size="sm"
            class="filter-select"
            @update:model-value="
              (v) => emit('change', { signed: v || undefined })
            "
          />
        </span>
      </template>
    </ModEmailDateFilter>

    <p class="ux-summary small mb-1">{{ summary }}</p>
    <p v-if="view.filtersActive" class="small mb-1">
      <button type="button" class="btn btn-link btn-sm p-0" @click="reset">
        Show everyone and all devices
      </button>
    </p>
    <details
      v-if="view.uses.includes('device')"
      class="small mb-1 ux-device-help"
    >
      <summary>About the device choices</summary>
      <p class="mb-0">{{ DEVICE_HELP }}</p>
    </details>
    <p class="small text-muted mb-0">
      Each day's figures arrive at 02:20 UTC the next day.
    </p>
  </div>
</template>

<script setup>
import { computed, useId } from 'vue'
import { DEVICE_LABELS, viewSummary } from '~/modtools/composables/useUxFormat'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'

const props = defineProps({
  // The view from useUxView: what the address says, and which filters the open section uses.
  view: { type: Object, required: true },
  loading: { type: Boolean, default: false },
  // An answer that carries the server's dates, or null before one has loaded. The summary line
  // prints those dates, and falls back to what was asked.
  summaryData: { type: Object, default: null },
})

// dates: the range to load, from the date filter. change: a filter was chosen, as a patch for
// useUxView.set (an undefined value takes the filter off).
const emit = defineEmits(['dates', 'change'])

const id = useId()

// The periods the server has rollups for: whole days, ending yesterday.
const PRESETS = ['7days', '30days', '90days', 'custom']

const DEVICE_OPTIONS = [
  { value: '', text: 'All devices' },
  ...Object.entries(DEVICE_LABELS).map(([value, text]) => ({ value, text })),
]
const SIGNED_OPTIONS = [
  { value: '', text: 'Everyone' },
  { value: 'in', text: 'Signed in' },
  { value: 'out', text: 'Not signed in' },
]

// Phone, tablet and desktop are the width of the browser window (useClientLog.js sets device_class).
const DEVICE_HELP =
  'Some figures have no device recorded. They are counted under All devices only. Phone, tablet and desktop are the width of the browser window: under 768 pixels, 768 to 1199 pixels, and 1200 pixels or more. A small desktop window counts as a tablet.'

const summary = computed(() => viewSummary(props.view, props.summaryData))

function onFetch(d) {
  emit('dates', { start: d.start, end: d.end, preset: d.preset })
}

function reset() {
  emit('change', { device: undefined, signed: undefined })
}
</script>

<style scoped lang="scss">
.filter-label {
  font-size: 0.85rem;
  font-weight: 600;
  margin: 0;
}

// The label and its select wrap as one, close together so they read as a pair. The select is as wide
// as its longest choice and no wider, with just room for its arrow, so the three filters and Update
// share a line at 1024 pixels.
.filter-pair {
  display: inline-flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 0.25rem;
  white-space: nowrap;
}

.filter-select {
  width: auto;
  max-width: 100%;
  padding-right: 2rem;
}

.ux-device-help summary {
  cursor: pointer;
  color: #495057;
}
</style>
