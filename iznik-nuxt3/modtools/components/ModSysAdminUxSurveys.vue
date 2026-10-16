<template>
  <div class="ux-members">
    <p v-if="summary" class="summary mb-1">{{ summary }}</p>

    <p class="caution small mb-1">
      These are the members who chose to answer. Each count is shown with the
      number it is out of, and there are no percentages below 100 answers.
      <button
        type="button"
        class="how-toggle"
        :aria-expanded="howOpen ? 'true' : 'false'"
        :aria-controls="`${id}-how`"
        @click="howOpen = !howOpen"
      >
        How to read these numbers
      </button>
    </p>
    <p v-if="howOpen" :id="`${id}-how`" class="how small text-muted mb-2">
      {{ HOW_TO_READ }}
    </p>

    <nav class="views mb-2" aria-label="Views">
      <a
        v-for="v in VIEWS"
        :key="v.key"
        :href="hrefFor(viewPatch(v.key))"
        class="view-link"
        :class="{ active: v.key === current }"
        :aria-current="v.key === current ? 'page' : undefined"
        @click="(e) => chooseView(e, v.key)"
      >
        {{ v.label }}
      </a>
    </nav>

    <template v-if="current === 'problems'">
      <div
        v-if="!store.groups.missing"
        class="filters d-flex flex-wrap align-items-center gap-2 mb-2"
      >
        <span class="filter-pair">
          <label :for="`${id}-flow`" class="filter-label">Funnel:</label>
          <select
            :id="`${id}-flow`"
            :value="flowFilter"
            class="flow-select form-select form-select-sm"
            @change="(e) => chooseFunnel(e.target.value)"
          >
            <option value="">All funnels</option>
            <option v-for="f in FLOW_ORDER" :key="f" :value="f">
              {{ FLOW_TITLES[f] }}
            </option>
            <option :value="NO_FUNNEL">Not tied to a funnel</option>
          </select>
        </span>
        <span class="filter-pair">
          <label :for="`${id}-q`" class="filter-label">Search:</label>
          <input
            :id="`${id}-q`"
            v-model="draft"
            type="search"
            class="search-box form-control form-control-sm"
            maxlength="60"
            autocomplete="off"
            @input="typed"
            @keydown.enter="commit"
            @blur="commit"
          />
        </span>
      </div>

      <p v-if="flowFilter" class="funnel-notice small mb-2">
        {{ noticeText }}
        <a :href="hrefFor(allPatch)" @click="showAll">Show all</a>
      </p>

      <ModSysAdminUxProblemGroups
        :group="extra.group || ''"
        :problem="extra.problem || ''"
        :flow="flowFilter"
        :q="extra.q || ''"
        @navigate="(patch) => emit('navigate', patch)"
      />
    </template>

    <ModSysAdminUxValues v-else-if="current === 'values'" />

    <ModSysAdminUxQuestions
      v-else
      :open="extra.question || ''"
      @navigate="(patch) => emit('navigate', patch)"
    />
  </div>
</template>

