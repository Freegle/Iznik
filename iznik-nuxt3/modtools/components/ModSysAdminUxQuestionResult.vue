<template>
  <div class="question-result">
    <p v-if="isDecoy" class="decoy-line small mb-1">
      Decoy: Freegle does not do this.
    </p>
    <p v-if="baseText" class="base small text-muted mb-1" data-testid="base">
      {{ baseText }}
    </p>

    <b-table
      v-if="kind === 'problems'"
      :items="rankingRows"
      :fields="rankingFields"
      small
      responsive
    />
    <div v-else-if="kind === 'areas'" class="balance" data-testid="balance">
      <p
        v-if="balanceLine"
        class="balance-summary small mb-1"
        data-testid="balance-summary"
      >
        {{ balanceLine }}
      </p>
      <p class="balance-key small text-muted mb-1">
        Balance is the members who picked an area as working best, less those
        who picked it as most needing improvement. The lowest balance is first.
      </p>
      <div class="balance-axis" aria-hidden="true">
        <span>&larr; Needs improving</span>
        <span>Works best &rarr;</span>
      </div>
      <ol class="balance-list list-unstyled mb-1" aria-label="Areas by balance">
        <li
          v-for="r in balanceRows"
          :key="r.key"
          class="balance-row"
          :data-testid="`balance-${r.key}`"
        >
          <div class="balance-head">
            <span class="balance-area">{{ r.label }}</span>
            <span class="balance-net" :class="`tone-${r.tone}`">
              <span class="balance-figure" data-testid="balance-net">{{
                r.netText
              }}</span>
              {{ r.word }}
            </span>
          </div>
          <div class="balance-bar" aria-hidden="true">
            <span class="half left"
              ><span
                class="fill improve"
                :style="{ width: r.improveWidth + '%' }"
            /></span>
            <span class="half right"
              ><span class="fill best" :style="{ width: r.bestWidth + '%' }"
            /></span>
          </div>
          <div class="balance-counts small text-muted">{{ r.countsText }}</div>
        </li>
      </ol>
    </div>
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
  areaBalances,
  balanceSummary,
  countOf,
  kindOf,
  ofAnswered,
  signed,
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

// A count out of its base: an option is judged against the times it was on screen, where the server
// says, because each member sees only a few of the options. Below 100 answers it has no percentage.
const countIn = (n, base) => (base ? ofAnswered(n, base, total.value) : '0')

// The follow-up has one pick, the biggest problem, so it is a table. The area survey has two picks and
// is drawn as a balance (below).
const rankingFields = computed(() => {
  const fields = [{ key: 'label', label: 'Problem' }]
  if (hasShown.value) fields.push({ key: 'shown', label: 'Times shown' })
  fields.push({ key: 'most', label: 'Picked as the biggest problem' })
  return fields
})

// The problem that was picked most comes first.
const rankingRows = computed(() =>
  (props.question.tallies || [])
    .slice()
    .sort((a, b) => (Number(b.most) || 0) - (Number(a.most) || 0))
    .map((t) => {
      const shown = Number(t.times_shown) > 0 ? Number(t.times_shown) : 0
      return {
        label: t.label,
        shown: shown || '',
        most: countIn(t.most, shown || total.value),
      }
    })
)

// ---- the balance of the area survey ----

// One row for each area, the one with the lowest balance first. The text of each row says everything
// the bar does, so the bar is only a picture of it and is not read out.
const balances = computed(() =>
  areaBalances(props.question.tallies, total.value)
)

const balanceRows = computed(() =>
  balances.value.map((r) => ({
    ...r,
    netText: signed(r.net),
    countsText: `${r.shown ? `Shown ${r.shown.toLocaleString()} times. ` : ''}Works best ${countIn(r.best, r.base)}. Most needs improving ${countIn(r.improve, r.base)}.`,
  }))
)

const balanceLine = computed(() => balanceSummary(balances.value))

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

<style scoped lang="scss">
/* The balance list of the area survey. Each row says its balance in words and figures, and its counts
   in text, so the bar is a picture of what is already written and does not rest on colour: needs
   improving is to the left of the line and hatched, works best is to the right and solid. The colours
   of the words and bars are dark enough for 4.5 to 1 on white (text) and 3 to 1 (bars). */
.balance-list {
  max-width: 36rem;
}

.balance-axis {
  display: flex;
  justify-content: space-between;
  max-width: 36rem;
  margin-bottom: 0.25rem;
  font-size: 0.75rem;
  color: #495057;
}

.balance-row {
  padding: 0.375rem 0;
  border-top: 1px solid #e9ecef;

  &:first-child {
    border-top: 0;
  }
}

.balance-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: baseline;
  gap: 0 0.75rem;
}

.balance-area {
  font-weight: 600;
  overflow-wrap: anywhere;
}

.balance-net {
  font-weight: 600;
  white-space: nowrap;
}

.balance-figure {
  font-variant-numeric: tabular-nums;
}

.tone-good {
  color: #1e5c0e;
}

.tone-bad {
  color: #9a3b00;
}

.tone-even,
.tone-none {
  color: #495057;
}

.balance-bar {
  display: grid;
  grid-template-columns: 1fr 1fr;
  height: 0.75rem;
  margin: 0.25rem 0;
  background: #f1f3f5;
}

.half {
  display: flex;
  min-width: 0;
}

.half.left {
  justify-content: flex-end;
  border-right: 2px solid #343a40;
}

.fill {
  display: block;
  height: 100%;
}

.fill.best {
  background: #2b7a14;
}

.fill.improve {
  background: repeating-linear-gradient(
    135deg,
    #b84a00 0,
    #b84a00 4px,
    #f2a15f 4px,
    #f2a15f 8px
  );
  box-shadow: inset 0 0 0 1px #8f3a00;
}

.balance-counts {
  overflow-wrap: anywhere;
}
</style>
