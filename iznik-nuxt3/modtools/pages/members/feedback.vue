<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpFeedback />
      <b-card v-if="happinessData.length" variant="white" class="mt-1">
        <b-card-text>
          <p class="text-center">
            This is what people have said over the last year across all of
            Freegle.
          </p>
          <div class="d-flex flex-wrap justify-content-between">
            <GChart
              type="PieChart"
              :data="happinessData"
              :options="happinessOptions"
            />
            <GChart
              type="BarChart"
              :data="happinessData"
              :options="happinessOptions"
            />
          </div>
        </b-card-text>
      </b-card>
      <NoticeMessage v-else class="mt-2">
        There's no feedback to show yet.
      </NoticeMessage>
    </client-only>
  </div>
</template>
<script setup>
// This page used to be a per-community feedback queue: a groupid-scoped list
// of individual happiness/comments cards plus a Thumbs Up/Down ratings tab,
// fed by the old MT2 "Happiness" membership collection. That collection has
// no equivalent on the national /modtools/members endpoint (which returns
// only id/displayname/added/flagreason - no happiness, comments, outcome or
// rating fields), and messages_outcomes has no happiness/comments concept
// either, so there is nothing left to feed a per-member list or ratings tab.
// What remains genuinely national and working is the aggregate happiness
// chart below, via the dashboard's systemwide Happiness component.
import { ref, onMounted } from 'vue'
import dayjs from 'dayjs'
import { GChart } from 'vue-google-charts'
import { useNuxtApp } from '#app'

const { $api } = useNuxtApp()

const happinessData = ref([])
const happinessOptions = {
  chartArea: {
    width: '80%',
    height: '80%',
  },
  pieSliceBorderColor: 'darkgrey',
  colors: ['green', '#f8f9fa', 'orange'],
  slices2: {
    1: { offset: 0.2 },
    2: { offset: 0.2 },
    3: { offset: 0.2 },
  },
}

async function getHappiness() {
  const start = dayjs().subtract(1, 'year').toDate().toISOString()
  const ret = await $api.dashboard.fetch({
    components: ['Happiness'],
    start,
    end: new Date().toISOString(),
    systemwide: true,
  })

  if (ret.Happiness) {
    happinessData.value = [['Feedback', 'Count']]
    ret.Happiness.forEach((h) => {
      happinessData.value.push([h.happiness, h.count])
    })
  }
}

onMounted(async () => {
  await getHappiness()
})
</script>
