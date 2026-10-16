<template>
  <div class="ux-ideas">
    <p v-if="store.ideas.missing" class="not-switched-on text-muted">
      {{ NOT_SWITCHED_ON }}
    </p>
    <NoticeMessage v-else-if="store.ideas.error" variant="danger" class="mb-3">
      {{ store.ideas.error }}
    </NoticeMessage>
    <div v-else-if="!data" class="loading text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>

    <p v-else-if="total === 0" class="none text-muted">{{ NO_IDEAS }}</p>

    <template v-else>
      <div
        class="chips d-flex flex-wrap gap-1 mb-2"
        role="group"
        aria-label="Show"
      >
        <button
          v-for="c in chips"
          :key="c.key"
          type="button"
          class="chip"
          :class="[`chip-${c.key}`, { active: c.key === status }]"
          :aria-pressed="c.key === status ? 'true' : 'false'"
          @click="chooseStatus(c.key)"
        >
          {{ c.label }} ({{ c.count }})
        </button>
      </div>

      <p class="score-note small text-muted mb-2">{{ SCORE_NOTE }}</p>

      <div v-if="!fresh" class="loading text-center py-3">
        <b-spinner small class="me-2" />
        Loading...
      </div>

      <!-- Waiting ideas are summarised for each funnel; a funnel is opened to list them. -->
      <ul v-else-if="byFunnel" class="funnels list-unstyled mb-2">
        <li v-for="f in funnelRows" :key="f.flow" class="funnel-row">
          <a
            class="funnel-link"
            :href="hrefFor({ flow: f.flow, idea: undefined })"
            @click="(e) => chooseFunnel(f.flow, e)"
            >{{ f.line }}</a
          >
        </li>
      </ul>

      <template v-else>
        <div class="filters d-flex flex-wrap align-items-center gap-2 mb-2">
          <span class="filter-pair">
            <label :for="`${id}-flow`" class="filter-label">Funnel:</label>
            <select
              :id="`${id}-flow`"
              :value="flowFilter"
              class="flow-select form-select form-select-sm"
              @change="(e) => chooseFunnelFrom(e.target.value)"
            >
              <option value="">All funnels</option>
              <option v-for="f in FLOW_ORDER" :key="f" :value="f">
                {{ FLOW_TITLES[f] }}
              </option>
            </select>
          </span>
          <span class="filter-pair">
            <label :for="`${id}-type`" class="filter-label">Type:</label>
            <select
              :id="`${id}-type`"
              :value="type"
              class="type-select form-select form-select-sm"
              @change="(e) => chooseType(e.target.value)"
            >
              <option value="">All</option>
              <option value="fix">Fix</option>
              <option value="experiment">Experiment</option>
            </select>
          </span>
        </div>

        <p v-if="flowFilter" class="funnel-notice small mb-2">
          Showing {{ FLOW_SHORT[flowFilter] }} only.
          <a
            class="show-all"
            :href="hrefFor({ flow: undefined, idea: undefined })"
            @click="showAll"
            >Show all</a
          >
        </p>

        <p v-if="openMissing" class="not-in-list small text-muted mb-2">
          That idea is not in this list.
        </p>

        <p v-if="cut" class="cut-notice small text-muted mb-2">
          {{ cutText }}
        </p>

        <p v-if="ideas.length === 0" class="none text-muted">
          {{ NO_IDEAS_HERE }}
        </p>
        <ul v-else class="list-unstyled mb-2">
          <li v-for="idea in ideas" :key="idea.id" class="mb-2">
            <ModSysAdminUxIdeaRow
              :idea="idea"
              :open="idea.id === openId"
              :controls="`ux-idea-${idea.id}`"
              :show-funnel="!flowFilter"
              @toggle="toggle(idea)"
              @navigate="(patch) => emit('navigate', patch)"
            />
          </li>
        </ul>
      </template>
    </template>
  </div>
</template>

<script setup>
// The Ideas section of the SysAdmin UX tab: what the idea generator has proposed, ranked. A strip of
// counts by status filters the list. With waiting ideas chosen and no funnel, there is one line for
// each funnel; a funnel is opened to list its ideas, and any other status lists its ideas straight
// away, and has a select for the funnel as well as the type. A list the route has cut says how many
// there are. One idea is open at a time. The section only reads: an idea is queued, set aside or put
// back with `php artisan ux:idea`. It needs only the ideas route, and reads nothing of the experiments,
// surveys or members' reactions.
import { computed, inject, onMounted, useId, watch } from 'vue'
import { useUxIdeasStore } from '~/modtools/stores/uxIdeas'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'
import {
  FLOW_ORDER,
  FLOW_SHORT,
  FLOW_TITLES,
} from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'
import {
  NO_IDEAS,
  NO_IDEAS_HERE,
  SCORE_NOTE,
  STATUS_ORDER,
  funnelLine,
  statusWord,
} from '~/modtools/composables/useUxIdeaLabels'
import ModSysAdminUxIdeaRow from '~/modtools/components/ModSysAdminUxIdeaRow.vue'

