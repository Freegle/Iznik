<template>
  <div class="reply-queue">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 class="mb-0">
        Reply Queue
        <span class="badge bg-secondary ms-2">{{ pendingDrafts.length }}</span>
      </h5>
      <button class="btn btn-outline-secondary btn-sm" @click="refresh" :disabled="loading">
        <i class="bi bi-arrow-clockwise" :class="{ 'spin': loading }"></i>
      </button>
    </div>

    <div v-if="loading" class="spinner-border spinner-border-sm" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div v-else-if="pendingDrafts.length === 0" class="alert alert-info small">
      No pending replies.
    </div>

    <div v-else class="space-y-3">
      <div v-for="draft in pendingDrafts" :key="draft.id" class="draft-card">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="flex-grow-1">
            <a
              :href="`https://discourse.ilovefreegle.org/t/${draft.topic}/${draft.post}`"
              target="_blank"
              rel="noopener"
              class="text-decoration-none fw-bold small"
            >
              Bug #{{ draft.topic }}
            </a>
            <div class="small text-muted">
              by {{ draft.username }}
            </div>
          </div>
          <div v-if="draft.pr_number" class="small d-flex align-items-center gap-2">
            <a
              :href="draft.pr_url"
              target="_blank"
              rel="noopener"
              class="text-decoration-none"
            >
              PR #{{ draft.pr_number }}
            </a>
            <span
              v-if="draft.deploy_state === 'deployed'"
              class="badge bg-success"
              title="Fix confirmed live on production branch"
            >✓ Live</span>
            <span
              v-else-if="draft.deploy_state === 'pending_deploy'"
              class="badge bg-warning text-dark"
              title="Fix merged but not yet deployed to production branch"
            >⏳ Deploying</span>
            <span
              v-else-if="draft.pr_number"
              class="badge bg-secondary"
              title="Deployment status unknown — PR may not be merged yet"
            >? Deploy status</span>
          </div>
        </div>

        <div class="quote-section mb-2">
          <div class="quote-text">{{ truncate(draft.quote, 150) }}</div>
        </div>

        <div class="mb-2">
          <textarea
            v-model="editingBody[draft.id]"
            class="form-control form-control-sm"
            rows="3"
            placeholder="Reply body..."
          ></textarea>
        </div>

        <div class="d-flex gap-2">
          <button
            class="btn btn-success btn-sm"
            @click="handleSend(draft)"
            :disabled="sending[draft.id]"
          >
            <span v-if="sending[draft.id]" class="spinner-border spinner-border-sm me-1"></span>
            Send
          </button>
          <button
            class="btn btn-outline-secondary btn-sm"
            @click="handleReject(draft)"
            :disabled="sending[draft.id]"
          >
            Dismiss
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { DraftRow } from '../types'
import { rejectDraft, sendDraft } from '../composables/useApi'

const props = defineProps<{
  drafts: DraftRow[]
  loading: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const editingBody = ref<Record<number, string>>({})
const sending = ref<Record<number, boolean>>({})

const pendingDrafts = computed(() => {
  return props.drafts.filter(d => !d.approved_at && !d.posted_at && !d.rejected_at)
})

// Populate editingBody when drafts load (async) — only fill ids not already edited by user
watch(pendingDrafts, (drafts) => {
  for (const draft of drafts) {
    if (!(draft.id in editingBody.value)) {
      editingBody.value[draft.id] = draft.body
    }
  }
}, { immediate: true })

const handleSend = async (draft: DraftRow) => {
  sending.value[draft.id] = true
  try {
    // Update body first if changed
    const newBody = editingBody.value[draft.id]
    if (newBody !== draft.body) {
      const res = await fetch(`/api/drafts/${draft.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ body: newBody }),
      })
      if (!res.ok) throw new Error('Failed to update draft')
    }

    // Send the draft
    await sendDraft(draft.id)
    emit('refresh')
  } catch (err: any) {
    console.error('Failed to send draft:', err)
    alert('Error: ' + String(err?.message ?? err))
  } finally {
    sending.value[draft.id] = false
  }
}

const handleReject = async (draft: DraftRow) => {
  sending.value[draft.id] = true
  try {
    await rejectDraft(draft.id)
    emit('refresh')
  } catch (err: any) {
    console.error('Failed to reject draft:', err)
    alert('Error: ' + String(err?.message ?? err))
  } finally {
    sending.value[draft.id] = false
  }
}

function truncate(text: string | null, length: number): string {
  if (!text) return '—'
  return text.length > length ? text.substring(0, length) + '...' : text
}

function refresh() {
  emit('refresh')
}

</script>

<style scoped>
.reply-queue {
  background: white;
  padding: 1rem;
  border-radius: 0.25rem;
  border: 1px solid #dee2e6;
}

.draft-card {
  padding: 1rem;
  border: 1px solid #e9ecef;
  border-radius: 0.25rem;
  margin-bottom: 0.5rem;
}

.draft-card:hover {
  background-color: #f8f9fa;
}

.quote-section {
  padding: 0.5rem;
  background-color: #f8f9fa;
  border-left: 3px solid #dee2e6;
  border-radius: 0.25rem;
}

.quote-text {
  color: #495057;
  font-style: italic;
}

.space-y-3 > * + * {
  margin-top: 0.5rem;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
