<template>
  <div v-if="detail" class="border-start ps-3 mt-2">
    <div class="d-flex flex-wrap gap-2 mb-3">
      <div class="factbox">
        <div class="small text-muted">Deal</div>
        <strong>{{ length || '-' }}</strong>
        <div class="small">
          {{ formatDate(p.startdate) }} to {{ formatDate(p.enddate) }}
        </div>
      </div>
      <div class="factbox">
        <div class="small text-muted">Received</div>
        <strong>£{{ formatMoney(p.paid) }}</strong>
        <div class="small">of £{{ formatMoney(p.amount) }} due</div>
      </div>
      <div v-if="discount" class="factbox">
        <div class="small text-muted">Bulk discount</div>
        <strong>{{ discount }}</strong>
        <div class="small">off £{{ formatMoney(p.fullprice) }}</div>
      </div>
      <div class="factbox">
        <div class="small text-muted">Renewal</div>
        <ModPartnershipRenewal :renewal="p.renewal" show-text />
        <div class="small">asked about by {{ formatDate(renewalAsk) }}</div>
      </div>
    </div>

    <b-row>
      <b-col cols="12" lg="6">
        <h5>Communities covered</h5>
        <p class="text-muted small">
          Every community that overlaps the council boundary significantly is
          covered, including any set up later. One that only touches the edge is
          not. The percentage is how much of it lies inside the boundary; the
          statistics count that share of it. These are the communities the
          statistics report on.
        </p>
        <NoticeMessage v-if="!covered.length" variant="warning">
          No communities are covered, so nothing is showing to members.
        </NoticeMessage>
        <ul v-else class="list-unstyled mb-2">
          <li
            v-for="g in covered"
            :key="'pg-' + g.groupid"
            class="d-flex align-items-center justify-content-between"
          >
            <span>
              <ExternalLink :href="exploreUrl(g)">{{
                g.namedisplay
              }}</ExternalLink>
              <span class="small text-muted ms-1">
                {{
                  g.source === 'Added'
                    ? 'added by hand'
                    : Math.round((g.overlap || 0) * 100) + '% inside'
                }}
              </span>
            </span>
            <b-button
              variant="link"
              size="sm"
              class="text-danger"
              @click="removeGroup(g.groupid)"
            >
              Leave out
            </b-button>
          </li>
        </ul>

        <div v-if="leftOut.length" class="mb-2">
          <p class="small mb-1">Overlapping the boundary but left out:</p>
          <b-button
            v-for="g in leftOut"
            :key="'out-' + g.groupid"
            variant="white"
            size="sm"
            class="me-1 mb-1"
            @click="addGroup(g.groupid)"
          >
            + {{ g.namedisplay }}
          </b-button>
        </div>

        <ModPartnershipGroupPicker
          class="mb-2"
          label="Add a community outside the boundary"
          :exclude="detail.groups.map((g) => g.groupid)"
          @pick="(g) => addGroup(g.id)"
        />

        <SpinButton
          variant="secondary"
          icon-name="sync"
          label="Re-check the boundary"
          size="sm"
          @handle="redetect"
        />
      </b-col>

      <b-col cols="12" lg="6">
        <h5>Council contacts</h5>
        <p v-if="!detail.contacts.length" class="text-muted small">
          None yet. Add them with Edit.
        </p>
        <ul v-else class="list-unstyled">
          <li v-for="c in detail.contacts" :key="'c-' + c.id">
            {{ c.name }}
            <a v-if="c.email" :href="'mailto:' + c.email">{{ c.email }}</a>
            <span class="small text-muted ms-1">({{ roleText(c.role) }})</span>
          </li>
        </ul>

        <h5 class="mt-3">Financial years</h5>
        <p class="text-muted small">
          {{
            hasExplicitYears
              ? 'Split as agreed with the council.'
              : 'Spread evenly across the term. Override it below if the council pays in uneven instalments.'
          }}
        </p>
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Year</th>
              <th class="text-end">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="y in years" :key="'fy-' + y.financialyear">
              <td>{{ y.label }}</td>
              <td class="text-end">
                <b-form-input
                  v-model="y.amount"
                  type="number"
                  size="sm"
                  class="text-end"
                />
              </td>
            </tr>
          </tbody>
        </table>
        <div class="d-flex align-items-center gap-2">
          <SpinButton
            variant="primary"
            icon-name="save"
            label="Save split"
            size="sm"
            spinclass="text-white"
            @handle="saveYears"
          />
          <SpinButton
            v-if="hasExplicitYears"
            variant="white"
            icon-name="undo"
            label="Spread evenly"
            size="sm"
            @handle="clearYears"
          />
        </div>
        <p v-if="yearsTotal !== null" class="small mt-1 mb-0">
          Split totals £{{ formatMoney(yearsTotal, true) }} against a deal value
          of £{{ formatMoney(p.amount, true) }}.
          <span v-if="Math.abs(yearsTotal - p.amount) > 0.01">
            <strong class="text-danger">These don't match.</strong>
          </span>
        </p>
      </b-col>
    </b-row>

    <h5 class="mt-3">Invoices</h5>
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Date</th>
          <th class="text-end">Amount</th>
          <th>Reference</th>
          <th>Paid</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="pay in detail.payments" :key="'pay-' + pay.id">
          <td>{{ pay.date }}</td>
          <td class="text-end">£{{ formatMoney(pay.amount, true) }}</td>
          <td>{{ pay.reference }}</td>
          <td>
            <span v-if="pay.paid" class="text-success">{{ pay.paid }}</span>
            <b-button
              v-else
              variant="link"
              size="sm"
              class="p-0"
              @click="markPaid(pay)"
            >
              Mark paid today
            </b-button>
          </td>
          <td class="text-end">
            <b-button
              variant="link"
              size="sm"
              class="text-danger p-0"
              @click="deletingPayment = pay"
            >
              Delete
            </b-button>
          </td>
        </tr>
        <tr v-if="!detail.payments.length">
          <td colspan="5" class="text-muted">Nothing invoiced yet.</td>
        </tr>
      </tbody>
    </table>

    <b-row class="align-items-end g-2">
      <b-col cols="6" md="3">
        <label :for="`${formId}-invoice-date`" class="small mb-0"
          >Invoice date</label
        >
        <b-form-input
          :id="`${formId}-invoice-date`"
          v-model="newPayment.date"
          type="date"
          size="sm"
        />
      </b-col>
      <b-col cols="6" md="2">
        <label :for="`${formId}-amount`" class="small mb-0">Amount (£)</label>
        <b-form-input
          :id="`${formId}-amount`"
          v-model="newPayment.amount"
          type="number"
          size="sm"
        />
      </b-col>
      <b-col cols="6" md="3">
        <label :for="`${formId}-reference`" class="small mb-0">Reference</label>
        <b-form-input
          :id="`${formId}-reference`"
          v-model="newPayment.reference"
          size="sm"
        />
      </b-col>
      <b-col cols="6" md="2">
        <label :for="`${formId}-paid-on`" class="small mb-0">Paid on</label>
        <b-form-input
          :id="`${formId}-paid-on`"
          v-model="newPayment.paid"
          type="date"
          size="sm"
        />
      </b-col>
      <b-col cols="12" md="2">
        <SpinButton
          variant="primary"
          icon-name="plus"
          label="Add"
          size="sm"
          spinclass="text-white"
          :disabled="!newPayment.date"
          @handle="addPayment"
        />
      </b-col>
    </b-row>

    <h5 class="mt-4">History with this council</h5>
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Runs</th>
          <th>Length</th>
          <th class="text-end">Value</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="h in detail.history"
          :key="'h-' + h.id"
          :class="{ 'fw-bold': h.id === id }"
        >
          <td>{{ formatDate(h.startdate) }} to {{ formatDate(h.enddate) }}</td>
          <td>{{ dealLength(h.startdate, h.enddate) }}</td>
          <td class="text-end">£{{ formatMoney(h.amount) }}</td>
          <td>
            <b-badge :variant="statusInfo(h.status).variant">
              {{ statusInfo(h.status).text }}
            </b-badge>
          </td>
        </tr>
      </tbody>
    </table>

    <ConfirmModal
      v-if="deletingPayment"
      title="Delete this invoice?"
      :message="
        'The £' +
        formatMoney(deletingPayment.amount, true) +
        ' invoice dated ' +
        deletingPayment.date +
        ' will be removed.'
      "
      @confirm="removePayment"
      @hidden="deletingPayment = null"
    />
  </div>
