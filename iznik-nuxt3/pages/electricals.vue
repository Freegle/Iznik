<template>
  <b-row class="m-0 pt-4">
    <b-col cols="12" lg="8" class="p-0" offset-lg="2">
      <div class="d-flex ps-1 bg-white">
        <b-img thumbnail src="/icon.png" class="titlelogo" />
        <div class="ms-2">
          <h2>Electricals on Freegle</h2>
          <h5>
            Anything with a plug, a battery or a cable. Here's how much of it
            gets used again instead of thrown away.
          </h5>
        </div>
      </div>

      <b-alert v-if="error" :model-value="true" variant="warning" class="mt-2">
        These figures aren't available at the moment.
      </b-alert>

      <div v-else-if="pending" class="text-center mt-4">
        <Spinner :size="50" />
      </div>

      <div v-else-if="stats">
        <!-- Headline figures, in the same shape as the impact banner on /stats. -->
        <b-card variant="white" class="border-white mt-2" no-body>
          <b-card-body class="pb-0">
            <b-row class="p-0">
              <b-col class="text-center">
                <v-icon icon="gift" class="purple titleicon" />
                <h3 class="purple">
                  {{ stats.counts.electrical.toLocaleString() }}
                  <br />
                  ELECTRICALS
                </h3>
              </b-col>
              <b-col class="text-center">
                <v-icon icon="chart-bar" class="green titleicon" />
                <h3 class="green">
                  {{ stats.counts.electrical_pct }}%
                  <br />
                  OF ALL ITEMS
                </h3>
              </b-col>
              <b-col v-if="stats.impact.tonnes !== null" class="text-center">
                <v-icon icon="balance-scale-left" class="gold titleicon" />
                <h3 class="gold">
                  {{ stats.impact.tonnes.toLocaleString() }}
                  <br />
                  TONNES
                </h3>
              </b-col>
              <b-col
                v-if="stats.impact.tonnes_co2e !== null"
                class="text-center"
              >
                <v-icon icon="cloud" class="green titleicon" />
                <h3 class="green">
                  {{ stats.impact.tonnes_co2e.toLocaleString() }}
                  <br />
                  TONNES CO2
                </h3>
              </b-col>
            </b-row>
          </b-card-body>
          <b-card-body class="pt-0 text-center">
            <span class="text-muted small">{{ rangeLabel }}</span>
          </b-card-body>
        </b-card>

        <b-card variant="white" class="mt-2">
          <b-card-text>
            <h3>What counts as an electrical</h3>
            <p>
              Anything with a plug, a battery or a cable. That is the definition
              <ExternalLink href="https://www.materialfocus.org.uk/"
                >Material Focus</ExternalLink
              >
              use for the national electricals recycling campaign, and it covers
              more than the obvious things: a kettle, but also a fish tank with
              a pump in it, a baby bouncer with a music box, or a jacket with a
              heater in the lining.
            </p>
            <p>
              We look at the photo on each item somebody offers and work out
              whether it is electrical. Of
              {{ stats.counts.classified.toLocaleString() }} items we looked at
              in the last 12 months,
              {{ stats.counts.electrical.toLocaleString() }} were. Checked
              against items sorted by hand, we get this right
              {{ stats.accuracy.is_electrical.pct }}% of the time.
            </p>
          </b-card-text>
        </b-card>

        <b-card
          v-if="stats.impact.tonnes !== null"
          variant="white"
          class="mt-2"
        >
          <b-card-text>
            <h3>Weights</h3>
            <p>
              The electricals given away in the last 12 months weighed
              {{ stats.impact.tonnes.toLocaleString() }} tonnes. That is
              {{ stats.impact.tonnes_co2e.toLocaleString() }} tonnes of CO2 kept
              out of the air, worth £{{
                stats.impact.carbon_value_gbp.toLocaleString()
              }}
              at the government's carbon value of £{{
                stats.impact.carbon_proxy_gbp_per_tonne
              }}
              a tonne. The average electrical item weighs
              {{ stats.impact.mean_item_kg }}kg.
            </p>
            <p>
              We work weights out from our catalogue of item types rather than
              from the photo. People don't always tell us when things have
              worked, so it's likely to be an underestimate.
            </p>
          </b-card-text>
        </b-card>

        <b-card
          v-if="stats.success.electrical || stats.success.other"
          variant="white"
          class="mt-2"
        >
          <b-card-text>
            <h3>Do electricals find a new home?</h3>
            <p v-if="stats.success.electrical && stats.success.other">
              {{ stats.success.electrical.taken_pct }}% of the electricals
              people offer get taken, against
              {{ stats.success.other.taken_pct }}% of everything else.
            </p>
            <p v-else-if="stats.success.electrical">
              {{ stats.success.electrical.taken_pct }}% of the electricals
              people offer get taken.
            </p>
            <p>
              This counts only posts old enough to have run their course. People
              don't always tell us when things have worked, so it's likely to be
              an underestimate.
            </p>
          </b-card-text>
        </b-card>

        <b-card v-if="conditionRows.length" variant="white" class="mt-2">
          <b-card-text>
            <h3>Even broken ones get taken</h3>
            <p>
              A lot of what gets offered is damaged or only good for spares, and
              it still finds someone who wants it, usually to fix or to strip
              for parts.
            </p>
            <b-table-simple small responsive class="mb-2">
              <b-thead>
                <b-tr>
                  <b-th>Condition</b-th>
                  <b-th class="text-end">Items</b-th>
                  <b-th class="text-end">Taken</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr v-for="row in conditionRows" :key="row.name">
                  <b-td>{{ row.label }}</b-td>
                  <b-td class="text-end">{{ row.count.toLocaleString() }}</b-td>
                  <b-td class="text-end">{{ row.taken_pct }}%</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
            <p class="text-muted small mb-0">
              Condition comes from the photo, and matches what our volunteers
              said {{ stats.accuracy.condition.pct }}% of the time.
            </p>
          </b-card-text>
        </b-card>

        <b-row class="mt-2 mx-0">
          <b-col md="6" class="mb-2 mb-md-0 px-0 pe-md-1">
            <b-card variant="white" class="h-100">
              <b-card-text>
                <h3>Most offered</h3>
                <b-list-group flush>
                  <b-list-group-item
                    v-for="item in stats.popular"
                    :key="item.name"
                    class="d-flex justify-content-between align-items-center px-0"
                  >
                    <span>{{ item.name }}</span>
                    <b-badge variant="success" pill>
                      {{ item.count.toLocaleString() }}
                    </b-badge>
                  </b-list-group-item>
                </b-list-group>
              </b-card-text>
            </b-card>
          </b-col>
          <b-col md="6" class="px-0 ps-md-1">
            <b-card variant="white" class="h-100">
              <b-card-text>
                <h3>More unusual</h3>
                <p>
                  Rarer things people have passed on. An item appears here only
                  once several different people in more than one community have
                  offered one, so a single odd listing can't get in.
                </p>
                <b-list-group v-if="stats.unusual.items.length" flush>
                  <b-list-group-item
                    v-for="item in stats.unusual.items"
                    :key="item.name"
                    class="d-flex justify-content-between align-items-center px-0"
                  >
                    <span>{{ item.name }}</span>
                    <b-badge variant="secondary" pill>
                      {{ item.count.toLocaleString() }}
                    </b-badge>
                  </b-list-group-item>
                </b-list-group>
                <p v-else class="text-muted mb-0">Nothing qualifies yet.</p>
              </b-card-text>
            </b-card>
          </b-col>
        </b-row>

        <p class="text-muted small mt-2">
          Covers the last 12 months. Worked out on {{ generatedOn }}.
        </p>
      </div>
    </b-col>
  </b-row>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRuntimeConfig, useHead, useAsyncData } from '#imports'
