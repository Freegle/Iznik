<template>
  <div class="p-2">
    <p class="text-muted small">
      Every question put to the AI Support Helper, most recent first, with what
      it answered and how the volunteer rated it. Open a run to see each step
      the helper took. Quota is the Claude subscription's use before and after
      the run; anything else on the same subscription at the time moves it too,
      so the change is an upper bound.
    </p>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
      <b-form-radio-group
        v-model="filter"
        :options="filterOptions"
        buttons
        button-variant="outline-secondary"
        size="sm"
        data-testid="supportai-filter"
        @update:model-value="reload"
      />
      <span v-if="runs.length" class="small text-muted">
        Showing {{ runs.length }}: {{ counts.up }} up, {{ counts.down }} down,
        {{ counts.unrated }} not rated
      </span>
    </div>

    <NoticeMessage v-if="error" variant="danger">{{ error }}</NoticeMessage>

    <div v-if="loading && !runs.length" class="text-center p-4">
      <b-spinner />
    </div>

    <p v-else-if="!runs.length && !error" class="text-muted">
      No runs to show.
    </p>

    <div
      v-for="run in runs"
      :key="run.id"
      class="run mb-2"
      :class="{
        'run--down': run.rating === -1,
        'run--up': run.rating === 1,
      }"
      data-testid="supportai-run"
    >
      <div
        class="run__summary d-flex flex-wrap align-items-start gap-2 p-2"
        role="button"
        @click="toggle(run)"
      >
        <v-icon
          :icon="expanded[run.id] ? 'chevron-down' : 'chevron-right'"
          class="mt-1"
        />
        <div class="flex-grow-1 run__main">
          <div class="small text-muted">
            {{ timeago(run.created_at) }} &middot;
            {{ run.modname || 'Unknown' }}
            <template v-if="run.userid">
              about {{ run.username || 'member' }} (#{{ run.userid }})
            </template>
            <b-badge
              v-if="run.status === 'Error'"
              variant="danger"
              class="ms-1"
            >
              Error
            </b-badge>
          </div>
          <div class="run__query">{{ run.query }}</div>
          <div v-if="run.rating_comment" class="small run__comment">
            &ldquo;{{ run.rating_comment }}&rdquo;
          </div>
        </div>
        <div class="small text-muted text-end run__stats">
          <div>{{ formatDuration(run.duration_ms) }}</div>
          <div>{{ formatTokens(run) }}</div>
          <div v-if="quotaText(run)" :title="quotaTitle(run)">
            {{ quotaText(run) }}
          </div>
        </div>
        <div @click.stop>
          <ModSupportAIRating
            :key="run.id + ':' + run.rating"
            :run-id="run.id"
            :rating="run.rating"
            :comment="run.rating_comment || ''"
            compact
            @rated="(r) => (run.rating = r.rating)"
          />
        </div>
      </div>

      <div v-if="expanded[run.id]" class="run__detail p-3">
        <h6>Answer</h6>
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div class="run__answer mb-3" v-html="renderMarkdown(run.analysis)" />
        <p v-if="run.error" class="text-danger small">{{ run.error }}</p>

        <h6>Steps</h6>
        <div v-if="!details[run.id]" class="text-muted small">
          <b-spinner small /> Loading&hellip;
        </div>
        <p v-else-if="!details[run.id].steps.length" class="text-muted small">
          No steps were recorded.
        </p>
        <ul v-else class="run__steps list-unstyled small">
          <li
            v-for="(step, i) in details[run.id].steps"
            :key="i"
            :class="'step-' + step.type"
          >
            <template v-if="step.type === 'text'">{{ step.text }}</template>
            <template v-else-if="step.type === 'tool'">
              <strong>{{ step.name }}</strong>
              <code class="ms-1">{{ formatInput(step.input) }}</code>
            </template>
            <details v-else-if="step.type === 'result'">
              <summary :class="{ 'text-danger': step.error }">
                {{ step.error ? 'Error' : 'Result' }} ({{
                  (step.text || '').length
                }}
                characters)
              </summary>
              <pre class="run__result">{{ step.text }}</pre>
            </details>
          </li>
        </ul>
        <div class="small text-muted mt-2">
          Run #{{ run.id }}
          <template v-if="run.model">&middot; {{ run.model }}</template>
          <template v-if="run.driver">&middot; {{ run.driver }}</template>
          <template v-if="run.sessionid">
            &middot; session {{ run.sessionid }}
          </template>
        </div>
      </div>
    </div>

    <div v-if="more" class="text-center mt-3">
      <b-button
        variant="outline-secondary"
        :disabled="loading"
        data-testid="supportai-more"
        @click="load"
      >
        <b-spinner v-if="loading" small /> Load more
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'isomorphic-dompurify'
import api from '~/api'
import { timeago } from '~/composables/useTimeFormat'

const PAGE = 50

const runtimeConfig = useRuntimeConfig()
const apiInstance = api(runtimeConfig)

const filterOptions = [
  { text: 'All', value: '' },
  { text: 'Thumbs down', value: 'down' },
  { text: 'Thumbs up', value: 'up' },
  { text: 'Not rated', value: 'unrated' },
]

const filter = ref('')
const runs = ref([])
const loading = ref(false)
const error = ref('')
const more = ref(false)
const expanded = ref({})
const details = ref({})

const counts = computed(() => ({
  up: runs.value.filter((r) => r.rating === 1).length,
  down: runs.value.filter((r) => r.rating === -1).length,
  unrated: runs.value.filter((r) => !r.rating).length,
}))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = { limit: PAGE }
    if (filter.value) params.rating = filter.value
    if (runs.value.length) params.before = runs.value[runs.value.length - 1].id
    const page = (await apiInstance.supportai.fetchRuns(params)) || []
    runs.value.push(...page)
    more.value = page.length === PAGE
  } catch (e) {
    error.value = 'Could not load the helper runs.'
  } finally {
    loading.value = false
  }
}

