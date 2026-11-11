<template>
  <div class="ux-problem-groups">
    <p v-if="store.groups.missing" class="not-switched-on text-muted">
      {{ NOT_SWITCHED_ON }}
    </p>
    <NoticeMessage v-else-if="store.groups.error" variant="danger" class="mb-3">
      {{ store.groups.error }}
    </NoticeMessage>
    <div v-else-if="!data" class="loading text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>

    <div v-else :class="{ stale: store.groups.loading }">
      <p v-if="!groups.length" class="none text-muted">
        {{ filtered ? 'No problems match.' : emptyText }}
      </p>
      <p v-else-if="groupMissing" class="not-in-list small text-muted mb-2">
        That group is not in the list.
      </p>

      <ul v-if="groups.length" class="groups list-unstyled mb-2">
        <li v-for="(g, n) in groups" :key="g.name" class="group mb-2">
          <button
            type="button"
            class="group-row"
            :class="{ open: g.name === openName }"
            :aria-expanded="g.name === openName ? 'true' : 'false'"
            :aria-controls="`${idPrefix}-group-${n}`"
            @click="toggleGroup(g)"
          >
            <span class="caret" aria-hidden="true" />
            <span class="name">{{ g.name }}</span>
            <span class="biggest">{{ biggestText(g) }}</span>
            <span class="counts text-muted">{{ countsText(g) }}</span>
          </button>

          <div
            v-if="g.name === openName"
            :id="`${idPrefix}-group-${n}`"
            class="group-body"
          >
            <p v-if="problems.error" class="list-error text-danger small mb-1">
              {{ problems.error }}
            </p>
            <p
              v-else-if="problems.missing"
              class="not-switched-on small text-muted mb-1"
            >
              {{ NOT_SWITCHED_ON }}
            </p>
            <div v-else-if="!list" class="loading small text-muted py-2">
              <b-spinner small class="me-2" />
              Loading...
            </div>
            <p
              v-else-if="!list.problems.length"
              class="none small text-muted mb-1"
            >
              No problems match.
            </p>
            <template v-else>
              <p
                v-if="problemMissing"
                class="not-in-list small text-muted mb-1"
              >
                That problem is not in the list.
              </p>
              <ul class="problems list-unstyled mb-1">
                <li v-for="p in list.problems" :key="p.id" class="problem mb-1">
                  <button
                    type="button"
                    class="problem-row"
                    :class="{ open: p.id === openProblemId }"
                    :aria-expanded="p.id === openProblemId ? 'true' : 'false'"
                    :aria-controls="`${idPrefix}-problem-${p.id}`"
                    @click="toggleProblem(p)"
                  >
                    <span class="caret" aria-hidden="true" />
                    <span class="title">{{ p.title }}</span>
                    <span class="of text-muted">{{
                      mentionCount(p.mentions, p.answers)
                    }}</span>
                  </button>
                  <div
                    v-if="p.id === openProblemId"
                    :id="`${idPrefix}-problem-${p.id}`"
                  >
                    <ModSysAdminUxProblem
                      :problem="p"
                      @navigate="(patch) => emit('navigate', patch)"
                    />
                  </div>
                </li>
              </ul>
              <!-- One problem is its own line: "Showing all 1." would add nothing to it. -->
              <p v-if="total > 1" class="showing small text-muted mb-1">
                {{ showingText }}
              </p>
              <button
                v-if="canShowMore"
                type="button"
                class="more btn btn-sm btn-outline-secondary"
                :disabled="problems.loading"
                @click="showMore"
              >
                Show more
              </button>
            </template>
          </div>
        </li>
      </ul>

      <p v-if="showLeftOver" class="left-over small text-muted mb-1">
        {{ countOf(data.left_over, 'answer') }} did not match any problem.
      </p>
      <p v-if="otherHeavy" class="other-heavy small text-muted mb-1">
        Many problems are in Other. The group list may need another group.
      </p>
    </div>
  </div>
</template>

<script setup>
// The problems members raised, under the headings the coder files them in. Every group is closed and
// shows how many problems and mentions it holds and its biggest problem. One group opens at a time
// (ux_group) and lists its problems; one problem opens at a time (ux_problem) and shows what
// members wrote. The server sends the groups biggest first with Other last, and this does not sort.
import { computed, ref, useId, watch } from 'vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'
import {
  countOf,
  mentionCount,
  ofWrittenAnswers,
  standingText,
} from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxProblem from '~/modtools/components/ModSysAdminUxProblem.vue'

