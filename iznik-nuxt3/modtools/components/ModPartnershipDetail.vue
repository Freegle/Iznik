<template>
  <div v-if="detail" class="border-start ps-3 mt-2">
    <b-row>
      <b-col cols="12" lg="6">
        <h5>Communities covered</h5>
        <p class="text-muted small">
          Worked out from the council boundary. Each one gets a sponsor entry
          showing the tagline and link above.
        </p>
        <NoticeMessage v-if="!detail.groups.length" variant="warning">
          No communities are covered, so nothing is showing to members.
        </NoticeMessage>
        <ul v-else class="list-unstyled mb-2">
          <li
            v-for="g in detail.groups"
            :key="'pg-' + g.groupid"
            class="d-flex align-items-center justify-content-between"
          >
            <span>{{ g.namedisplay }}</span>
            <b-button
              variant="link"
              size="sm"
              class="text-danger"
              @click="removeGroup(g.groupid)"
            >
              Remove
            </b-button>
          </li>
        </ul>

        <SpinButton
          variant="secondary"
          icon-name="sync"
          label="Re-check the boundary"
          size="sm"
          @handle="redetect"
        />

        <div v-if="missing.length" class="mt-2">
          <p class="small mb-1">
            Inside the boundary but not covered by this deal:
          </p>
          <b-button
            v-for="g in missing"
            :key="'avail-' + g.groupid"
            variant="white"
            size="sm"
            class="me-1 mb-1"
            @click="addGroup(g.groupid)"
          >
            + {{ g.namedisplay }}
          </b-button>
        </div>
      </b-col>

      <b-col cols="12" lg="6">
        <h5>Financial years</h5>
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
          Split totals £{{ formatMoney(yearsTotal) }} against a deal value of
          £{{ formatMoney(detail.partnership.amount) }}.
          <span v-if="Math.abs(yearsTotal - detail.partnership.amount) > 0.01">
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
        <tr v-for="p in detail.payments" :key="'pay-' + p.id">
          <td>{{ p.date }}</td>
          <td class="text-end">£{{ formatMoney(p.amount) }}</td>
          <td>{{ p.reference }}</td>
          <td>
            <span v-if="p.paid" class="text-success">{{ p.paid }}</span>
            <b-button
              v-else
              variant="link"
              size="sm"
              class="p-0"
              @click="markPaid(p)"
            >
              Mark paid today
            </b-button>
          </td>
          <td class="text-end">
            <b-button
              variant="link"
              size="sm"
              class="text-danger p-0"
              @click="removePayment(p)"
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
        <label class="small mb-0">Invoice date</label>
        <b-form-input v-model="newPayment.date" type="date" size="sm" />
      </b-col>
      <b-col cols="6" md="2">
        <label class="small mb-0">Amount (£)</label>
        <b-form-input v-model="newPayment.amount" type="number" size="sm" />
      </b-col>
      <b-col cols="6" md="3">
        <label class="small mb-0">Reference</label>
        <b-form-input v-model="newPayment.reference" size="sm" />
      </b-col>
      <b-col cols="6" md="2">
        <label class="small mb-0">Paid on</label>
        <b-form-input v-model="newPayment.paid" type="date" size="sm" />
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
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import { usePartnershipsStore } from '~/stores/partnerships'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const partnershipsStore = usePartnershipsStore()

const available = ref([])
const years = ref([])
const hasExplicitYears = ref(false)
const newPayment = ref({ date: '', amount: null, reference: '', paid: '' })

const detail = computed(() => partnershipsStore.byId(props.id))

// Groups inside the council boundary that this deal doesn't currently cover - usually
// because someone removed them by hand, occasionally because the boundary has moved.
const missing = computed(() => {
  if (!detail.value) {
    return []
  }

  const covered = new Set(detail.value.groups.map((g) => g.groupid))
  return available.value.filter((g) => !covered.has(g.groupid))
})

const yearsTotal = computed(() => {
  if (!years.value.length) {
    return null
  }

  return years.value.reduce((t, y) => t + (parseFloat(y.amount) || 0), 0)
})

function formatMoney(v) {
  return (parseFloat(v) || 0).toLocaleString('en-GB', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

async function load() {
  const ret = await partnershipsStore.fetchOne(props.id)
  syncYears(ret)

  const groups = await partnershipsStore.fetchGroups(props.id)
  available.value = groups.available
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

async function removePayment(payment) {
  await partnershipsStore.removePayment(props.id, payment.id)
}
</script>
