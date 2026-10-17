<template>
  <section
    v-if="mine"
    class="ux-reply-confirm mb-2"
    data-testid="ux-reply-confirm"
  >
    <h6 class="ux-exp-h">Still want it?</h6>
    <p class="what mb-1">
      Freegle asks the replier whether they still want an offer that looks
      damaged, or too big to carry home, before the reply goes to the poster.
      Only some repliers are asked.
    </p>
    <p v-if="!tables.length" class="none text-muted mb-0">
      No replies counted yet.
    </p>
    <template v-else>
      <p class="all-name fw-bold mb-1">{{ tables[0].label }}</p>
      <b-table
        :items="tables[0].items"
        :fields="fields"
        small
        class="all mb-1"
      />
      <button
        type="button"
        class="rows-toggle btn btn-link btn-sm p-0"
        :aria-expanded="everyRow ? 'true' : 'false'"
        @click="everyRow = !everyRow"
      >
        {{ everyRow ? 'Show fewer rows' : 'Show every row' }}
      </button>
      <template v-if="tables.length > 1">
        <p class="by-reason fw-bold mt-2 mb-1">By reason</p>
        <div v-for="t in tables.slice(1)" :key="t.key" class="reason">
          <button
            type="button"
            class="reason-name btn btn-link btn-sm p-0"
            :aria-expanded="open === t.key ? 'true' : 'false'"
            @click="open = open === t.key ? null : t.key"
          >
            {{ t.label }}
          </button>
          <b-table
            v-if="open === t.key"
            :items="t.items"
            :fields="fields"
            small
            class="reason-table mb-1"
          />
        </div>
      </template>
    </template>
  </section>
</template>

<script setup>
// What is particular to the experiment confirm-reply, in the details of the Experiments section: how repliers
// answered the question "Do you still want it?", all together and for each reason it was asked. The Channel block
// above it says what each version does, how long members are reached and how many were counted, so none of that
// is repeated. The figures are the experiment's own last read (result.detail.reasons, written by
// ConfirmReplySource), so this needs no store and no call, and it draws nothing for any other experiment.
//
// It is short by default, and drills down: one sentence, the rows that tell the story for all reasons, and each reason closed
// until it is opened, one at a time. What each version does is in the block above, which also names the versions, the change
// first, as this does.
import { computed, ref } from 'vue'
import { withCount } from '~/modtools/composables/useUxFormat'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

// Whether the rows that are not part of the story are shown, and which reason is open.
const everyRow = ref(false)
const open = ref(null)

const mine = computed(() => props.experiment?.name === 'confirm-reply')

// The reasons in the order staff read them. A reason the read does not have is left out.
const REASONS = [
  ['all', 'All reasons'],
  ['damaged', 'Looks damaged'],
  ['car', 'Needs a car'],
  ['both', 'Both'],
]

const ARM_LABEL = {
  control: 'Without the change',
  ask: 'With the change',
}

const num = (n) => (n || 0).toLocaleString()
const share = (n, d) => (d ? withCount(n, d) : '–')

// Each row is a measure; each cell is one version's figure for it, with the count behind it. The first group is
// the story: how many were counted, how many were asked, how they answered and how many replies reached the poster.
// The rest is the questions the offer outlived, who is still waiting, and the two figures the block above also gives.
const MEASURES = [
  { name: 'Repliers', cell: (c) => num(c.replies), story: true },
  { name: 'Asked', cell: (c) => share(c.asked, c.replies), story: true },
  { name: 'Said yes', cell: (c) => share(c.yes_button, c.asked), story: true },
  { name: 'Said no', cell: (c) => share(c.no, c.asked), story: true },
  {
    name: 'Did not answer',
    cell: (c) => share(c.never_answered, c.asked),
    story: true,
  },
  { name: 'The offer went first', cell: (c) => share(c.post_gone, c.asked) },
  { name: 'Still waiting', cell: (c) => num(c.waiting) },
  {
    name: 'Reached the poster',
    cell: (c) => share(c.reached, c.replies),
    story: true,
  },
  { name: 'Counted', cell: (c) => num(c.counted) },
  { name: 'Promised or taken', cell: (c) => share(c.handed_over, c.counted) },
]

const reasons = computed(() => {
  const r = props.experiment?.result?.detail?.reasons
  return r && typeof r === 'object' && !Array.isArray(r) ? r : null
})

// The change first, then the others by name, and the version without the change last, as the block above puts them.
const arms = computed(() => {
  const names = Object.keys(reasons.value?.all || {})
  return names.sort((a, b) =>
    a === 'control' ? 1 : b === 'control' ? -1 : a.localeCompare(b)
  )
})

const fields = computed(() => [
  { key: 'measure', label: '' },
  ...arms.value.map((a) => ({
    key: a,
    label: ARM_LABEL[a] || a.charAt(0).toUpperCase() + a.slice(1),
  })),
])

const tables = computed(() => {
  if (!reasons.value || !arms.value.length) return []
  const measures = MEASURES.filter((m) => everyRow.value || m.story)
  return REASONS.filter(([key]) => reasons.value[key]).map(([key, label]) => ({
    key,
    label,
    items: measures.map((m) => {
      const row = { measure: m.name }
      for (const a of arms.value) row[a] = m.cell(reasons.value[key]?.[a] || {})
      return row
    }),
  }))
})
</script>

<style scoped lang="scss">
// A sub-heading in the style of the headings of the details, as the Channel block draws its own.
.ux-exp-h {
  margin-bottom: 0.15rem;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: none;
  color: #495057;
}

// Three narrow columns on a phone: the long measure name wraps instead of widening the table, and the cells are close
// together so that the piece stays short.
.ux-reply-confirm :deep(td),
.ux-reply-confirm :deep(th) {
  overflow-wrap: anywhere;
  font-size: 0.75rem;
  line-height: 1.25;
  padding: 0.2rem 0.3rem;
}

.all-name,
.by-reason {
  font-size: 0.8rem;
}

// A reason is a row of its own, closed until it is opened.
.reason {
  border-top: 1px solid #dee2e6;
  padding: 0.25rem 0;
}

.reason-name {
  font-size: 0.8rem;
}
</style>