const props = defineProps({
  // The open group, by name, as the address holds it, or an empty string.
  group: { type: String, default: '' },
  // The open problem's id, as the address holds it, or an empty string.
  problem: { type: String, default: '' },
  // The funnel the list is narrowed to: a funnel key, none for problems tied to no funnel, or an
  // empty string for every funnel.
  flow: { type: String, default: '' },
  // The search text, or an empty string.
  q: { type: String, default: '' },
})
const emit = defineEmits(['navigate'])

const store = useUxSurveysStore()
const idPrefix = `ux-pg-${useId()}`

// The first list is 30 problems, and each Show more asks for 30 more, up to the most the server sends.
const FIRST = 30
const STEP = 30
const MOST = 100

const EMPTY_TEXT =
  'No problems yet. They appear after members answer a question in their own words and the answers have been analysed.'

// An older server does not send open_questions, and then this is the plain text.
const emptyText = computed(() =>
  standingText(data.value?.open_questions, EMPTY_TEXT)
)

const data = computed(() => store.groups.data)
const groups = computed(() => data.value?.groups || [])
const problems = computed(() => store.problems)
const filtered = computed(() => !!props.flow || !!props.q)

// The address keeps a value to letters, digits, spaces and . : - , 80 characters at most, and drops
// the rest (useUxView). A group is matched on its name read the same way, so a name with an
// apostrophe or an ampersand still opens from a link.
const addressForm = (name) => name.replace(/[^\w .:-]/g, '').slice(0, 80)

// The group in the address, if the list has it. This is the name the server sent, which is what the
// problems are asked for.
const openName = computed(
  () =>
    (props.group &&
      groups.value.find((g) => addressForm(g.name) === props.group)?.name) ||
    ''
)
const groupMissing = computed(() => !!props.group && !openName.value)

// More than a quarter of all mentions in Other says the group list is missing a heading. That is about
// the whole list: a funnel or a search leaves only some of the groups, so the share of what is left says
// nothing about it, and nothing is said.
const otherHeavy = computed(() => {
  if (filtered.value) return false
  const all = groups.value.reduce((t, g) => t + g.mentions, 0)
  const other = groups.value.find((g) => g.name === 'Other')
  return !!other && all > 0 && other.mentions * 4 > all
})

// The answers that matched no problem are counted for the funnel and not for the search text, so under a
// search the figure would sit beside a list it does not describe.
const showLeftOver = computed(() => data.value?.left_over > 0 && !props.q)

// ---- the open group's problems ----

const limit = ref(FIRST)
// A link to a problem that the list does not reach is followed up once (see below).
const tried = ref(false)

// The list in the store is drawn only when it is the list for what is open now: the same group, funnel
// and search. A list for another group is never drawn under this heading.
const list = computed(() => {
  const held = store.problemsFor
  if (
    !store.problems.data ||
    !held ||
    held.group !== openName.value ||
    held.flow !== props.flow ||
    held.q !== props.q
  ) {
    return null
  }
  return store.problems.data
})

const shown = computed(() => list.value?.problems.length || 0)
const total = computed(() => list.value?.total || 0)
const canShowMore = computed(
  () => shown.value < total.value && shown.value < MOST
)

const showingText = computed(() => {
  if (shown.value >= total.value) return `Showing all ${total.value}.`
  if (shown.value >= MOST) {
    return `Showing ${shown.value} of ${total.value}. Narrow the list with the funnel or the search box.`
  }
  return `Showing ${shown.value} of ${total.value}.`
})

function load(n) {
  store.loadProblems({
    group: openName.value,
    flow: props.flow,
    q: props.q,
    limit: n,
  })
}

function showMore() {
  limit.value = Math.min(limit.value + STEP, MOST)
  load(limit.value)
}

// A group opening, or the funnel or the search changing under an open group, starts the list again.
watch(
  () => [openName.value, props.flow, props.q],
  ([name]) => {
    limit.value = FIRST
    tried.value = false
    if (name) load(FIRST)
  },
  { immediate: true }
)

