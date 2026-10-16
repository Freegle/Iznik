<template>
  <div class="question-result">
    <p v-if="isDecoy" class="decoy-line small mb-1">
      Decoy: Freegle does not do this.
    </p>
    <p v-if="baseText" class="base small text-muted mb-1" data-testid="base">
      {{ baseText }}
    </p>

    <b-table
      v-if="kind === 'problems' || kind === 'areas'"
      :items="rankingRows"
      :fields="rankingFields"
      small
      responsive
    />
    <p v-else-if="kind === 'open'" class="open-counts small mb-1">
      {{ openText }}
    </p>
    <template v-else>
      <b-table
        :items="awarenessRows"
        :fields="awarenessFields"
        small
        responsive
      />
      <p v-if="isYesNo" class="right-answer small mb-1">{{ rightText }}</p>
      <p
        v-if="isYesNo && !question.decoy && decoy.questions"
        class="decoy-check small mb-1"
        data-testid="decoy-check"
      >
        {{ decoyText }}
      </p>
    </template>

    <p v-if="kind !== 'open' && waysOut" class="ways-out small text-muted mb-1">
      {{ waysOut }}
    </p>
    <p v-if="question.skipped" class="skipped small text-muted mb-1">
      {{ question.skipped }} skipped it.
    </p>
    <ModSysAdminUxSaid
      v-if="comments.length"
      :comments="comments"
      :limit="8"
      :capped="comments.length >= COMMENT_CAP"
      :label="`What members wrote (${comments.length})`"
    />
  </div>
</template>

<script setup>
// One question opened: how it did, in counts. Every figure is "of the N who answered", and a
// percentage is added only from 100 answers. What a member typed on a closed question sits behind a
// toggle, escaped. An open question is never counted here: it is read through the analysis, and the
// problems and what works are in the other views.
import { computed } from 'vue'
import {
  PERCENT_MIN_ANSWERS,
  countOf,
  kindOf,
  ofAnswered,
} from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'

const props = defineProps({
  // One question of the questions route.
  question: { type: Object, required: true },
  // How the decoy questions did, for the yes-saying check: { questions, answers, yes }.
  decoy: {
    type: Object,
    default: () => ({ questions: 0, answers: 0, yes: 0 }),
  },
})

// The server sends at most this many comments for a question.
const COMMENT_CAP = 20

const kind = computed(() => kindOf(props.question))
const total = computed(() => props.question.responses || 0)
const comments = computed(() => props.question.comments || [])
const isDecoy = computed(
  () => kind.value === 'awareness' && !!props.question.decoy
)

// Every figure is "of the N who answered". Below 100 answers it is a count with its base and no
// percentage; from 100 the percentage is added beside the count.
const baseText = computed(() => {
  if (kind.value === 'open') return ''
  const n = total.value
  if (!n) return 'Nobody has answered yet.'
  return n < PERCENT_MIN_ANSWERS
    ? `Of the ${n} who answered. Below ${PERCENT_MIN_ANSWERS} answers these are counts, not percentages, because a few more answers can change them a lot.`
    : `Of the ${n.toLocaleString()} who answered.`
})

// ---- the two rankings ----

const hasShown = computed(() =>
  (props.question.tallies || []).some((t) => Number(t.times_shown) > 0)
)

// The follow-up has one pick, the biggest problem. The area survey has two: which part works best,
// and which other part most needs improving.
const rankingFields = computed(() => {
  const fields = [
    { key: 'label', label: kind.value === 'problems' ? 'Problem' : 'Area' },
  ]
  if (hasShown.value) fields.push({ key: 'shown', label: 'Times shown' })
  if (kind.value === 'problems') {
    fields.push({ key: 'most', label: 'Picked as the biggest problem' })
  } else {
    fields.push(
      { key: 'most', label: 'Works best' },
      { key: 'least', label: 'Most needs improving' }
    )
  }
  return fields
})

