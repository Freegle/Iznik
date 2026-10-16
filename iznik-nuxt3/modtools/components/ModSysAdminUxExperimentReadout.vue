<template>
  <div class="ux-exp-readout">
    <!-- Why it was decided as it was, as the batch wrote it. The figures can look the other way: a
         guardrail can take a change off whatever the main result was. -->
    <p v-if="decision" class="decision mb-1">{{ decision }}</p>
    <template v-if="lines">
      <p v-if="running" class="mb-1">
        <span class="verdict badge" :class="verdictClass">{{ verdict }}</span>
      </p>
      <p class="started mb-1">{{ lines.started }}</p>
      <p v-if="lines.finished" class="finished mb-1">{{ lines.finished }}</p>
      <p v-if="lines.chance" class="chance mb-1">{{ lines.chance }}</p>
      <div v-if="lines.others.length" class="others mb-1">
        <!-- These are checks on the experiment's own funnel and on others. Only the lines that begin
             with finishing are funnels, so the heading does not call them that. -->
        <div class="others-label">Also checked</div>
        <ul class="list-unstyled mb-0">
          <li
            v-for="(line, i) in lines.others"
            :key="i"
            :class="{ worried: worried(i) }"
          >
            {{ line }}
          </li>
        </ul>
      </div>
      <p v-if="lines.share" class="share mb-1">{{ lines.share }}</p>
      <!-- How a metric source counts, in its own words. Only an experiment decided on the server has one. -->
      <p v-if="lines.note" class="note text-muted mb-0">{{ lines.note }}</p>
    </template>
    <p v-else-if="running" class="none text-muted mb-0">{{ NO_READ_YET }}</p>
    <p v-else class="none text-muted mb-0">No figures were kept.</p>
  </div>
</template>

<script setup>
// The numbers of one experiment in plain words: where the comparison stands, how many started and
// finished with and without the change, the chance the change is better, how the other things
// checked are doing, and a warning when the share of starts is not what was served. The figures come
// from the experiment's `result`, for a running experiment and a finished one alike. A `result` with
// no `arms` is no read yet, which is what an experiment shows just after a start or a restart. A
// finished experiment also gives the batch's own account of why it was decided as it was.
import { computed } from 'vue'
import {
  NO_READ_YET,
  readLines,
  readOf,
  verdictOf,
} from '~/modtools/composables/useUxExperimentRead'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

const running = computed(() => props.experiment.status === 'running')
const read = computed(() => readOf(props.experiment))
const lines = computed(() => readLines(read.value))
const verdict = computed(() => verdictOf(read.value))

// What the batch stored when it decided, whole and as text. A running experiment has not been decided.
const decision = computed(() =>
  running.value ? '' : String(props.experiment.decision ?? '').trim()
)

// Dark green when ahead (white text on the theme's own green is too faint to read), red when behind,
// and plain grey otherwise.
const verdictClass = computed(
  () =>
    ({
      'Change is ahead': 'ux-green',
      'Change is behind': 'bg-danger',
    })[verdict.value] || 'bg-secondary'
)

// A line for a guardrail that may be worse stands out.
const worried = (i) => read.value.guardrails[i].pWorse >= 0.5
</script>

<style scoped lang="scss">
// A badge is 0.75em, which inside the small text of the details is about 10 pixels.
.verdict {
  font-size: 0.75rem;
}

// The theme forces its own green on .bg-success, which white text cannot be read on (2.8 to 1).
// This one is 5.4 to 1, and has no bg- class for the theme to change.
.ux-green {
  background-color: #2a7a0a;
  color: #fff;
}

// A sub-heading of how it is going, in the style of the headings of the details.
.others-label {
  font-size: 0.8rem;
  font-weight: 700;
  color: #495057;
}

.worried {
  color: #664d03;
  font-weight: 600;
}

.share {
  padding: 0.35rem 0.5rem;
  border-left: 3px solid #ffc107;
  background: #fff8e1;
  color: #4d3b02;
}
</style>
