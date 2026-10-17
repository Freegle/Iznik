<template>
  <div class="table-responsive">
    <table class="table table-sm ux-funnel-table mb-0">
      <thead>
        <tr>
          <th scope="col">Funnel</th>
          <th scope="col" class="text-end">Started</th>
          <th scope="col" class="ps-2 ps-md-4">Finished</th>
          <th scope="col">Biggest loss</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.flow" :data-flow="r.flow">
          <th scope="row" class="fw-normal">
            <a
              :href="hrefFor({ section: 'funnels', flow: r.flow })"
              @click="(e) => go(e, { section: 'funnels', flow: r.flow })"
            >
              {{ flowTitle(r.flow) }}
            </a>
          </th>
          <td class="text-end">{{ r.started.toLocaleString() }}</td>
          <td class="ps-2 ps-md-4">
            <template v-if="r.started">
              <div class="finished">
                {{ whole(r.finishedRate) }} ({{ r.finished.toLocaleString() }})
              </div>
              <div class="small text-muted before">{{ before(r) }}</div>
            </template>
            <template v-else>{{ DASH }}</template>
          </td>
          <td>
            <template v-if="r.loss.state === 'step'">
              <a
                class="loss"
                :href="
                  hrefFor({
                    section: 'funnels',
                    flow: r.flow,
                    step: r.loss.step,
                  })
                "
                @click="
                  (e) =>
                    go(e, {
                      section: 'funnels',
                      flow: r.flow,
                      step: r.loss.step,
                    })
                "
              >
                {{ r.loss.label }}: {{ whole(r.loss.lossRate) }} of
                {{ r.loss.visits.toLocaleString() }} visits ended there
              </a>
            </template>
            <template v-else-if="r.loss.state === 'none'">
              No step lost many people
            </template>
            <template v-else-if="r.loss.state === 'few'">
              Too few visits to say
            </template>
            <template v-else>{{ DASH }}</template>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { flowTitle } from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'

defineProps({
  // One row for each funnel, from useUxFunnel summariseFlow.
  rows: { type: Array, required: true },
  // (patch) => the address that patch would give.
  hrefFor: { type: Function, required: true },
})
const emit = defineEmits(['navigate'])

const DASH = '–'

const whole = (fraction) => `${Math.round(fraction * 100)}%`

// How the period before compares, in the points the two whole percentages differ by, so the words
// agree with the numbers on screen.
function before(r) {
  if (r.beforeRate === null) return 'No figures for the period before.'
  const gap = Math.round(r.finishedRate * 100) - Math.round(r.beforeRate * 100)
  const points = Math.abs(gap)
  const words =
    gap === 0
      ? 'no change'
      : `${points} ${points === 1 ? 'point' : 'points'} ${gap > 0 ? 'higher' : 'lower'}`
  return `Before: ${whole(r.beforeRate)} (${words})`
}

function go(e, patch) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', patch)
}
</script>
