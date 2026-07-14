<template>
  <b-modal
    :id="'messageReportModal-' + message?.id"
    ref="modal"
    scrollable
    title="Report this post"
    size="md"
    @hidden="$emit('hidden')"
  >
    <template #default>
      <div v-if="!submitted" class="report-content">
        <div class="report-item-preview">
          <div class="preview-type">
            {{ message?.type === 'Offer' ? 'OFFER' : 'WANTED' }}
          </div>
          <div class="preview-subject">{{ message?.subject }}</div>
        </div>

        <p class="report-explanation">
          If something's wrong with this post, please let us know. Your report
          will be sent to local volunteers who will review it and take
          appropriate action.
        </p>

        <div class="report-reasons">
          <label class="form-label">What's wrong with this post?</label>
          <div class="reason-options">
            <b-form-radio
              v-model="selectedReason"
              name="report-reason"
              value="inappropriate"
            >
              Inappropriate content
            </b-form-radio>
            <b-form-radio
              v-model="selectedReason"
              name="report-reason"
              value="spam"
            >
              Spam or advertising
            </b-form-radio>
            <b-form-radio
              v-model="selectedReason"
              name="report-reason"
              value="scam"
            >
              Possible scam
            </b-form-radio>
            <b-form-radio
              v-model="selectedReason"
              name="report-reason"
              value="other"
            >
              Other issue
            </b-form-radio>
          </div>
        </div>

        <div v-if="showGroupSelector" class="report-groups">
          <label class="form-label"
            >Which communities should be notified?</label
          >
          <p class="report-groups-hint">
            This post is on several communities. As a moderator you can report
            it to more than one team.
          </p>
          <b-form-checkbox
            v-model="allSelected"
            :indeterminate="someSelected"
            class="report-group-all"
          >
            All communities
          </b-form-checkbox>
          <b-form-checkbox-group v-model="selectedGroupIds" stacked>
            <b-form-checkbox
              v-for="grp in reportableGroups"
              :key="grp.groupid"
              :value="grp.groupid"
            >
              {{ grp.name }}
            </b-form-checkbox>
          </b-form-checkbox-group>
        </div>

        <div class="report-details">
          <label class="form-label">Additional details (optional)</label>
          <b-form-textarea
            v-model="additionalDetails"
            rows="3"
            placeholder="Please provide any additional information that might help our volunteers."
          />
        </div>

        <p v-if="submitError" class="report-error text-danger">
          Sorry, we couldn't send your report. Please check your connection and
          try again.
        </p>
      </div>

      <div v-else class="report-success">
        <v-icon icon="check-circle" class="success-icon" />
        <h5>Report submitted</h5>
        <p>
          Thank you for helping keep Freegle safe. Our volunteers will review
          this post and take appropriate action.
        </p>
        <p v-if="failedGroups.length" class="report-partial text-warning">
          We couldn't reach the volunteers for: {{ failedGroups.join(', ') }}.
        </p>
      </div>
    </template>

    <template #footer>
      <div v-if="!submitted" class="d-flex gap-2 w-100 justify-content-end">
        <b-button variant="secondary" @click="hide">Cancel</b-button>
        <b-button
          variant="warning"
          :disabled="!canSubmit || submitting"
          @click="report"
        >
          <span v-if="submitting">
            <b-spinner small class="me-1" />Submitting...
          </span>
          <span v-else>Submit Report</span>
        </b-button>
      </div>
      <div v-else class="w-100 text-end">
        <b-button variant="primary" @click="hide">Close</b-button>
      </div>
    </template>
  </b-modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRuntimeConfig } from 'nuxt/app'
import { useMessageStore } from '~/stores/message'
import { useChatStore } from '~/stores/chat'
import { useAuthStore } from '~/stores/auth'
import { useGroupStore } from '~/stores/group'
import { useMe } from '~/composables/useMe'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

defineEmits(['hidden'])

const messageStore = useMessageStore()
const chatStore = useChatStore()
const authStore = useAuthStore()
const groupStore = useGroupStore()
const { me } = useMe()
const { modal, hide } = useOurModal()

const selectedReason = ref(null)
const additionalDetails = ref('')
const submitting = ref(false)
const submitted = ref(false)
const submitError = ref(false)
const failedGroups = ref([])
const selectedGroupIds = ref([])

const message = computed(() => {
  return messageStore?.byId(props.id)
})

const reportGroupId = computed(() => {
  if (!message.value?.groups?.length) return null
  const myGroupIds = (authStore.groups || []).map((g) => parseInt(g.groupid))
  // Find groups shared between the user and the message, preferring most recent.
  const shared = message.value.groups
    .filter((g) => myGroupIds.includes(parseInt(g.groupid)))
    .sort((a, b) => new Date(b.arrival) - new Date(a.arrival))
  return shared.length > 0 ? shared[0].groupid : message.value.groups[0].groupid
})

// Moderators (and above) can report a post to more than one community's mod
// team, since a post can be on several groups once it has rippled out.
const isMod = computed(() => {
  const role = me.value?.systemrole
  return role === 'Moderator' || role === 'Support' || role === 'Admin'
})

