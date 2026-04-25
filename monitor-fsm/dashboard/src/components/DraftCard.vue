<template>
  <div class="card mb-3" :class="{ 'border-success': draft.approved_at, 'border-danger': draft.rejected_at }">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong>@{{ draft.username }}</strong>
        <a
          :href="`https://discourse.ilovefreegle.org/t/${draft.topic}/${draft.post}`"
          target="_blank"
          rel="noopener"
          class="ms-2 small"
        >
          topic {{ draft.topic }}, post {{ draft.post }}
        </a>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <span v-if="draft.pr_number" class="badge" :class="prBadgeClass">
          PR #{{ draft.pr_number }} {{ draft.pr_state ?? '' }}
        </span>
        <span v-if="draft.posted_at" class="badge bg-success">sent</span>
        <span v-else-if="draft.approved_at" class="badge bg-primary">approved</span>
        <span v-else-if="draft.rejected_at" class="badge bg-danger">rejected</span>
        <span v-else class="badge bg-warning text-dark">pending</span>
      </div>
    </div>

    <div class="card-body p-3">
      <div v-if="draft.quote" class="mb-3 ps-3 border-start border-secondary">
        <p class="mb-0 small text-muted fst-italic">{{ draft.quote }}</p>
      </div>

      <textarea
        v-model="editedBody"
        class="form-control form-control-sm font-monospace"
        rows="8"
        :readonly="!!draft.posted_at || !!draft.approved_at"
      ></textarea>

      <div v-if="draft.posted_at" class="mt-2 small text-success">
        <i class="bi bi-check-circle"></i> Sent {{ formatDate(draft.posted_at) }}
      </div>
      <div v-else-if="draft.rejected_at" class="mt-2 small text-danger">
        <i class="bi bi-x-circle"></i> Rejected {{ formatDate(draft.rejected_at) }}
        <span v-if="draft.rejection_reason"> — {{ draft.rejection_reason }}</span>
      </div>
    </div>

    <div v-if="!draft.posted_at && !draft.rejected_at" class="card-footer bg-transparent d-flex gap-2 flex-wrap">
      <template v-if="!draft.approved_at">
        <button type="button" class="btn btn-success btn-sm" @click="handleApprove" :disabled="busy">
          <span v-if="approving" class="spinner-border spinner-border-sm me-1" role="status"></span>
          <i v-else class="bi bi-check-lg me-1"></i>Approve
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="handleSave" :disabled="busy || editedBody === draft.body">
          <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
          Save edits
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" @click="handleReject" :disabled="busy">
          Reject
        </button>
      </template>
      <template v-else>
        <span class="text-success small"><i class="bi bi-check-circle me-1"></i>Approved — FSM will post this reply when it next runs.</span>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { DraftRow } from '../types'
import { approveDraft, rejectDraft, editDraft } from '../composables/useApi'

const props = defineProps<{ draft: DraftRow }>()
const emit = defineEmits<{ (e: 'updated'): void }>()

const editedBody = ref(props.draft.body)
const approving = ref(false)
const saving = ref(false)
const rejecting = ref(false)
const busy = computed(() => approving.value || saving.value || rejecting.value)

const prBadgeClass = computed(() => ({
  'bg-success': props.draft.pr_state === 'MERGED',
  'bg-warning text-dark': props.draft.pr_state === 'OPEN',
  'bg-danger': props.draft.pr_state === 'CLOSED',
  'bg-secondary': !props.draft.pr_state,
}))

const formatDate = (s: string) => new Date(s).toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' })

const handleApprove = async () => {
  approving.value = true
  try {
    await approveDraft(props.draft.id)
    emit('updated')
  } catch (err: any) {
    alert(String(err?.message ?? err))
  } finally {
    approving.value = false
  }
}

const handleReject = async () => {
  const reason = prompt('Reason for rejection (optional):') ?? undefined
  if (reason === null) return
  rejecting.value = true
  try {
    await rejectDraft(props.draft.id, reason || undefined)
    emit('updated')
  } catch (err: any) {
    alert(String(err?.message ?? err))
  } finally {
    rejecting.value = false
  }
}

const handleSave = async () => {
  saving.value = true
  try {
    await editDraft(props.draft.id, editedBody.value)
    emit('updated')
  } catch (err: any) {
    alert(String(err?.message ?? err))
  } finally {
    saving.value = false
  }
}
</script>
