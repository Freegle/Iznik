<template>
  <ModSysAdminUxPanel
    title="Friction"
    explanation="Where people click things that do nothing (dead clicks), click the same thing again and again (rage clicks) or repeat the same action. Trouble is the share of clicks that went wrong. The biggest rise since the previous period comes first."
    collapsible
    :open="open"
    :summary="summary"
    :notes="notes"
    :loading="store.friction.loading"
    :error="store.friction.error"
    :missing="store.friction.missing"
    :empty="!rows.length"
    empty-text="Nothing to report."
    @toggle="emit('toggle')"
  >
    <b-table :items="rows" :fields="fields" small responsive />
  </ModSysAdminUxPanel>
</template>

<script setup>
import { computed } from 'vue'
import { useUxStore } from '~/modtools/stores/ux'
import { withCount, signed } from '~/modtools/composables/useUxFormat'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'

defineProps({
  // Whether the person opened it. The section keeps this in the address.
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle'])

const store = useUxStore()

const fields = [
  { key: 'page', label: 'Page' },
  { key: 'component', label: 'Component' },
  { key: 'clicks', label: 'Clicks' },
  { key: 'dead', label: 'Dead clicks' },
  { key: 'deadPrev', label: 'Dead, previous' },
  { key: 'rage', label: 'Rage clicks' },
  { key: 'ragePrev', label: 'Rage, previous' },
  { key: 'repeated', label: 'Repeated intent' },
  { key: 'change', label: 'Change' },
]

const components = computed(() => store.friction.data?.components || [])

const rows = computed(() =>
  components.value.map((c) => {
    const p = c.previous || {}
    return {
      page: c.page,
      component: c.component,
      clicks: (c.clicks || 0).toLocaleString(),
      dead: withCount(c.dead_clicks, c.clicks),
      deadPrev: withCount(p.dead_clicks, p.clicks),
      rage: withCount(c.rage_clicks, c.clicks),
      ragePrev: withCount(p.rage_clicks, p.clicks),
      repeated: (c.repeated_intent || 0).toLocaleString(),
      // The server's change is a difference of two shares, so a rise of 0.3 is 30 points.
      change: `${signed((c.change || 0) * 100)} points`,
    }
  })
)

// The server sends the components in order of the rise in their trouble, biggest first, and only the
// top of them. So the first is the one whose trouble rose most, which is not the one with the most
// trouble: a button going from 1% to 4% outranks one stuck at 40%. If even the first did not rise,
// none did.
const summary = computed(() => {
  const first = components.value[0]
  if (!first) return ''
  const n = components.value.length
  const listed = n === 1 ? '1 component listed' : `${n} components listed`
  if (!(first.change > 0)) {
    return `Trouble did not rise at any component. ${listed}.`
  }
  return `Trouble rose most at ${first.page} / ${first.component}. ${listed}${n > 1 ? ', biggest rise first' : ''}.`
})

const notes = computed(() => store.notes('friction'))
</script>