</template>
<script setup>
import { ref, computed, watch, useId } from 'vue'
import { usePartnershipsStore } from '~/stores/partnerships'
import {
  CONTACT_ROLES,
  bulkDiscount,
  dealLength,
  formatDate,
  formatMoney,
  renewalAskDate,
  statusInfo,
} from '~/modtools/composables/usePartnershipFormat'

// Unique per instance so label/for pairs never clash when the component renders twice.
const formId = useId()

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const partnershipsStore = usePartnershipsStore()
const runtimeConfig = useRuntimeConfig()

const years = ref([])
const hasExplicitYears = ref(false)
const newPayment = ref({ date: '', amount: null, reference: '', paid: '' })
const deletingPayment = ref(null)

const detail = computed(() => partnershipsStore.byId(props.id))
const p = computed(() => detail.value.partnership)

const covered = computed(() =>
  detail.value.groups.filter((g) => g.source !== 'Removed')
)
const leftOut = computed(() =>
  detail.value.groups.filter((g) => g.source === 'Removed')
)

const length = computed(() => dealLength(p.value.startdate, p.value.enddate))
const discount = computed(() => bulkDiscount(p.value.fullprice, p.value.amount))

// The point to ask the council about next year.
const renewalAsk = computed(() => renewalAskDate(p.value.enddate))

