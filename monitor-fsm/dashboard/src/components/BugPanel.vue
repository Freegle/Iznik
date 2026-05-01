<template>
  <div class="bug-panel">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="mb-0">
        Bugs
        <span class="badge bg-secondary ms-2">{{ activeBugs.length }}</span>
      </h5>
      <button class="btn btn-outline-secondary btn-sm" @click="refresh" :disabled="loading">
        <i class="bi bi-arrow-clockwise" :class="{ 'spin': loading }"></i>
      </button>
    </div>

    <div v-if="loading" class="spinner-border spinner-border-sm" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div v-else-if="activeBugs.length === 0" class="alert alert-info small">
      No active bugs.
    </div>

    <table v-else class="table table-sm table-hover">
      <thead>
        <tr>
          <th style="width: 100px;">Reporter</th>
          <th>Summary</th>
          <th style="width: 80px;">State</th>
          <th style="width: 50px;">PR</th>
          <th style="width: 60px;"></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="(group, featureArea) in groupedBugs" :key="featureArea">
          <tr class="table-light">
            <td :colspan="5" class="text-muted fst-italic small">
              {{ featureArea }}
            </td>
          </tr>
          <tr v-for="bug in group" :key="`${bug.topic}-${bug.post}`" :class="{ 'table-warning': bug.state === 'deferred' }">
            <td>
              <a
                :href="`https://discourse.ilovefreegle.org/t/${bug.topic}/${bug.post}`"
                target="_blank"
                rel="noopener"
                class="text-decoration-none"
              >
                {{ bug.reporter || 'Unknown' }}
              </a>
            </td>
            <td style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 0; width: 100%;">
              <span :title="bug.state === 'deferred' && bug.reason ? bug.reason : (bug.excerpt || bug.topic_title || '')">
                {{ bug.excerpt || bug.topic_title || '—' }}
              </span>
            </td>
            <td>
              <StateBadge :state="bug.state" />
            </td>
            <td>
              <a
                v-if="bug.pr_number"
                :href="`https://github.com/Freegle/Iznik/pull/${bug.pr_number}`"
                target="_blank"
                rel="noopener"
                class="text-decoration-none"
              >
                #{{ bug.pr_number }}
              </a>
              <span v-else class="text-muted small">—</span>
            </td>
            <td class="text-end">
              <template v-if="bug.state === 'deferred'">
                <button
                  class="btn btn-outline-secondary btn-xs me-1"
                  title="Dismiss — remove from active bugs"
                  @click="showDismissModal(bug)"
                >✕</button>
                <button
                  class="btn btn-outline-primary btn-xs"
                  title="Link a PR — marks bug as fix-queued"
                  @click="promptLinkPr(bug)"
                >PR#</button>
              </template>
              <template v-else>
                <button
                  class="btn btn-outline-secondary btn-xs"
                  title="Dismiss — remove from active bugs"
                  @click="showDismissModal(bug)"
                >✕</button>
              </template>
            </td>
          </tr>
        </template>
      </tbody>
    </table>

    <!-- Dismiss Confirmation Modal -->
    <div class="modal fade" id="dismissModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Confirm Dismiss</h5>
            <button type="button" class="btn-close" @click="hideDismissModal"></button>
          </div>
          <div v-if="selectedBug" class="modal-body">
            <p>Are you sure you want to dismiss this bug?</p>
            <div class="alert alert-light border">
              <div class="small text-muted mb-1">Reporter:</div>
              <div class="mb-3">
                <a
                  :href="`https://discourse.ilovefreegle.org/t/${selectedBug.topic}/${selectedBug.post}`"
                  target="_blank"
                  rel="noopener"
                  class="text-decoration-none fw-semibold"
                >
                  {{ selectedBug.reporter || 'Unknown' }}
                </a>
              </div>
              <div class="small text-muted mb-1">Summary:</div>
              <div class="fw-semibold text-truncate">
                {{ selectedBug.excerpt || selectedBug.topic_title || '—' }}
              </div>
            </div>
            <p class="text-muted small mb-0">
              This will mark the bug as off-topic and remove it from the active list.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="hideDismissModal">Cancel</button>
            <button type="button" class="btn btn-danger" @click="confirmDismiss">Dismiss</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, nextTick } from 'vue'
import { Modal } from 'bootstrap'
import type { BugRow } from '../types'
import StateBadge from './StateBadge.vue'

const props = defineProps<{
  bugs: BugRow[]
  loading: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const selectedBug = ref<BugRow | null>(null)
let dismissModal: Modal | null = null

const activeBugs = computed(() =>
  props.bugs.filter(bug => ['open', 'investigating', 'fix-queued', 'deferred'].includes(bug.state))
)

const hasHumanBugs = computed(() => activeBugs.value.some(b => b.state === 'deferred'))

const groupedBugs = computed(() => {
  const groups: Record<string, BugRow[]> = {}
  for (const bug of activeBugs.value) {
    const area = (bug as any).group_key || bug.feature_area || 'Uncategorised'
    if (!groups[area]) groups[area] = []
    groups[area].push(bug)
  }
  return groups
})

function showDismissModal(bug: BugRow) {
  selectedBug.value = bug
  nextTick(() => {
    if (!dismissModal) {
      const modal = document.getElementById('dismissModal')
      if (modal) {
        dismissModal = new Modal(modal)
      }
    }
    if (dismissModal) {
      dismissModal.show()
    }
  })
}

function hideDismissModal() {
  if (dismissModal) {
    dismissModal.hide()
  }
  selectedBug.value = null
}

async function confirmDismiss() {
  if (!selectedBug.value) return
  const bug = selectedBug.value

  hideDismissModal()

  await fetch(`/api/bugs/${bug.topic}/${bug.post}/state`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ state: 'off-topic', reason: 'Dismissed by human' }),
  })
  emit('refresh')
}

async function promptLinkPr(bug: BugRow) {
  const input = window.prompt(`Link PR to bug ${bug.topic}/${bug.post}:\nEnter PR number:`)
  if (!input) return
  const prNumber = parseInt(input.trim(), 10)
  if (isNaN(prNumber) || prNumber <= 0) { alert('Invalid PR number'); return }
  await fetch(`/api/bugs/${bug.topic}/${bug.post}/link-pr`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ prNumber }),
  })
  emit('refresh')
}

function refresh() {
  emit('refresh')
}
</script>

<style scoped>
.bug-panel {
  background: white;
  padding: 1rem;
  border-radius: 0.25rem;
  border: 1px solid #dee2e6;
}

.table {
  margin-bottom: 0;
}

.btn-xs {
  padding: 0.1rem 0.35rem;
  font-size: 0.75rem;
  line-height: 1.2;
  border-radius: 0.2rem;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