// ---- the open problem ----

const openProblemId = computed(() =>
  /^\d+$/.test(props.problem) ? Number(props.problem) : null
)

const inList = computed(
  () =>
    openProblemId.value !== null &&
    !!list.value &&
    list.value.problems.some((p) => p.id === openProblemId.value)
)

// Said only when nothing more can be asked for: until then the problem may yet arrive.
const problemMissing = computed(
  () =>
    openProblemId.value !== null &&
    !!list.value &&
    !inList.value &&
    !canShowMore.value
)

// A link can name a problem that the first 30 of its group do not reach. The list is then asked for
// again, once, at the most the server sends, so that a link opens its problem whenever it can.
watch([list, openProblemId], () => {
  if (
    tried.value ||
    openProblemId.value === null ||
    !list.value ||
    inList.value ||
    !canShowMore.value
  ) {
    return
  }
  tried.value = true
  limit.value = MOST
  load(MOST)
})

// ---- the words ----

const countsText = (g) =>
  `${countOf(g.problems, 'problem')}, ${countOf(g.mentions, 'mention')}`

// "Biggest: Photos fail to upload (14 of 45 written answers)".
const biggestText = (g) =>
  `Biggest: ${g.biggest?.title} (${ofWrittenAnswers(g.biggest?.mentions, g.biggest?.answers)})`

// ---- opening and closing ----

function toggleGroup(g) {
  emit('navigate', {
    group: g.name === openName.value ? undefined : g.name,
    problem: undefined,
  })
}

function toggleProblem(p) {
  emit('navigate', {
    problem: p.id === openProblemId.value ? undefined : String(p.id),
  })
}
</script>

<style scoped lang="scss">
// The list is narrower than the window by the room the ModTools menu takes, and the menu is open when
// the page loads, at every width. So how a row is laid out depends on the width of the list, and not on
// the width of the window.
.ux-problem-groups {
  container-type: inline-size;
  container-name: ux-groups;
}

// One button for the whole line. assets/css/buttons.scss keeps every button on one line with
// !important, which would run a long line off the right edge of a phone.
.group-row,
.problem-row {
  display: grid;
  align-items: baseline;
  gap: 0.15rem 0.75rem;
  width: 100%;
  border: 1px solid #dee2e6;
  border-radius: 0.25rem;
  background: #fff;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  white-space: normal !important;

  &:hover {
    background: #f8f9fa;
  }

  &.open {
    border-color: #61ae24;
    background: #f6fbf2;
  }
}

// The counts and then the biggest problem go under the name, each on a line of its own. Beside the name
// and the counts the biggest problem keeps about 250 pixels in a list 46rem wide, and less as it narrows.
@mixin stacked-row {
  grid-template-columns: 1.25rem minmax(0, 1fr);
  grid-template-areas:
    'caret name'
    '. counts'
    '. biggest';

  .caret {
    grid-area: caret;
  }
  .name {
    grid-area: name;
  }
  .counts {
    grid-area: counts;
  }
  .biggest {
    grid-area: biggest;
  }
}

.group-row {
  grid-template-columns: 1.25rem 15rem minmax(0, 1fr) auto;
  padding: 0.5rem;

  // A browser that does not know container queries goes by the width of the window instead.
  @media (max-width: 767.98px) {
    @include stacked-row;
  }
}

@container ux-groups (max-width: 46rem) {
  .group-row {
    @include stacked-row;
  }
}

.problem-row {
  grid-template-columns: 1.25rem minmax(0, 1fr) auto;
  padding: 0.35rem 0.5rem;
  font-size: 0.925rem;
}

.caret::before {
  content: '\25B8';
  display: inline-block;
  transition: transform 0.15s;
}

.open > .caret::before {
  transform: rotate(90deg);
}

.name {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.biggest,
.title {
  overflow-wrap: anywhere;
}

.counts,
.of {
  font-size: 0.875rem;
  white-space: nowrap;
}

.group-body {
  padding: 0.5rem 0 0.25rem 1.25rem;
}

// A list that is being asked for again, because the funnel or the search changed, is dimmed so that
// it is not read as the answer to the new choice.
.stale {
  opacity: 0.6;
}
</style>