const yearsTotal = computed(() => {
  if (!years.value.length) {
    return null
  }

  return years.value.reduce((t, y) => t + (parseFloat(y.amount) || 0), 0)
})

function exploreUrl(g) {
  const site = runtimeConfig.public.USER_SITE || 'https://www.ilovefreegle.org'
  return site.replace(/\/$/, '') + '/explore/' + g.nameshort
}

function roleText(role) {
  return CONTACT_ROLES.find((r) => r.value === role)?.text || role
}

async function load() {
  const ret = await partnershipsStore.fetchOne(props.id)
  syncYears(ret)
}

// The API returns the pro-rata split when no year-by-year split has been agreed, and says
// which of the two it gave us.
function syncYears(ret) {
  years.value = (ret?.years || []).map((y) => ({ ...y }))
  hasExplicitYears.value = Boolean(ret?.explicityears)
}

watch(() => props.id, load, { immediate: true })

async function addGroup(groupid) {
  await partnershipsStore.addGroup(props.id, groupid)
}

async function removeGroup(groupid) {
  await partnershipsStore.removeGroup(props.id, groupid)
}

async function redetect(callback) {
  await partnershipsStore.redetectGroups(props.id)
  callback?.()
}

async function saveYears(callback) {
  await partnershipsStore.setYears(
    props.id,
    years.value.map((y) => ({
      financialyear: y.financialyear,
      amount: parseFloat(y.amount) || 0,
    }))
  )
  hasExplicitYears.value = true
  callback?.()
}

async function clearYears(callback) {
  await partnershipsStore.setYears(props.id, [])
  hasExplicitYears.value = false
  syncYears(partnershipsStore.byId(props.id))
  callback?.()
}

async function addPayment(callback) {
  await partnershipsStore.addPayment(props.id, {
    date: newPayment.value.date,
    amount: parseFloat(newPayment.value.amount) || 0,
    reference: newPayment.value.reference,
    paid: newPayment.value.paid,
  })
  newPayment.value = { date: '', amount: null, reference: '', paid: '' }
  callback?.()
}

async function markPaid(payment) {
  await partnershipsStore.editPayment(props.id, payment.id, {
    paid: new Date().toISOString().substring(0, 10),
  })
}

async function removePayment() {
  await partnershipsStore.removePayment(props.id, deletingPayment.value.id)
  deletingPayment.value = null
}
</script>
<style scoped lang="scss">
.factbox {
  border: 1px solid $color-gray--light;
  border-radius: 0.25rem;
  padding: 0.5rem 0.75rem;
  min-width: 10rem;
}
</style>