// The problem that was picked most comes first. In the area survey the area that most needs
// improving does.
const rankingRows = computed(() => {
  const by = kind.value === 'problems' ? 'most' : 'least'
  return (props.question.tallies || [])
    .slice()
    .sort((a, b) => (Number(b[by]) || 0) - (Number(a[by]) || 0))
    .map((t) => {
      // An option is judged against the times it was on screen, where the server says, because
      // each member sees only a few of the options.
      const shown = Number(t.times_shown) > 0 ? Number(t.times_shown) : 0
      const base = shown || total.value
      const cell = (n) => (base ? ofAnswered(n, base, total.value) : '0')
      return {
        label: t.label,
        shown: shown || '',
        most: cell(t.most),
        least: cell(t.least),
      }
    })
})

// ---- awareness ----

const awarenessFields = [
  { key: 'label', label: 'Answer' },
  { key: 'count', label: 'Members' },
]

// A question made of yes, no and not sure answers has a count for each. An older one is a list of
// options of its own, each with a count.
const isYesNo = computed(
  () =>
    props.question.yes !== undefined ||
    props.question.no !== undefined ||
    props.question.not_sure !== undefined
)

const awarenessRows = computed(() => {
  const q = props.question
  if (isYesNo.value) {
    return [
      { label: 'Yes', count: ofAnswered(q.yes, total.value) },
      { label: 'No', count: ofAnswered(q.no, total.value) },
      { label: 'Not sure', count: ofAnswered(q.not_sure, total.value) },
    ]
  }
  return (q.tallies || []).map((t) => {
    const n = t.key === 'dont_understand' ? q.dont_understand || 0 : t.count
    return {
      label: t.label,
      count: total.value ? ofAnswered(n, total.value) : '0',
    }
  })
})

// A real feature is rightly answered Yes; a decoy (something Freegle does not do) is rightly
// answered No.
const rightText = computed(() => {
  const q = props.question
  const right = (q.decoy ? q.no : q.yes) || 0
  return `Right answer (${q.decoy ? 'No' : 'Yes'}): ${ofAnswered(right, total.value)}.`
})

// Across the decoys, how many said Yes to something Freegle does not do. It sits beside the real
// questions so a reader can allow for how readily the members who answered say yes.
const decoyText = computed(() => {
  const d = props.decoy
  return `Yes-saying check: across ${countOf(d.questions, 'decoy question')} about things Freegle does not do, ${ofAnswered(d.yes, d.answers)} answers were Yes. So some Yes answers on this question may be guesses.`
})

// ---- the ways out, and the open question ----

// The answers that are neither a pick nor a row of the table: none of these (a survey only), and did
// not understand.
const waysOut = computed(() => {
  const q = props.question
  const parts = []
  const none = q.kind === 'survey' ? (q.none || 0) + (q.dont_care || 0) : 0
  if (none) parts.push(`${none} said none of these`)
  if (q.dont_understand) {
    parts.push(`${q.dont_understand} did not understand the question`)
  }
  return parts.length ? parts.join('; ') + '.' : ''
})

// An open question has no tally: members wrote, had nothing to tell us, or did not understand. The
// text is read through the analysis, never counted here.
const wrote = computed(() =>
  Math.max(
    0,
    total.value -
      (props.question.nothing || 0) -
      (props.question.dont_understand || 0)
  )
)

// The results are drawn from the answers there were when the question was analysed. A question that
// stays open keeps collecting, so say how many the analysis has not read.
const analysisText = computed(() => {
  const q = props.question
  if (!q.analysed) return 'The answers have not been analysed yet.'
  const read = q.analysed_answers || 0
  if (read && read < wrote.value) {
    return `The results were drawn from ${read} of the ${wrote.value} written answers. ${
      wrote.value - read
    } have arrived since and have not been analysed.`
  }
  return 'The answers have been analysed. The results are in Problems and What works.'
})

const openText = computed(
  () =>
    `Of the ${total.value} who answered, ${wrote.value} wrote something, ${
      props.question.nothing || 0
    } had nothing to tell us, ${
      props.question.dont_understand || 0
    } did not understand the question. ${analysisText.value}`
)
</script>
