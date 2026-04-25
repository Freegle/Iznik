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
          <div v-if="draft.pr_number" class="small">
            <a
              :href="draft.pr_url"
              target="_blank"
              rel="noopener"
              class="text-decoration-none"
            >
              PR #{{ draft.pr_number }}
            </a>
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
            class="btn btn-outline-primary btn-sm"
            @click="handleApprove(draft)"
            :disabled="sending[draft.id]"
          >
            Approve
          </button>
          <button
            class="btn btn-outline-danger btn-sm"
            @click="handleReject(draft)"
            :disabled="sending[draft.id]"
          >
            Reject
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { DraftRow } from '../types'
import { approveDraft, rejectDraft, sendDraft } from '../composables/useApi'

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

// Initialize editing body with current draft body
const initializeEditingBody = () => {
  const body: Record<number, string> = {}
  for (const draft of pendingDrafts.value) {
    body[draft.id] = draft.body
  }
  editingBody.value = body
}

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

const handleApprove = async (draft: DraftRow) => {
  sending.value[draft.id] = true
  try {
    await approveDraft(draft.id)
    emit('refresh')
  } catch (err: any) {
    console.error('Failed to approve draft:', err)
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

// Watch props to initialize/update editing body
if (pendingDrafts.value.length > 0) {
  initializeEditingBody()
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
