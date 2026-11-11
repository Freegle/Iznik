<template>
  <div class="funnel-chart">
    <div class="d-flex justify-content-between align-items-baseline flex-wrap">
      <h6 v-if="title" class="mb-1">{{ title }}</h6>
      <b-button
        variant="link"
        size="sm"
        class="p-0 ms-auto view-toggle"
        data-testid="chart-view-toggle"
        :aria-pressed="asTable ? 'true' : 'false'"
        @click="asTable = !asTable"
      >
        {{ asTable ? 'Show as bars' : 'Show as a table' }}
      </b-button>
    </div>

    <ul v-if="!asTable" class="rows list-unstyled mb-1" :aria-label="ariaLabel">
      <li
        v-for="row in rows"
        :key="row.key"
        class="funnel-row"
        :class="{
          indent: row.indent,
          worst: row.worst,
          active: row.selectable && row.step === selected,
          exit: row.exit,
          last: row.last,
        }"
      >
        <component
          :is="row.selectable ? 'button' : 'div'"
          :type="row.selectable ? 'button' : undefined"
          :tabindex="row.selectable ? undefined : 0"
          :aria-describedby="row.help ? helpId(row) : undefined"
          :aria-pressed="
            row.selectable
              ? row.step === selected
                ? 'true'
                : 'false'
              : undefined
          "
          :aria-label="row.aria"
          class="line"
          @click="row.selectable && emit('select', row.step)"
        >
          <span class="label-line">
            <span class="row-label">{{ row.label }}</span>
          </span>
          <span class="track">
            <span
              v-if="row.prev"
              class="bar-prev"
              :style="{ width: row.prev + '%' }"
            />
            <span
              class="bar"
              :class="{ worst: row.worst, exit: row.exit }"
              :style="{ width: row.width + '%' }"
            >
              <span
                v-if="row.lossWidth"
                class="bar-loss"
                :style="{ width: row.lossWidth + '%' }"
              />
            </span>
          </span>
          <span class="row-value">{{ row.text }}</span>
          <span
            v-if="row.help"
            :id="helpId(row)"
            role="tooltip"
            class="row-help"
            >{{ row.help }}</span
          >
          <span v-if="row.worst" class="worst-tag">Biggest loss</span>
        </component>
      </li>
    </ul>

    <div v-else class="table-responsive">
      <table class="table table-sm figures mb-1">
        <caption class="visually-hidden">
          {{
            title || 'Funnel'
          }}
          figures
        </caption>
        <thead>
          <tr>
            <th scope="col" class="tag-cell">
              <span class="visually-hidden">Flag</span>
            </th>
            <th scope="col">Step</th>
            <th scope="col" class="text-end">Reached</th>
            <th scope="col" class="text-end">Visits</th>
            <th scope="col" class="text-end">Finished</th>
            <th scope="col" class="text-end">Previous period</th>
            <th scope="col" class="text-end">Ended there</th>
            <th scope="col" class="text-end">Of visits</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.key"
            :class="{ indent: row.indent }"
          >
            <td class="tag-cell">
              <span v-if="row.worst" class="worst-tag">Biggest loss</span>
            </td>
            <th scope="row" class="fw-normal" :title="row.help || undefined">
              {{ row.label }}
            </th>
            <td class="text-end">{{ row.entered.toLocaleString() }}</td>
            <td class="text-end">
              {{ row.exit ? '' : row.visits.toLocaleString() }}
            </td>
            <td class="text-end">{{ row.exit ? '' : row.completedText }}</td>
            <td class="text-end">{{ row.exit ? '' : row.previousText }}</td>
            <td class="text-end">
              {{ row.exit ? '' : row.abandoned.toLocaleString() }}
            </td>
            <td class="text-end">{{ row.exit ? '' : row.ofVisits }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="showKey" class="small text-muted mb-0 key">
      Grey dashed outline: the same step in the period before, as a share of
      that period's first step.
    </p>
  </div>
</template>

<script setup>
// One funnel as bars: the bar is the share of people who reached the step, the red end is the
// share of its visits that ended there, the dashed outline is the previous period. Plain HTML, not
// SVG, so a screen reader gets a real button with a name and a pressed state, the text reflows on
// a phone (the label sits above the bar below 600px), and the figures are one switch away as a
// table. The colours never carry meaning alone: the worst step is tagged "Biggest loss" and the
// loss is also written out as a number.
import { computed, ref } from 'vue'
import { uid } from '~/composables/useId'
import { withCount } from '~/modtools/composables/useUxFormat'
import {
  stepHelp,
  stageHelp,
  wayInHelp,
} from '~/modtools/composables/useUxFunnel'

// Each chart's tooltips need ids no other chart on the page has.
const chartId = uid('funnel-help')
const helpId = (row) => `${chartId}-${row.key}`

const props = defineProps({
  title: { type: String, default: '' },
  // The flow the steps belong to, for the explanation of a step whose meaning depends on it.
  flow: { type: String, default: '' },
  // From useUxFunnel funnelsFor(): stages [{ step, label, entered, visits, abandoned, reach,
  // lossRate, alternatives? }]
  steps: { type: Array, required: true },
  // step -> reach in the previous period, for the outline. A stage with ways in is also keyed
  // 'stage:<position>' for its heading, whose reach is the sum of its ways in and is not any one
  // way's.
  previousReach: { type: Object, default: () => ({}) },
  selected: { type: String, default: '' },
  // Ways out that are not steps: [{ step, label, entered, visits }]
  exits: { type: Array, default: () => [] },
  // The step to tag "Biggest loss", from useUxFunnel worstStepOf. The chart does not work it out
  // itself: the headline, the all-funnels table and this tag must name the same step.
  worst: { type: String, default: '' },
  // Whether to draw the line explaining the dashed outline. A funnel with two charts draws it once.
  showKey: { type: Boolean, default: true },
  // step -> { completed, visits } in the period before, keyed like previousReach. The table view
  // reads it.
  previousDone: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['select'])

const asTable = ref(false)

const pct = (v) => `${Math.round(v * 100)}%`

// One denominator on a line: the figure after "reached" counts people, the figure about leaving
// counts visits (a person can visit a step more than once), and the line says "of N visits" so
// the percentage can be checked by hand.
function valueText(s) {
  const reached = `${s.entered.toLocaleString()} reached`
  if (!s.abandoned) return reached
  return `${reached}. ${s.abandoned.toLocaleString()} of ${s.visits.toLocaleString()} visits ended here (${pct(s.lossRate)})`
}

function makeRow(s, opts = {}) {
  const width = Math.max(1, s.reach * 100)
  // A stage heading is the previous period's stage at the same position, not the way in that
  // shares its step name.
  const previousKey = opts.stage !== undefined ? 'stage:' + opts.stage : s.step
  const prev = props.previousReach?.[previousKey]
  const done = props.previousDone?.[previousKey]
  const text = valueText(s)
  // A stage heading (selectable: false) is a sum of its ways in and is never itself the worst.
  const worst =
    !!props.worst && s.step === props.worst && opts.selectable !== false
  return {
    key: (opts.indent ? 'alt:' : 'stage:') + s.step,
    step: s.step,
    label: opts.indent ? `came in through ${s.label}` : s.label,
    text,
    aria: `${s.label}: ${text}${worst ? '. Biggest loss.' : ''}`,
    width,
    // The red end is the loss rate applied to the bar, so it matches the percentage in the text.
    lossWidth: s.abandoned ? Math.max(1, s.lossRate * width) : 0,
    prev: prev ? Math.max(1, prev * 100) : 0,
    entered: s.entered,
    visits: s.visits,
    abandoned: s.abandoned,
    completed: s.completed,
    // The share of visits that went on, and the same for the period before, as the old panel gave
    // them for every step. A step the period before did not have shows a dash.
    completedText: withCount(s.completed, s.visits),
    previousText: done ? withCount(done.completed, done.visits) : '–',
    ofVisits: s.visits ? pct(s.lossRate) : '',
    selectable: opts.selectable !== false,
    indent: !!opts.indent,
    last: !!opts.last,
    help: opts.indent ? wayInHelp(s, props.flow) : stageHelp(s, props.flow),
    worst,
    exit: false,
  }
}

const rows = computed(() => {
  const out = []
  props.steps.forEach((s, i) => {
    if (s.alternatives) {
      // The stage is the sum of its ways in; it is a heading, and each way in can be chosen.
      out.push({
        ...makeRow(s, { selectable: false, stage: i }),
        key: 'stage:' + s.step,
      })
      s.alternatives.forEach((a, n) =>
        out.push(
          makeRow(a, { indent: true, last: n === s.alternatives.length - 1 })
        )
      )
    } else {
      out.push(makeRow(s))
    }
  })
  const base = props.steps[0]?.entered || 0
  for (const e of props.exits || []) {
    const width = base
      ? Math.max(1, Math.min(100, (e.entered / base) * 100))
      : 1
    const text = `${e.entered.toLocaleString()} people. Not counted as lost.`
    out.push({
      key: 'exit:' + e.step,
      step: e.step,
      label: e.label,
      text,
      aria: `${e.label}: ${text}`,
      width,
      lossWidth: 0,
      prev: 0,
      entered: e.entered,
      visits: e.visits,
      abandoned: 0,
      completed: 0,
      completedText: '',
      previousText: '',
      ofVisits: '',
      selectable: false,
      indent: false,
      last: false,
      help: stepHelp(e.step, props.flow),
      worst: false,
      exit: true,
    })
  }
  return out
})

const ariaLabel = computed(
  () => `Funnel${props.title ? ': ' + props.title : ''}`
)
</script>

<style scoped lang="scss">
.rows {
  padding: 0;
}

.funnel-row {
  margin-bottom: 0.35rem;

  // A way in is set in from its stage by its label alone, so its tag is in the tag column like every
  // other and its bar starts where the others do.
  &.indent .row-label {
    padding-left: 1.25rem;
  }
}

// Four columns: the label, the bar, the figures and the tag. The tag has a column of its own at the
// right-hand end, so it sits in the same place on every chart whatever the label says and no row
// changes height for it. The first three are the same on every row, a stage heading and a way out as
// well as a step, so every bar is the same width for the same share.
.line {
  position: relative;
  display: grid;
  width: 100%;
  grid-template-columns: 11rem minmax(7rem, 14rem) 1fr auto;
  align-items: center;
  column-gap: 0.75rem;
  padding: 0.15rem 0.25rem;
  border: 1px solid transparent;
  border-radius: 0.25rem;
  text-align: left;
  color: inherit;
}

// A button that looks like a row: no chrome, the whole line is the target.
button.line {
  background: none;
  cursor: pointer;

  &:hover,
  &:focus-visible {
    background: #f8f9fa;
  }
}

.funnel-row.active button.line {
  border-color: #212529;
}

// On a wide screen the label is a cell of the row's grid. On a phone it is a line above the bar.
.label-line {
  display: contents;
}

.row-label {
  font-size: 0.9rem;
  color: #212529;
  // A selectable row is a button, and assets/css/buttons.scss keeps every button on one line with
  // !important, so a long step name would run into the bar.
  white-space: normal !important;
  min-width: 0;
}

.row-value {
  font-size: 0.8rem;
  color: #495057;
}

.track {
  position: relative;
  display: block;
  height: 1.4rem;
}

.bar,
.bar-prev {
  position: absolute;
  left: 0;
  top: 0;
  height: 100%;
  display: block;
}

.bar {
  background: #61ae24;
  border-radius: 2px;

  &.worst {
    background: #c98a1e;
  }

  &.exit {
    background: #adb5bd;
  }
}

.bar-prev {
  border: 1px dashed #6c757d;
  box-sizing: border-box;
}

// The loss sits at the end of the bar. Its colour is backed by a hatch so it does not rely on
// red against green alone.
.bar-loss {
  position: absolute;
  right: 0;
  top: 0;
  height: 100%;
  background: repeating-linear-gradient(
    45deg,
    #dc3545,
    #dc3545 4px,
    #a71d2a 4px,
    #a71d2a 8px
  );
  border-radius: 0 2px 2px 0;
}

// The biggest loss is flagged by the amber bar, a faint amber wash on the row and these words at the
// end of it. Plain coloured text with no background, and one line tall, so a row with the tag is as
// tall as the same row without it.
.funnel-row.worst .line {
  background: rgba(201, 138, 30, 0.12);
}

.worst-tag {
  font-size: 0.75rem;
  line-height: 1.3;
  font-weight: bold;
  color: #8a5a00;
  white-space: nowrap;
  justify-self: end;
}

// What a step is, shown over the row on hover and keyboard focus. The row is a button, or a div
// made focusable, and aria-describedby points here, so a screen reader reads it and a tap on a
// phone, which focuses the row, shows it. position: absolute keeps it out of the grid.
.row-help {
  position: absolute;
  z-index: 10;
  top: 100%;
  left: 0.25rem;
  display: none;
  max-width: min(24rem, 100%);
  padding: 0.4rem 0.6rem;
  border-radius: 0.25rem;
  background: #212529;
  color: #fff;
  font-size: 0.8rem;
  font-weight: normal;
  font-style: normal;
  line-height: 1.35;
  text-align: left;
  // A selectable row is a button, which keeps its text on one line (see .row-label).
  white-space: normal !important;
  box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.25);
  pointer-events: none;
}

.line:hover .row-help,
.line:focus .row-help,
.line:focus-within .row-help {
  display: block;
}

// A faint tree line from a stage down to its ways in: a vertical line at the left of the group and
// an elbow into each way in, ending at the last one. It takes the row's text colour at low opacity,
// so it shows in light and dark alike. The vertical reaches up across the gap between rows.
.funnel-row.indent {
  position: relative;

  &::before,
  &::after {
    content: '';
    position: absolute;
    left: 0.65rem;
    border-color: currentColor;
    opacity: 0.25;
    pointer-events: none;
  }

  // The stem.
  &::before {
    top: -0.35rem;
    height: calc(100% + 0.35rem);
    border-left: 1px solid;
  }

  // The elbow, level with the label.
  &::after {
    top: 0.85rem;
    width: 0.5rem;
    border-top: 1px solid;
  }

  &.last::before {
    height: calc(0.85rem + 0.35rem);
  }
}

.funnel-row.exit .line {
  font-style: italic;
}

.figures .indent th {
  padding-left: 1.5rem;
}

.figures .tag-cell {
  width: 1%;
  white-space: nowrap;
}

// A phone: the label sits above the bar, and the figures and then the tag go below, each on a line
// of its own.
@media (max-width: 599px) {
  .line {
    grid-template-columns: 1fr;
    row-gap: 0.15rem;
  }

  .label-line {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
  }

  .worst-tag {
    justify-self: start;
  }

  // The label sits above the bar on a phone, so the whole line is set in.
  .funnel-row.indent .line {
    padding-left: 1rem;
  }

  .funnel-row.indent .row-label {
    padding-left: 0;
  }
}
</style>