function reload() {
  runs.value = []
  expanded.value = {}
  load()
}

async function toggle(run) {
  expanded.value[run.id] = !expanded.value[run.id]
  if (expanded.value[run.id] && !details.value[run.id]) {
    try {
      const full = await apiInstance.supportai.fetchRun(run.id)
      details.value[run.id] = { steps: parseSteps(full?.transcript) }
    } catch (e) {
      details.value[run.id] = { steps: [] }
    }
  }
}

function parseSteps(transcript) {
  if (!transcript) return []
  try {
    const steps = JSON.parse(transcript)
    return Array.isArray(steps) ? steps : []
  } catch (e) {
    return []
  }
}

// The answer is markdown that can quote member-supplied text, so sanitise it
// as the helper itself does.
function renderMarkdown(text) {
  return DOMPurify.sanitize(marked(text || ''))
}

function formatInput(input) {
  const s = typeof input === 'string' ? input : JSON.stringify(input)
  return s && s.length > 400 ? s.slice(0, 400) + '…' : s
}

function formatDuration(ms) {
  if (!ms) return ''
  const s = Math.round(ms / 1000)
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`
}

function formatTokens(run) {
  const n = (run.input_tokens || 0) + (run.output_tokens || 0)
  if (!n) return ''
  return n >= 1000 ? `${(n / 1000).toFixed(1)}k tokens` : `${n} tokens`
}

function delta(before, after) {
  if (before === null || before === undefined) return null
  if (after === null || after === undefined) return null
  return Math.round((after - before) * 100) / 100
}

function quotaText(run) {
  const d5 = delta(run.quota_5h_before, run.quota_5h_after)
  const d7 = delta(run.quota_7d_before, run.quota_7d_after)
  const parts = []
  if (d5 !== null) parts.push(`5h +${d5}%`)
  if (d7 !== null) parts.push(`week +${d7}%`)
  return parts.join(', ')
}

function quotaTitle(run) {
  return (
    `5-hour: ${run.quota_5h_before ?? '?'}% → ${run.quota_5h_after ?? '?'}%\n` +
    `Week: ${run.quota_7d_before ?? '?'}% → ${run.quota_7d_after ?? '?'}%`
  )
}

onMounted(load)
</script>

<style scoped lang="scss">
.run {
  border: 1px solid #dee2e6;
  border-left: 4px solid #dee2e6;
  border-radius: 4px;
  background: #fff;
}

.run--down {
  border-left-color: #dc3545;
}

.run--up {
  border-left-color: #28a745;
}

.run__summary:hover {
  background: #f8f9fa;
}

.run__main {
  min-width: 0;
}

.run__query {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.run__comment {
  color: #842029;
  font-style: italic;
}

.run__stats {
  min-width: 7rem;
}

.run__detail {
  border-top: 1px solid #dee2e6;
  background: #fbfbfb;
}

.run__answer {
  overflow-wrap: anywhere;
}

.run__steps li {
  margin-bottom: 0.35rem;
  overflow-wrap: anywhere;
}

.run__steps .step-text {
  color: #212529;
}

.run__steps .step-tool,
.run__steps .step-result {
  color: #6c757d;
  padding-left: 1rem;
}

.run__result {
  max-height: 20rem;
  overflow: auto;
  white-space: pre-wrap;
  font-size: 0.75rem;
  background: #f1f3f5;
  padding: 0.5rem;
}
</style>
