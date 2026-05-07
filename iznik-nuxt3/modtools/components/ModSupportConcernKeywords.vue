<template>
  <div>
    <NoticeMessage variant="danger" class="mb-3">
      Use with extreme care. Concern keywords affect automated moderation across
      the entire system and all communities.
    </NoticeMessage>

    <div v-if="systemConfigStore.isLoading" class="text-center">
      <b-spinner class="me-2" />
      Loading keywords...
    </div>

    <div v-else>
      <b-form class="mb-3" @submit.prevent="addKeyword">
        <b-form-group label="Add new keyword:">
          <div class="d-flex gap-2 flex-wrap align-items-end">
            <b-form-input
              v-model="newKeyword"
              placeholder="Keyword or pattern"
              class="flex-grow-1"
              style="min-width: 180px"
            />
            <b-form-select
              v-model="newCategory"
              :options="categoryOptions"
              style="width: 200px"
            />
            <b-form-select
              v-model="newMatchMode"
              :options="matchModeOptions"
              style="width: 180px"
            />
            <b-form-select
              v-model="newAction"
              :options="actionOptions"
              style="width: 180px"
            />
            <b-button
              type="submit"
              variant="primary"
              :disabled="!newKeyword || !newKeyword.trim() || systemConfigStore.isLoading"
            >
              Add
            </b-button>
          </div>
        </b-form-group>
      </b-form>

      <div v-if="systemConfigStore.hasError" class="mb-3">
        <NoticeMessage variant="danger">
          Error: {{ systemConfigStore.getError }}
        </NoticeMessage>
      </div>

      <div class="d-flex align-items-center gap-3 mb-3">
        <span class="fw-bold">
          {{ filteredKeywords.length }} of
          {{ globalKeywords.length }} keywords
        </span>
        <b-form-select
          v-model="filterCategory"
          :options="filterOptions"
          style="width: 220px"
        />
      </div>

      <div v-if="globalKeywords.length === 0" class="text-muted">
        No concern keywords configured.
      </div>

      <b-table
        v-else
        :items="filteredKeywords"
        :fields="fields"
        striped
        hover
        small
        responsive
      >
        <template #cell(category)="{ item }">
          <span :class="categoryClass(item.category)">
            {{ categoryLabel(item.category) }}
          </span>
        </template>
        <template #cell(match_mode)="{ item }">
          {{ matchModeLabel(item.match_mode) }}
        </template>
        <template #cell(action)="{ item }">
          <span :class="item.action === 'block' ? 'text-danger fw-bold' : 'text-warning'">
            {{ item.action === 'block' ? 'Block' : 'Flag' }}
          </span>
        </template>
        <template #cell(delete)="{ item }">
          <b-button size="sm" variant="outline-danger" @click="remove(item.id)">
            Delete
          </b-button>
        </template>
      </b-table>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useSystemConfigStore } from '~/stores/systemconfig'

const systemConfigStore = useSystemConfigStore()
await systemConfigStore.fetchConcernKeywords()

const newKeyword = ref('')
const newCategory = ref('review')
const newMatchMode = ref('literal')
const newAction = ref('flag')
const filterCategory = ref('all')

const categoryOptions = [
  { value: 'substance_regulated', text: 'Substance — Regulated' },
  { value: 'substance_reportable', text: 'Substance — Reportable' },
  { value: 'substance_medicine', text: 'Substance — Medicine' },
  { value: 'scam', text: 'Scam / fraud' },
  { value: 'review', text: 'Review (general)' },
  { value: 'allowed', text: 'Allowed (whitelist)' },
]

const matchModeOptions = [
  { value: 'literal', text: 'Literal (word boundary)' },
  { value: 'fuzzy', text: 'Fuzzy (catches typos)' },
  { value: 'regex', text: 'Regex' },
]

const actionOptions = [
  { value: 'flag', text: 'Flag for review' },
  { value: 'block', text: 'Block immediately' },
]

const filterOptions = [
  { value: 'all', text: 'All categories' },
  ...categoryOptions,
]

const fields = [
  { key: 'keyword', label: 'Keyword', sortable: true },
  { key: 'category', label: 'Category', sortable: true },
  { key: 'match_mode', label: 'Match mode' },
  { key: 'action', label: 'Action', sortable: true },
  { key: 'delete', label: '' },
]

const globalKeywords = computed(() =>
  systemConfigStore.getConcernKeywords.filter((k) => k.scope === 'global')
)

const filteredKeywords = computed(() => {
  if (filterCategory.value === 'all') return globalKeywords.value
  return globalKeywords.value.filter((k) => k.category === filterCategory.value)
})

function categoryLabel(cat) {
  return categoryOptions.find((o) => o.value === cat)?.text ?? cat
}

function matchModeLabel(mode) {
  return matchModeOptions.find((o) => o.value === mode)?.text ?? mode
}

function categoryClass(cat) {
  if (cat === 'substance_regulated' || cat === 'scam') return 'text-danger'
  if (cat === 'allowed') return 'text-success'
  return ''
}

async function addKeyword() {
  if (!newKeyword.value.trim()) return
  await systemConfigStore.addConcernKeyword({
    keyword: newKeyword.value.trim(),
    category: newCategory.value,
    match_mode: newMatchMode.value,
    action: newAction.value,
    scope: 'global',
  })
  newKeyword.value = ''
}

async function remove(id) {
  await systemConfigStore.deleteConcernKeyword(id)
}
</script>
