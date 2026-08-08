<template>
  <div class="bg-white ps-2 pe-2 pb-4">
    <client-only>
      <h1>Partnerships</h1>
      <p>
        Sponsorship deals with local authorities. Each one covers the Freegle
        communities inside the council's boundary, and shows the council's
        tagline and link to members there.
      </p>

      <NoticeMessage v-if="!canUse" variant="warning">
        You need to be on the Partnerships team to use this page.
      </NoticeMessage>

      <template v-else>
        <div v-if="summary" class="d-flex flex-wrap gap-3 mb-3">
          <ModPartnershipTotal
            label="Agreed income"
            :value="summary.agreed"
            money
          />
          <ModPartnershipTotal
            label="In discussion"
            :value="summary.total - summary.agreed"
            money
          />
          <ModPartnershipTotal
            label="Invoiced"
            :value="summary.invoiced"
            money
          />
          <ModPartnershipTotal label="Paid" :value="summary.paid" money />
          <ModPartnershipTotal
            label="Outstanding"
            :value="summary.outstanding"
            money
            :variant="summary.outstanding > 0 ? 'warning' : null"
          />
          <ModPartnershipTotal label="Live deals" :value="summary.active" />
        </div>

        <NoticeMessage v-if="expiring.length" variant="warning" class="mb-3">
          <strong>
            {{ expiring.length }}
            {{
              expiring.length === 1 ? 'sponsorship runs' : 'sponsorships run'
            }}
            out within three months:
          </strong>
          {{ expiring.map((p) => p.name + ' (' + p.enddate + ')').join(', ') }}.
          The Partnerships team gets an email about each one too.
        </NoticeMessage>

        <div v-if="chartData.length > 1" class="mb-4">
          <h3>Income by financial year</h3>
          <p class="text-muted small">
            Multi-year deals are spread across the years they cover, so a
            three-year deal shows in three bars rather than all in the year it
            was signed.
          </p>
          <GChart
            type="ColumnChart"
            :data="chartData"
            :options="chartOptions"
            class="chart"
          />
        </div>

        <div class="d-flex justify-content-between align-items-center">
          <h3 class="mb-0">Deals</h3>
          <b-button variant="primary" @click="addPartnership">
            <v-icon icon="plus" /> Add partnership
          </b-button>
        </div>

        <table class="table table-sm mt-2">
          <thead>
            <tr>
              <th>Council</th>
              <th>Runs</th>
              <th class="text-end">Value</th>
              <th class="text-end">Paid</th>
              <th>Communities</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <template v-for="p in partnerships" :key="'partnership-' + p.id">
            <tbody>
              <tr>
                <td>
                  <strong>{{ p.name }}</strong>
                  <div
                    v-if="p.name !== p.authorityname"
                    class="small text-muted"
                  >
                    {{ p.authorityname }}
                  </div>
                </td>
                <td>{{ p.startdate }} to {{ p.enddate }}</td>
                <td class="text-end">£{{ formatMoney(p.amount) }}</td>
                <td class="text-end">£{{ formatMoney(p.paid) }}</td>
                <td>{{ p.groupcount }}</td>
                <td>
                  <b-badge v-if="!p.agreed" variant="secondary">
                    In discussion
                  </b-badge>
                  <b-badge v-else-if="p.expired" variant="danger">
                    Ended
                  </b-badge>
                  <b-badge v-else-if="p.expiring" variant="warning">
                    Renewal due
                  </b-badge>
                  <b-badge v-else variant="success">Live</b-badge>
                  <b-badge v-if="!p.visible" variant="light" class="ms-1">
                    Hidden
                  </b-badge>
                </td>
                <td class="text-end text-nowrap">
                  <b-button variant="link" size="sm" @click="toggle(p.id)">
                    {{ expanded === p.id ? 'Hide' : 'Details' }}
                  </b-button>
                  <b-button
                    variant="link"
                    size="sm"
                    @click="editPartnership(p)"
                  >
                    Edit
                  </b-button>
                  <b-button
                    variant="link"
                    size="sm"
                    class="text-danger"
                    @click="confirmDelete(p)"
                  >
                    Delete
                  </b-button>
                </td>
              </tr>
              <tr v-if="expanded === p.id">
                <td colspan="7">
                  <ModPartnershipDetail :id="p.id" />
                </td>
              </tr>
            </tbody>
          </template>
          <tbody v-if="!partnerships.length">
            <tr>
              <td colspan="7" class="text-muted">
                No partnerships yet. Add the first one above.
              </td>
            </tr>
          </tbody>
        </table>

        <hr />

        <ModPartnershipStats :partnerships="partnerships" />
      </template>

      <ModPartnershipEditModal
        v-if="showEdit"
        :partnership="editing"
        @hidden="showEdit = false"
      />
      <ConfirmModal
        v-if="deleting"
        :title="'Delete the ' + deleting.name + ' partnership?'"
        message="The council will stop showing as a sponsor on every community it covers."
        @confirm="doDelete"
        @hidden="deleting = null"
      />
    </client-only>
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRuntimeConfig, useHead } from '#imports'
import { usePartnershipsStore } from '~/stores/partnerships'
import { useMe } from '~/composables/useMe'
import { buildHead } from '~/composables/useMTBuildHead'

const partnershipsStore = usePartnershipsStore()
const route = useRoute()
const runtimeConfig = useRuntimeConfig()
const { me, supportOrAdmin } = useMe()

const showEdit = ref(false)
const editing = ref(null)
const deleting = ref(null)
const expanded = ref(null)

useHead(
  buildHead(
    route,
    runtimeConfig,
    'Partnerships',
    'Sponsorship deals with local authorities'
  )
)

// Members of the Partnerships team run this; Support and Admin can see it too.
const canUse = computed(() => {
  return (
    supportOrAdmin.value || Boolean(me.value?.teams?.includes('Partnerships'))
  )
})

const partnerships = computed(() => partnershipsStore.list)
const summary = computed(() => partnershipsStore.summary)
const expiring = computed(() => partnershipsStore.expiring)

function formatMoney(v) {
  return (parseFloat(v) || 0).toLocaleString('en-GB', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })
}

// Agreed and hoped-for money are stacked separately, so a fat pipeline never reads as
// income we already have.
const chartData = computed(() => {
  const rows = [['Financial year', 'Agreed', 'In discussion']]

  summary.value?.years?.forEach((y) => {
    rows.push([y.label, y.agreed, y.pipeline])
  })

  return rows
})

const chartOptions = {
  height: 320,
  isStacked: true,
  legend: { position: 'top' },
  colors: ['#5B8930', '#c0c0c0'],
  vAxis: { format: '£#,##0', minValue: 0 },
  chartArea: { width: '85%', height: '70%' },
}

function toggle(id) {
  expanded.value = expanded.value === id ? null : id
}

function addPartnership() {
  editing.value = null
  showEdit.value = true
}

function editPartnership(p) {
  editing.value = p
  showEdit.value = true
}

function confirmDelete(p) {
  deleting.value = p
}

async function doDelete() {
  await partnershipsStore.remove(deleting.value.id)
  deleting.value = null
}

onMounted(async () => {
  if (canUse.value) {
    await partnershipsStore.refresh()

    // A reminder email links straight to the deal it is chasing.
    const id = parseInt(route.query.id)
    if (id) {
      expanded.value = id
    }
  }
})
</script>
<style scoped lang="scss">
.chart {
  width: 100%;
  max-width: 900px;
}
</style>