<script setup>
// The What members say section of the SysAdmin UX tab: the problems members raised, grouped under
// headings and closed until opened; what works well for them; and the questions that were asked, with
// how each did. It reads only the four survey routes, through its own store. The view, the open group,
// the open problem, the search and the open question are in the address, so a copied link restores
// them. The funnel is the tab's shared ux_flow.
import { computed, inject, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import {
  FLOW_ORDER,
  FLOW_SHORT,
  FLOW_TITLES,
} from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'
import { countOf } from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxProblemGroups from '~/modtools/components/ModSysAdminUxProblemGroups.vue'
import ModSysAdminUxValues from '~/modtools/components/ModSysAdminUxValues.vue'
import ModSysAdminUxQuestions from '~/modtools/components/ModSysAdminUxQuestions.vue'

const props = defineProps({
  // The view from useUxView: ux_flow is view.flow, and the section's own keys are in view.extra:
  // view, group, problem, q and question.
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

const store = useUxSurveysStore()
const id = `ux-members-${useId()}`

// The value of ux_flow for problems that are tied to no funnel.
const NO_FUNNEL = 'none'

const VIEWS = [
  { key: 'problems', label: 'Problems' },
  { key: 'values', label: 'What works' },
  { key: 'questions', label: 'Questions' },
]

const HOW_TO_READ =
  'Microvolunteers are asked about problems they have had, whether they know a feature exists, and which parts of Freegle work best. They chose to answer. They tend to be keen, and they prefer what they know. Read this as what the members who answered said, not as what all members think. A mention is one answer that names a problem, and one answer can name more than one. Every count is shown with the number it is out of. For a problem or a value, that is the written answers that were analysed for the question it came from. For a question, it is the members who answered it. A percentage appears only from 100 answers. No error range is shown, because an error range does not suit people who chose to answer. A skipped question is counted separately and is in none of the figures.'

const extra = computed(() => props.view.extra || {})

// An unknown view in the address is the first one.
const current = computed(() =>
  VIEWS.some((v) => v.key === extra.value.view) ? extra.value.view : 'problems'
)

// ux_flow may hold something that is no funnel (a value another section uses), which is then no
// filter.
const flowFilter = computed(() =>
  FLOW_ORDER.includes(props.view.flow) || props.view.flow === NO_FUNNEL
    ? props.view.flow
    : ''
)

const noticeText = computed(() =>
  flowFilter.value === NO_FUNNEL
    ? 'Showing problems not tied to a funnel only.'
    : `Showing ${FLOW_SHORT[flowFilter.value]} only.`
)

// ---- the line at the top ----

// "12 problems in 5 groups, from 120 written answers. 6 questions asked." The questions are left out
// until they have arrived.
const summary = computed(() => {
  const d = store.groups.data
  if (!d) return ''
  const problems = (d.groups || []).reduce((t, g) => t + g.problems, 0)
  const line = `${countOf(problems, 'problem')} in ${countOf(
    (d.groups || []).length,
    'group'
  )}, from ${countOf(d.answers, 'written answer')}.`
  const asked = store.questions.data?.questions
  return asked ? `${line} ${countOf(asked.length, 'question')} asked.` : line
})

const howOpen = ref(false)

// ---- the views ----

// The address of a view. The first view is the default and is left out of the address. A change of
// view closes whatever was open in the one before.
const viewPatch = (key) => ({
  view: key === 'problems' ? undefined : key,
  group: undefined,
  problem: undefined,
  question: undefined,
})

function chooseView(e, key) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', viewPatch(key))
}

// ---- the funnel and the search ----

// A new choice of funnel or search closes the open group and problem, which it may be hiding.
const closeOpen = { group: undefined, problem: undefined }
const allPatch = { flow: undefined, ...closeOpen }

function chooseFunnel(value) {
  emit('navigate', { flow: value || undefined, ...closeOpen })
}

function showAll(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', allPatch)
}

// The search box holds what is typed; the address and the server get it when typing pauses, on Enter,
// and when the box loses focus. The text goes as typed, with its apostrophes, and the address keeps it.
const draft = ref(extra.value.q || '')
// The box follows the address when it changes from outside. It is left alone when the address has only
// caught up with what was typed: the address holds the text trimmed, and the person may have typed a
// space since, to go on to a second word.
watch(
  () => extra.value.q,
  (q) => {
    const text = q || ''
    if (text !== draft.value.trim()) draft.value = text
  }
)

const PAUSE_MS = 400
let timer = null

function commit() {
  if (timer) clearTimeout(timer)
  timer = null
  const text = draft.value.trim()
  if (text === (extra.value.q || '')) return
  emit('navigate', { q: text || undefined, ...closeOpen })
}

function typed() {
  if (timer) clearTimeout(timer)
  timer = setTimeout(commit, PAUSE_MS)
}

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

// ---- what is loaded ----

// The tab draws this section anew each time a person comes back to it, while the store lives on, so
// each visit asks again for what it shows, and replaces what the visit before left in the store.
//
// The funnel and the search narrow the problems and nothing else: What works and Questions draw
// neither. So the groups are asked for with them only while Problems is open, and the line at the top
// then counts what the funnel and the search leave, beside the notice that says so. On the other views
// it counts everything. They stay in the address for the way back to Problems. The two are watched as a
// pair of sources, which compares them one by one: the view object is new each time the address
// changes, and a watch on a function that made an array would ask again for every change, such as
// opening a group.
const groupFlow = computed(() =>
  current.value === 'problems' ? flowFilter.value : ''
)
const groupSearch = computed(() =>
  current.value === 'problems' ? extra.value.q || '' : ''
)
watch(
  [groupFlow, groupSearch],
  ([flow, q]) => {
    store.loadGroups({ flow, q })
  },
  { immediate: true }
)

// The questions are asked for once a visit, for the count at the top and for the Questions view.
store.loadQuestions()

// The values are asked for the first time What works opens in a visit, and not again while the person
// moves between the views.
let valuesAsked = false
watch(
  current,
  (view) => {
    if (view === 'values' && !valuesAsked) {
      valuesAsked = true
      store.loadValues()
    }
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
.how-toggle {
  display: inline;
  padding: 0;
  border: 0;
  background: none;
  color: #0b5ed7;
  font: inherit;
  text-decoration: underline;
  cursor: pointer;
  // assets/css/buttons.scss keeps every button on one line with !important.
  white-space: normal !important;
}

// A row of links with a line under the open one, as the tab's own nav has.
.views {
  display: flex;
  gap: 1.25rem;
  border-bottom: 1px solid #dee2e6;
}

.view-link {
  display: block;
  padding: 0.35rem 0.1rem;
  border-bottom: 3px solid transparent;
  color: #495057;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;

  &:hover {
    color: #212529;
    border-bottom-color: #ced4da;
  }

  &.active {
    color: #212529;
    border-bottom-color: #61ae24;
  }
}

// Each label and its control are one group, so on a phone a label is never left at the end of a line,
// away from its control.
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

.flow-select {
  width: auto;
  max-width: 100%;
  padding-right: 2rem;
}

.search-box {
  width: 12rem;
  max-width: 100%;
}

.funnel-notice {
  padding: 0.35rem 0.5rem;
  border-left: 3px solid #0d6efd;
  background: #eef5ff;
}
</style>
