<template>
  <div class="sysadmin-ux" data-ux-ignore>
    <!-- data-ux-ignore: this tab must not feed its own telemetry. -->
    <ModSysAdminUxNav
      :sections="navSections"
      :current="active.key"
      :href-for="hrefFor"
      @go="(key) => set({ section: key })"
    />
    <!-- Kept mounted when hidden: the date filter works out the dates to load when it mounts. -->
    <ModSysAdminUxFilters
      v-show="active.filters.length"
      :view="view"
      :loading="loading"
      :summary-data="summaryData"
      @dates="onDates"
      @change="set"
    />
    <p v-show="!active.filters.length" class="ux-pillar-note small text-muted">
      This section shows everything so far. The period, device and sign-in
      filters do not apply here.
    </p>
    <NoticeMessage v-if="failingChecks.length" variant="danger" class="mb-3">
      Not delivering as expected: {{ failingChecks.join(', ') }}. The figures
      below may reflect instrumentation rather than members.
    </NoticeMessage>
    <NoticeMessage v-if="drawFailed" variant="danger" class="mb-3">
      This section could not be drawn. The other sections still work.
    </NoticeMessage>
    <component
      :is="active.component"
      v-else
      :key="active.key"
      :view="view"
      @navigate="set"
    />
  </div>
</template>

<script setup>
import {
  computed,
  getCurrentInstance,
  onErrorCaptured,
  provide,
  ref,
  watch,
} from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { useUxView } from '~/modtools/composables/useUxView'
import { uxSections } from '~/modtools/composables/useUxSections'
import { healthLabel } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxNav from '~/modtools/components/ModSysAdminUxNav.vue'
import ModSysAdminUxFilters from '~/modtools/components/ModSysAdminUxFilters.vue'

const store = useUxStore()
const { view, set, hrefFor } = useUxView()

const sections = uxSections()
const navSections = sections.map(({ key, label, badge }) => ({
  key,
  label,
  badge,
}))
const active = computed(
  () => sections.find((s) => s.key === view.value.section) || sections[0]
)

// Every section can get the address of a change, for links that can be copied, and the funnel page
// can offer the sections that want a link from it.
provide('uxHrefFor', hrefFor)
provide(
  'uxFunnelLinks',
  sections
    .filter((s) => s.funnelLink)
    .map((s) => ({ key: s.key, label: s.funnelLink.label }))
)

// The page mounts this tab afresh each time its UX pill is clicked, so nothing from before is held.
// Make sure the address says tab=ux, and drop anything in it that this tab does not understand.
store.forget()
set({})

// The dates to load, from the date filter. It sends them when it mounts and whenever the person
// chooses a period or presses Update. Update with the same dates is a refresh, so the store is told
// to forget what it holds and ask the server again.
const dates = ref(null)
function onDates({ start, end, preset }) {
  store.forget()
  dates.value = { start, end }
  set(
    preset === 'custom'
      ? { period: preset, from: start, to: end }
      : { period: preset }
  )
}

// Load what the open section shows, with the dates and only the filters that section uses. Overview
// and Pages never send Signed in, even when the address has it.
watch(
  () => [
    active.value.key,
    dates.value,
    JSON.stringify(view.value.filterParams),
  ],
  () => {
    if (!dates.value) return
    store.loadSlots(active.value.slots, {
      start: dates.value.start,
      end: dates.value.end,
      ...view.value.filterParams,
    })
  },
  { immediate: true }
)

const loading = computed(() => active.value.slots.some((k) => store[k].loading))

// An answer that carries the server's dates, for the summary line. One that was asked for other
// dates (the person just changed the period) is not used while its replacement loads.
const summaryData = computed(() => {
  const asked = dates.value
  const loaded = active.value.slots
    .map((k) => store[k].data)
    .filter((d) => d?.start && d?.end)
  const best = loaded.find((d) => d.previous?.start) || loaded[0]
  if (
    best &&
    (!asked || (best.start === asked.start && best.end === asked.end))
  ) {
    return best
  }
  return asked ? { start: asked.start, end: asked.end } : null
})

// A check on the data itself that is failing, on the sections that draw figures, named in words.
// Overview shows the health row itself.
const failingChecks = computed(() => {
  if (
    active.value.key === 'overview' ||
    !active.value.slots.includes('health')
  ) {
    return []
  }
  return (store.health.data?.metrics || [])
    .filter((m) => !m.ok)
    .map((m) => healthLabel(m.metric))
})

// A section that throws while it draws must not take the nav and the filters with it. The error is
// not swallowed: this hook returns nothing, so it goes on to the app's error handler, which is
// where Sentry and the page's own handler are wired in. A hook that returned false would stop it
// there, and the person would see only the notice while nothing was reported anywhere.
//
// The nav and the filter bar are the tab's own furniture. A fault in them is not the open section's:
// that section drew, so it stays, and the fault is reported like any other.
const self = getCurrentInstance()
const inFurniture = (instance) => {
  for (let i = instance?.$; i && i !== self; i = i.parent) {
    if (i.type === ModSysAdminUxNav || i.type === ModSysAdminUxFilters) {
      return true
    }
  }
  return false
}
const failedKey = ref('')
const drawFailed = computed(() => failedKey.value === active.value.key)
onErrorCaptured((err, instance) => {
  if (!inFurniture(instance)) failedKey.value = active.value.key
})
watch(
  () => active.value.key,
  (key) => {
    if (failedKey.value !== key) failedKey.value = ''
  }
)
</script>
