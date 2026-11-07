<template>
  <div data-testid="case-list">
    <h2>Support cases</h2>
    <b-form-radio-group
      v-model="filter"
      :options="filterOptions"
      buttons
      button-variant="outline-secondary"
      size="sm"
      class="mb-3"
      data-testid="case-filter"
      @update:model-value="reloadList"
    />
    <NoticeMessage v-if="error" variant="danger">{{ error }}</NoticeMessage>
    <div v-if="loading && !cases.length" class="text-center p-4">
      <b-spinner />
    </div>
    <p v-else-if="!cases.length && !error" class="text-muted">
      No cases to show.
    </p>
    <nuxt-link
      v-for="c in cases"
      :key="c.id"
      :to="'/support/cases/' + c.id"
      class="case-row d-block p-2 mb-2 text-decoration-none"
      data-testid="case-row"
    >
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <strong>Case {{ c.id }}</strong>
        <b-badge :variant="statusVariant(c.status)">{{ c.status }}</b-badge>
        <b-badge v-if="c.classification" variant="secondary">
          {{ c.classification }}
        </b-badge>
        <span v-if="c.confidence" class="small text-muted">
          {{ c.confidence }} confidence
        </span>
        <span class="small text-muted ms-auto">
          {{ timeago(c.lastactivity || c.created) }}
        </span>
      </div>
      <div class="small text-muted">
        <template v-if="c.member">About {{ c.member.displayname }}.</template>
        <template v-if="c.referrer">
          Referred by {{ c.referrer.displayname }}.
        </template>
        {{ c.messages }} {{ c.messages === 1 ? 'reply' : 'replies' }}.
        <template v-if="c.firstpass_status !== 'Done'">
          First pass: {{ c.firstpass_status }}.
        </template>
      </div>
      <div v-if="c.summary" class="case-row__summary">{{ c.summary }}</div>
    </nuxt-link>
    <div v-if="more" class="text-center mt-3">
      <b-button
        variant="outline-secondary"
        :disabled="loading"
        data-testid="case-more"
        @click="loadList"
      >
        Load more
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import api from '~/api'
import { timeago } from '~/composables/useTimeFormat'
import { useMe } from '~/composables/useMe'

const PAGE = 50

const { myid } = useMe()
const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const filterOptions = [
  { text: 'Open', value: 'Open' },
  { text: 'Handled', value: 'Handled' },
  { text: 'All', value: 'All' },
]
const filter = ref('Open')
const cases = ref([])
const more = ref(false)
const loading = ref(false)
const error = ref('')

function statusVariant(status) {
  return status === 'Open' ? 'warning' : 'success'
}

async function loadList() {
  loading.value = true
  error.value = ''
  try {
    const params = { status: filter.value, limit: PAGE }
    if (cases.value.length) {
      params.before = cases.value[cases.value.length - 1].id
    }
    const page = (await apiInstance.supportcases.fetchCases(params)) || []
    cases.value.push(...page)
    more.value = page.length === PAGE
  } catch (e) {
    error.value = 'Could not load the cases.'
  } finally {
    loading.value = false
  }
}

function reloadList() {
  cases.value = []
  loadList()
}

// Logging in after the page opened loads the list.
watch(myid, (newVal, oldVal) => {
  if (!oldVal && newVal && !cases.value.length && !loading.value) {
    reloadList()
  }
})

onMounted(loadList)
</script>

<style scoped lang="scss">
.case-row {
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #fff;
  color: inherit;
}

.case-row__summary {
  overflow-wrap: anywhere;
}
</style>
