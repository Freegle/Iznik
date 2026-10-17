<template>
  <b-modal
    :model-value="modelValue"
    :title="`Sample sessions: ${flowTitle(flow)} / ${stepName(flow, step)} on ${day}`"
    size="xl"
    scrollable
    ok-only
    ok-title="Close"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <b-form-group
      v-if="days.length > 1"
      label="Day"
      label-for="ux-sample-day"
      class="mb-3"
    >
      <b-form-select
        id="ux-sample-day"
        v-model="day"
        :options="days"
        class="sample-day w-auto"
      />
    </b-form-group>
    <p class="small text-muted mb-2">
      Sample sessions are not filtered by device or sign-in.
    </p>
    <div v-if="store.samples.loading" class="text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>
    <NoticeMessage v-else-if="store.samples.error" variant="danger">
      {{ store.samples.error }}
    </NoticeMessage>
    <template v-else>
      <NoticeMessage v-if="store.samples.data?.loki_error" variant="warning">
        Some timelines could not be read from the logs:
        {{ store.samples.data.loki_error }}
      </NoticeMessage>
      <p v-if="!sessions.length" class="text-muted">
        No sampled sessions for this step on this day. Samples are kept for
        about a week.
      </p>
      <div v-for="s in sessions" :key="s.session_id" class="session mb-3">
        <h6 class="mb-1">Session {{ s.session_id }}</h6>
        <ol class="timeline list-unstyled small mb-0">
          <li v-for="(e, i) in s.events" :key="i" class="event">
            <span class="text-muted">{{ time(e.ts) }}</span>
            <strong class="ms-2">{{ e.event_type }}</strong>
            <span v-if="e.action_name" class="ms-1">{{ e.action_name }}</span>
            <span v-if="e.step" class="ms-1 text-muted">[{{ e.step }}]</span>
            <span v-if="e.label" class="ms-1">"{{ e.label }}"</span>
            <span v-if="e.component" class="ms-1 text-muted">
              in {{ e.component }}
            </span>
            <span v-if="e.dwell_ms" class="ms-1"
              >after {{ ms(e.dwell_ms) }}</span
            >
            <span v-if="e.page_route" class="ms-1 text-muted">
              {{ e.page_route }}
            </span>
          </li>
        </ol>
      </div>
    </template>
  </b-modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { ms } from '~/modtools/composables/useUxFormat'
import { flowTitle } from '~/modtools/composables/useUxFlows'
import { stepName } from '~/modtools/composables/useUxFunnel'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  flow: { type: String, default: '' },
  step: { type: String, default: '' },
  // The selected period, YYYY-MM-DD. Samples are kept per day, so one day is read at a time,
  // the last day of the period first.
  start: { type: String, default: '' },
  end: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const store = useUxStore()
const sessions = computed(() => store.samples.data?.sessions || [])

// Every day of the period, latest first.
const days = computed(() => {
  const last = Date.parse(props.end + 'T00:00:00Z')
  if (Number.isNaN(last)) return []
  const first = Date.parse(props.start + 'T00:00:00Z')
  const out = []
  for (let d = last; d >= (Number.isNaN(first) ? last : first); d -= 86400000) {
    out.push(new Date(d).toISOString().slice(0, 10))
  }
  return out
})
const day = ref(props.end)

function time(ts) {
  const m = /T(\d\d:\d\d:\d\d)/.exec(ts || '')
  return m ? m[1] : ts || ''
}

function load() {
  store.load('samples', {
    date: day.value,
    flow: props.flow,
    step: props.step,
  })
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    if (day.value === props.end) load()
    // Otherwise the watch on day loads it.
    else day.value = props.end
  }
)
watch(day, () => props.modelValue && load())
if (props.modelValue) load()
</script>
