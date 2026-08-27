<template>
  <b-container fluid class="p-0">
    <b-row class="m-0">
      <b-col cols="12" lg="10" offset-lg="1" class="p-3">
        <h1>Electricals on Freegle</h1>

        <p class="text-muted">
          Anything with a plug, battery or cable counts as an electrical. We
          look at the photo on each item offered and work out whether it is one,
          so we can show how much electrical equipment gets reused rather than
          thrown away.
        </p>

        <b-alert v-if="error" :model-value="true" variant="warning">
          These figures are not available at the moment.
        </b-alert>

        <div v-else-if="pending" class="d-flex justify-content-center p-5">
          <b-spinner />
        </div>

        <div v-else-if="stats">
          <!-- Headline -->
          <b-card class="mb-3">
            <b-row>
              <b-col md="4" class="text-center mb-3 mb-md-0">
                <div class="display-5 text-success">
                  {{ stats.counts.electrical.toLocaleString() }}
                </div>
                <div class="text-muted">electrical items offered</div>
                <div class="small text-muted">in the last 12 months</div>
              </b-col>
              <b-col md="4" class="text-center mb-3 mb-md-0">
                <div class="display-5 text-success">
                  {{ stats.counts.electrical_pct }}%
                </div>
                <div class="text-muted">of everything offered</div>
                <div class="small text-muted">
                  of {{ stats.counts.classified.toLocaleString() }} items we
                  could check
                </div>
              </b-col>
              <b-col md="4" class="text-center">
                <div class="display-5 text-success">
                  {{ stats.impact.tonnes }}
                </div>
                <div class="text-muted">tonnes passed on</div>
                <div class="small text-muted">
                  {{ stats.impact.items_taken.toLocaleString() }} items actually
                  taken
                </div>
              </b-col>
            </b-row>
          </b-card>

          <!-- Impact -->
          <b-card header="What that adds up to" class="mb-3">
            <b-row>
              <b-col md="6" class="mb-3 mb-md-0">
                <p class="mb-1">
                  <strong>{{ stats.impact.tonnes_co2e }} tonnes</strong> of CO2e
                  avoided, worth about
                  <strong
                    >£{{
                      stats.impact.carbon_value_gbp.toLocaleString()
                    }}</strong
                  >
                  using the National TOMs carbon value of £{{
                    stats.impact.carbon_proxy_gbp_per_tonne
                  }}
                  per tonne.
                </p>
                <p class="small text-muted mb-0">
                  The average electrical item passed on weighs
                  {{ stats.impact.mean_item_kg }}kg. That is an average, not a
                  best case.
                </p>
              </b-col>
              <b-col md="6">
                <p class="small text-muted mb-0">
                  Weights come from our item catalogue, not from the photo. We
                  measured how well the model estimates weight from a picture
                  and it was only
                  {{ accuracy.weight?.pct }}% accurate, so we do not use it for
                  this.
                </p>
              </b-col>
            </b-row>
          </b-card>

          <!-- Success rate -->
          <b-card header="Do electricals find a new home?" class="mb-3">
            <b-row v-if="stats.success.electrical && stats.success.other">
              <b-col sm="6" class="text-center mb-3 mb-sm-0">
                <div class="h2 mb-0">
                  {{ stats.success.electrical.taken_pct }}%
                </div>
                <div class="text-muted">of electricals were taken</div>
                <div class="small text-muted">
                  {{ stats.success.electrical.posts.toLocaleString() }} posts
                </div>
              </b-col>
              <b-col sm="6" class="text-center">
                <div class="h2 mb-0">{{ stats.success.other.taken_pct }}%</div>
                <div class="text-muted">of everything else</div>
                <div class="small text-muted">
                  {{ stats.success.other.posts.toLocaleString() }} posts
                </div>
              </b-col>
            </b-row>
            <p class="small text-muted mb-0 mt-3">
              Only counts posts old enough to have settled, so nothing here is
              still waiting for a reply.
            </p>
          </b-card>

          <!-- Condition, including broken -->
          <b-card
            v-if="conditionRows.length"
            header="Even broken ones get taken"
            class="mb-3"
          >
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
            <p class="small text-muted mb-0">
              Condition is read from the photo and is right about
              {{ accuracy.condition?.pct }}% of the time, checked against our
              volunteers.
            </p>
          </b-card>

          <!-- Popular and unusual -->
          <b-row class="mb-3">
            <b-col md="6" class="mb-3 mb-md-0">
              <b-card header="Most offered" class="h-100">
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
              </b-card>
            </b-col>
            <b-col md="6">
              <b-card header="More unusual" class="h-100">
                <b-list-group flush>
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
                <p class="small text-muted mb-0 mt-2">
                  {{ stats.unusual.guard.note }}
                </p>
              </b-card>
            </b-col>
          </b-row>

          <p class="small text-muted">
            Figures cover the last 12 months and were worked out on
            {{ generatedOn }}. We check the photo on each item; that judgement
            is right about {{ accuracy.is_electrical?.pct }}% of the time,
            measured against items labelled by hand.
          </p>
        </div>
      </b-col>
    </b-row>
  </b-container>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRuntimeConfig, useHead, useAsyncData } from '#imports'
import Api from '~/api'
import { buildHead } from '~/composables/useBuildHead'

const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const api = Api(runtimeConfig)

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Electricals on Freegle',
    'How much electrical equipment gets reused rather than thrown away, and what happens to it.'
  )
)

const {
  data: stats,
  pending,
  error,
} = await useAsyncData('electricals-stats', () => api.electricals.stats())

const accuracy = computed(() => stats.value?.accuracy ?? {})

// Order the condition rows so the interesting one - damaged items still being taken -
// is not buried underneath the unremarkable majority.
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