// Every community the post is on, resolved to a display name via the group
// store, with today's default group first. De-duplicated by group id.
const reportableGroups = computed(() => {
  const raw = message.value?.groups
  if (!raw?.length) return []
  const defaultId = parseInt(reportGroupId.value)
  const seen = new Set()
  const groups = []
  for (const g of raw) {
    const groupid = parseInt(g.groupid)
    if (seen.has(groupid)) continue
    seen.add(groupid)
    const grp = groupStore.get(groupid)
    groups.push({
      groupid,
      name: grp?.namedisplay || grp?.nameshort || 'Community #' + groupid,
    })
  }
  return groups.sort((a, b) => {
    if (a.groupid === defaultId) return -1
    if (b.groupid === defaultId) return 1
    return a.name.localeCompare(b.name)
  })
})

// Only show the multi-community selector to mods, and only when there is a
// real choice to make (the post is on more than one community).
const showGroupSelector = computed(
  () => isMod.value && reportableGroups.value.length > 1
)

const allSelected = computed({
  get() {
    return (
      reportableGroups.value.length > 0 &&
      selectedGroupIds.value.length === reportableGroups.value.length
    )
  },
  set(val) {
    selectedGroupIds.value = val
      ? reportableGroups.value.map((g) => g.groupid)
      : []
  },
})

const someSelected = computed(
  () =>
    selectedGroupIds.value.length > 0 &&
    selectedGroupIds.value.length < reportableGroups.value.length
)

// Seed the selection with today's default single group so a mod who ignores
// the selector and just hits Submit gets the same behaviour as before.
watch(
  reportGroupId,
  (id) => {
    if (id !== null && id !== undefined && !selectedGroupIds.value.length) {
      selectedGroupIds.value = [parseInt(id)]
    }
  },
  { immediate: true }
)

const canSubmit = computed(() => {
  if (selectedReason.value === null) return false
  // A mod who has opened the selector must pick at least one community.
  if (showGroupSelector.value && selectedGroupIds.value.length === 0) {
    return false
  }
  return true
})

const reasonLabels = {
  inappropriate: 'Inappropriate content',
  spam: 'Spam or advertising',
  scam: 'Possible scam',
  other: 'Other issue',
}

async function report() {
  if (!canSubmit.value || submitting.value) return

  submitting.value = true
  submitError.value = false
  failedGroups.value = []

  const runtimeConfig = useRuntimeConfig()
  const reasonText = reasonLabels[selectedReason.value] || selectedReason.value
  let reportMessage =
    "I'm reporting this post as inappropriate:\n" +
    runtimeConfig.public.USER_SITE +
    '/message/' +
    message.value.id +
    '\n\nReason: ' +
    reasonText

  if (additionalDetails.value?.trim()) {
    reportMessage +=
      '\n\nAdditional details: "' + additionalDetails.value.trim() + '"'
  }

  // Mods can widen the report to several communities; everyone else reports to
  // the single natural group exactly as before.
  const targetIds =
    showGroupSelector.value && selectedGroupIds.value.length
      ? selectedGroupIds.value
      : [parseInt(reportGroupId.value)]

  // Report to each community's mod team in turn. One failure must not stop the
  // rest, and we surface any that failed rather than swallowing them.
  let anySucceeded = false
  for (const groupid of targetIds) {
    try {
      const chatid = await chatStore.openChatToMods(groupid)
      await chatStore.send({
        chatid,
        message: reportMessage,
        refmsgid: props.id,
      })
      anySucceeded = true
    } catch (error) {
      console.error('Failed to submit report to group ' + groupid, error)
      const grp = reportableGroups.value.find((g) => g.groupid === groupid)
      failedGroups.value.push(grp?.name || 'Community #' + groupid)
    }
  }

  submitting.value = false

  if (anySucceeded) {
    submitted.value = true
  } else {
    submitError.value = true
  }
}
</script>

<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.report-content {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.report-item-preview {
  background: $color-gray-3;
  padding: 0.75rem 1rem;
  border-left: 3px solid $color-success;
  border-radius: var(--radius-md, 0.5rem);
}

.preview-type {
  font-size: 0.7rem;
  font-weight: 600;
  color: $color-success;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.preview-subject {
  font-weight: 600;
  color: $color-gray--darker;
}

.report-explanation {
  color: var(--color-gray-600);
  font-size: 0.9rem;
  margin: 0;
}

.report-reasons {
  .form-label {
    font-weight: 600;
    color: $color-gray--darker;
    margin-bottom: 0.5rem;
  }
}

.reason-options {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;

  :deep(.form-check-input) {
    border: 2px solid $color-gray--base;
    background-color: $color-white;

    &:checked {
      background-color: $color-success;
      border-color: $color-success;
    }
  }
}

.report-details {
  .form-label {
    font-weight: 600;
    color: $color-gray--darker;
    margin-bottom: 0.5rem;
  }
}

.report-groups {
  .form-label {
    font-weight: 600;
    color: $color-gray--darker;
    margin-bottom: 0.25rem;
  }
}

.report-groups-hint {
  color: var(--color-gray-600);
  font-size: 0.85rem;
  margin: 0 0 0.5rem;
}

.report-group-all {
  font-weight: 600;
  margin-bottom: 0.25rem;
}

.report-error {
  font-size: 0.9rem;
  margin: 0;
}

.report-partial {
  font-size: 0.9rem;
  margin: 0.5rem 0 0;
}

.report-success {
  text-align: center;
  padding: 1.5rem;

  .success-icon {
    font-size: 3rem;
    color: $color-green--darker;
    margin-bottom: 1rem;
  }

  h5 {
    color: $color-gray--darker;
    margin-bottom: 0.5rem;
  }

  p {
    color: var(--color-gray-600);
    margin: 0;
  }
}
</style>
