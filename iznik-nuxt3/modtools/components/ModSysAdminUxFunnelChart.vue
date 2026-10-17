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
        }"
      >
        <component
          :is="row.selectable ? 'button' : 'div'"
          :type="row.selectable ? 'button' : undefined"
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
            <span class="tag-slot">
              <span v-if="row.worst" class="worst-tag">Biggest loss</span>
            </span>
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
            <th scope="row" class="fw-normal">{{ row.label }}</th>
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
import { withCount } from '~/modtools/composables/useUxFormat'

const props = defineProps({
  title: { type: String, default: '' },
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
      for (const a of s.alternatives) out.push(makeRow(a, { indent: true }))
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

// Four columns: the tag, the label, the bar and the figures. The tag has a column of its own, so it
// sits in the same place on every chart whatever the label says and no row changes height for it.
// Every row has them, a stage heading and a way out as well as a step, so every bar is the same
// width for the same share.
.line {
  display: grid;
  width: 100%;
  grid-template-columns: 5.5rem 11rem minmax(7rem, 14rem) 1fr;
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

// On a wide screen the tag and the label are two cells of the row's grid. On a phone they share a
// line above the bar.
.label-line {
  display: contents;
}

.row-label {
  font-size: 0.9rem;
  color: #212529;
  // A selectable row is a button, and assets/css/buttons.scss keeps every button on one line with
  // !important, so a long step name and its "Biggest loss" tag would run into the bar.
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

// The tag is shorter than a bar, and its slot is a flex box so the line height of the page text does
// not make it any taller: a row with the tag is as tall as the same row without it.
.tag-slot {
  display: flex;
  align-items: center;
}

.worst-tag {
  display: inline-block;
  padding: 0 0.35rem;
  font-size: 0.7rem;
  line-height: 1.3;
  font-weight: bold;
  color: #fff;
  background: #8a5a00;
  border-radius: 0.6rem;
  white-space: nowrap;
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

// A phone: the tag and the label share a line above the bar, and the figures go below, each on a
// line of its own.
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

  .tag-slot:empty {
    display: none;
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