const props = defineProps({
  // The view from useUxView: ux_flow is view.flow, and the section's own keys are view.extra.idea
  // (an idea's id), view.extra.istatus (open, scheduled, running, done or rejected) and
  // view.extra.itype (fix or experiment).
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

const store = useUxIdeasStore()
const id = useId()

// ux_flow may hold something that is no funnel (a value another section uses), which is then no filter.
const flowFilter = computed(() =>
  FLOW_ORDER.includes(props.view.flow) ? props.view.flow : ''
)
const status = computed(() =>
  STATUS_ORDER.includes(props.view.extra?.istatus)
    ? props.view.extra.istatus
    : 'open'
)
const type = computed(() =>
  ['fix', 'experiment'].includes(props.view.extra?.itype)
    ? props.view.extra.itype
    : ''
)
const openId = computed(() => {
  const idea = props.view.extra?.idea
  return /^\d+$/.test(idea || '') ? Number(idea) : null
})

// Waiting ideas with no funnel chosen are shown as a line for each funnel. That needs only the
// totals the route sends with every answer, so one idea is asked for. Anything else is a list, cut
// down by the funnel, the status and the type.
const byFunnel = computed(() => !flowFilter.value && status.value === 'open')
const params = computed(() => {
  if (byFunnel.value) return { status: 'open', limit: 1 }
  const p = { status: status.value }
  if (flowFilter.value) p.flow = flowFilter.value
  if (type.value) p.type = type.value
  return p
})
const key = computed(() => JSON.stringify(params.value))

onMounted(() => {
  store.load(params.value)
})
watch(key, () => {
  store.load(params.value)
})

const data = computed(() => store.ideas.data)

// While a changed choice loads, the slot still holds the answer to the one before. It is not drawn
// as the answer to this one.
const fresh = computed(() => store.asked === key.value)

const counts = computed(() => data.value?.counts || {})
const total = computed(() =>
  STATUS_ORDER.reduce((sum, s) => sum + (Number(counts.value[s]) || 0), 0)
)
const chips = computed(() =>
  STATUS_ORDER.map((s) => ({
    key: s,
    label: statusWord(s),
    count: Number(counts.value[s]) || 0,
  }))
)

// One line for each funnel, in the order members meet them.
const funnelRows = computed(() =>
  FLOW_ORDER.map((flow) => ({
    flow,
    line: funnelLine(
      FLOW_TITLES[flow],
      (data.value?.flows || []).find((f) => f.flow === flow)
    ),
  }))
)

const ideas = computed(() => data.value?.ideas || [])
// How many ideas match the choice, before the route cut the list to the most it sends. When that is more
// than the list holds, the list is the first of them and the section says so.
const matching = computed(() => Number(data.value?.matching) || 0)
const cut = computed(() => matching.value > ideas.value.length)
// A funnel and a type are the two ways to see fewer, so they are offered only while one is left to choose.
const cutText = computed(
  () =>
    `Showing the first ${ideas.value.length} of ${matching.value}.` +
    (flowFilter.value && type.value
      ? ''
      : ' Choose a funnel or a type to see fewer.')
)
const openMissing = computed(
  () => openId.value !== null && !ideas.value.some((i) => i.id === openId.value)
)

function toggle(idea) {
  emit('navigate', {
    idea: openId.value === idea.id ? undefined : String(idea.id),
  })
}

// A change of choice closes the open idea, which the choice may be hiding.
function chooseStatus(value) {
  emit('navigate', {
    istatus: value === 'open' ? undefined : value,
    idea: undefined,
  })
}
function chooseType(value) {
  emit('navigate', { itype: value || undefined, idea: undefined })
}
function chooseFunnel(flow, e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { flow, idea: undefined })
}
// The same choice from the select, where "All funnels" takes the funnel out of the address.
function chooseFunnelFrom(value) {
  emit('navigate', { flow: value || undefined, idea: undefined })
}
function showAll(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { flow: undefined, idea: undefined })
}
</script>

<style scoped lang="scss">
.filter-pair {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  white-space: nowrap;
}

.filter-label {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
}

.type-select,
.flow-select {
  width: auto;
  max-width: 100%;
  padding-right: 2rem;
}

// A chip that is chosen is dark and plain, so it cannot be taken for one that is switched off.
.chip {
  padding: 0.15rem 0.7rem;
  border: 1px solid #6c757d;
  border-radius: 1rem;
  background: #fff;
  color: #343a40;
  font-size: 0.85rem;
  // assets/css/buttons.scss keeps every button on one line.
  white-space: normal !important;

  &:hover:not(.active) {
    background: #e9ecef;
  }

  &.active {
    border-color: #343a40;
    background: #343a40;
    color: #fff;
  }
}

.funnel-link {
  display: block;
  padding: 0.5rem 0.5rem;
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
  color: inherit;
  text-decoration: none;
  overflow-wrap: anywhere;

  &:hover {
    background: #f8f9fa;
  }
}

.funnel-row + .funnel-row {
  margin-top: 0.5rem;
}

.funnel-notice {
  padding: 0.35rem 0.5rem;
  border-left: 3px solid #0d6efd;
  background: #eef5ff;
}
</style>
