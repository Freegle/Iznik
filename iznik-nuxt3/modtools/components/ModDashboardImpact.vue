<template>
  <div>
    <h2>
      Impact <span class="text-muted">on {{ groupName }}</span>
    </h2>
    <p>This is our estimate of the impact your active communities have had.</p>
    <b-card v-if="loading" no-body>
      <b-card-body>
        <b-row><b-col>&nbsp;</b-col></b-row>
        <b-row><b-col>&nbsp;</b-col></b-row>
        <b-row>
          <b-col class="text-faded pulsate text-center"> Loading... </b-col>
        </b-row>
        <b-row><b-col>&nbsp;</b-col></b-row>
        <b-row><b-col>&nbsp;</b-col></b-row>
        <b-row><b-col>&nbsp;</b-col></b-row>
      </b-card-body>
    </b-card>
    <ModImpact
      v-else
      :range="startf + ' - ' + endf"
      :total-benefit="totalBenefit"
      :benefit-note="flyTipNote"
      :total-c-o2="totalCO2"
      :total-weight="totalWeight"
      class="mt-2"
    />
  </div>
</template>
<script setup>
import { computed } from 'vue'
import dayjs from 'dayjs'
import { useModDashboard } from '~/modtools/composables/useModDashboard'
import { sumCountsInRange } from '~/composables/useAuthoritySearch'
import {
  getBenefitPerTonne,
  CO2_PER_TONNE,
  calculateFlyTipSaving,
  describeFlyTipSaving,
} from '~/composables/useReuseBenefit'

const props = defineProps({
  groupid: {
    type: Number,
    required: false,
    default: null,
  },
  groupName: {
    type: String,
    required: true,
  },
  start: {
    type: Date,
    required: true,
  },
  end: {
    type: Date,
    required: true,
  },
})

const { loading, Weight, Outcomes } = useModDashboard(props, [
  'Weight',
  'Outcomes',
])

const startf = computed(() => dayjs(props.start).format('YYYY-MM-DD'))

const endf = computed(() => dayjs(props.end).format('YYYY-MM-DD'))

const totalWeight = computed(() => {
  const weights = Weight.value
  let total = 0
  const start = dayjs(props.start)
  const end = dayjs(props.end)

  if (weights) {
    for (const w of weights) {
      if (
        start.isSameOrBefore(dayjs(w.date), 'days') &&
        end.isSameOrAfter(dayjs(w.date), 'days')
      ) {
        total += w.count
      }
    }
  }

  return total / 1000
})

// Items given away in the range: offers taken and wanteds received.
const totalFlyTipSaving = computed(() =>
  calculateFlyTipSaving(
    sumCountsInRange(Outcomes.value, dayjs(props.start), dayjs(props.end))
  )
)

// Benefit of reuse per tonne and CO2 impact based on WRAP figures.
// https://wrap.org.uk/resources/tool/benefits-reuse-tool
// The benefit adds the fly-tipping clear-up costs avoided for the gifts made.
// Both are inflation-adjusted to current year prices.
const totalBenefit = computed(
  () => totalWeight.value * getBenefitPerTonne() + totalFlyTipSaving.value
)

const flyTipNote = computed(() => describeFlyTipSaving(totalFlyTipSaving.value))

const totalCO2 = computed(() => totalWeight.value * CO2_PER_TONNE)
</script>