import Api from '~/api'
import ExternalLink from '~/components/ExternalLink.vue'
import { buildHead } from '~/composables/useBuildHead'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const api = Api(runtimeConfig)

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Electricals on Freegle',
    'How much electrical equipment gets used again instead of thrown away, and what happens to it.'
  )
)

const {
  data: stats,
  pending,
  error,
} = await useAsyncData('electricals-stats', () => api.electricals.stats())

const rangeLabel = 'in the last 12 months'

// Damaged sits second deliberately: that broken things still get taken is the
// point of the table, and burying it under the unremarkable majority loses it.
const CONDITION_LABELS = {
  reusable: 'Working',
  damaged: 'Damaged or for spares',
  unsure: 'Not clear from the photo',
}

const conditionRows = computed(() => {
  const split = stats.value?.condition ?? {}

  return Object.keys(CONDITION_LABELS)
    .filter((name) => split[name])
    .map((name) => ({
      name,
      label: CONDITION_LABELS[name],
      count: split[name].count,
      taken_pct: split[name].taken_pct,
    }))
})

const generatedOn = computed(() => {
  const at = stats.value?.generated_at

  return at
    ? new Date(at).toLocaleDateString('en-GB', { dateStyle: 'long' })
    : ''
})
</script>

<style scoped lang="scss">
.titlelogo {
  width: 60px;
  height: 60px;
}

.titleicon {
  width: 2rem;
  height: 2rem;
}

.purple {
  color: $color-purple !important;
}

.gold {
  color: $color-gold !important;
}

.green {
  color: $color-green--darker !important;
}
</style>
